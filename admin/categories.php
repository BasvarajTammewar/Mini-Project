<?php
/**
 * CareerAct — Admin Activity Category Management
 * Allows admins to view, create, edit, and safely activate/deactivate categories.
 * Enforces duplicate name prevention and preserves existing student data references.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../includes/admin_service.php';

$db = getDbConnection();
$adminId = getCurrentUserId() ?? 1;
$csrfToken = getCsrfToken();

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$feedback = '';
$feedbackType = 'info';

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $feedback = 'Security token expired. Please refresh the page.';
        $feedbackType = 'danger';
    } else {
        $action = trim($_POST['action'] ?? '');

        if ($action === 'save_category') {
            $catData = [
                'id'          => !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null,
                'name'        => trim($_POST['name'] ?? ''),
                'code'        => trim($_POST['code'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'is_active'   => isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1,
            ];

            $res = saveCategoryAdmin($db, $adminId, $catData);
            if ($res['success']) {
                $feedback = $res['message'] ?? 'Category saved successfully.';
                $feedbackType = 'success';
            } else {
                $feedback = $res['error'] ?? 'Failed to save category.';
                $feedbackType = 'danger';
            }
        } elseif ($action === 'toggle_active') {
            $catId = (int)($_POST['category_id'] ?? 0);
            if ($catId > 0) {
                $res = toggleCategoryActive($db, $adminId, $catId);
                if ($res['success']) {
                    $feedback = $res['message'];
                    $feedbackType = 'success';
                } else {
                    $feedback = $res['error'] ?? 'Could not change status.';
                    $feedbackType = 'danger';
                }
            }
        }
    }
}

// Fetch categories
$categories = getAllCategoriesAdmin($db, $search ?: null, $statusFilter ?: null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Categories — CareerAct Management Console</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-body">

    <?php require __DIR__ . '/admin_nav.php'; ?>

    <main class="admin-container">
        <!-- Page Header -->
        <div class="admin-page-header">
            <div class="admin-title-area">
                <h1>Extracurricular Activity Categories</h1>
                <p>Manage standard categories for competitions, research, clubs, workshops, and student tracks.</p>
            </div>
            <div>
                <button type="button" class="admin-btn-primary" onclick="openCategoryModal()">
                    + Add New Category
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

        <!-- Filter Bar -->
        <div class="admin-filter-bar">
            <form method="GET" action="categories.php" class="admin-search-form">
                <input 
                    type="text" 
                    name="search" 
                    class="admin-input" 
                    placeholder="Search category name, code, or description..." 
                    value="<?= htmlspecialchars($search) ?>"
                >
                <select name="status" class="admin-select" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
                </select>
                <button type="submit" class="admin-btn-primary" style="padding: 10px 16px;">
                    Search
                </button>
                <?php if (!empty($search) || !empty($statusFilter)): ?>
                    <a href="categories.php" class="btn-sm btn-secondary" style="padding: 10px 14px;">Reset</a>
                <?php endif; ?>
            </form>
            <div style="font-size: 13px; color: var(--adm-text-muted);">
                Showing <strong><?= count($categories) ?></strong> categories
            </div>
        </div>

        <!-- Categories Table -->
        <div class="admin-card">
            <div class="admin-table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Category Name</th>
                            <th>Code</th>
                            <th>Description</th>
                            <th>Linked Opps</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categories)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--adm-text-muted); padding: 32px;">
                                    No categories found matching your criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td>
                                        <strong style="color: var(--adm-navy);"><?= htmlspecialchars($cat['name']) ?></strong>
                                    </td>
                                    <td>
                                        <code><?= htmlspecialchars($cat['code']) ?></code>
                                    </td>
                                    <td style="max-width: 320px; font-size: 13px; color: var(--adm-text-muted);">
                                        <?= htmlspecialchars($cat['description'] ?: 'No description provided.') ?>
                                    </td>
                                    <td>
                                        <a href="opportunities.php?category_id=<?= $cat['id'] ?>" title="View linked opportunities">
                                            <strong><?= (int)$cat['opportunities_count'] ?></strong> total
                                            <span style="font-size: 12px; color: var(--adm-text-muted);">
                                                (<?= (int)$cat['verified_opps_count'] ?> verified)
                                            </span>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="status-pill status-<?= $cat['is_active'] ? 'active' : 'inactive' ?>">
                                            <?= $cat['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <button 
                                            type="button" 
                                            class="btn-sm btn-outline" 
                                            onclick='editCategory(<?= json_encode($cat) ?>)'
                                        >
                                            Edit
                                        </button>
                                        <form method="POST" action="categories.php" style="display: inline-block; margin-left: 6px;">
                                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                            <?php if ($cat['is_active']): ?>
                                                <button 
                                                    type="submit" 
                                                    class="btn-sm btn-secondary" 
                                                    onclick="return confirm('Deactivating this category will hide it from student exploration without deleting existing student activity records. Proceed?')"
                                                    title="Deactivate safely"
                                                >
                                                    Deactivate
                                                </button>
                                            <?php else: ?>
                                                <button type="submit" class="btn-sm btn-success" title="Activate category">
                                                    Activate
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="background: #F8FAFC; border: 1px solid var(--adm-border); border-radius: 8px; padding: 14px 18px; font-size: 13px; color: var(--adm-text-muted);">
            💡 <strong>Safe Deactivation Policy:</strong> To ensure historical student portfolios, resumes, and saved activities never lose data integrity, categories are deactivated rather than deleted.
        </div>
    </main>

    <!-- Modal: Add / Edit Category -->
    <div id="categoryModal" class="admin-modal-backdrop">
        <div class="admin-modal-card">
            <div class="admin-modal-header">
                <h3 id="modalTitle" class="admin-modal-title">Add New Category</h3>
                <button type="button" class="admin-close-btn" onclick="closeCategoryModal()">&times;</button>
            </div>
            <form method="POST" action="categories.php">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="save_category">
                <input type="hidden" id="modalCatId" name="category_id" value="">

                <div style="margin-bottom: 16px;">
                    <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Category Name *</label>
                    <input type="text" id="modalName" name="name" class="admin-input" placeholder="e.g. Hackathons & Code Sprints" required>
                    <small style="color: var(--adm-text-muted); font-size: 11px;">Must be unique across the platform.</small>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">System Code</label>
                    <input type="text" id="modalCode" name="code" class="admin-input" placeholder="e.g. HACKATHON (Auto-generated if blank)">
                    <small style="color: var(--adm-text-muted); font-size: 11px;">Uppercase unique identifier (e.g. WORKSHOP, RESEARCH).</small>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display:block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Description</label>
                    <textarea id="modalDesc" name="description" class="admin-input" rows="3" placeholder="Brief summary of opportunities grouped under this category..."></textarea>
                </div>

                <div style="margin-bottom: 24px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; cursor: pointer;">
                        <input type="checkbox" id="modalActive" name="is_active" value="1" checked>
                        <span>Active for student exploration and activity listings</span>
                    </label>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-sm btn-secondary" onclick="closeCategoryModal()" style="padding: 9px 16px;">Cancel</button>
                    <button type="submit" class="admin-btn-primary" style="padding: 9px 18px;">Save Category</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openCategoryModal() {
            document.getElementById('modalTitle').textContent = 'Add New Category';
            document.getElementById('modalCatId').value = '';
            document.getElementById('modalName').value = '';
            document.getElementById('modalCode').value = '';
            document.getElementById('modalDesc').value = '';
            document.getElementById('modalActive').checked = true;
            document.getElementById('categoryModal').classList.add('open');
        }

        function editCategory(cat) {
            document.getElementById('modalTitle').textContent = 'Edit Category: ' + cat.name;
            document.getElementById('modalCatId').value = cat.id;
            document.getElementById('modalName').value = cat.name;
            document.getElementById('modalCode').value = cat.code;
            document.getElementById('modalDesc').value = cat.description || '';
            document.getElementById('modalActive').checked = parseInt(cat.is_active) === 1;
            document.getElementById('categoryModal').classList.add('open');
        }

        function closeCategoryModal() {
            document.getElementById('categoryModal').classList.remove('open');
        }

        window.onclick = function(e) {
            const modal = document.getElementById('categoryModal');
            if (e.target === modal) {
                closeCategoryModal();
            }
        };
    </script>
</body>
</html>

