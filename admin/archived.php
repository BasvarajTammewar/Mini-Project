<?php
/**
 * CareerAct — Expired & Archived Opportunity Management
 * Dedicated view for past-deadline and archived opportunities.
 * Ensures student historical records (enrollments, portfolios) are safely preserved.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../includes/admin_service.php';

$db = getDbConnection();
$adminId = getCurrentUserId() ?? 1;
$csrfToken = getCsrfToken();

// Auto expire any past deadlines first
autoExpirePastOpportunities($db);

$search = trim($_GET['search'] ?? '');
$typeFilter = trim($_GET['type'] ?? ''); // 'expired', 'archived'

$feedback = '';
$feedbackType = 'info';

// Handle Reactivation / Republishing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $feedback = 'Security token expired.';
        $feedbackType = 'danger';
    } else {
        $action = trim($_POST['action'] ?? '');
        $oppId = (int)($_POST['opportunity_id'] ?? 0);
        $newDeadline = trim($_POST['new_deadline'] ?? '');

        if ($oppId > 0 && $action === 'republish') {
            $stmt = $db->prepare("SELECT id, title, status, registration_deadline FROM opportunities WHERE id = :id");
            $stmt->execute([':id' => $oppId]);
            $opp = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($opp) {
                $deadlineVal = !empty($newDeadline) ? $newDeadline : $opp['registration_deadline'];
                // Check deadline
                $isFuture = empty($deadlineVal) || strtotime($deadlineVal) >= strtotime(date('Y-m-d'));
                if (!$isFuture) {
                    $feedback = 'To republish an expired opportunity, please specify a future registration deadline.';
                    $feedbackType = 'danger';
                } else {
                    $up = $db->prepare("
                        UPDATE opportunities 
                        SET status = 'published', 
                            verification_status = 'verified',
                            registration_deadline = :deadline,
                            updated_at = NOW() 
                        WHERE id = :id
                    ");
                    $up->execute([':deadline' => $deadlineVal, ':id' => $oppId]);

                    logAdminAction(
                        $db,
                        $adminId,
                        'opportunity_republished',
                        'opportunity',
                        $oppId,
                        "Republished opportunity '{$opp['title']}' with deadline '{$deadlineVal}'",
                        json_encode(['status' => $opp['status'], 'deadline' => $opp['registration_deadline']]),
                        json_encode(['status' => 'published', 'deadline' => $deadlineVal])
                    );

                    $feedback = "Opportunity '{$opp['title']}' has been republished successfully.";
                    $feedbackType = 'success';
                }
            }
        }
    }
}

// Fetch expired / archived opportunities
$sql = "
    SELECT 
        o.id,
        o.title,
        o.activity_id,
        o.provider_id,
        o.delivery_mode,
        o.scope_level,
        o.registration_deadline,
        o.status,
        o.verification_status,
        o.venue,
        o.created_at,
        ac.name AS category_name,
        COALESCE(p.name, 'Academic Partner') AS provider_name,
        COUNT(DISTINCT e.id) AS enrolled_students_count
    FROM opportunities o
    JOIN activities a ON o.activity_id = a.id
    JOIN activity_categories ac ON a.category_id = ac.id
    LEFT JOIN providers p ON o.provider_id = p.id
    LEFT JOIN enrollments e ON o.id = e.opportunity_id
    WHERE (o.status IN ('expired', 'archived') OR (o.registration_deadline IS NOT NULL AND o.registration_deadline < CURDATE()))
";

$params = [];
if (!empty($search)) {
    $sql .= " AND (o.title LIKE :kw OR p.name LIKE :kw OR ac.name LIKE :kw)";
    $params[':kw'] = '%' . $search . '%';
}
if ($typeFilter === 'expired') {
    $sql .= " AND (o.status = 'expired' OR (o.registration_deadline IS NOT NULL AND o.registration_deadline < CURDATE() AND o.status != 'archived'))";
} elseif ($typeFilter === 'archived') {
    $sql .= " AND o.status = 'archived'";
}

$sql .= " GROUP BY o.id, o.title, o.activity_id, o.provider_id, o.delivery_mode, o.scope_level, o.registration_deadline, o.status, o.verification_status, o.venue, o.created_at, ac.name, p.name";
$sql .= " ORDER BY o.registration_deadline DESC, o.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$archivedList = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expired & Archived Listings — CareerAct Management Console</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-body">

    <?php require __DIR__ . '/admin_nav.php'; ?>

    <main class="admin-container">
        <!-- Header -->
        <div class="admin-page-header">
            <div class="admin-title-area">
                <h1>Expired & Archived Opportunities</h1>
                <p>Track historical extracurricular opportunities, review student participation, and republish extended deadlines.</p>
            </div>
            <div>
                <a href="opportunities.php" class="btn-sm btn-outline" style="padding: 9px 16px;">
                    Back to Active Catalog
                </a>
            </div>
        </div>

        <!-- Student Data Integrity Protection Alert -->
        <div class="admin-alert admin-alert-info" style="margin-bottom: 24px;">
            <div>
                <strong>🔒 Student Historical Integrity Protected:</strong>
                Activities in this list are hidden from active discovery but remain permanently preserved in students' completed experience, verified portfolios, and resumes.
            </div>
        </div>

        <!-- Feedback -->
        <?php if (!empty($feedback)): ?>
            <div class="admin-alert admin-alert-<?= $feedbackType ?>">
                <span><?= htmlspecialchars($feedback) ?></span>
                <button type="button" onclick="this.parentElement.style.display='none'" style="background:none; border:none; cursor:pointer; font-weight:bold;">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Filters Bar -->
        <div class="admin-filter-bar">
            <form method="GET" action="archived.php" class="admin-search-form">
                <input 
                    type="text" 
                    name="search" 
                    class="admin-input" 
                    placeholder="Search expired listings by title, organizer, or category..." 
                    value="<?= htmlspecialchars($search) ?>"
                >
                <select name="type" class="admin-select" onchange="this.form.submit()">
                    <option value="">All Inactive Types</option>
                    <option value="expired" <?= $typeFilter === 'expired' ? 'selected' : '' ?>>Deadline Expired</option>
                    <option value="archived" <?= $typeFilter === 'archived' ? 'selected' : '' ?>>Manually Archived</option>
                </select>
                <button type="submit" class="admin-btn-primary" style="padding: 10px 16px;">Search</button>
                <?php if (!empty($search) || !empty($typeFilter)): ?>
                    <a href="archived.php" class="btn-sm btn-secondary" style="padding: 10px 14px;">Reset</a>
                <?php endif; ?>
            </form>
            <div style="font-size: 13px; color: var(--adm-text-muted);">
                Showing <strong><?= count($archivedList) ?></strong> listings
            </div>
        </div>

        <!-- Archived Listings Table -->
        <div class="admin-card">
            <div class="admin-table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Opportunity Title</th>
                            <th>Category</th>
                            <th>Organizer</th>
                            <th>Reason / Status</th>
                            <th>Deadline</th>
                            <th>Students Enrolled</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($archivedList)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--adm-text-muted); padding: 32px;">
                                    No expired or archived opportunities found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($archivedList as $opp): ?>
                                <tr>
                                    <td>
                                        <strong style="color: var(--adm-navy);"><?= htmlspecialchars($opp['title']) ?></strong>
                                    </td>
                                    <td><?= htmlspecialchars($opp['category_name']) ?></td>
                                    <td style="font-size: 13px; color: var(--adm-text-muted);"><?= htmlspecialchars($opp['provider_name']) ?></td>
                                    <td>
                                        <?php if ($opp['status'] === 'archived'): ?>
                                            <span class="status-pill status-archived">Archived by Admin</span>
                                        <?php else: ?>
                                            <span class="status-pill status-expired">Deadline Expired</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size: 13px; color: var(--adm-danger);">
                                        <?= !empty($opp['registration_deadline']) ? date('M j, Y', strtotime($opp['registration_deadline'])) : 'Past' ?>
                                    </td>
                                    <td>
                                        <strong style="color: var(--adm-navy);"><?= (int)$opp['enrolled_students_count'] ?></strong> students
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <button 
                                            type="button" 
                                            class="btn-sm btn-outline"
                                            onclick="openRepublishModal(<?= (int)$opp['id'] ?>, '<?= htmlspecialchars(addslashes($opp['title']), ENT_QUOTES) ?>')"
                                        >
                                            Extend & Republish
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal: Extend & Republish -->
    <div id="republishModal" class="admin-modal-backdrop">
        <div class="admin-modal-card">
            <div class="admin-modal-header">
                <h3 class="admin-modal-title">Extend Deadline & Republish</h3>
                <button type="button" class="admin-close-btn" onclick="closeRepublishModal()">&times;</button>
            </div>
            <form method="POST" action="archived.php">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="republish">
                <input type="hidden" id="republishOppId" name="opportunity_id" value="">

                <p id="republishOppTitle" style="font-size: 14px; font-weight: 600; color: var(--adm-navy); margin-bottom: 16px;"></p>

                <div style="margin-bottom: 20px;">
                    <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">New Registration Deadline *</label>
                    <input type="date" id="republishDeadline" name="new_deadline" class="admin-input" required min="<?= date('Y-m-d') ?>">
                    <small style="color: var(--adm-text-muted); font-size: 11px;">Must be today or in the future for active student visibility.</small>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-sm btn-secondary" onclick="closeRepublishModal()" style="padding: 9px 16px;">Cancel</button>
                    <button type="submit" class="admin-btn-primary" style="padding: 9px 18px;">Republish Opportunity</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openRepublishModal(id, title) {
            document.getElementById('republishOppId').value = id;
            document.getElementById('republishOppTitle').textContent = title;
            document.getElementById('republishModal').classList.add('open');
        }

        function closeRepublishModal() {
            document.getElementById('republishModal').classList.remove('open');
        }

        window.onclick = function(e) {
            const modal = document.getElementById('republishModal');
            if (e.target === modal) closeRepublishModal();
        };
    </script>
</body>
</html>

