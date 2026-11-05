<?php
/**
 * CareerAct — Admin Management Dashboard
 * Central command center with real platform metrics, opportunity overview,
 * category distribution, student participation metrics, and recent audit activity.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../includes/admin_service.php';

$db = getDbConnection();
$stats = getAdminPlatformStats($db);
$recentAudit = getAdminAuditLogs($db, 8);
$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — CareerAct Management Console</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-body">

    <?php require __DIR__ . '/admin_nav.php'; ?>

    <main class="admin-container">
        <!-- Header -->
        <div class="admin-page-header">
            <div class="admin-title-area">
                <h1>Platform Management Dashboard</h1>
                <p>Real-time database analytics, extracurricular opportunity verification, and platform oversight.</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="verification_queue.php" class="admin-btn-primary">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    Review Queue (<?= $stats['opportunities']['pending_verification'] ?>)
                </a>
                <a href="opportunities.php?action=create" class="admin-btn-secondary" style="color: #1E293B;">
                    + New Opportunity
                </a>
            </div>
        </div>

        <!-- Metrics Grid -->
        <section class="admin-metrics-grid">
            <!-- Metric 1: Pending Queue -->
            <div class="admin-metric-card">
                <div class="metric-header">
                    <span class="metric-title">Verification Queue</span>
                    <div class="metric-icon-wrap metric-icon-amber">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                    </div>
                </div>
                <div class="metric-number"><?= number_format($stats['opportunities']['pending_verification']) ?></div>
                <div class="metric-footer">
                    <span>Awaiting administrative verification</span>
                </div>
            </div>

            <!-- Metric 2: Active Opportunities -->
            <div class="admin-metric-card">
                <div class="metric-header">
                    <span class="metric-title">Active Opportunities</span>
                    <div class="metric-icon-wrap metric-icon-green">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                    </div>
                </div>
                <div class="metric-number"><?= number_format($stats['opportunities']['active_upcoming']) ?></div>
                <div class="metric-footer">
                    <span><?= number_format($stats['opportunities']['total']) ?> total cataloged</span>
                </div>
            </div>

            <!-- Metric 3: Students Overview -->
            <div class="admin-metric-card">
                <div class="metric-header">
                    <span class="metric-title">Registered Students</span>
                    <div class="metric-icon-wrap metric-icon-blue">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                    </div>
                </div>
                <div class="metric-number"><?= number_format($stats['students']['total']) ?></div>
                <div class="metric-footer">
                    <span><?= number_format($stats['students']['active']) ?> active in platform activities</span>
                </div>
            </div>

            <!-- Metric 4: Expired & Archived -->
            <div class="admin-metric-card">
                <div class="metric-header">
                    <span class="metric-title">Expired & Archived</span>
                    <div class="metric-icon-wrap metric-icon-purple">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="21 8 21 21 3 21 3 8"></polyline>
                            <rect x="1" y="3" width="22" height="5"></rect>
                            <line x1="10" y1="12" x2="14" y2="12"></line>
                        </svg>
                    </div>
                </div>
                <div class="metric-number"><?= number_format($stats['opportunities']['expired'] + $stats['opportunities']['archived']) ?></div>
                <div class="metric-footer">
                    <span>Preserved in student history</span>
                </div>
            </div>
        </section>

        <!-- Two Column Section: Category Distribution & Participation -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 32px;">
            <!-- Category Distribution -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h2>Activity Categories Overview</h2>
                    <a href="categories.php" class="btn-sm btn-outline">Manage Categories &rarr;</a>
                </div>
                <div class="admin-table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Code</th>
                                <th>Status</th>
                                <th>Total Opps</th>
                                <th>Published</th>
                                <th>Pending</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['categories']['distribution'] as $cat): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($cat['name']) ?></strong>
                                    </td>
                                    <td><code><?= htmlspecialchars($cat['code']) ?></code></td>
                                    <td>
                                        <span class="status-pill status-<?= $cat['is_active'] ? 'active' : 'inactive' ?>">
                                            <?= $cat['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td><?= number_format((int)$cat['total_opportunities']) ?></td>
                                    <td>
                                        <span class="status-pill status-published">
                                            <?= number_format((int)$cat['published_opportunities']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ((int)$cat['pending_opportunities'] > 0): ?>
                                            <span class="status-pill status-pending">
                                                <?= (int)$cat['pending_opportunities'] ?> pending
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--adm-text-muted); font-size: 13px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Participation Summary -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h2>Student Participation</h2>
                </div>
                <div class="admin-card-body">
                    <div style="margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <span style="font-size: 14px; color: var(--adm-text-muted);">Total Enrollments:</span>
                            <strong style="font-size: 15px; color: var(--adm-navy);"><?= number_format($stats['participation']['total']) ?></strong>
                        </div>
                    </div>
                    <ul style="list-style: none; display: flex; flex-direction: column; gap: 12px;">
                        <li style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: #F8FAFC; border-radius: 8px;">
                            <span class="status-pill status-verified">Completed</span>
                            <strong><?= number_format($stats['participation']['completed']) ?></strong>
                        </li>
                        <li style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: #F8FAFC; border-radius: 8px;">
                            <span class="status-pill status-published">Registered / Enrolled</span>
                            <strong><?= number_format($stats['participation']['registered']) ?></strong>
                        </li>
                        <li style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: #F8FAFC; border-radius: 8px;">
                            <span class="status-pill status-pending">In Progress</span>
                            <strong><?= number_format($stats['participation']['in_progress']) ?></strong>
                        </li>
                        <li style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: #F8FAFC; border-radius: 8px;">
                            <span class="status-pill status-sample">Bookmarked / Saved</span>
                            <strong><?= number_format($stats['participation']['saved']) ?></strong>
                        </li>
                    </ul>
                    <div style="margin-top: 24px; padding: 14px; background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 8px; font-size: 12px; color: #166534;">
                        <strong>Student Data Privacy Guarantee:</strong> Metrics displayed are aggregate counts. No personal student records or assessment evaluations are exposed.
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Audit Trail -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h2>Recent Administrative Audit Trail</h2>
                <a href="audit_log.php" class="btn-sm btn-outline">View Full Log &rarr;</a>
            </div>
            <div class="admin-table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Admin</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>Details</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentAudit)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--adm-text-muted); padding: 32px;">
                                    No administrative activity recorded yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentAudit as $log): ?>
                                <tr>
                                    <td style="white-space: nowrap; color: var(--adm-text-muted); font-size: 13px;">
                                        <?= date('M j, Y H:i', strtotime($log['created_at'])) ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($log['admin_name'] ?? 'System') ?></strong>
                                    </td>
                                    <td>
                                        <code style="background: #F1F5F9; padding: 3px 6px; border-radius: 4px; font-size: 12px;">
                                            <?= htmlspecialchars($log['action']) ?>
                                        </code>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($log['entity'] ?? '—') ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($log['detail'] ?? '—') ?>
                                    </td>
                                    <td style="font-family: monospace; font-size: 12px; color: var(--adm-text-muted);">
                                        <?= htmlspecialchars($log['ip'] ?? '127.0.0.1') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

</body>
</html>
