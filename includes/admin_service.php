<?php
/**
 * CareerAct — Admin Service & Platform Management
 * Handles dashboard platform statistics, activity category management,
 * opportunity lifecycle & verification queue, audit logging,
 * and Gemini-assisted advisory review.
 * 
 * Database: carrearact2
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/gemini_service.php';
require_once __DIR__ . '/event_discovery_service.php';

/**
 * Returns comprehensive aggregate platform statistics for the Admin Dashboard.
 * STRICT PRIVACY: Aggregate counts only. No student PII or assessment logs.
 */
function getAdminPlatformStats(PDO $db): array
{
    // 1. Run automatic expiration for any past published opportunities first
    autoExpirePastOpportunities($db);

    // 2. Student metrics
    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'student'");
    $totalStudents = (int)$stmt->fetchColumn();

    $stmt = $db->query("SELECT COUNT(*) FROM student_profiles WHERE profile_completed = 1");
    $onboardedStudents = (int)$stmt->fetchColumn();

    // Active students: students who have at least 1 enrollment, activity, or assessment
    $stmt = $db->query("
        SELECT COUNT(DISTINCT u.id) 
        FROM users u
        LEFT JOIN enrollments e ON u.id = e.user_id
        LEFT JOIN student_existing_activities sea ON u.id = sea.user_id
        LEFT JOIN ai_skill_assessments sa ON u.id = sa.user_id
        WHERE u.role = 'student' 
          AND (e.id IS NOT NULL OR sea.id IS NOT NULL OR sa.id IS NOT NULL)
    ");
    $activeStudents = (int)$stmt->fetchColumn();

    // 3. Opportunity metrics
    $stmt = $db->query("SELECT COUNT(*) FROM opportunities");
    $totalOpportunities = (int)$stmt->fetchColumn();

    $stmt = $db->query("
        SELECT COUNT(*) 
        FROM opportunities 
        WHERE verification_status IN ('unverified', 'needs_correction')
    ");
    $pendingVerification = (int)$stmt->fetchColumn();

    $stmt = $db->query("
        SELECT COUNT(*) 
        FROM opportunities 
        WHERE verification_status = 'verified'
    ");
    $verifiedOpportunities = (int)$stmt->fetchColumn();

    $stmt = $db->query("
        SELECT COUNT(*) 
        FROM opportunities 
        WHERE status = 'published' 
          AND (registration_deadline IS NULL OR registration_deadline >= CURDATE())
    ");
    $activeOpportunities = (int)$stmt->fetchColumn();

    $stmt = $db->query("
        SELECT COUNT(*) 
        FROM opportunities 
        WHERE status = 'expired' 
           OR (registration_deadline IS NOT NULL AND registration_deadline < CURDATE() AND status != 'archived')
    ");
    $expiredOpportunities = (int)$stmt->fetchColumn();

    $stmt = $db->query("SELECT COUNT(*) FROM opportunities WHERE status = 'archived'");
    $archivedOpportunities = (int)$stmt->fetchColumn();

    // 4. Activity Categories
    $stmt = $db->query("SELECT COUNT(*) FROM activity_categories");
    $totalCategories = (int)$stmt->fetchColumn();

    $stmt = $db->query("SELECT COUNT(*) FROM activity_categories WHERE is_active = 1");
    $activeCategories = (int)$stmt->fetchColumn();

    $stmt = $db->query("SELECT COUNT(*) FROM activity_categories WHERE is_active = 0");
    $inactiveCategories = (int)$stmt->fetchColumn();

    // 5. Category Distribution Breakdown
    $stmt = $db->query("
        SELECT 
            ac.id,
            ac.code,
            ac.name,
            ac.is_active,
            COUNT(DISTINCT a.id) AS total_activities,
            COUNT(DISTINCT o.id) AS total_opportunities,
            COUNT(DISTINCT CASE WHEN o.verification_status IN ('unverified', 'needs_correction') THEN o.id END) AS pending_opportunities,
            COUNT(DISTINCT CASE WHEN o.status = 'published' AND o.verification_status IN ('verified', 'sample') THEN o.id END) AS published_opportunities
        FROM activity_categories ac
        LEFT JOIN activities a ON ac.id = a.category_id
        LEFT JOIN opportunities o ON a.id = o.activity_id
        GROUP BY ac.id, ac.code, ac.name, ac.is_active
        ORDER BY ac.id ASC
    ");
    $categoryDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Student Enrollment / Participation Aggregate Metrics
    $stmt = $db->query("SELECT COUNT(*) FROM enrollments");
    $totalEnrollments = (int)$stmt->fetchColumn();

    $stmt = $db->query("SELECT status, COUNT(*) AS cnt FROM enrollments GROUP BY status");
    $enrollmentStatusCounts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $enrollmentStatusCounts[$row['status']] = (int)$row['cnt'];
    }

    // 7. Recent Audit Logs Count (Past 24 hours)
    $stmt = $db->query("SELECT COUNT(*) FROM audit_log WHERE created_at >= NOW() - INTERVAL 1 DAY");
    $auditLogsPast24h = (int)$stmt->fetchColumn();

    return [
        'students' => [
            'total'     => $totalStudents,
            'onboarded' => $onboardedStudents,
            'active'    => $activeStudents,
        ],
        'opportunities' => [
            'total'                => $totalOpportunities,
            'pending_verification' => $pendingVerification,
            'verified'             => $verifiedOpportunities,
            'active_upcoming'      => $activeOpportunities,
            'expired'              => $expiredOpportunities,
            'archived'             => $archivedOpportunities,
        ],
        'categories' => [
            'total'        => $totalCategories,
            'active'       => $activeCategories,
            'inactive'     => $inactiveCategories,
            'distribution' => $categoryDistribution,
        ],
        'participation' => [
            'total'       => $totalEnrollments,
            'saved'       => $enrollmentStatusCounts['saved'] ?? 0,
            'registered'  => $enrollmentStatusCounts['registered'] ?? 0,
            'in_progress' => $enrollmentStatusCounts['in_progress'] ?? 0,
            'completed'   => $enrollmentStatusCounts['completed'] ?? 0,
            'withdrawn'   => $enrollmentStatusCounts['withdrawn'] ?? 0,
        ],
        'audit' => [
            'past_24h_count' => $auditLogsPast24h,
        ],
    ];
}

/**
 * Logs an administrative action to the audit_log table.
 */
function logAdminAction(
    PDO $db,
    ?int $adminId,
    string $action,
    ?string $entity,
    ?int $entityId,
    string $detail,
    ?string $oldVal = null,
    ?string $newVal = null
): bool {
    try {
        $ip = getClientIp();
        $stmt = $db->prepare("
            INSERT INTO audit_log (
                user_id, action, entity, entity_id, detail, old_value, new_value, ip, created_at
            ) VALUES (
                :user_id, :action, :entity, :entity_id, :detail, :old_val, :new_val, :ip, NOW()
            )
        ");
        return $stmt->execute([
            ':user_id'   => $adminId,
            ':action'    => substr($action, 0, 60),
            ':entity'    => $entity ? substr($entity, 0, 60) : null,
            ':entity_id' => $entityId,
            ':detail'    => substr($detail, 0, 500),
            ':old_val'   => $oldVal,
            ':new_val'   => $newVal,
            ':ip'        => substr($ip, 0, 45),
        ]);
    } catch (PDOException $e) {
        error_log("Failed to log admin action: " . $e->getMessage());
        return false;
    }
}

/**
 * Retrieves audit log records with optional filtering.
 */
function getAdminAuditLogs(
    PDO $db,
    int $limit = 50,
    ?string $actionFilter = null,
    ?string $entityFilter = null
): array {
    $sql = "
        SELECT 
            al.id,
            al.user_id,
            al.action,
            al.entity,
            al.entity_id,
            al.detail,
            al.old_value,
            al.new_value,
            al.ip,
            al.created_at,
            u.name AS admin_name,
            u.email AS admin_email
        FROM audit_log al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE 1 = 1
    ";

    $params = [];
    if (!empty($actionFilter)) {
        $sql .= " AND al.action = :action";
        $params[':action'] = $actionFilter;
    }
    if (!empty($entityFilter)) {
        $sql .= " AND al.entity = :entity";
        $params[':entity'] = $entityFilter;
    }

    $sql .= " ORDER BY al.id DESC LIMIT :lim";

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', max(1, min(200, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Retrieves all categories with stats for administrative management.
 */
function getAllCategoriesAdmin(
    PDO $db,
    ?string $search = null,
    ?string $statusFilter = null
): array {
    $sql = "
        SELECT 
            ac.id,
            ac.code,
            ac.name,
            ac.description,
            ac.is_active,
            COUNT(DISTINCT a.id) AS activities_count,
            COUNT(DISTINCT o.id) AS opportunities_count,
            COUNT(DISTINCT CASE WHEN o.verification_status = 'verified' THEN o.id END) AS verified_opps_count,
            COUNT(DISTINCT CASE WHEN o.verification_status IN ('unverified', 'needs_correction') THEN o.id END) AS pending_opps_count
        FROM activity_categories ac
        LEFT JOIN activities a ON ac.id = a.category_id
        LEFT JOIN opportunities o ON a.id = o.activity_id
        WHERE 1 = 1
    ";

    $params = [];
    if (!empty($search)) {
        $sql .= " AND (ac.name LIKE :search_name OR ac.code LIKE :search_code OR ac.description LIKE :search_desc)";
        $params[':search_name'] = '%' . trim($search) . '%';
        $params[':search_code'] = '%' . trim($search) . '%';
        $params[':search_desc'] = '%' . trim($search) . '%';
    }

    if ($statusFilter === 'active') {
        $sql .= " AND ac.is_active = 1";
    } elseif ($statusFilter === 'inactive') {
        $sql .= " AND ac.is_active = 0";
    }

    $sql .= " GROUP BY ac.id, ac.code, ac.name, ac.description, ac.is_active";
    $sql .= " ORDER BY ac.id ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Creates or updates an activity category with strict duplicate prevention.
 */
function saveCategoryAdmin(PDO $db, int $adminId, array $data): array
{
    $id = !empty($data['id']) ? (int)$data['id'] : null;
    $name = trim((string)($data['name'] ?? ''));
    $code = strtoupper(trim((string)($data['code'] ?? '')));
    $description = trim((string)($data['description'] ?? ''));
    $isActive = isset($data['is_active']) ? ((int)$data['is_active'] ? 1 : 0) : 1;

    if (mb_strlen($name) < 2) {
        return ['success' => false, 'error' => 'Category name must be at least 2 characters long.'];
    }

    // Auto-generate code from name if empty
    if (empty($code)) {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $name));
        $code = trim(preg_replace('/_+/', '_', $code), '_');
        $code = substr($code, 0, 40);
    }

    // 1. Duplicate check (Case-insensitive trimmed name)
    $dupSql = "SELECT id, name FROM activity_categories WHERE LOWER(TRIM(name)) = LOWER(:name)";
    $dupParams = [':name' => $name];
    if ($id !== null) {
        $dupSql .= " AND id != :id";
        $dupParams[':id'] = $id;
    }
    $dupStmt = $db->prepare($dupSql);
    $dupStmt->execute($dupParams);
    if ($dupStmt->fetch()) {
        return ['success' => false, 'error' => "A category with the name '{$name}' already exists. Duplicate categories are not permitted."];
    }

    // 2. Duplicate code check
    $codeSql = "SELECT id, code FROM activity_categories WHERE code = :code";
    $codeParams = [':code' => $code];
    if ($id !== null) {
        $codeSql .= " AND id != :id";
        $codeParams[':id'] = $id;
    }
    $codeStmt = $db->prepare($codeSql);
    $codeStmt->execute($codeParams);
    if ($codeStmt->fetch()) {
        return ['success' => false, 'error' => "A category with code '{$code}' already exists. Please provide a distinct code."];
    }

    if ($id !== null) {
        // Update existing category
        $stmtOld = $db->prepare("SELECT * FROM activity_categories WHERE id = :id");
        $stmtOld->execute([':id' => $id]);
        $oldCategory = $stmtOld->fetch(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("
            UPDATE activity_categories
            SET name = :name, code = :code, description = :description, is_active = :is_active
            WHERE id = :id
        ");
        $stmt->execute([
            ':name'        => $name,
            ':code'        => $code,
            ':description' => $description,
            ':is_active'   => $isActive,
            ':id'          => $id,
        ]);

        logAdminAction(
            $db,
            $adminId,
            'category_updated',
            'activity_category',
            $id,
            "Updated category '{$name}' ({$code})",
            json_encode($oldCategory),
            json_encode(['name' => $name, 'code' => $code, 'description' => $description, 'is_active' => $isActive])
        );

        return ['success' => true, 'id' => $id, 'message' => "Category '{$name}' updated successfully."];
    } else {
        // Insert new category
        $stmt = $db->prepare("
            INSERT INTO activity_categories (code, name, description, is_active)
            VALUES (:code, :name, :description, :is_active)
        ");
        $stmt->execute([
            ':code'        => $code,
            ':name'        => $name,
            ':description' => $description,
            ':is_active'   => $isActive,
        ]);
        $newId = (int)$db->lastInsertId();

        logAdminAction(
            $db,
            $adminId,
            'category_created',
            'activity_category',
            $newId,
            "Created new category '{$name}' ({$code})",
            null,
            json_encode(['name' => $name, 'code' => $code, 'description' => $description, 'is_active' => $isActive])
        );

        return ['success' => true, 'id' => $newId, 'message' => "Category '{$name}' created successfully."];
    }
}

/**
 * Toggles an activity category between active and inactive.
 * SAFE DESIGN: Never deletes a category to preserve historical student data and foreign references.
 */
function toggleCategoryActive(PDO $db, int $adminId, int $categoryId): array
{
    $stmt = $db->prepare("SELECT id, name, code, is_active FROM activity_categories WHERE id = :id");
    $stmt->execute([':id' => $categoryId]);
    $cat = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cat) {
        return ['success' => false, 'error' => 'Category not found.'];
    }

    $newStatus = $cat['is_active'] ? 0 : 1;
    $upStmt = $db->prepare("UPDATE activity_categories SET is_active = :status WHERE id = :id");
    $upStmt->execute([':status' => $newStatus, ':id' => $categoryId]);

    $actionName = $newStatus ? 'category_activated' : 'category_deactivated';
    $statusText = $newStatus ? 'activated' : 'deactivated';

    logAdminAction(
        $db,
        $adminId,
        $actionName,
        'activity_category',
        $categoryId,
        "Category '{$cat['name']}' ({$cat['code']}) was {$statusText}",
        json_encode(['is_active' => $cat['is_active']]),
        json_encode(['is_active' => $newStatus])
    );

    return [
        'success'   => true,
        'is_active' => $newStatus,
        'message'   => "Category '{$cat['name']}' has been {$statusText}."
    ];
}

/**
 * Retrieves opportunities with rich administrative filters.
 */
function getAdminOpportunities(PDO $db, array $filters = []): array
{
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
            o.cost_amount,
            o.cost_currency,
            o.registration_url,
            o.source_url,
            o.dedupe_hash,
            o.verification_status,
            o.verified_by,
            o.verified_at,
            o.status,
            o.created_at,
            o.updated_at,
            a.title AS activity_title,
            ac.id AS category_id,
            ac.name AS category_name,
            ac.code AS category_code,
            COALESCE(p.name, 'Unspecified Organizer') AS provider_name,
            u.name AS verifier_name
        FROM opportunities o
        JOIN activities a ON o.activity_id = a.id
        JOIN activity_categories ac ON a.category_id = ac.id
        LEFT JOIN providers p ON o.provider_id = p.id
        LEFT JOIN users u ON o.verified_by = u.id
        WHERE 1 = 1
    ";

    $params = [];

    // Search keyword
    if (!empty($filters['search'])) {
        $kw = '%' . trim((string)$filters['search']) . '%';
        $sql .= " AND (o.title LIKE :kw_title OR o.description LIKE :kw_desc OR o.venue LIKE :kw_venue OR p.name LIKE :kw_prov)";
        $params[':kw_title'] = $kw;
        $params[':kw_desc']  = $kw;
        $params[':kw_venue'] = $kw;
        $params[':kw_prov']  = $kw;
    }

    // Category filter
    if (!empty($filters['category_id'])) {
        $sql .= " AND ac.id = :cat_id";
        $params[':cat_id'] = (int)$filters['category_id'];
    }

    // Verification status filter
    if (!empty($filters['verification_status'])) {
        if ($filters['verification_status'] === 'pending') {
            $sql .= " AND o.verification_status IN ('unverified', 'needs_correction')";
        } else {
            $sql .= " AND o.verification_status = :ver_status";
            $params[':ver_status'] = $filters['verification_status'];
        }
    }

    // Lifecycle status filter
    if (!empty($filters['status'])) {
        $sql .= " AND o.status = :status";
        $params[':status'] = $filters['status'];
    }

    // Delivery mode
    if (!empty($filters['delivery_mode'])) {
        $sql .= " AND o.delivery_mode = :delivery_mode";
        $params[':delivery_mode'] = $filters['delivery_mode'];
    }

    // Scope level
    if (!empty($filters['scope_level'])) {
        $sql .= " AND o.scope_level = :scope_level";
        $params[':scope_level'] = $filters['scope_level'];
    }

    $sql .= " ORDER BY o.id DESC";

    if (!empty($filters['limit'])) {
        $sql .= " LIMIT :lim";
    }

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    if (!empty($filters['limit'])) {
        $stmt->bindValue(':lim', (int)$filters['limit'], PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Retrieves a single opportunity by ID with joined activity and provider info.
 */
function getAdminOpportunityById(PDO $db, int $oppId): ?array
{
    $sql = "
        SELECT 
            o.*,
            a.title AS activity_title,
            a.category_id,
            ac.name AS category_name,
            ac.code AS category_code,
            COALESCE(p.name, 'Unspecified Organizer') AS provider_name,
            p.website AS provider_website,
            u.name AS verifier_name
        FROM opportunities o
        JOIN activities a ON o.activity_id = a.id
        JOIN activity_categories ac ON a.category_id = ac.id
        LEFT JOIN providers p ON o.provider_id = p.id
        LEFT JOIN users u ON o.verified_by = u.id
        WHERE o.id = :id
        LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':id' => $oppId]);
    $opp = $stmt->fetch(PDO::FETCH_ASSOC);
    return $opp ?: null;
}

/**
 * Saves (creates or updates) an opportunity via the Admin Panel.
 * Validates essential fields and computes deduplication hash.
 */
function saveOpportunityAdmin(PDO $db, int $adminId, array $data): array
{
    $id = !empty($data['id']) ? (int)$data['id'] : null;
    $title = trim((string)($data['title'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $activityId = !empty($data['activity_id']) ? (int)$data['activity_id'] : 0;
    $providerId = !empty($data['provider_id']) ? (int)$data['provider_id'] : null;
    $deliveryMode = in_array($data['delivery_mode'] ?? '', ['online', 'offline', 'hybrid'], true) ? $data['delivery_mode'] : 'online';
    $stateName = trim((string)($data['state_name'] ?? ''));
    $scopeLevel = in_array($data['scope_level'] ?? '', ['local', 'state', 'national', 'international_eligible'], true) ? $data['scope_level'] : 'national';
    $venue = trim((string)($data['venue'] ?? ''));
    $startDate = !empty($data['start_date']) ? $data['start_date'] : null;
    $endDate = !empty($data['end_date']) ? $data['end_date'] : null;
    $deadline = !empty($data['registration_deadline']) ? $data['registration_deadline'] : null;
    $eligibility = trim((string)($data['eligibility'] ?? ''));
    $costAmount = isset($data['cost_amount']) && is_numeric($data['cost_amount']) ? (float)$data['cost_amount'] : 0.00;
    $costCurrency = !empty($data['cost_currency']) ? strtoupper(substr(trim($data['cost_currency']), 0, 3)) : 'INR';
    $regUrl = trim((string)($data['registration_url'] ?? ''));
    $sourceUrl = trim((string)($data['source_url'] ?? ''));
    $status = in_array($data['status'] ?? '', ['draft', 'published', 'expired', 'archived'], true) ? $data['status'] : 'draft';
    $verStatus = in_array($data['verification_status'] ?? '', ['sample', 'unverified', 'verified', 'needs_correction', 'rejected'], true) ? $data['verification_status'] : 'unverified';

    if (mb_strlen($title) < 5) {
        return ['success' => false, 'error' => 'Opportunity title must be at least 5 characters long.'];
    }
    if ($activityId <= 0) {
        // Fallback: Pick or create a general activity in the designated category
        $stmtAct = $db->query("SELECT id FROM activities WHERE is_active = 1 LIMIT 1");
        $activityId = (int)$stmtAct->fetchColumn();
    }

    // Compute unique deduplication hash
    $dedupeKey = strtolower(trim($title)) . '|' . strtolower(trim($sourceUrl ?: $regUrl));
    $dedupeHash = sha1($dedupeKey);

    // Duplicate check
    $dupSql = "SELECT id, title FROM opportunities WHERE dedupe_hash = :hash";
    $dupParams = [':hash' => $dedupeHash];
    if ($id !== null) {
        $dupSql .= " AND id != :id";
        $dupParams[':id'] = $id;
    }
    $dupStmt = $db->prepare($dupSql);
    $dupStmt->execute($dupParams);
    $duplicate = $dupStmt->fetch(PDO::FETCH_ASSOC);
    if ($duplicate) {
        return [
            'success' => false,
            'error'   => "A duplicate opportunity ('{$duplicate['title']}', ID: {$duplicate['id']}) already exists with the same title and official URL."
        ];
    }

    if ($id !== null) {
        $stmtOld = $db->prepare("SELECT * FROM opportunities WHERE id = :id");
        $stmtOld->execute([':id' => $id]);
        $oldOpp = $stmtOld->fetch(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("
            UPDATE opportunities
            SET activity_id = :act_id,
                provider_id = :prov_id,
                title = :title,
                description = :description,
                delivery_mode = :delivery_mode,
                state_name = :state_name,
                scope_level = :scope_level,
                venue = :venue,
                start_date = :start_date,
                end_date = :end_date,
                registration_deadline = :deadline,
                eligibility = :eligibility,
                cost_amount = :cost_amount,
                cost_currency = :cost_currency,
                registration_url = :reg_url,
                source_url = :source_url,
                dedupe_hash = :dedupe_hash,
                verification_status = :ver_status,
                status = :status,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':act_id'        => $activityId,
            ':prov_id'       => $providerId,
            ':title'         => $title,
            ':description'   => $description,
            ':delivery_mode' => $deliveryMode,
            ':state_name'    => $stateName ?: null,
            ':scope_level'   => $scopeLevel,
            ':venue'         => $venue ?: null,
            ':start_date'    => $startDate,
            ':end_date'      => $endDate,
            ':deadline'      => $deadline,
            ':eligibility'   => $eligibility ?: null,
            ':cost_amount'   => $costAmount,
            ':cost_currency' => $costCurrency,
            ':reg_url'       => $regUrl ?: null,
            ':source_url'    => $sourceUrl ?: null,
            ':dedupe_hash'   => $dedupeHash,
            ':ver_status'    => $verStatus,
            ':status'        => $status,
            ':id'            => $id,
        ]);

        logAdminAction(
            $db,
            $adminId,
            'opportunity_updated',
            'opportunity',
            $id,
            "Updated opportunity '{$title}'",
            json_encode($oldOpp),
            json_encode(['title' => $title, 'status' => $status, 'verification_status' => $verStatus])
        );

        return ['success' => true, 'id' => $id, 'message' => "Opportunity '{$title}' updated successfully."];
    } else {
        $stmt = $db->prepare("
            INSERT INTO opportunities (
                activity_id, provider_id, title, description, delivery_mode, country_id,
                state_name, scope_level, is_international_eligible, venue,
                start_date, end_date, registration_deadline, eligibility,
                cost_amount, cost_currency, registration_url, source_url,
                source_id, dedupe_hash, verification_status, status,
                is_sample, created_at, updated_at
            ) VALUES (
                :act_id, :prov_id, :title, :description, :delivery_mode, 37,
                :state_name, :scope_level, 0, :venue,
                :start_date, :end_date, :deadline, :eligibility,
                :cost_amount, :cost_currency, :reg_url, :source_url,
                1, :dedupe_hash, :ver_status, :status,
                0, NOW(), NOW()
            )
        ");
        $stmt->execute([
            ':act_id'        => $activityId,
            ':prov_id'       => $providerId,
            ':title'         => $title,
            ':description'   => $description,
            ':delivery_mode' => $deliveryMode,
            ':state_name'    => $stateName ?: null,
            ':scope_level'   => $scopeLevel,
            ':venue'         => $venue ?: null,
            ':start_date'    => $startDate,
            ':end_date'      => $endDate,
            ':deadline'      => $deadline,
            ':eligibility'   => $eligibility ?: null,
            ':cost_amount'   => $costAmount,
            ':cost_currency' => $costCurrency,
            ':reg_url'       => $regUrl ?: null,
            ':source_url'    => $sourceUrl ?: null,
            ':dedupe_hash'   => $dedupeHash,
            ':ver_status'    => $verStatus,
            ':status'        => $status,
        ]);
        $newId = (int)$db->lastInsertId();

        logAdminAction(
            $db,
            $adminId,
            'opportunity_created',
            'opportunity',
            $newId,
            "Created new opportunity '{$title}'",
            null,
            json_encode(['title' => $title, 'status' => $status, 'verification_status' => $verStatus])
        );

        return ['success' => true, 'id' => $newId, 'message' => "Opportunity '{$title}' created successfully."];
    }
}

/**
 * Updates the verification status of an opportunity in the verification queue.
 * Automatically publishes when verified if valid, or moves to draft on rejection.
 */
function setOpportunityVerification(
    PDO $db,
    int $adminId,
    int $oppId,
    string $newVerStatus,
    ?string $adminNotes = null
): array {
    if (!in_array($newVerStatus, ['verified', 'needs_correction', 'rejected', 'unverified'], true)) {
        return ['success' => false, 'error' => "Invalid verification status '{$newVerStatus}'."];
    }

    $stmt = $db->prepare("SELECT id, title, verification_status, status, registration_deadline FROM opportunities WHERE id = :id");
    $stmt->execute([':id' => $oppId]);
    $opp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$opp) {
        return ['success' => false, 'error' => 'Opportunity not found.'];
    }

    $oldVerStatus = $opp['verification_status'];
    $oldStatus = $opp['status'];
    $newStatus = $oldStatus;

    if ($newVerStatus === 'verified') {
        // If deadline is not expired, publish it
        $isExpired = !empty($opp['registration_deadline']) && strtotime($opp['registration_deadline']) < strtotime(date('Y-m-d'));
        $newStatus = $isExpired ? 'expired' : 'published';
    } elseif ($newVerStatus === 'rejected') {
        $newStatus = 'draft';
    }

    $updateSql = "
        UPDATE opportunities 
        SET verification_status = :ver_status,
            verified_by = :admin_id,
            verified_at = NOW(),
            status = :status,
            updated_at = NOW()
        WHERE id = :id
    ";
    $stmtUp = $db->prepare($updateSql);
    $stmtUp->execute([
        ':ver_status' => $newVerStatus,
        ':admin_id'   => $adminId,
        ':status'     => $newStatus,
        ':id'         => $oppId,
    ]);

    $detail = "Verification decision '{$newVerStatus}' recorded for '{$opp['title']}'";
    if ($adminNotes) {
        $detail .= ". Notes: " . substr($adminNotes, 0, 300);
    }

    logAdminAction(
        $db,
        $adminId,
        'opportunity_verification_updated',
        'opportunity',
        $oppId,
        $detail,
        json_encode(['verification_status' => $oldVerStatus, 'status' => $oldStatus]),
        json_encode(['verification_status' => $newVerStatus, 'status' => $newStatus, 'notes' => $adminNotes])
    );

    return [
        'success'             => true,
        'verification_status' => $newVerStatus,
        'status'              => $newStatus,
        'message'             => "Opportunity verification set to '{$newVerStatus}'."
    ];
}

/**
 * Archives an opportunity.
 * PRESERVES historical student enrollments intact without cascade deletion.
 */
function archiveOpportunityAdmin(PDO $db, int $adminId, int $oppId, string $reason = ''): array
{
    $stmt = $db->prepare("SELECT id, title, status FROM opportunities WHERE id = :id");
    $stmt->execute([':id' => $oppId]);
    $opp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$opp) {
        return ['success' => false, 'error' => 'Opportunity not found.'];
    }

    $oldStatus = $opp['status'];
    $stmtUp = $db->prepare("UPDATE opportunities SET status = 'archived', updated_at = NOW() WHERE id = :id");
    $stmtUp->execute([':id' => $oppId]);

    $detail = "Opportunity '{$opp['title']}' archived.";
    if ($reason) {
        $detail .= " Reason: " . substr($reason, 0, 300);
    }

    logAdminAction(
        $db,
        $adminId,
        'opportunity_archived',
        'opportunity',
        $oppId,
        $detail,
        json_encode(['status' => $oldStatus]),
        json_encode(['status' => 'archived', 'reason' => $reason])
    );

    // Notify enrolled students that opportunity is no longer active
    if (file_exists(__DIR__ . '/notification_service.php')) {
        require_once __DIR__ . '/notification_service.php';
        $stmtEnr = $db->prepare("SELECT user_id FROM enrollments WHERE opportunity_id = :id AND status IN ('saved', 'registered', 'in_progress')");
        $stmtEnr->execute([':id' => $oppId]);
        while ($uid = $stmtEnr->fetchColumn()) {
            createNotification(
                $db,
                (int)$uid,
                'opportunity_archived',
                "Opportunity Concluded: {$opp['title']}",
                "The opportunity '{$opp['title']}' has been archived or concluded. Your enrollment history remains preserved.",
                "enrollments.php",
                "opp_archived_{$oppId}_u{$uid}"
            );
        }
    }

    return ['success' => true, 'message' => "Opportunity '{$opp['title']}' archived successfully."];
}

/**
 * Gemini-Assisted Queue Review & Quality Inspection.
 * 
 * STRICT CONSTRAINTS:
 * 1. Purely ADVISORY for human administrators.
 * 2. Never auto-approves opportunities.
 * 3. Base analysis STRICTLY on provided text; NEVER invent missing organizers, dates, fees, or links.
 * 4. Graceful fallback if Gemini API is unavailable.
 */
function analyzeOpportunityWithGemini(GeminiService $gemini, array $oppData): array
{
    $title       = trim((string)($oppData['title'] ?? ''));
    $desc        = trim((string)($oppData['description'] ?? ''));
    $category    = trim((string)($oppData['category_name'] ?? ''));
    $delivery    = trim((string)($oppData['delivery_mode'] ?? 'online'));
    $deadline    = trim((string)($oppData['registration_deadline'] ?? ''));
    $eligibility = trim((string)($oppData['eligibility'] ?? ''));
    $cost        = ($oppData['cost_amount'] ?? 0) > 0 ? ($oppData['cost_currency'] ?? 'INR') . ' ' . $oppData['cost_amount'] : 'Free';
    $sourceUrl   = trim((string)($oppData['source_url'] ?? ''));

    // Rule-based completeness checks
    $missingFields = [];
    if (empty($deadline)) {
        $missingFields[] = 'Registration deadline not specified';
    }
    if (empty($eligibility)) {
        $missingFields[] = 'Student eligibility criteria missing';
    }
    if (empty($sourceUrl)) {
        $missingFields[] = 'Official source link / website URL missing';
    }
    if (empty($desc) || mb_strlen($desc) < 20) {
        $missingFields[] = 'Description is minimal or missing';
    }

    $isGeminiAvailable = $gemini->isConfigured();

    if ($isGeminiAvailable) {
        try {
            $sysPrompt = "You are a senior data verification advisor for CareerAct.
Evaluate this student extracurricular opportunity submission for an administrator.
STRICT INSTRUCTIONS:
1. Base your review STRICTLY on the text provided.
2. NEVER invent organizers, dates, websites, or prerequisites.
3. Be purely advisory. You CANNOT approve or publish the event yourself.
4. Output valid JSON ONLY with this schema:
{
  \"category_fit\": \"high\" | \"medium\" | \"low\",
  \"category_rationale\": \"1-2 sentence explanation\",
  \"brief_summary\": \"Concise 2-sentence verified summary of the opportunity for students\",
  \"missing_information\": [\"item 1\", \"item 2\"],
  \"quality_score\": 1-10,
  \"advisory_recommendation\": \"approve_ready\" | \"needs_clarification\" | \"reject_suspect\",
  \"recommendation_note\": \"1 sentence advice to the administrator\"
}";

            $userPrompt = "Opportunity Details:
Title: {$title}
Designated Category: {$category}
Delivery Mode: {$delivery}
Description: {$desc}
Registration Deadline: {$deadline}
Eligibility: {$eligibility}
Cost: {$cost}
Official Source: {$sourceUrl}";

            $raw = $gemini->callGeminiApi($sysPrompt, $userPrompt, 4, 1);
            $parsed = $gemini->extractAndValidateJson($raw);

            if (is_array($parsed) && isset($parsed['category_fit'], $parsed['brief_summary'])) {
                // Merge AI missing fields with rule-based checks
                $aiMissing = (array)($parsed['missing_information'] ?? []);
                $allMissing = array_unique(array_merge($missingFields, $aiMissing));

                return [
                    'source'                 => 'gemini_ai',
                    'category_fit'           => $parsed['category_fit'],
                    'category_rationale'     => $parsed['category_rationale'] ?? '',
                    'brief_summary'          => $parsed['brief_summary'],
                    'missing_information'    => array_values($allMissing),
                    'quality_score'          => (int)($parsed['quality_score'] ?? 7),
                    'advisory_recommendation'=> $parsed['advisory_recommendation'] ?? 'needs_clarification',
                    'recommendation_note'    => $parsed['recommendation_note'] ?? 'Verify official source before publishing.',
                ];
            }
        } catch (Throwable $e) {
            error_log("Gemini opportunity analysis failed: " . $e->getMessage());
        }
    }

    // Heuristic Fallback
    $qualityScore = 10 - (count($missingFields) * 2);
    $advisory = count($missingFields) === 0 ? 'approve_ready' : (count($missingFields) > 2 ? 'needs_clarification' : 'needs_clarification');

    return [
        'source'                 => 'rule_based_fallback',
        'category_fit'           => 'medium',
        'category_rationale'     => "Submitted under category '{$category}'. Review description to confirm track alignment.",
        'brief_summary'          => !empty($desc) ? substr($desc, 0, 160) . '...' : "Extracurricular event: {$title}.",
        'missing_information'    => $missingFields,
        'quality_score'          => max(3, $qualityScore),
        'advisory_recommendation'=> $advisory,
        'recommendation_note'    => count($missingFields) > 0 ? 'Review missing fields prior to verification.' : 'All standard fields present. Ready for manual review.',
    ];
}
