<?php
/**
 * CareerAct — Event & Activity Discovery Service
 * Handles career-targeted extracurricular activity recommendations,
 * city-based real-world opportunity exploration, automatic deadline expiration,
 * and opportunity saving/enrollment.
 */

declare(strict_types=1);

require_once __DIR__ . '/notification_service.php';

/**
 * Automatically transitions past-deadline published opportunities to 'expired' status.
 */
function autoExpirePastOpportunities(PDO $db): int
{
    $stmt = $db->prepare("
        UPDATE opportunities 
        SET status = 'expired', updated_at = NOW() 
        WHERE status = 'published' 
          AND registration_deadline IS NOT NULL 
          AND registration_deadline < CURDATE()
    ");
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * Retrieves the student's active primary career goal.
 */
function getStudentTargetCareer(PDO $db, int $userId): ?array
{
    $stmt = $db->prepare("
        SELECT 
            scg.career_id,
            scg.is_primary,
            scg.created_at AS selected_at,
            c.title AS career_title,
            c.description AS career_desc,
            c.typical_level,
            cs.name AS subfield_name,
            cf.id AS field_id,
            cf.name AS field_name
        FROM student_career_goals scg
        JOIN careers c ON scg.career_id = c.id
        LEFT JOIN career_subfields cs ON c.subfield_id = cs.id
        LEFT JOIN career_fields cf ON cs.field_id = cf.id
        WHERE scg.user_id = :user_id
        ORDER BY scg.is_primary DESC, scg.career_id ASC
        LIMIT 1
    ");
    $stmt->execute([':user_id' => $userId]);
    $goal = $stmt->fetch(PDO::FETCH_ASSOC);
    return $goal ?: null;
}

/**
 * Retrieves career-targeted activity recommendations for the given career goal.
 * Highlights activities that develop competencies or address known student skill gaps.
 */
function getRecommendedActivities(PDO $db, int $careerId, int $userId): array
{
    // 1. Fetch student's assessed skill gaps to cross-reference
    $stmtGaps = $db->prepare("
        SELECT rsg.skill_id, s.name AS skill_name, rsg.classification, rsg.gap
        FROM run_skill_gaps rsg
        JOIN recommendation_runs rr ON rsg.run_id = rr.id
        JOIN skills s ON rsg.skill_id = s.id
        WHERE rr.user_id = :user_id
        ORDER BY rr.id DESC
    ");
    $stmtGaps->execute([':user_id' => $userId]);
    $studentGaps = $stmtGaps->fetchAll(PDO::FETCH_ASSOC);
    $gapSkillIds = array_column($studentGaps, 'skill_id');

    // 2. Query activities associated with the career
    $stmtActs = $db->prepare("
        SELECT 
            ca.career_id,
            ca.activity_id,
            ca.relevance,
            ca.basis,
            a.id,
            a.title,
            a.description,
            a.delivery_mode,
            a.difficulty,
            a.typical_hours,
            a.eligibility,
            a.learning_outcomes,
            ac.id AS category_id,
            ac.code AS category_code,
            ac.name AS category_name,
            c.title AS career_title
        FROM career_activities ca
        JOIN activities a ON ca.activity_id = a.id
        JOIN activity_categories ac ON a.category_id = ac.id
        JOIN careers c ON ca.career_id = c.id
        WHERE ca.career_id = :career_id AND a.is_active = 1
        ORDER BY ca.relevance DESC, a.difficulty ASC
    ");
    $stmtActs->execute([':career_id' => $careerId]);
    $activities = $stmtActs->fetchAll(PDO::FETCH_ASSOC);

    // If no direct career activities mapped, fallback to high-value starter activities
    if (empty($activities)) {
        $stmtFallback = $db->prepare("
            SELECT 
                :career_id AS career_id,
                a.id AS activity_id,
                4 AS relevance,
                'curated_starter' AS basis,
                a.id,
                a.title,
                a.description,
                a.delivery_mode,
                a.difficulty,
                a.typical_hours,
                a.eligibility,
                a.learning_outcomes,
                ac.id AS category_id,
                ac.code AS category_code,
                ac.name AS category_name,
                (SELECT title FROM careers WHERE id = :career_id2) AS career_title
            FROM activities a
            JOIN activity_categories ac ON a.category_id = ac.id
            WHERE a.is_active = 1
            LIMIT 6
        ");
        $stmtFallback->execute([':career_id' => $careerId, ':career_id2' => $careerId]);
        $activities = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);
    }

    // 3. Attach developed skills and compute personalized benefit explanation
    foreach ($activities as &$act) {
        $stmtSkills = $db->prepare("
            SELECT s.id, s.name, s.skill_type, ask.gain_level
            FROM activity_skills ask
            JOIN skills s ON ask.skill_id = s.id
            WHERE ask.activity_id = :act_id
            ORDER BY ask.gain_level DESC
        ");
        $stmtSkills->execute([':act_id' => $act['activity_id']]);
        $skillsGained = $stmtSkills->fetchAll(PDO::FETCH_ASSOC);
        $act['skills_developed'] = $skillsGained;

        // Count opportunities count for this activity
        $stmtOppCount = $db->prepare("
            SELECT COUNT(*) 
            FROM opportunities 
            WHERE activity_id = :act_id 
              AND status = 'published' 
              AND (registration_deadline IS NULL OR registration_deadline >= CURDATE())
        ");
        $stmtOppCount->execute([':act_id' => $act['activity_id']]);
        $act['live_opportunities_count'] = (int)$stmtOppCount->fetchColumn();

        // Check if closes gap
        $closesGap = false;
        $gapNames = [];
        foreach ($skillsGained as $sk) {
            if (in_array($sk['id'], $gapSkillIds)) {
                $closesGap = true;
                $gapNames[] = $sk['name'];
            }
        }
        $act['closes_gap'] = $closesGap;
        $act['gap_skills'] = $gapNames;

        // Contextual benefit rationale
        if ($closesGap) {
            $act['why_beneficial'] = "Directly strengthens your identified skill gaps in " . implode(', ', array_slice($gapNames, 0, 2)) . " for target role {$act['career_title']}.";
        } elseif (!empty($skillsGained)) {
            $skillNames = array_column(array_slice($skillsGained, 0, 2), 'name');
            $act['why_beneficial'] = "Builds core competencies in " . implode(' & ', $skillNames) . ", vital for advancing toward {$act['career_title']}.";
        } else {
            $act['why_beneficial'] = "Offers hands-on practical experience that strengthens your resume and portfolio for {$act['career_title']}.";
        }
    }
    unset($act);

    return $activities;
}

/**
 * Returns supported tech hub cities with live opportunity counts.
 */
function getSupportedCities(PDO $db): array
{
    $cities = [
        ['code' => 'all',       'name' => 'All Hubs',       'display' => 'All Hubs & Online'],
        ['code' => 'pune',      'name' => 'Pune',           'display' => 'Pune'],
        ['code' => 'mumbai',    'name' => 'Mumbai',         'display' => 'Mumbai'],
        ['code' => 'bengaluru', 'name' => 'Bengaluru',      'display' => 'Bengaluru'],
        ['code' => 'delhi',     'name' => 'New Delhi',      'display' => 'Delhi / NCR'],
        ['code' => 'hyderabad', 'name' => 'Hyderabad',      'display' => 'Hyderabad'],
        ['code' => 'online',    'name' => 'Online',         'display' => 'Online / Virtual'],
    ];

    foreach ($cities as &$c) {
        if ($c['code'] === 'all') {
            $stmt = $db->query("
                SELECT COUNT(*) FROM opportunities 
                WHERE status = 'published' AND (registration_deadline IS NULL OR registration_deadline >= CURDATE())
            ");
            $c['count'] = (int)$stmt->fetchColumn();
        } elseif ($c['code'] === 'online') {
            $stmt = $db->query("
                SELECT COUNT(*) FROM opportunities 
                WHERE delivery_mode = 'online' AND status = 'published' AND (registration_deadline IS NULL OR registration_deadline >= CURDATE())
            ");
            $c['count'] = (int)$stmt->fetchColumn();
        } else {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM opportunities o
                JOIN cities ct ON o.city_id = ct.id
                WHERE ct.name LIKE :city_name 
                  AND o.status = 'published' 
                  AND (o.registration_deadline IS NULL OR o.registration_deadline >= CURDATE())
            ");
            $stmt->execute([':city_name' => '%' . $c['name'] . '%']);
            $c['count'] = (int)$stmt->fetchColumn();
        }
    }
    unset($c);

    return $cities;
}

/**
 * Retrieves the preferred city from the student profile.
 */
function getStudentPreferredCity(PDO $db, int $userId): string
{
    $stmt = $db->prepare("SELECT city FROM student_profiles WHERE user_id = :user_id LIMIT 1");
    $stmt->execute([':user_id' => $userId]);
    $city = trim((string)$stmt->fetchColumn());
    return $city !== '' ? $city : 'Pune'; // Default to Pune per project spec
}

/**
 * Updates the student's preferred city in student_profiles.
 */
function setStudentPreferredCity(PDO $db, int $userId, string $cityName): bool
{
    $stmt = $db->prepare("
        UPDATE student_profiles 
        SET city = :city, updated_at = NOW() 
        WHERE user_id = :user_id
    ");
    return $stmt->execute([':city' => $cityName, ':user_id' => $userId]);
}

/**
 * Retrieves available real-world opportunities matching filters.
 */
function getEventOpportunities(PDO $db, array $filters = [], int $userId = 0): array
{
    // Always trigger auto-expiration first
    autoExpirePastOpportunities($db);

    $sql = "
        SELECT 
            o.id,
            o.activity_id,
            o.provider_id,
            o.title,
            o.description,
            o.delivery_mode,
            o.country_id,
            o.city_id,
            o.state_name,
            o.scope_level,
            o.is_international_eligible,
            o.venue,
            o.start_date,
            o.end_date,
            o.registration_deadline,
            o.eligibility,
            o.min_age,
            o.cost_amount,
            o.cost_currency,
            o.financial_aid,
            o.registration_url,
            o.source_url,
            o.verification_status,
            o.status,
            a.title AS activity_title,
            ac.name AS category_name,
            ac.code AS category_code,
            COALESCE(p.name, 'Verified Academic Partner') AS organizer_name,
            p.website AS organizer_website,
            COALESCE(ct.name, CASE WHEN o.delivery_mode = 'online' THEN 'Online / Virtual' ELSE 'Regional' END) AS city_name
        FROM opportunities o
        JOIN activities a ON o.activity_id = a.id
        JOIN activity_categories ac ON a.category_id = ac.id
        LEFT JOIN providers p ON o.provider_id = p.id
        LEFT JOIN cities ct ON o.city_id = ct.id
        WHERE o.status = 'published'
          AND (o.registration_deadline IS NULL OR o.registration_deadline >= CURDATE())
          AND (o.country_id = 37 OR o.is_international_eligible = 1)
          AND o.verification_status IN ('verified', 'sample')
          AND ac.is_active = 1
    ";

    $params = [];

    // Filter by specific activity
    if (!empty($filters['activity_id'])) {
        $sql .= " AND o.activity_id = :activity_id";
        $params[':activity_id'] = (int)$filters['activity_id'];
    }

    // Filter by category (by ID or code)
    if (!empty($filters['category_id'])) {
        $sql .= " AND a.category_id = :category_id";
        $params[':category_id'] = (int)$filters['category_id'];
    } elseif (!empty($filters['category_code'])) {
        $sql .= " AND (ac.code = :category_code OR ac.id = :category_code_id)";
        $params[':category_code'] = strtoupper(trim((string)$filters['category_code']));
        $params[':category_code_id'] = (int)$filters['category_code'];
    }

    // Filter by Indian State
    if (!empty($filters['state']) && !in_array(strtolower(trim((string)$filters['state'])), ['all', 'all india', 'all_india', 'all hubs'])) {
        $st = strtolower(trim((string)$filters['state']));
        if ($st === 'online') {
            $sql .= " AND o.delivery_mode = 'online'";
        } else {
            $sql .= " AND (o.state_name = :state_filter OR o.scope_level = 'national' OR o.delivery_mode = 'online')";
            $params[':state_filter'] = trim((string)$filters['state']);
        }
    }

    // Filter by City / District / Town
    if (!empty($filters['city']) && !in_array(strtolower(trim((string)$filters['city'])), ['all', 'all cities', 'all india', 'all hubs'])) {
        $c = strtolower(trim((string)$filters['city']));
        if ($c === 'online') {
            $sql .= " AND o.delivery_mode = 'online'";
        } else {
            $sql .= " AND (ct.name LIKE :city_filter OR o.venue LIKE :city_venue OR o.state_name LIKE :city_state OR o.scope_level = 'national' OR o.delivery_mode = 'online')";
            $params[':city_filter'] = '%' . trim((string)$filters['city']) . '%';
            $params[':city_venue']  = '%' . trim((string)$filters['city']) . '%';
            $params[':city_state']  = '%' . trim((string)$filters['city']) . '%';
        }
    }

    // Filter by Scope Level (local, state, national, international_eligible)
    if (!empty($filters['scope_level']) && $filters['scope_level'] !== 'all') {
        $sql .= " AND o.scope_level = :scope_level";
        $params[':scope_level'] = $filters['scope_level'];
    }

    // Filter by Deadline urgency
    if (($filters['deadline_filter'] ?? '') === 'closing_soon') {
        $sql .= " AND o.registration_deadline IS NOT NULL AND DATEDIFF(o.registration_deadline, CURDATE()) BETWEEN 0 AND 7";
    } elseif (($filters['deadline_filter'] ?? '') === 'this_month') {
        $sql .= " AND o.registration_deadline IS NOT NULL AND DATEDIFF(o.registration_deadline, CURDATE()) BETWEEN 0 AND 30";
    }

    // Filter by Eligibility
    if (!empty($filters['eligibility']) && $filters['eligibility'] !== 'all') {
        $sql .= " AND o.eligibility LIKE :elig_filter";
        $params[':elig_filter'] = '%' . trim((string)$filters['eligibility']) . '%';
    }

    // Filter by delivery mode
    if (!empty($filters['delivery_mode']) && $filters['delivery_mode'] !== 'all') {
        $sql .= " AND o.delivery_mode = :delivery_mode";
        $params[':delivery_mode'] = $filters['delivery_mode'];
    }

    // Filter by cost (free vs paid)
    if (!empty($filters['cost_type'])) {
        if ($filters['cost_type'] === 'free') {
            $sql .= " AND (o.cost_amount IS NULL OR o.cost_amount = 0)";
        } elseif ($filters['cost_type'] === 'paid') {
            $sql .= " AND o.cost_amount > 0";
        }
    }

    // Search keyword
    if (!empty($filters['search'])) {
        $searchTerm = '%' . trim((string)$filters['search']) . '%';
        $sql .= " AND (
            o.title LIKE :s1 
            OR o.description LIKE :s2 
            OR o.venue LIKE :s3 
            OR a.title LIKE :s4 
            OR p.name LIKE :s5
            OR o.state_name LIKE :s6
        )";
        $params[':s1'] = $searchTerm;
        $params[':s2'] = $searchTerm;
        $params[':s3'] = $searchTerm;
        $params[':s4'] = $searchTerm;
        $params[':s5'] = $searchTerm;
        $params[':s6'] = $searchTerm;
    }

    $sortBy = $filters['sort_by'] ?? 'deadline';
    if ($sortBy === 'recent') {
        $sql .= " ORDER BY o.id DESC";
    } elseif ($sortBy === 'cost_asc') {
        $sql .= " ORDER BY (o.cost_amount > 0) ASC, o.cost_amount ASC, o.registration_deadline ASC";
    } else {
        $sql .= " ORDER BY o.registration_deadline ASC, o.start_date ASC, o.id DESC";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $opportunities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch user's enrollments to flag saved/registered opportunities
    $enrolledOppMap = [];
    $enrolledActMap = [];
    if ($userId > 0) {
        $stmtEnr = $db->prepare("
            SELECT id, activity_id, opportunity_id, status 
            FROM enrollments 
            WHERE user_id = :user_id
        ");
        $stmtEnr->execute([':user_id' => $userId]);
        $enrollments = $stmtEnr->fetchAll(PDO::FETCH_ASSOC);
        foreach ($enrollments as $enr) {
            if (!empty($enr['opportunity_id'])) {
                $enrolledOppMap[$enr['opportunity_id']] = $enr['status'];
            }
            $enrolledActMap[$enr['activity_id']] = $enr['status'];
        }
    }

    // Decorate opportunity records with countdown & enrollment status
    $today = new DateTimeImmutable('today');
    foreach ($opportunities as &$opp) {
        $opp['is_enrolled'] = isset($enrolledOppMap[$opp['id']]);
        $opp['enrollment_status'] = $enrolledOppMap[$opp['id']] ?? null;
        $opp['activity_enrolled'] = isset($enrolledActMap[$opp['activity_id']]);

        if (!empty($opp['registration_deadline'])) {
            $deadline = new DateTimeImmutable($opp['registration_deadline']);
            $diff = $today->diff($deadline);
            $opp['days_remaining'] = $diff->invert ? 0 : $diff->days;
            $opp['is_closing_soon'] = ($opp['days_remaining'] <= 5 && !$diff->invert);
        } else {
            $opp['days_remaining'] = null;
            $opp['is_closing_soon'] = false;
        }

        // Deterministic AI Match Score Calculation
        $careerTitle = strtolower($targetCareer['career_title'] ?? '');
        $oppTitle = strtolower($opp['title'] ?? '');
        $oppDesc = strtolower($opp['description'] ?? '');
        $baseScore = 55;
        if ($careerTitle !== '' && (strpos($oppTitle, 'software') !== false || strpos($oppTitle, 'code') !== false || strpos($oppTitle, 'hackathon') !== false || strpos($oppDesc, 'developer') !== false)) {
            $baseScore += 25;
        } elseif (in_array(strtolower($opp['delivery_mode'] ?? ''), ['online', 'hybrid'])) {
            $baseScore += 15;
        }
        if (($opp['verification_status'] ?? '') === 'verified') {
            $baseScore += 8;
        }
        $opp['match_score'] = min(96, max(50, $baseScore));
    }
    unset($opp);

    return $opportunities;
}

/**
 * Saves or registers an opportunity / activity in the enrollments table.
 */
function saveOrEnrollOpportunity(PDO $db, int $userId, int $activityId, ?int $opportunityId = null, string $status = 'registered'): array
{
    // Check if an enrollment record already exists
    if ($opportunityId) {
        $stmtChk = $db->prepare("
            SELECT id, status FROM enrollments 
            WHERE user_id = :user_id AND opportunity_id = :opp_id 
            LIMIT 1
        ");
        $stmtChk->execute([':user_id' => $userId, ':opp_id' => $opportunityId]);
        $existing = $stmtChk->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmtChk = $db->prepare("
            SELECT id, status FROM enrollments 
            WHERE user_id = :user_id AND activity_id = :act_id 
            LIMIT 1
        ");
        $stmtChk->execute([':user_id' => $userId, ':act_id' => $activityId]);
        $existing = $stmtChk->fetch(PDO::FETCH_ASSOC);
    }

    if ($existing) {
        // Update status (e.g. from saved to registered)
        $stmtUp = $db->prepare("
            UPDATE enrollments 
            SET status = :status, updated_at = NOW() 
            WHERE id = :id
        ");
        $stmtUp->execute([':status' => $status, ':id' => $existing['id']]);

        // Trigger in-app notification if status is meaningful confirmation
        if (function_exists('createNotification') && in_array($status, ['registered', 'in_progress', 'completed'], true)) {
            $stmtTitle = $db->prepare("SELECT title FROM activities WHERE id = :id");
            $stmtTitle->execute([':id' => $activityId]);
            $actTitle = $stmtTitle->fetchColumn() ?: 'Activity';
            $labelMap = ['registered' => 'Registered', 'in_progress' => 'In Progress', 'completed' => 'Completed'];
            $label = $labelMap[$status] ?? ucfirst($status);
            createNotification(
                $db,
                $userId,
                'activity_status_change',
                "Activity Status: {$label}",
                "You updated your participation status for '{$actTitle}' to {$label}.",
                "enrollments.php",
                "status_{$status}_act{$activityId}_u{$userId}_" . date('YmdHi')
            );
        }

        return ['success' => true, 'action' => 'updated', 'status' => $status];
    } else {
        $stmtIns = $db->prepare("
            INSERT INTO enrollments (
                user_id, activity_id, opportunity_id, status, progress_pct, created_at, updated_at
            ) VALUES (
                :user_id, :activity_id, :opp_id, :status, 0, NOW(), NOW()
            )
        ");
        $stmtIns->execute([
            ':user_id'     => $userId,
            ':activity_id' => $activityId,
            ':opp_id'      => $opportunityId,
            ':status'      => $status,
        ]);

        if (function_exists('createNotification') && in_array($status, ['registered', 'in_progress', 'completed'], true)) {
            $stmtTitle = $db->prepare("SELECT title FROM activities WHERE id = :id");
            $stmtTitle->execute([':id' => $activityId]);
            $actTitle = $stmtTitle->fetchColumn() ?: 'Activity';
            $labelMap = ['registered' => 'Registered', 'in_progress' => 'In Progress', 'completed' => 'Completed'];
            $label = $labelMap[$status] ?? ucfirst($status);
            createNotification(
                $db,
                $userId,
                'activity_status_change',
                "Activity Status: {$label}",
                "You registered for '{$actTitle}'. Manage your tracking in My Enrollments.",
                "enrollments.php",
                "status_{$status}_act{$activityId}_u{$userId}_" . date('YmdHi')
            );
        }

        return ['success' => true, 'action' => 'created', 'status' => $status];
    }
}

/**
 * Retrieves the standard icon and color palette for a given category code.
 */
function getCategoryVisualMeta(string $code): array
{
    $code = strtoupper(trim($code));
    $meta = [
        'HACKATHON' => [
            'theme'     => 'blue',
            'bg_color'  => '#EEF4FF',
            'icon_color'=> '#1769E8',
            'border_color' => '#D1E2FF',
            'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><polyline fill="none" stroke="currentColor" points="16 18 22 12 16 6"></polyline><polyline fill="none" stroke="currentColor" points="8 6 2 12 8 18"></polyline></svg>'
        ],
        'COMPETITION' => [
            'theme'     => 'amber',
            'bg_color'  => '#FFF7EB',
            'icon_color'=> '#D97706',
            'border_color' => '#FEE5B8',
            'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><path fill="none" stroke="currentColor" d="M6 9H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><path fill="none" stroke="currentColor" d="M18 9h2a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-4"></path><path fill="none" stroke="currentColor" d="M4 22h16"></path><path fill="none" stroke="currentColor" d="M10 14.66V17c0 .55-.45 1-1 1H7"></path><path fill="none" stroke="currentColor" d="M14 14.66V17c0 .55.45 1 1 1h2"></path><path fill="none" stroke="currentColor" d="M18 2H6v7a6 6 0 0 0 12 0V2z"></path></svg>'
        ],
        'INTERNSHIP' => [
            'theme'     => 'teal',
            'bg_color'  => '#E6F8F3',
            'icon_color'=> '#0F9D8A',
            'border_color' => '#BAEFE2',
            'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><rect fill="none" stroke="currentColor" x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path fill="none" stroke="currentColor" d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>'
        ],
        'WORKSHOP' => [
            'theme'     => 'purple',
            'bg_color'  => '#F3EDFF',
            'icon_color'=> '#8A46EA',
            'border_color' => '#E0D0FD',
            'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><line fill="none" stroke="currentColor" x1="4" y1="21" x2="4" y2="14"></line><line fill="none" stroke="currentColor" x1="4" y1="10" x2="4" y2="3"></line><line fill="none" stroke="currentColor" x1="12" y1="21" x2="12" y2="12"></line><line fill="none" stroke="currentColor" x1="12" y1="8" x2="12" y2="3"></line><line fill="none" stroke="currentColor" x1="20" y1="21" x2="20" y2="16"></line><line fill="none" stroke="currentColor" x1="20" y1="12" x2="20" y2="3"></line><line fill="none" stroke="currentColor" x1="1" y1="14" x2="7" y2="14"></line><line fill="none" stroke="currentColor" x1="9" y1="8" x2="15" y2="8"></line><line fill="none" stroke="currentColor" x1="17" y1="16" x2="23" y2="16"></line></svg>'
        ],
        'PROJECT' => [
            'theme'     => 'indigo',
            'bg_color'  => '#EEF2FF',
            'icon_color'=> '#4F46E5',
            'border_color' => '#CDD7FE',
            'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><polygon fill="none" stroke="currentColor" points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline fill="none" stroke="currentColor" points="2 17 12 22 22 17"></polyline><polyline fill="none" stroke="currentColor" points="2 12 12 17 22 12"></polyline></svg>'
        ],
        'LEADERSHIP' => [
            'theme'     => 'rose',
            'bg_color'  => '#FFF1F2',
            'icon_color'=> '#E11D48',
            'border_color' => '#FECDD3',
            'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><circle fill="none" stroke="currentColor" cx="12" cy="8" r="7"></circle><polyline fill="none" stroke="currentColor" points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline></svg>'
        ],
        'VOLUNTEERING' => [
            'theme'     => 'emerald',
            'bg_color'  => '#EDFDF5',
            'icon_color'=> '#059669',
            'border_color' => '#A7F3D0',
            'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><path fill="none" stroke="currentColor" d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>'
        ],
        'RESEARCH' => [
            'theme'     => 'slate',
            'bg_color'  => '#F1F5F9',
            'icon_color'=> '#475569',
            'border_color' => '#CBD5E1',
            'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><path fill="none" stroke="currentColor" d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path fill="none" stroke="currentColor" d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>'
        ],
        'CONFERENCE' => [
            'theme'     => 'sky',
            'bg_color'  => '#F0F9FF',
            'icon_color'=> '#0284C7',
            'border_color' => '#BAE6FD',
            'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><circle fill="none" stroke="currentColor" cx="12" cy="12" r="10"></circle><line fill="none" stroke="currentColor" x1="2" y1="12" x2="22" y2="12"></line><path fill="none" stroke="currentColor" d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>'
        ],
        'QUIZ' => [
            'theme'     => 'amber',
            'bg_color'  => '#FEFCE8',
            'icon_color'=> '#CA8A04',
            'border_color' => '#FEF08A',
            'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><circle fill="none" stroke="currentColor" cx="12" cy="12" r="10"></circle><path fill="none" stroke="currentColor" d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line fill="none" stroke="currentColor" x1="12" y1="17" x2="12.01" y2="17"></line></svg>'
        ],
        'MENTORSHIP' => [
            'theme'     => 'emerald',
            'bg_color'  => '#ECFDF5',
            'icon_color'=> '#059669',
            'border_color' => '#A7F3D0',
            'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><path fill="none" stroke="currentColor" d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle fill="none" stroke="currentColor" cx="8.5" cy="7" r="4"></circle><polyline fill="none" stroke="currentColor" points="17 11 19 13 23 9"></polyline></svg>'
        ],
        'FESTIVAL' => [
            'theme'     => 'fuchsia',
            'bg_color'  => '#FDF4FF',
            'icon_color'=> '#C026D3',
            'border_color' => '#F5D0FE',
            'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><polygon fill="none" stroke="currentColor" points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>'
        ],
        'CLUB' => [
            'theme'     => 'cyan',
            'bg_color'  => '#ECFEFF',
            'icon_color'=> '#0891B2',
            'border_color' => '#A5F3FC',
            'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><path fill="none" stroke="currentColor" d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle fill="none" stroke="currentColor" cx="9" cy="7" r="4"></circle><path fill="none" stroke="currentColor" d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path fill="none" stroke="currentColor" d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>'
        ],
        'COURSE' => [
            'theme'     => 'blue',
            'bg_color'  => '#EFF6FF',
            'icon_color'=> '#2563EB',
            'border_color' => '#BFDBFE',
            'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><path fill="none" stroke="currentColor" d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path fill="none" stroke="currentColor" d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>'
        ],
        'PERFORMANCE' => [
            'theme'     => 'rose',
            'bg_color'  => '#FFF1F2',
            'icon_color'=> '#F43F5E',
            'border_color' => '#FECDD3',
            'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><circle fill="none" stroke="currentColor" cx="12" cy="12" r="10"></circle><polygon fill="none" stroke="currentColor" points="10 8 16 12 10 16 10 8"></polygon></svg>'
        ],
        'ENTREPRENEURSHIP' => [
            'theme'     => 'amber',
            'bg_color'  => '#FFF7ED',
            'icon_color'=> '#EA580C',
            'border_color' => '#FED7AA',
            'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><path fill="none" stroke="currentColor" d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"></path><path fill="none" stroke="currentColor" d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"></path><path fill="none" stroke="currentColor" d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"></path><path fill="none" stroke="currentColor" d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"></path></svg>'
        ],
        'SPORTS' => [
            'theme'     => 'emerald',
            'bg_color'  => '#ECFDF5',
            'icon_color'=> '#059669',
            'border_color' => '#A7F3D0',
            'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><circle fill="none" stroke="currentColor" cx="12" cy="12" r="10"></circle><path fill="none" stroke="currentColor" d="M4.93 4.93l4.24 4.24"></path><path fill="none" stroke="currentColor" d="M14.83 9.17l4.24-4.24"></path><path fill="none" stroke="currentColor" d="M14.83 14.83l4.24 4.24"></path><path fill="none" stroke="currentColor" d="M9.17 14.83L4.93 19.07"></path><circle fill="none" stroke="currentColor" cx="12" cy="12" r="4"></circle></svg>'
        ]
    ];

    return $meta[$code] ?? [
        'theme'     => 'blue',
        'bg_color'  => '#EEF4FF',
        'icon_color'=> '#1769E8',
        'border_color' => '#D1E2FF',
        'icon_svg'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="fill:none!important;stroke:currentColor!important;"><circle fill="none" stroke="currentColor" cx="12" cy="12" r="10"></circle><polyline fill="none" stroke="currentColor" points="12 6 12 12 16 14"></polyline></svg>'
    ];
}

/**
 * Retrieves all activity categories with real verified opportunity counts,
 * tailored icons, and recommendation status based on the student's career goal.
 */
function getActivityCategoriesCatalog(PDO $db, int $userId = 0, ?string $searchQuery = null): array
{
    // Auto-expire opportunities first
    autoExpirePastOpportunities($db);

    // Identify student's target career recommendations
    $recommendedCategoryCodes = [];
    if ($userId > 0) {
        $goal = getStudentTargetCareer($db, $userId);
        if ($goal && !empty($goal['career_id'])) {
            $stmtRecCats = $db->prepare("
                SELECT DISTINCT ac.code 
                FROM career_activities ca
                JOIN activities a ON ca.activity_id = a.id
                JOIN activity_categories ac ON a.category_id = ac.id
                WHERE ca.career_id = :cid
            ");
            $stmtRecCats->execute([':cid' => (int)$goal['career_id']]);
            $recommendedCategoryCodes = $stmtRecCats->fetchAll(PDO::FETCH_COLUMN);
            $recommendedCategoryCodes = array_map('strtoupper', $recommendedCategoryCodes);
        }
    }

    // Default recommendations for software/tech tracks if goal not yet explicitly linked
    if (empty($recommendedCategoryCodes)) {
        $recommendedCategoryCodes = ['HACKATHON', 'PROJECT', 'WORKSHOP', 'INTERNSHIP', 'COMPETITION'];
    }

    // Base query for active categories
    $sql = "
        SELECT 
            ac.id,
            ac.code,
            ac.name,
            ac.description,
            COUNT(DISTINCT CASE 
                WHEN o.status = 'published' AND o.verification_status IN ('verified', 'sample') AND (o.registration_deadline IS NULL OR o.registration_deadline >= CURDATE()) 
                THEN o.id ELSE NULL 
            END) AS verified_opps_count
        FROM activity_categories ac
        LEFT JOIN activities a ON ac.id = a.category_id AND a.is_active = 1
        LEFT JOIN opportunities o ON a.id = o.activity_id
        WHERE ac.is_active = 1
    ";

    $params = [];
    if (!empty($searchQuery)) {
        $sql .= " AND (ac.name LIKE :search_name OR ac.description LIKE :search_desc)";
        $params[':search_name'] = '%' . trim($searchQuery) . '%';
        $params[':search_desc'] = '%' . trim($searchQuery) . '%';
    }

    $sql .= " GROUP BY ac.id, ac.code, ac.name, ac.description";
    $sql .= " ORDER BY ac.id ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decorate categories with visual meta and recommendation flags
    foreach ($categories as &$cat) {
        $code = strtoupper(trim((string)$cat['code']));
        $visual = getCategoryVisualMeta($code);
        $cat['theme'] = $visual['theme'];
        $cat['bg_color'] = $visual['bg_color'];
        $cat['icon_color'] = $visual['icon_color'];
        $cat['border_color'] = $visual['border_color'];
        $cat['icon_svg'] = $visual['icon_svg'];

        $cat['verified_opps_count'] = (int)$cat['verified_opps_count'];
        $cat['is_recommended'] = in_array($code, $recommendedCategoryCodes, true);
        $cat['badge_text'] = $cat['is_recommended'] ? 'Recommended for Your Track' : null;
    }
    unset($cat);

    return $categories;
}

/**
 * Retrieves details for a specific activity category by ID or Code.
 */
function getActivityCategoryDetails(PDO $db, $categoryIdOrCode): ?array
{
    autoExpirePastOpportunities($db);

    $sql = "
        SELECT 
            ac.id,
            ac.code,
            ac.name,
            ac.description,
            COUNT(DISTINCT CASE 
                WHEN o.status = 'published' AND o.verification_status IN ('verified', 'sample') AND (o.registration_deadline IS NULL OR o.registration_deadline >= CURDATE()) 
                THEN o.id ELSE NULL 
            END) AS verified_opps_count
        FROM activity_categories ac
        LEFT JOIN activities a ON ac.id = a.category_id AND a.is_active = 1
        LEFT JOIN opportunities o ON a.id = o.activity_id
        WHERE (ac.id = :id_param OR ac.code = :code_param) AND ac.is_active = 1
        GROUP BY ac.id, ac.code, ac.name, ac.description
        LIMIT 1
    ";

    $idParam = is_numeric($categoryIdOrCode) ? (int)$categoryIdOrCode : 0;
    $codeParam = strtoupper(trim((string)$categoryIdOrCode));

    $stmt = $db->prepare($sql);
    $stmt->execute([':id_param' => $idParam, ':code_param' => $codeParam]);
    $cat = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cat) {
        return null;
    }

    $visual = getCategoryVisualMeta($cat['code']);
    $cat['theme'] = $visual['theme'];
    $cat['bg_color'] = $visual['bg_color'];
    $cat['icon_color'] = $visual['icon_color'];
    $cat['border_color'] = $visual['border_color'];
    $cat['icon_svg'] = $visual['icon_svg'];
    $cat['verified_opps_count'] = (int)$cat['verified_opps_count'];

    return $cat;
}

/**
 * Returns structured directory of Indian States and primary city hubs.
 */
function getIndianStatesAndCities(): array
{
    return [
        'Maharashtra' => [
            'cities' => ['Pune', 'Mumbai', 'Nagpur', 'Nashik', 'Aurangabad', 'Kolhapur', 'Thane'],
            'hubs'   => 'COEP Tech, IIT Bombay, Savitribai Phule Pune Univ, VJTI'
        ],
        'Karnataka' => [
            'cities' => ['Bengaluru', 'Mysuru', 'Mangaluru', 'Hubballi-Dharwad', 'Belagavi'],
            'hubs'   => 'IISc Bengaluru, IIIT Bangalore, RV College, PES University'
        ],
        'Delhi' => [
            'cities' => ['New Delhi', 'North Delhi', 'South Delhi', 'Rohini', 'Dwarka'],
            'hubs'   => 'IIT Delhi, Delhi Technological Univ (DTU), NSUT, IIIT Delhi'
        ],
        'Tamil Nadu' => [
            'cities' => ['Chennai', 'Coimbatore', 'Madurai', 'Tiruchirappalli', 'Salem'],
            'hubs'   => 'IIT Madras, Anna University, NIT Trichy, PSG Tech'
        ],
        'Telangana' => [
            'cities' => ['Hyderabad', 'Warangal', 'Nizamabad', 'Karimnagar'],
            'hubs'   => 'IIIT Hyderabad, T-Hub Phase 2, IIT Hyderabad, Osmania Univ'
        ],
        'Gujarat' => [
            'cities' => ['Ahmedabad', 'Gandhinagar', 'Surat', 'Vadodara', 'Rajkot'],
            'hubs'   => 'IIT Gandhinagar, GTU Chandkheda, IIM Ahmedabad, DA-IICT'
        ],
        'Uttar Pradesh' => [
            'cities' => ['Noida', 'Greater Noida', 'Lucknow', 'Kanpur', 'Varanasi', 'Prayagraj'],
            'hubs'   => 'IIT Kanpur, IIT BHU, IIIT Allahabad, AKTU Lucknow'
        ],
        'West Bengal' => [
            'cities' => ['Kolkata', 'Kharagpur', 'Howrah', 'Durgapur', 'Siliguri'],
            'hubs'   => 'IIT Kharagpur, Jadavpur Univ, IIEST Shibpur, NIT Durgapur'
        ],
        'Kerala' => [
            'cities' => ['Kochi', 'Thiruvananthapuram', 'Kozhikode', 'Thrissur'],
            'hubs'   => 'IIT Palakkad, NIT Calicut, Maker Village KSUM, CUSAT'
        ],
        'Rajasthan' => [
            'cities' => ['Jaipur', 'Jodhpur', 'Udaipur', 'Kota', 'Bikaner'],
            'hubs'   => 'IIT Jodhpur, BITS Pilani, MNIT Jaipur, LNMIIT'
        ],
        'Andhra Pradesh' => [
            'cities' => ['Visakhapatnam', 'Vijayawada', 'Guntur', 'Tirupati'],
            'hubs'   => 'IIT Tirupati, NIT Andhra, Andhra University'
        ],
        'Madhya Pradesh' => [
            'cities' => ['Indore', 'Bhopal', 'Gwalior', 'Jabalpur'],
            'hubs'   => 'IIT Indore, MANIT Bhopal, IIITM Gwalior'
        ],
        'Punjab' => [
            'cities' => ['Chandigarh', 'Ludhiana', 'Amritsar', 'Jalandhar', 'Patiala'],
            'hubs'   => 'IIT Ropar, Thapar Institute, NIT Jalandhar, Panjab Univ'
        ],
        'Haryana' => [
            'cities' => ['Gurugram', 'Faridabad', 'Panipat', 'Ambala', 'Kurukshetra'],
            'hubs'   => 'NIT Kurukshetra, Ashoka University, YMCA Faridabad'
        ],
        'Bihar' => [
            'cities' => ['Patna', 'Gaya', 'Muzaffarpur', 'Bhagalpur'],
            'hubs'   => 'IIT Patna, NIT Patna, BIT Mesra Patna'
        ],
        'Odisha' => [
            'cities' => ['Bhubaneswar', 'Cuttack', 'Rourkela', 'Sambalpur'],
            'hubs'   => 'IIT Bhubaneswar, NIT Rourkela, KIIT, SOA University'
        ],
        'Assam' => [
            'cities' => ['Guwahati', 'Dibrugarh', 'Silchar', 'Jorhat'],
            'hubs'   => 'IIT Guwahati, NIT Silchar, Gauhati University'
        ],
        'Goa' => [
            'cities' => ['Panaji', 'Margao', 'Vasco da Gama'],
            'hubs'   => 'IIT Goa, BITS Pilani Goa, Goa University'
        ],
        'Uttarakhand' => [
            'cities' => ['Dehradun', 'Roorkee', 'Haridwar', 'Pantnagar'],
            'hubs'   => 'IIT Roorkee, UPES Dehradun, GB Pant University'
        ],
        'Himachal Pradesh' => [
            'cities' => ['Shimla', 'Dharamshala', 'Mandi', 'Solan'],
            'hubs'   => 'IIT Mandi, NIT Hamirpur, Jaypee Univ Solan'
        ],
        'Jharkhand' => [
            'cities' => ['Ranchi', 'Jamshedpur', 'Dhanbad'],
            'hubs'   => 'IIT ISM Dhanbad, BIT Mesra, NIT Jamshedpur'
        ],
        'Chhattisgarh' => [
            'cities' => ['Raipur', 'Bilaspur', 'Bhilai'],
            'hubs'   => 'IIT Bhilai, NIT Raipur, IIIT Naya Raipur'
        ],
        'Jammu & Kashmir' => [
            'cities' => ['Srinagar', 'Jammu'],
            'hubs'   => 'IIT Jammu, NIT Srinagar, University of Jammu'
        ]
    ];
}

/**
 * Curated regional knowledge base for Indian activity categories and state hubs
 */
function getIndianCategoryKnowledgeBase(string $categoryCode, string $state = 'all'): array
{
    $code = strtoupper(trim($categoryCode));
    $st = trim($state);
    $stLabel = ($st === 'all' || strtolower($st) === 'all india') ? 'All India' : $st;

    $base = [
        'HACKATHON' => [
            'summary' => "Hackathons in {$stLabel} range from rapid 24-hour campus prototyping to national flagship initiatives like the Smart India Hackathon. Participants work in cross-functional squads solving real-world enterprise, civic, and deep-tech challenges.",
            'top_hubs' => [
                'Smart India Hackathon (Ministry of Education & AICTE)',
                'Unstop National Tech Competitions & Campus Hackathons',
                'IIT Bombay Techfest & E-Cell Sprints',
                'Google Developer Groups (GDG India)'
            ],
            'prep_tips' => [
                'Form a balanced team with complementary skills: 1 Systems/Backend, 1 Frontend/Design, and 1 Data/API integrator.',
                'Ensure all team members hold active college ID cards; national hackathons like SIH require official institute nomination letters.'
            ],
            'official_portals' => [
                ['name' => 'Smart India Hackathon', 'url' => 'https://sih.gov.in'],
                ['name' => 'Unstop National Portal', 'url' => 'https://unstop.com'],
                ['name' => 'Techfest IIT Bombay', 'url' => 'https://techfest.org']
            ]
        ],
        'COMPETITION' => [
            'summary' => "Competitive challenges in {$stLabel} evaluate algorithmic reasoning, data structures, and case problem-solving against national peer benchmarks.",
            'top_hubs' => [
                'Tata Crucible Campus Conclave',
                'IIT Madras Shaastra Algorithmic Track',
                'ACM ICPC India Regional Contests',
                'Unstop National Coding League'
            ],
            'prep_tips' => [
                'Practice high-efficiency asymptotic optimizations (O(N) and O(N log N)) on standard arrays, graphs, and dynamic programming.',
                'Familiarize yourself with automated test runners and edge-case boundary verifications.'
            ],
            'official_portals' => [
                ['name' => 'Tata Crucible Campus', 'url' => 'https://tatacrucible.com'],
                ['name' => 'Shaastra IIT Madras', 'url' => 'https://shaastra.org'],
                ['name' => 'ICPC Global India', 'url' => 'https://icpc.global']
            ]
        ],
        'WORKSHOP' => [
            'summary' => "Hands-on workshops across {$stLabel} provide practical skill-building in cloud architecture, AI engineering, full-stack pipelines, and hardware prototyping.",
            'top_hubs' => [
                'Google Developer Groups (GDG India)',
                'AWS Community & User Groups India',
                'IEEE Computer Society India Council',
                'NPTEL & SWAYAM National Masterclasses'
            ],
            'prep_tips' => [
                'Clone required repository dependencies and test your terminal toolchain 24 hours prior to workshop commencement.',
                'Actively complete the end-to-end capstone exercise to secure verifiable partner certificates.'
            ],
            'official_portals' => [
                ['name' => 'GDG India Community', 'url' => 'https://developers.google.com/community/gdg'],
                ['name' => 'AWS Community India', 'url' => 'https://aws.amazon.com/developer/community'],
                ['name' => 'NPTEL SWAYAM Portal', 'url' => 'https://nptel.ac.in']
            ]
        ],
        'INTERNSHIP' => [
            'summary' => "Internship pathways in {$stLabel} connect undergraduate and postgraduate students with mentored work placements, government technology cells, and deep-tech incubators.",
            'top_hubs' => [
                'AICTE National Internship Portal (Ministry of Education)',
                'T-Hub DeepTech & Hardware Innovation Hub',
                'Maker Village Kerala Startup Mission',
                'National Informatics Centre (NIC) Student Internships'
            ],
            'prep_tips' => [
                'Verify your institution’s credit transfer and No-Objection Certificate (NOC) submission deadlines.',
                'Maintain a GitHub or portfolio profile demonstrating 2-3 production-grade projects.'
            ],
            'official_portals' => [
                ['name' => 'AICTE Internship Portal', 'url' => 'https://internship.aicte-india.org'],
                ['name' => 'T-Hub Hyderabad', 'url' => 'https://t-hub.co'],
                ['name' => 'Kerala Startup Mission', 'url' => 'https://startupmission.kerala.gov.in']
            ]
        ],
        'ENTREPRENEURSHIP' => [
            'summary' => "Entrepreneurship events across {$stLabel} nurture student-led innovation through pitch competitions, seed grants, and university incubation centers.",
            'top_hubs' => [
                'IIT Bombay E-Cell (Eureka!)',
                'Startup India (Govt of India)',
                'Gujarat Student Startup & Innovation Policy (SSIP)',
                'Kerala Startup Mission (Idea Fest)'
            ],
            'prep_tips' => [
                'Validate your problem statement with at least 25 real end-user interviews before pitching to jury panels.',
                'Focus on defensible IP, market sizing in India, and realistic initial customer acquisition strategies.'
            ],
            'official_portals' => [
                ['name' => 'Startup India Portal', 'url' => 'https://www.startupindia.gov.in'],
                ['name' => 'Eureka! IIT Bombay', 'url' => 'https://ecell.in'],
                ['name' => 'SSIP Gujarat Hub', 'url' => 'https://gtu.ac.in']
            ]
        ],
        'CONFERENCE' => [
            'summary' => "Academic conferences and conclaves in {$stLabel} offer student researchers opportunities to present peer-reviewed papers, engage with faculty scholars, and discover cutting-edge research.",
            'top_hubs' => [
                'IEEE India Council (INDICON)',
                'IISc Science Innovation Symposia',
                'IIT Delhi Tech Conclaves',
                'CSIR National Research Forums'
            ],
            'prep_tips' => [
                'Follow strict IEEE/ACM 2-column formatting and ensure zero plagiarism across literature reviews.',
                'Prepare a concise 10-minute slide deck with clear experimental results and benchmark comparative tables.'
            ],
            'official_portals' => [
                ['name' => 'IEEE India Council', 'url' => 'https://ieee.org'],
                ['name' => 'INDICON Flagship', 'url' => 'https://ieee.org/conferences']
            ]
        ],
        'QUIZ' => [
            'summary' => "Inter-collegiate quizzes across {$stLabel} test domain trivia, business history, technological milestones, and lateral cognitive problem-solving.",
            'top_hubs' => [
                'Tata Crucible Campus Quiz',
                'IIT Kharagpur Kshitij National Quiz',
                'Delhi University Quiz Circuit'
            ],
            'prep_tips' => [
                'Review Indian business acquisitions, patent filings, and breakthrough technology announcements from the past 12 months.',
                'Practice fast buzz-in speed on visual clues and audio rounds.'
            ],
            'official_portals' => [
                ['name' => 'Tata Crucible', 'url' => 'https://tatacrucible.com'],
                ['name' => 'Kshitij IIT Kharagpur', 'url' => 'https://ktj.in']
            ]
        ],
        'MENTORSHIP' => [
            'summary' => "Structured mentorship initiatives in {$stLabel} match students with experienced industry professionals for career guidance, technical code reviews, and mock interview practice.",
            'top_hubs' => [
                'GDSC India Mentorship Network',
                'IEEE Student Branch Mentorship',
                'National Innovation Foundation Mentors'
            ],
            'prep_tips' => [
                'Define clear, measurable 30-day milestones before your first mentor checkpoint.',
                'Bring specific code repositories, architectural diagrams, or system bottlenecks to mentor calls.'
            ],
            'official_portals' => [
                ['name' => 'Google Developer Groups', 'url' => 'https://developers.google.com/community/gdg'],
                ['name' => 'IEEE India', 'url' => 'https://ieee.org']
            ]
        ],
        'FESTIVAL' => [
            'summary' => "Inter-collegiate festivals across {$stLabel} celebrate technology, arts, design, and management, drawing thousands of participants nationwide.",
            'top_hubs' => [
                'IIT Bombay Techfest (Mumbai)',
                'IIT Madras Shaastra (Chennai)',
                'IIT Kharagpur Kshitij (West Bengal)',
                'BITS Pilani Oasis / APOGEE (Rajasthan)'
            ],
            'prep_tips' => [
                'Book on-campus accommodation passes early to avoid logistics bottlenecks during peak festival days.',
                'Register your team rosters early to prevent schedule overlaps across simultaneous events.'
            ],
            'official_portals' => [
                ['name' => 'Techfest IIT Bombay', 'url' => 'https://techfest.org'],
                ['name' => 'Shaastra IIT Madras', 'url' => 'https://shaastra.org'],
                ['name' => 'Kshitij IIT KGP', 'url' => 'https://ktj.in']
            ]
        ],
        'SPORTS' => [
            'summary' => "Collegiate athletics and sports tournaments across {$stLabel} provide competitive arenas for student athletes to test physical endurance, teamwork, and tactical execution.",
            'top_hubs' => [
                'Association of Indian Universities (AIU Inter-University Games)',
                'Khelo India University Games (Ministry of Youth Affairs)',
                'Balewadi Sports Complex (Pune)',
                'Kanteerava Sports Complex (Bengaluru)'
            ],
            'prep_tips' => [
                'Ensure valid medical clearance forms and varsity athletic director authorizations are stamped prior to arrival.',
                'Follow age-eligibility standards set by the AIU Inter-University Sports Board.'
            ],
            'official_portals' => [
                ['name' => 'AIU Sports Board', 'url' => 'https://aiu.ac.in'],
                ['name' => 'Khelo India University Games', 'url' => 'https://kheloindia.gov.in']
            ]
        ]
    ];

    return $base[$code] ?? [
        'summary' => "Extracurricular opportunities in {$stLabel} offer practical, hands-on learning outside the lecture hall. Students build teamwork, leadership, and portfolio evidence across university and national events.",
        'top_hubs' => [
            'AICTE Student Development Cell',
            'Unstop Campus League',
            'National Innovation Foundation'
        ],
        'prep_tips' => [
            'Regularly verify event registration deadlines and ensure student ID credentials are valid.',
            'Collaborate with multidisciplinary peer teams to solve practical real-world challenges.'
        ],
        'official_portals' => [
            ['name' => 'AICTE India', 'url' => 'https://aicte-india.org'],
            ['name' => 'Unstop Official', 'url' => 'https://unstop.com']
        ]
    ];
}

/**
 * Retrieves AI-assisted opportunity discovery intelligence for an Indian location and category.
 * Uses Gemini API when configured; cleanly falls back to verified regional directory when offline.
 */
function getCategoryAiDiscoveryInsights(PDO $db, string $categoryCode, string $state = 'all', string $city = 'all', string $targetCareer = ''): array
{
    require_once __DIR__ . '/gemini_service.php';
    $gemini = new GeminiService();

    $catDetails = getActivityCategoryDetails($db, $categoryCode);
    $categoryName = $catDetails['name'] ?? $categoryCode;
    $locationDisplay = ($state === 'all' || strtolower($state) === 'all india')
        ? 'All India (National & Regional Hubs)'
        : ($city !== 'all' ? "{$city}, {$state}" : "{$state}, India");

    $isLiveAi = false;
    $summary = "";
    $topHubs = [];
    $prepTips = [];
    $officialPortals = [];

    // Attempt Gemini live analysis if key configured
    if ($gemini->isConfigured()) {
        try {
            $sysPrompt = "You are CareerAct's India Extracurricular Opportunity Intelligence Engine.
Analyze current extracurricular opportunities for Indian college students.
Category: {$categoryName}
Target Location: {$locationDisplay}
Target Career: {$targetCareer}

CRITICAL RULES:
1. Summarize verified organizers, major platforms, and active university hubs in India.
2. DO NOT invent fictitious events, fake dates, or fake URLs.
3. Return strictly a JSON object with:
   summary: string (2-3 sentences overview of this activity category in the region)
   top_hubs: array of 3-4 strings (prominent organizers, institutes, or hubs in India)
   prep_tips: array of 2-3 strings (concrete preparation and eligibility advice for Indian students)
   official_portals: array of objects with 'name' and 'url' (real official platforms such as sih.gov.in, unstop.com, techfest.org, aicte-india.org, shaastra.org, tatacrucible.com)";

            $userPrompt = "Provide India discovery intelligence for {$categoryName} in {$locationDisplay}.";
            $res = $gemini->callGeminiApi($sysPrompt, $userPrompt, 3, 0);
            $parsed = $gemini->extractAndValidateJson($res);
            if (!empty($parsed) && is_array($parsed) && !empty($parsed['summary'])) {
                $summary = trim((string)$parsed['summary']);
                $topHubs = array_values(array_filter((array)($parsed['top_hubs'] ?? [])));
                $prepTips = array_values(array_filter((array)($parsed['prep_tips'] ?? [])));
                $officialPortals = (array)($parsed['official_portals'] ?? []);
                $isLiveAi = true;
            }
        } catch (Exception $e) {
            $isLiveAi = false;
        }
    }

    if (!$isLiveAi) {
        $knowledge = getIndianCategoryKnowledgeBase($categoryCode, $state);
        $summary = $knowledge['summary'];
        $topHubs = $knowledge['top_hubs'];
        $prepTips = $knowledge['prep_tips'];
        $officialPortals = $knowledge['official_portals'];
    }

    return [
        'is_live_ai'         => $isLiveAi,
        'category_name'      => $categoryName,
        'location_display'   => $locationDisplay,
        'summary'            => $summary,
        'top_hubs'           => $topHubs,
        'prep_tips'          => $prepTips,
        'official_portals'   => $officialPortals,
        'disclaimer'         => 'CareerAct indexes verified listings from official universities, government bodies, and recognized platforms. We do not claim exhaustive coverage of every local event in India.'
    ];
}


