<?php
/**
 * CareerAct — Admin Platform Announcements Management
 * Allows administrators to compose and broadcast platform-wide notifications to students.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../includes/admin_service.php';
require_once __DIR__ . '/../includes/notification_service.php';

$db = getDbConnection();
$adminId = getCurrentUserId() ?? 1;
$csrfToken = getCsrfToken();

$feedback = '';
$feedbackType = 'info';

// Handle publishing an announcement
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $feedback = 'Security token invalid or expired. Please refresh.';
        $feedbackType = 'danger';
    } else {
        $action = trim($_POST['action'] ?? '');
        if ($action === 'publish_announcement') {
            $title   = trim($_POST['title'] ?? '');
            $message = trim($_POST['message'] ?? '');
            $link    = trim($_POST['link'] ?? '');
            $audience = in_array($_POST['target_audience'] ?? '', ['all_students', 'all_users'], true) ? $_POST['target_audience'] : 'all_students';

            if (mb_strlen($title) < 5) {
                $feedback = 'Announcement title must be at least 5 characters.';
                $feedbackType = 'danger';
            } elseif (mb_strlen($message) < 10) {
                $feedback = 'Announcement message must be at least 10 characters.';
                $feedbackType = 'danger';
            } else {
                $annId = publishPlatformAnnouncement($db, $adminId, $title, $message, $link ?: null, $audience);
                
                logAdminAction(
                    $db,
                    $adminId,
                    'announcement_published',
                    'platform_announcement',
                    $annId,
                    "Published announcement '{$title}' to {$audience}",
                    null,
                    json_encode(['title' => $title, 'audience' => $audience])
                );

                $feedback = "Announcement '{$title}' published and delivered to student notification centers.";
                $feedbackType = 'success';
            }
        }
    }
}

// Fetch announcements
$stmt = $db->query("
    SELECT 
        pa.*,
        u.name AS admin_name,
        u.email AS admin_email
    FROM platform_announcements pa
    LEFT JOIN users u ON pa.admin_id = u.id
    ORDER BY pa.id DESC
");
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform Announcements — CareerAct Management Console</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-body">

    <?php require __DIR__ . '/admin_nav.php'; ?>

    <main class="admin-container">
        <!-- Page Header -->
        <div class="admin-page-header">
            <div class="admin-title-area">
                <h1>Platform Announcements</h1>
                <p>Compose and broadcast important platform updates and opportunities to all students.</p>
            </div>
            <div>
                <button type="button" class="admin-btn-primary" onclick="openAnnModal()">
                    + New Announcement
                </button>
            </div>
        </div>

        <!-- Alert Feedback -->
        <?php if (!empty($feedback)): ?>
            <div class="admin-alert admin-alert-<?= $feedbackType ?>">
                <span><?= htmlspecialchars($feedback) ?></span>
                <button type="button" onclick="this.parentElement.style.display='none'" style="background:none; border:none; cursor:pointer; font-weight:bold;">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Announcements Table -->
        <div class="admin-card">
            <div class="admin-table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Title & Subject</th>
                            <th>Message Preview</th>
                            <th>Target Audience</th>
                            <th>Destination Link</th>
                            <th>Author</th>
                            <th>Published Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($announcements)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--adm-text-muted); padding: 32px;">
                                    No announcements have been published yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($announcements as $ann): ?>
                                <tr>
                                    <td>
                                        <strong style="color: var(--adm-navy);"><?= htmlspecialchars($ann['title']) ?></strong>
                                    </td>
                                    <td style="max-width: 380px; font-size: 13px; color: var(--adm-text-main);">
                                        <?= htmlspecialchars(mb_substr($ann['message'], 0, 160)) ?><?= mb_strlen($ann['message']) > 160 ? '...' : '' ?>
                                    </td>
                                    <td>
                                        <span class="status-pill status-sample">
                                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $ann['target_audience']))) ?>
                                        </span>
                                    </td>
                                    <td style="font-size: 13px;">
                                        <?php if (!empty($ann['link'])): ?>
                                            <a href="<?= htmlspecialchars($ann['link']) ?>" target="_blank" style="color: var(--adm-primary); font-weight: 600;">
                                                🔗 <?= htmlspecialchars(mb_substr($ann['link'], 0, 32)) ?>...
                                            </a>
                                        <?php else: ?>
                                            <span style="color: var(--adm-text-muted);">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($ann['admin_name'] ?? 'Admin') ?></strong>
                                    </td>
                                    <td style="white-space: nowrap; font-size: 13px; color: var(--adm-text-muted);">
                                        <?= date('M j, Y H:i', strtotime($ann['created_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal: Compose Announcement -->
    <div id="annModal" class="admin-modal-backdrop">
        <div class="admin-modal-card">
            <div class="admin-modal-header">
                <h3 class="admin-modal-title">Broadcast Platform Announcement</h3>
                <button type="button" class="admin-close-btn" onclick="closeAnnModal()">&times;</button>
            </div>
            <form method="POST" action="announcements.php">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="publish_announcement">

                <div style="margin-bottom: 16px;">
                    <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Announcement Title *</label>
                    <input type="text" name="title" class="admin-input" placeholder="e.g. National Smart India Hackathon 2026 Registration Open" required>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Message Content *</label>
                    <textarea name="message" class="admin-input" rows="4" placeholder="Concise announcement details for students..." required></textarea>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Optional Destination Link</label>
                    <input type="text" name="link" class="admin-input" placeholder="e.g. activities.php or https://verified-domain.edu">
                </div>

                <div style="margin-bottom: 24px;">
                    <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Target Audience</label>
                    <select name="target_audience" class="admin-input">
                        <option value="all_students">All Active Students</option>
                        <option value="all_users">All Users</option>
                    </select>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-sm btn-secondary" onclick="closeAnnModal()" style="padding: 9px 16px;">Cancel</button>
                    <button type="submit" class="admin-btn-primary" style="padding: 9px 18px;">Publish & Broadcast</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAnnModal() {
            document.getElementById('annModal').classList.add('open');
        }
        function closeAnnModal() {
            document.getElementById('annModal').classList.remove('open');
        }
        window.onclick = function(e) {
            const modal = document.getElementById('annModal');
            if (e.target === modal) closeAnnModal();
        };
    </script>
</body>
</html>

