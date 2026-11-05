<?php
/**
 * CareerAct — Admin Extracurricular Opportunity Management
 * Allows admins to view, search, filter, create, edit, and archive opportunities.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../includes/admin_service.php';

$db = getDbConnection();
$adminId = getCurrentUserId() ?? 1;
$csrfToken = getCsrfToken();

$search = trim($_GET['search'] ?? '');
$catFilter = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$verFilter = trim($_GET['verification_status'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$modeFilter = trim($_GET['delivery_mode'] ?? '');

$feedback = '';
$feedbackType = 'info';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $feedback = 'Security token invalid or expired.';
        $feedbackType = 'danger';
    } else {
        $action = trim($_POST['action'] ?? '');

        if ($action === 'save_opportunity') {
            $oppData = [
                'id'                    => !empty($_POST['opportunity_id']) ? (int)$_POST['opportunity_id'] : null,
                'title'                 => trim($_POST['title'] ?? ''),
                'description'           => trim($_POST['description'] ?? ''),
                'activity_id'           => !empty($_POST['activity_id']) ? (int)$_POST['activity_id'] : 0,
                'provider_id'           => !empty($_POST['provider_id']) ? (int)$_POST['provider_id'] : null,
                'delivery_mode'         => trim($_POST['delivery_mode'] ?? 'online'),
                'state_name'            => trim($_POST['state_name'] ?? ''),
                'scope_level'           => trim($_POST['scope_level'] ?? 'national'),
                'venue'                 => trim($_POST['venue'] ?? ''),
                'start_date'            => !empty($_POST['start_date']) ? $_POST['start_date'] : null,
                'end_date'              => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                'registration_deadline' => !empty($_POST['registration_deadline']) ? $_POST['registration_deadline'] : null,
                'eligibility'           => trim($_POST['eligibility'] ?? ''),
                'cost_amount'           => isset($_POST['cost_amount']) ? (float)$_POST['cost_amount'] : 0.00,
                'cost_currency'         => trim($_POST['cost_currency'] ?? 'INR'),
                'registration_url'      => trim($_POST['registration_url'] ?? ''),
                'source_url'            => trim($_POST['source_url'] ?? ''),
                'status'                => trim($_POST['status'] ?? 'draft'),
                'verification_status'   => trim($_POST['verification_status'] ?? 'unverified'),
            ];

            $res = saveOpportunityAdmin($db, $adminId, $oppData);
            if ($res['success']) {
                $feedback = $res['message'] ?? 'Opportunity saved successfully.';
                $feedbackType = 'success';
            } else {
                $feedback = $res['error'] ?? 'Could not save opportunity.';
                $feedbackType = 'danger';
            }
        } elseif ($action === 'archive_opportunity') {
            $oppId = (int)($_POST['opportunity_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? 'Archived via admin catalog');
            if ($oppId > 0) {
                $res = archiveOpportunityAdmin($db, $adminId, $oppId, $reason);
                if ($res['success']) {
                    $feedback = $res['message'];
                    $feedbackType = 'success';
                } else {
                    $feedback = $res['error'] ?? 'Failed to archive opportunity.';
                    $feedbackType = 'danger';
                }
            }
        }
    }
}

// Fetch categories for filter and dropdowns
$allCategories = getAllCategoriesAdmin($db);

// Fetch activities for dropdown
$stmtActivities = $db->query("SELECT id, title, category_id FROM activities WHERE is_active = 1 ORDER BY title ASC");
$allActivities = $stmtActivities->fetchAll(PDO::FETCH_ASSOC);

// Fetch providers
$stmtProviders = $db->query("SELECT id, name FROM providers ORDER BY name ASC");
$allProviders = $stmtProviders->fetchAll(PDO::FETCH_ASSOC);

// Filter opportunities
$filters = [
    'search'              => $search,
    'category_id'         => $catFilter,
    'verification_status' => $verFilter,
    'status'              => $statusFilter,
    'delivery_mode'       => $modeFilter,
    'limit'               => 100,
];
$opportunities = getAdminOpportunities($db, $filters);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Opportunities Catalog — CareerAct Management Console</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-body">

    <?php require __DIR__ . '/admin_nav.php'; ?>

    <main class="admin-container">
        <!-- Page Header -->
        <div class="admin-page-header">
            <div class="admin-title-area">
                <h1>Extracurricular Opportunities Catalog</h1>
                <p>Manage, review, publish, and track all real-world activities, hackathons, and conferences.</p>
            </div>
            <div>
                <button type="button" class="admin-btn-primary" onclick="openOppModal()">
                    + Add Opportunity
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

        <!-- Filters Bar -->
        <div class="admin-filter-bar">
            <form method="GET" action="opportunities.php" class="admin-search-form" style="flex-wrap: wrap;">
                <input 
                    type="text" 
                    name="search" 
                    class="admin-input" 
                    placeholder="Search by title, venue, or provider..." 
                    value="<?= htmlspecialchars($search) ?>"
                    style="min-width: 240px; flex: 2;"
                >
                <select name="category_id" class="admin-select" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php foreach ($allCategories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $catFilter === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="verification_status" class="admin-select" onchange="this.form.submit()">
                    <option value="">All Verifications</option>
                    <option value="verified" <?= $verFilter === 'verified' ? 'selected' : '' ?>>Verified Only</option>
                    <option value="pending" <?= $verFilter === 'pending' ? 'selected' : '' ?>>Pending Review (Queue)</option>
                    <option value="needs_correction" <?= $verFilter === 'needs_correction' ? 'selected' : '' ?>>Needs Correction</option>
                    <option value="rejected" <?= $verFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="sample" <?= $verFilter === 'sample' ? 'selected' : '' ?>>Sample Starter</option>
                </select>

                <select name="status" class="admin-select" onchange="this.form.submit()">
                    <option value="">All Lifecycles</option>
                    <option value="published" <?= $statusFilter === 'published' ? 'selected' : '' ?>>Published</option>
                    <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                    <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>

                <select name="delivery_mode" class="admin-select" onchange="this.form.submit()">
                    <option value="">All Modes</option>
                    <option value="online" <?= $modeFilter === 'online' ? 'selected' : '' ?>>Online</option>
                    <option value="offline" <?= $modeFilter === 'offline' ? 'selected' : '' ?>>Offline (In-Person)</option>
                    <option value="hybrid" <?= $modeFilter === 'hybrid' ? 'selected' : '' ?>>Hybrid</option>
                </select>

                <button type="submit" class="admin-btn-primary" style="padding: 10px 16px;">Filter</button>
                <?php if (!empty($search) || !empty($catFilter) || !empty($verFilter) || !empty($statusFilter) || !empty($modeFilter)): ?>
                    <a href="opportunities.php" class="btn-sm btn-secondary" style="padding: 10px 14px;">Reset</a>
                <?php endif; ?>
            </form>
            <div style="font-size: 13px; color: var(--adm-text-muted);">
                Showing <strong><?= count($opportunities) ?></strong> listings
            </div>
        </div>

        <!-- Opportunities Table -->
        <div class="admin-card">
            <div class="admin-table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Opportunity Title</th>
                            <th>Category</th>
                            <th>Organizer</th>
                            <th>Mode / Scope</th>
                            <th>Deadline</th>
                            <th>Verification</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($opportunities)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; color: var(--adm-text-muted); padding: 32px;">
                                    No opportunities found matching current filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($opportunities as $opp): ?>
                                <tr>
                                    <td>
                                        <strong style="color: var(--adm-navy);"><?= htmlspecialchars($opp['title']) ?></strong>
                                        <?php if (!empty($opp['venue'])): ?>
                                            <div style="font-size: 12px; color: var(--adm-text-muted);">
                                                📍 <?= htmlspecialchars($opp['venue']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-size: 13px; font-weight: 500; color: #1E293B;">
                                            <?= htmlspecialchars($opp['category_name']) ?>
                                        </span>
                                    </td>
                                    <td style="font-size: 13px; color: var(--adm-text-muted);">
                                        <?= htmlspecialchars($opp['provider_name']) ?>
                                    </td>
                                    <td style="font-size: 12px;">
                                        <span style="text-transform: capitalize;"><?= htmlspecialchars($opp['delivery_mode']) ?></span>
                                        <span style="color: var(--adm-text-muted);">• <?= htmlspecialchars($opp['scope_level']) ?></span>
                                    </td>
                                    <td style="font-size: 13px; white-space: nowrap;">
                                        <?php if (!empty($opp['registration_deadline'])): ?>
                                            <?php 
                                            $isPast = strtotime($opp['registration_deadline']) < strtotime(date('Y-m-d'));
                                            ?>
                                            <span style="color: <?= $isPast ? 'var(--adm-danger)' : 'var(--adm-text-main)' ?>;">
                                                <?= date('M j, Y', strtotime($opp['registration_deadline'])) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--adm-text-muted);">Open / Rolling</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-pill status-<?= htmlspecialchars($opp['verification_status']) ?>">
                                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $opp['verification_status']))) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-pill status-<?= htmlspecialchars($opp['status']) ?>">
                                            <?= htmlspecialchars(ucwords($opp['status'])) ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <?php if (in_array($opp['verification_status'], ['unverified', 'needs_correction'], true)): ?>
                                            <a href="verification_queue.php?opp_id=<?= $opp['id'] ?>" class="btn-sm btn-warning" title="Inspect in Verification Queue">
                                                Review
                                            </a>
                                        <?php endif; ?>
                                        <button 
                                            type="button" 
                                            class="btn-sm btn-outline" 
                                            onclick='editOpportunity(<?= json_encode($opp) ?>)'
                                        >
                                            Edit
                                        </button>
                                        <?php if ($opp['status'] !== 'archived'): ?>
                                            <form method="POST" action="opportunities.php" style="display: inline-block; margin-left: 4px;">
                                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                <input type="hidden" name="action" value="archive_opportunity">
                                                <input type="hidden" name="opportunity_id" value="<?= $opp['id'] ?>">
                                                <button 
                                                    type="submit" 
                                                    class="btn-sm btn-secondary" 
                                                    onclick="return confirm('Archive this opportunity? It will be moved to the Archived view while safely preserving student records.')"
                                                    title="Archive listing"
                                                >
                                                    Archive
                                                </button>
                                            </form>
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

    <!-- Modal: Add / Edit Opportunity -->
    <div id="oppModal" class="admin-modal-backdrop">
        <div class="admin-modal-card" style="max-width: 760px;">
            <div class="admin-modal-header">
                <h3 id="oppModalTitle" class="admin-modal-title">Add Extracurricular Opportunity</h3>
                <button type="button" class="admin-close-btn" onclick="closeOppModal()">&times;</button>
            </div>
            <form method="POST" action="opportunities.php">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="save_opportunity">
                <input type="hidden" id="modalOppId" name="opportunity_id" value="">

                <div style="margin-bottom: 16px;">
                    <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Opportunity Title *</label>
                    <input type="text" id="modalOppTitle" name="title" class="admin-input" placeholder="e.g. Smart India Hackathon 2026 — Senior Edition" required>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Parent Activity Track *</label>
                        <select id="modalActivityId" name="activity_id" class="admin-input" required>
                            <?php foreach ($allActivities as $act): ?>
                                <option value="<?= $act['id'] ?>"><?= htmlspecialchars($act['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Organizer / Provider</label>
                        <select id="modalProviderId" name="provider_id" class="admin-input">
                            <option value="">Unspecified Academic Partner</option>
                            <?php foreach ($allProviders as $prov): ?>
                                <option value="<?= $prov['id'] ?>"><?= htmlspecialchars($prov['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Description</label>
                    <textarea id="modalOppDesc" name="description" class="admin-input" rows="3" placeholder="Overview of rounds, problem statements, perks, and learning objectives..."></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Delivery Mode</label>
                        <select id="modalDeliveryMode" name="delivery_mode" class="admin-input">
                            <option value="online">Online</option>
                            <option value="offline">Offline</option>
                            <option value="hybrid">Hybrid</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Scope Level</label>
                        <select id="modalScopeLevel" name="scope_level" class="admin-input">
                            <option value="national">National</option>
                            <option value="state">State</option>
                            <option value="local">Local</option>
                            <option value="international_eligible">International (India Eligible)</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Indian State / UT</label>
                        <input type="text" id="modalStateName" name="state_name" class="admin-input" placeholder="e.g. Karnataka">
                    </div>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Venue / Campus Details</label>
                    <input type="text" id="modalVenue" name="venue" class="admin-input" placeholder="e.g. Virtual via Discord or IIT Bombay Campus, Powai, Mumbai">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Registration Deadline</label>
                        <input type="date" id="modalDeadline" name="registration_deadline" class="admin-input">
                    </div>
                    <div>
                        <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Start Date</label>
                        <input type="date" id="modalStartDate" name="start_date" class="admin-input">
                    </div>
                    <div>
                        <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">End Date</label>
                        <input type="date" id="modalEndDate" name="end_date" class="admin-input">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Eligibility Criteria</label>
                        <input type="text" id="modalEligibility" name="eligibility" class="admin-input" placeholder="e.g. Open to all enrolled undergraduate students in India">
                    </div>
                    <div>
                        <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Cost (INR)</label>
                        <input type="number" step="0.01" id="modalCost" name="cost_amount" class="admin-input" placeholder="0.00 for Free">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Official Registration URL</label>
                        <input type="url" id="modalRegUrl" name="registration_url" class="admin-input" placeholder="https://organizer.example/register">
                    </div>
                    <div>
                        <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Official Source Website URL</label>
                        <input type="url" id="modalSourceUrl" name="source_url" class="admin-input" placeholder="https://organizer.example/details">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px;">
                    <div>
                        <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Verification Status</label>
                        <select id="modalVerStatus" name="verification_status" class="admin-input">
                            <option value="unverified">Unverified (Pending Review)</option>
                            <option value="verified">Verified (Approved)</option>
                            <option value="needs_correction">Needs Correction</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Lifecycle Status</label>
                        <select id="modalStatus" name="status" class="admin-input">
                            <option value="published">Published (Visible to Students)</option>
                            <option value="draft">Draft (Hidden)</option>
                            <option value="expired">Expired</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-sm btn-secondary" onclick="closeOppModal()" style="padding: 9px 16px;">Cancel</button>
                    <button type="submit" class="admin-btn-primary" style="padding: 9px 18px;">Save Opportunity</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openOppModal() {
            document.getElementById('oppModalTitle').textContent = 'Add Extracurricular Opportunity';
            document.getElementById('modalOppId').value = '';
            document.getElementById('modalOppTitle').value = '';
            document.getElementById('modalOppDesc').value = '';
            document.getElementById('modalStateName').value = '';
            document.getElementById('modalVenue').value = '';
            document.getElementById('modalDeadline').value = '';
            document.getElementById('modalStartDate').value = '';
            document.getElementById('modalEndDate').value = '';
            document.getElementById('modalEligibility').value = '';
            document.getElementById('modalCost').value = '0.00';
            document.getElementById('modalRegUrl').value = '';
            document.getElementById('modalSourceUrl').value = '';
            document.getElementById('modalVerStatus').value = 'verified';
            document.getElementById('modalStatus').value = 'published';
            document.getElementById('oppModal').classList.add('open');
        }

        function editOpportunity(opp) {
            document.getElementById('oppModalTitle').textContent = 'Edit Opportunity: ' + opp.title;
            document.getElementById('modalOppId').value = opp.id;
            document.getElementById('modalOppTitle').value = opp.title;
            document.getElementById('modalOppDesc').value = opp.description || '';
            document.getElementById('modalActivityId').value = opp.activity_id;
            if (opp.provider_id) document.getElementById('modalProviderId').value = opp.provider_id;
            document.getElementById('modalDeliveryMode').value = opp.delivery_mode;
            document.getElementById('modalScopeLevel').value = opp.scope_level;
            document.getElementById('modalStateName').value = opp.state_name || '';
            document.getElementById('modalVenue').value = opp.venue || '';
            document.getElementById('modalDeadline').value = opp.registration_deadline || '';
            document.getElementById('modalStartDate').value = opp.start_date || '';
            document.getElementById('modalEndDate').value = opp.end_date || '';
            document.getElementById('modalEligibility').value = opp.eligibility || '';
            document.getElementById('modalCost').value = opp.cost_amount || '0.00';
            document.getElementById('modalRegUrl').value = opp.registration_url || '';
            document.getElementById('modalSourceUrl').value = opp.source_url || '';
            document.getElementById('modalVerStatus').value = opp.verification_status;
            document.getElementById('modalStatus').value = opp.status;
            document.getElementById('oppModal').classList.add('open');
        }

        function closeOppModal() {
            document.getElementById('oppModal').classList.remove('open');
        }

        window.onclick = function(e) {
            const modal = document.getElementById('oppModal');
            if (e.target === modal) {
                closeOppModal();
            }
        };

        <?php if (isset($_GET['action']) && $_GET['action'] === 'create'): ?>
            window.addEventListener('DOMContentLoaded', openOppModal);
        <?php endif; ?>
    </script>
</body>
</html>

