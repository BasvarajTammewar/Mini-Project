<?php
/**
 * CareerAct — Event & Activity Discovery Service
 * Handles career-targeted extracurricular activity recommendations,
 * city-based real-world opportunity exploration, automatic deadline expiration,
 * and opportunity saving/enrollment.
 */

declare(strict_types=1);

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
            COALESCE(ct.name, CASE WHEN o.delivery_mode = 'online' THEN 'Online / Virtual' ELSE 'Regional' END) AS city_name
        FROM opportunities o
        JOIN activities a ON o.activity_id = a.id
        JOIN activity_categories ac ON a.category_id = ac.id
        LEFT JOIN providers p ON o.provider_id = p.id
        LEFT JOIN cities ct ON o.city_id = ct.id
        WHERE o.status = 'published'
          AND (o.registration_deadline IS NULL OR o.registration_deadline >= CURDATE())
    ";

    $params = [];

    // Filter by specific activity
    if (!empty($filters['activity_id'])) {
        $sql .= " AND o.activity_id = :activity_id";
        $params[':activity_id'] = (int)$filters['activity_id'];
    }

    // Filter by category
    if (!empty($filters['category_id'])) {
        $sql .= " AND a.category_id = :category_id";
        $params[':category_id'] = (int)$filters['category_id'];
    }

    // Filter by city
    if (!empty($filters['city']) && strtolower($filters['city']) !== 'all') {
        $c = strtolower(trim($filters['city']));
        if ($c === 'online') {
            $sql .= " AND o.delivery_mode = 'online'";
        } else {
            // When filtering by a physical city, include both that city's events AND universal online events
            $sql .= " AND (ct.name LIKE :city_filter OR o.delivery_mode = 'online')";
            $params[':city_filter'] = '%' . $filters['city'] . '%';
        }
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
        $searchTerm = '%' . trim($filters['search']) . '%';
        $sql .= " AND (
            o.title LIKE :s1 
            OR o.description LIKE :s2 
            OR o.venue LIKE :s3 
            OR a.title LIKE :s4 
            OR p.name LIKE :s5
        )";
        $params[':s1'] = $searchTerm;
        $params[':s2'] = $searchTerm;
        $params[':s3'] = $searchTerm;
        $params[':s4'] = $searchTerm;
        $params[':s5'] = $searchTerm;
    }

    $sql .= " ORDER BY o.registration_deadline ASC, o.start_date ASC, o.id DESC";

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
        return ['success' => true, 'action' => 'created', 'status' => $status];
    }
}

