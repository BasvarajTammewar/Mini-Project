<?php
/**
 * CareerAct — Admin Audit Log Viewer
 * Provides an immutable administrative audit trail of all platform management actions:
 * category modifications, opportunity verifications, archival, and publishing events.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../includes/admin_service.php';

$db = getDbConnection();

$actionFilter = trim($_GET['action'] ?? '');
$entityFilter = trim($_GET['entity'] ?? '');
$limit = !empty($_GET['limit']) ? min(200, max(20, (int)$_GET['limit'])) : 100;

$logs = getAdminAuditLogs($db, $limit, $actionFilter ?: null, $entityFilter ?: null);

// Distinct actions and entities for filter dropdowns
$distinctActions = $db->query("SELECT DISTINCT action FROM audit_log ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
$distinctEntities = $db->query("SELECT DISTINCT entity FROM audit_log WHERE entity IS NOT NULL ORDER BY entity ASC")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log — CareerAct Management Console</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .code-diff-box {
            background: #1E293B;
            color: #F8FAFC;
            font-family: monospace;
            font-size: 12px;
            padding: 12px;
            border-radius: 8px;
            overflow-x: auto;
            max-height: 240px;
            white-space: pre-wrap;
        }
    </style>
</head>
<body class="admin-body">

    <?php require __DIR__ . '/admin_nav.php'; ?>

    <main class="admin-container">
        <!-- Page Header -->
        <div class="admin-page-header">
            <div class="admin-title-area">
                <h1>Administrative Audit Log</h1>
                <p>Complete historical timeline of all platform changes, verifications, status updates, and security events.</p>
            </div>
            <div>
                <span style="font-size: 13px; color: var(--adm-text-muted);">
                    Showing latest <strong><?= count($logs) ?></strong> events
                </span>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="admin-filter-bar">
            <form method="GET" action="audit_log.php" class="admin-search-form">
                <select name="action" class="admin-select" onchange="this.form.submit()">
                    <option value="">All Action Types</option>
                    <?php foreach ($distinctActions as $act): ?>
                        <option value="<?= htmlspecialchars($act) ?>" <?= $actionFilter === $act ? 'selected' : '' ?>>
                            <?= htmlspecialchars($act) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="entity" class="admin-select" onchange="this.form.submit()">
                    <option value="">All Entities</option>
                    <?php foreach ($distinctEntities as $ent): ?>
                        <option value="<?= htmlspecialchars($ent) ?>" <?= $entityFilter === $ent ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $ent))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="admin-btn-primary" style="padding: 10px 16px;">Filter</button>
                <?php if (!empty($actionFilter) || !empty($entityFilter)): ?>
                    <a href="audit_log.php" class="btn-sm btn-secondary" style="padding: 10px 14px;">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Audit Table -->
        <div class="admin-card">
            <div class="admin-table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Administrator</th>
                            <th>Action</th>
                            <th>Entity / ID</th>
                            <th>Narrative Details</th>
                            <th>IP Address</th>
                            <th style="text-align: right;">Changes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--adm-text-muted); padding: 32px;">
                                    No audit events found matching filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td style="white-space: nowrap; font-size: 13px; color: var(--adm-text-muted);">
                                        <?= date('M j, Y H:i:s', strtotime($log['created_at'])) ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($log['admin_name'] ?? 'System') ?></strong>
                                        <?php if (!empty($log['admin_email'])): ?>
                                            <div style="font-size: 11px; color: var(--adm-text-muted);">
                                                <?= htmlspecialchars($log['admin_email']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code style="background: #F1F5F9; padding: 3px 6px; border-radius: 4px; font-size: 12px; color: var(--adm-navy);">
                                            <?= htmlspecialchars($log['action']) ?>
                                        </code>
                                    </td>
                                    <td style="font-size: 13px;">
                                        <?= htmlspecialchars($log['entity'] ?? '—') ?>
                                        <?php if (!empty($log['entity_id'])): ?>
                                            <span style="color: var(--adm-text-muted);">#<?= $log['entity_id'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size: 13px; max-width: 360px;">
                                        <?= htmlspecialchars($log['detail'] ?? '—') ?>
                                    </td>
                                    <td style="font-family: monospace; font-size: 12px; color: var(--adm-text-muted);">
                                        <?= htmlspecialchars($log['ip'] ?? '127.0.0.1') ?>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <?php if (!empty($log['new_value']) || !empty($log['old_value'])): ?>
                                            <button 
                                                type="button" 
                                                class="btn-sm btn-outline" 
                                                onclick='showDiffModal(<?= json_encode($log) ?>)'
                                            >
                                                View Diff
                                            </button>
                                        <?php else: ?>
                                            <span style="color: var(--adm-text-muted); font-size: 12px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal: View Diff -->
    <div id="diffModal" class="admin-modal-backdrop">
        <div class="admin-modal-card" style="max-width: 680px;">
            <div class="admin-modal-header">
                <h3 id="diffModalTitle" class="admin-modal-title">Audit Record Change Details</h3>
                <button type="button" class="admin-close-btn" onclick="closeDiffModal()">&times;</button>
            </div>
            <div style="margin-bottom: 16px;">
                <div style="font-size: 13px; font-weight: 600; color: var(--adm-text-muted); margin-bottom: 6px;">Previous State (Before):</div>
                <div id="diffOldValue" class="code-diff-box"></div>
            </div>
            <div style="margin-bottom: 20px;">
                <div style="font-size: 13px; font-weight: 600; color: #10B981; margin-bottom: 6px;">Updated State (After):</div>
                <div id="diffNewValue" class="code-diff-box"></div>
            </div>
            <div style="display: flex; justify-content: flex-end;">
                <button type="button" class="btn-sm btn-secondary" onclick="closeDiffModal()" style="padding: 8px 16px;">Close</button>
            </div>
        </div>
    </div>

    <script>
        function showDiffModal(log) {
            document.getElementById('diffModalTitle').textContent = 'Action: ' + log.action + ' (#' + log.id + ')';
            
            let oldText = 'None (New Record)';
            if (log.old_value) {
                try {
                    oldText = JSON.stringify(JSON.parse(log.old_value), null, 2);
                } catch(e) {
                    oldText = log.old_value;
                }
            }
            document.getElementById('diffOldValue').textContent = oldText;

            let newText = 'None (Deleted or Cleared)';
            if (log.new_value) {
                try {
                    newText = JSON.stringify(JSON.parse(log.new_value), null, 2);
                } catch(e) {
                    newText = log.new_value;
                }
            }
            document.getElementById('diffNewValue').textContent = newText;

            document.getElementById('diffModal').classList.add('open');
        }

        function closeDiffModal() {
            document.getElementById('diffModal').classList.remove('open');
        }

        window.onclick = function(e) {
            const modal = document.getElementById('diffModal');
            if (e.target === modal) closeDiffModal();
        };
    </script>
</body>
</html>

