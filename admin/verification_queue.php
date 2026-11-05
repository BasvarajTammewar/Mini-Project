<?php
/**
 * CareerAct — Admin Opportunity Verification Queue & Review
 * Review submitted and unverified opportunities with Gemini AI-assisted advisory checks.
 * Admin decision required to verify, request corrections, or reject.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../includes/admin_service.php';

$db = getDbConnection();
$adminId = getCurrentUserId() ?? 1;
$csrfToken = getCsrfToken();
$gemini = new GeminiService();

$feedback = '';
$feedbackType = 'info';

// Handle Verification Decisions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $feedback = 'Security token expired. Please refresh.';
        $feedbackType = 'danger';
    } else {
        $action = trim($_POST['action'] ?? '');
        $oppId = (int)($_POST['opportunity_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($oppId > 0 && in_array($action, ['approve', 'needs_correction', 'reject'], true)) {
            $statusMap = [
                'approve'          => 'verified',
                'needs_correction' => 'needs_correction',
                'reject'           => 'rejected',
            ];
            $verStatus = $statusMap[$action];
            $res = setOpportunityVerification($db, $adminId, $oppId, $verStatus, $notes ?: null);

            if ($res['success']) {
                $feedback = "Opportunity #{$oppId} marked as '{$verStatus}'. " . ($action === 'approve' ? 'Listing is now published and visible to students.' : 'Listing status updated.');
                $feedbackType = ($action === 'approve') ? 'success' : 'warning';
            } else {
                $feedback = $res['error'] ?? 'Failed to update verification status.';
                $feedbackType = 'danger';
            }
        }
    }
}

// Fetch unverified opportunities
$queueFilters = [
    'verification_status' => 'pending', // unverified and needs_correction
    'limit'               => 50,
];
$queue = getAdminOpportunities($db, $queueFilters);

// Check if a specific opportunity is selected for inspection
$selectedId = !empty($_GET['opp_id']) ? (int)$_GET['opp_id'] : ($queue[0]['id'] ?? 0);
$selectedOpp = null;
$geminiAnalysis = null;

if ($selectedId > 0) {
    $selectedOpp = getAdminOpportunityById($db, $selectedId);
    if ($selectedOpp) {
        // Run advisory Gemini analysis
        $geminiAnalysis = analyzeOpportunityWithGemini($gemini, $selectedOpp);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification Queue — CareerAct Management Console</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .queue-layout {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 1024px) {
            .queue-layout {
                grid-template-columns: 1fr;
            }
        }
        .queue-item-card {
            background: #FFFFFF;
            border: 1px solid var(--adm-border);
            border-radius: var(--adm-radius);
            padding: 16px;
            margin-bottom: 12px;
            cursor: pointer;
            text-decoration: none;
            display: block;
            color: inherit;
            transition: all 0.15s ease;
        }
        .queue-item-card:hover {
            border-color: var(--adm-primary);
            box-shadow: var(--adm-shadow-sm);
        }
        .queue-item-card.active {
            border-color: var(--adm-primary);
            background: #F8FAFD;
            box-shadow: 0 0 0 2px var(--adm-primary);
        }
        .queue-item-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--adm-navy);
            margin-bottom: 6px;
        }
        .queue-item-meta {
            font-size: 12px;
            color: var(--adm-text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body class="admin-body">

    <?php require __DIR__ . '/admin_nav.php'; ?>

    <main class="admin-container">
        <!-- Page Header -->
        <div class="admin-page-header">
            <div class="admin-title-area">
                <h1>Opportunity Verification Queue</h1>
                <p>Review submitted opportunities, run Gemini advisory checks, verify source links, and publish to students.</p>
            </div>
            <div>
                <span class="status-pill status-pending" style="font-size: 13px; padding: 6px 14px;">
                    <?= count($queue) ?> Opportunities Pending Review
                </span>
            </div>
        </div>

        <!-- Alert Feedback -->
        <?php if (!empty($feedback)): ?>
            <div class="admin-alert admin-alert-<?= $feedbackType ?>">
                <span><?= htmlspecialchars($feedback) ?></span>
                <button type="button" onclick="this.parentElement.style.display='none'" style="background:none; border:none; cursor:pointer; font-weight:bold;">&times;</button>
            </div>
        <?php endif; ?>

        <?php if (empty($queue)): ?>
            <!-- Empty Queue State -->
            <div class="admin-card" style="text-align: center; padding: 60px 24px;">
                <div style="font-size: 48px; margin-bottom: 16px;">🎉</div>
                <h2 style="font-size: 22px; color: var(--adm-navy); margin-bottom: 8px;">Verification Queue is Clear!</h2>
                <p style="color: var(--adm-text-muted); max-width: 480px; margin: 0 auto 24px auto; font-size: 14px;">
                    All submitted extracurricular opportunities, hackathons, and workshops have been reviewed and verified.
                </p>
                <div style="display: flex; gap: 12px; justify-content: center;">
                    <a href="opportunities.php" class="btn-sm btn-outline" style="padding: 10px 18px;">
                        Browse All Opportunities Catalog
                    </a>
                    <a href="opportunities.php?action=create" class="admin-btn-primary" style="padding: 10px 18px;">
                        + Add New Opportunity
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="queue-layout">
                <!-- Left: Queue Sidebar -->
                <div class="admin-card" style="padding: 16px; max-height: 80vh; overflow-y: auto;">
                    <div style="font-size: 13px; font-weight: 700; color: var(--adm-text-muted); text-transform: uppercase; margin-bottom: 14px;">
                        Pending Submissions (<?= count($queue) ?>)
                    </div>
                    <?php foreach ($queue as $item): ?>
                        <a href="verification_queue.php?opp_id=<?= $item['id'] ?>" class="queue-item-card <?= $item['id'] === $selectedId ? 'active' : '' ?>">
                            <div class="queue-item-title"><?= htmlspecialchars($item['title']) ?></div>
                            <div class="queue-item-meta">
                                <span><?= htmlspecialchars($item['category_name']) ?></span>
                                <span>•</span>
                                <span class="status-pill status-<?= htmlspecialchars($item['verification_status']) ?>" style="font-size: 10px; padding: 2px 6px;">
                                    <?= htmlspecialchars($item['verification_status']) ?>
                                </span>
                            </div>
                            <?php if (!empty($item['registration_deadline'])): ?>
                                <div style="font-size: 11px; color: var(--adm-text-muted); margin-top: 6px;">
                                    Deadline: <?= date('M j, Y', strtotime($item['registration_deadline'])) ?>
                                </div>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Right: Inspection & Decision Card -->
                <?php if ($selectedOpp): ?>
                    <div class="admin-card">
                        <div class="admin-card-header">
                            <div>
                                <span class="status-pill status-<?= htmlspecialchars($selectedOpp['verification_status']) ?>">
                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $selectedOpp['verification_status']))) ?>
                                </span>
                                <span style="font-size: 13px; color: var(--adm-text-muted); margin-left: 10px;">
                                    Submission ID #<?= $selectedOpp['id'] ?>
                                </span>
                            </div>
                            <div>
                                <a href="opportunities.php?search=<?= urlencode($selectedOpp['title']) ?>" class="btn-sm btn-outline">
                                    Edit in Catalog
                                </a>
                            </div>
                        </div>

                        <div class="admin-card-body">
                            <h2 style="font-size: 22px; font-weight: 700; color: var(--adm-navy); margin-bottom: 12px;">
                                <?= htmlspecialchars($selectedOpp['title']) ?>
                            </h2>

                            <!-- Metadata Badges -->
                            <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;">
                                <span style="background: #EEF4FD; color: #1769E8; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600;">
                                    📂 <?= htmlspecialchars($selectedOpp['category_name']) ?>
                                </span>
                                <span style="background: #F1F5F9; color: #475569; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; text-transform: capitalize;">
                                    🌐 <?= htmlspecialchars($selectedOpp['delivery_mode']) ?>
                                </span>
                                <span style="background: #F1F5F9; color: #475569; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600;">
                                    🏛️ <?= htmlspecialchars($selectedOpp['provider_name']) ?>
                                </span>
                                <?php if (!empty($selectedOpp['scope_level'])): ?>
                                    <span style="background: #F1F5F9; color: #475569; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; text-transform: capitalize;">
                                        📍 <?= htmlspecialchars($selectedOpp['scope_level']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Gemini Advisory Analysis Panel -->
                            <?php if ($geminiAnalysis): ?>
                                <div class="gemini-advisory-box">
                                    <div class="gemini-advisory-header">
                                        <div class="gemini-brand-pill">
                                            <span>✦ Gemini Verification Advisor</span>
                                        </div>
                                        <div class="gemini-disclaimer">
                                            Advisory Only • Requires Admin Confirmation
                                        </div>
                                    </div>

                                    <div class="gemini-summary-text">
                                        <strong>AI Summary:</strong> <?= htmlspecialchars($geminiAnalysis['brief_summary']) ?>
                                    </div>

                                    <div class="gemini-metrics-row">
                                        <div>
                                            <span style="color: var(--adm-text-muted);">Category Fit:</span>
                                            <strong style="text-transform: capitalize; color: #3730A3;">
                                                <?= htmlspecialchars($geminiAnalysis['category_fit']) ?>
                                            </strong>
                                        </div>
                                        <div>
                                            <span style="color: var(--adm-text-muted);">Completeness Score:</span>
                                            <strong style="color: #3730A3;">
                                                <?= (int)$geminiAnalysis['quality_score'] ?> / 10
                                            </strong>
                                        </div>
                                        <div>
                                            <span style="color: var(--adm-text-muted);">Advisory:</span>
                                            <strong style="color: #3730A3; text-transform: uppercase;">
                                                <?= htmlspecialchars(str_replace('_', ' ', $geminiAnalysis['advisory_recommendation'])) ?>
                                            </strong>
                                        </div>
                                    </div>

                                    <?php if (!empty($geminiAnalysis['missing_information'])): ?>
                                        <div style="margin-top: 14px; padding-top: 12px; border-top: 1px dashed #C7D2FE;">
                                            <span style="font-size: 12px; font-weight: 700; color: #92400E; text-transform: uppercase;">Missing / Incomplete Fields to Verify:</span>
                                            <ul class="gemini-checklist">
                                                <?php foreach ($geminiAnalysis['missing_information'] as $item): ?>
                                                    <li><?= htmlspecialchars($item) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>

                                    <div style="margin-top: 10px; font-size: 12px; color: #4338CA;">
                                        💡 <strong>Advisor Note:</strong> <?= htmlspecialchars($geminiAnalysis['recommendation_note']) ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Opportunity Details Overview -->
                            <div style="margin-bottom: 24px;">
                                <h3 style="font-size: 14px; font-weight: 700; color: var(--adm-navy); margin-bottom: 8px;">Description & Overview</h3>
                                <p style="font-size: 14px; line-height: 1.6; color: var(--adm-text-main); background: #F8FAFC; padding: 16px; border-radius: 8px; border: 1px solid var(--adm-border);">
                                    <?= nl2br(htmlspecialchars($selectedOpp['description'] ?: 'No detailed description provided by the submitter.')) ?>
                                </p>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; font-size: 13px;">
                                <div style="background: #F8FAFC; padding: 14px; border-radius: 8px; border: 1px solid var(--adm-border);">
                                    <div style="color: var(--adm-text-muted); margin-bottom: 4px;">Registration Deadline</div>
                                    <strong style="color: var(--adm-navy);">
                                        <?= !empty($selectedOpp['registration_deadline']) ? date('F j, Y', strtotime($selectedOpp['registration_deadline'])) : 'Open / Rolling' ?>
                                    </strong>
                                </div>
                                <div style="background: #F8FAFC; padding: 14px; border-radius: 8px; border: 1px solid var(--adm-border);">
                                    <div style="color: var(--adm-text-muted); margin-bottom: 4px;">Student Eligibility</div>
                                    <strong style="color: var(--adm-navy);">
                                        <?= htmlspecialchars($selectedOpp['eligibility'] ?: 'Open to all Indian students') ?>
                                    </strong>
                                </div>
                                <div style="background: #F8FAFC; padding: 14px; border-radius: 8px; border: 1px solid var(--adm-border);">
                                    <div style="color: var(--adm-text-muted); margin-bottom: 4px;">Cost / Fees</div>
                                    <strong style="color: var(--adm-navy);">
                                        <?= ($selectedOpp['cost_amount'] > 0) ? htmlspecialchars($selectedOpp['cost_currency']) . ' ' . number_format((float)$selectedOpp['cost_amount'], 2) : 'Free of Cost' ?>
                                    </strong>
                                </div>
                                <div style="background: #F8FAFC; padding: 14px; border-radius: 8px; border: 1px solid var(--adm-border);">
                                    <div style="color: var(--adm-text-muted); margin-bottom: 4px;">Venue Location</div>
                                    <strong style="color: var(--adm-navy);">
                                        <?= htmlspecialchars($selectedOpp['venue'] ?: ($selectedOpp['delivery_mode'] === 'online' ? 'Online / Virtual Platform' : 'To be announced')) ?>
                                    </strong>
                                </div>
                            </div>

                            <!-- Source Verification Links -->
                            <div style="background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
                                <div style="font-size: 13px; font-weight: 700; color: #1E40AF; margin-bottom: 8px;">
                                    Official Source Verification Links
                                </div>
                                <div style="display: flex; gap: 16px; flex-wrap: wrap; font-size: 13px;">
                                    <?php if (!empty($selectedOpp['source_url'])): ?>
                                        <a href="<?= htmlspecialchars($selectedOpp['source_url']) ?>" target="_blank" rel="noopener noreferrer" style="color: #1769E8; font-weight: 600; text-decoration: underline;">
                                            🔗 Open Official Source Page &nearr;
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!empty($selectedOpp['registration_url'])): ?>
                                        <a href="<?= htmlspecialchars($selectedOpp['registration_url']) ?>" target="_blank" rel="noopener noreferrer" style="color: #1769E8; font-weight: 600; text-decoration: underline;">
                                            📝 Open Registration Portal &nearr;
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Verification Decision Form -->
                            <div style="border-top: 1px solid var(--adm-border); padding-top: 24px;">
                                <h3 style="font-size: 15px; font-weight: 700; color: var(--adm-navy); margin-bottom: 12px;">
                                    Record Administrative Decision
                                </h3>
                                <form method="POST" action="verification_queue.php?opp_id=<?= $selectedOpp['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="opportunity_id" value="<?= $selectedOpp['id'] ?>">

                                    <div style="margin-bottom: 16px;">
                                        <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">
                                            Review Notes & Rationale (Recorded in Audit Log)
                                        </label>
                                        <textarea name="notes" class="admin-input" rows="2" placeholder="e.g. Official organizer website verified, eligibility confirmed for undergraduate students..."></textarea>
                                    </div>

                                    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                                        <button type="submit" name="action" value="approve" class="btn-sm btn-success" style="padding: 10px 20px; font-size: 14px;">
                                            ✓ Approve & Publish to Students
                                        </button>
                                        <button type="submit" name="action" value="needs_correction" class="btn-sm btn-warning" style="padding: 10px 20px; font-size: 14px;">
                                            ⚠️ Request Correction
                                        </button>
                                        <button type="submit" name="action" value="reject" class="btn-sm btn-danger" style="padding: 10px 20px; font-size: 14px;" onclick="return confirm('Reject this opportunity? It will be marked rejected and kept in draft status.')">
                                            ✕ Reject Submission
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

</body>
</html>

