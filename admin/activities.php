<?php
/**
 * CareerAct — Admin Activity Management
 * Create, edit, publish, expire, and manage extracurricular opportunities.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';

$db = getDbConnection();
$csrfToken = getCsrfToken();
$feedback = '';
$feedbackType = 'info';

// 1. Handle POST Actions: Create, Update Status, Archive
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $feedback = 'Invalid session token. Please try again.';
        $feedbackType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_activity') {
            $title          = trim((string)($_POST['title'] ?? ''));
            $description    = trim((string)($_POST['description'] ?? ''));
            $categoryId     = (int)($_POST['category_id'] ?? 1);
            $typeId         = (int)($_POST['activity_type_id'] ?? 1);
            $modeId         = (int)($_POST['mode_id'] ?? 1);
            $organizerName  = trim((string)($_POST['organizer_name'] ?? 'CareerAct Partners'));
            $regDeadline    = !empty($_POST['registration_deadline']) ? $_POST['registration_deadline'] : null;
            $startDate      = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $endDate        = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $regUrl         = trim((string)($_POST['registration_url'] ?? ''));
            $eligibility    = trim((string)($_POST['eligibility_text'] ?? 'Open to all college students'));
            $benefits       = trim((string)($_POST['prize_or_benefits'] ?? 'Skill certification and portfolio enhancement'));
            $statusId       = (int)($_POST['activity_status_id'] ?? 3); // 3 = published

            if (empty($title) || empty($description)) {
                $feedback = 'Title and description are required fields.';
                $feedbackType = 'danger';
            } else {
                try {
                    // Find or create organizer
                    $orgStmt = $db->prepare("SELECT organizer_id FROM organizers WHERE organizer_name = :name LIMIT 1");
                    $orgStmt->execute([':name' => $organizerName]);
                    $organizerId = $orgStmt->fetchColumn();

                    if (!$organizerId) {
                        $insOrg = $db->prepare("
                            INSERT INTO organizers (organizer_name, organizer_type_id, is_verified, created_at)
                            VALUES (:name, 1, 1, NOW())
                        ");
                        $insOrg->execute([':name' => $organizerName]);
                        $organizerId = (int)$db->lastInsertId();
                    }

                    $insertStmt = $db->prepare("
                        INSERT INTO activities (
                            activity_type_id, category_id, domain_id, mode_id, organizer_id, source_id,
                            activity_status_id, verification_status_id, title, description,
                            start_date, end_date, registration_deadline, eligibility_text,
                            prize_or_benefits, registration_url, official_source_url, created_by_user_id,
                            created_at, updated_at
                        ) VALUES (
                            :type_id, :cat_id, 1, :mode_id, :org_id, 1,
                            :status_id, 2, :title, :desc,
                            :start_date, :end_date, :deadline, :eligibility,
                            :benefits, :reg_url, :reg_url, :user_id,
                            NOW(), NOW()
                        )
                    ");

                    $insertStmt->execute([
                        ':type_id'      => $typeId,
                        ':cat_id'       => $categoryId,
                        ':mode_id'      => $modeId,
                        ':org_id'       => $organizerId,
                        ':status_id'    => $statusId,
                        ':title'        => $title,
                        ':desc'         => $description,
                        ':start_date'   => $startDate,
                        ':end_date'     => $endDate,
                        ':deadline'     => $regDeadline,
                        ':eligibility'  => $eligibility,
                        ':benefits'     => $benefits,
                        ':reg_url'      => $regUrl,
                        ':user_id'      => (int)$_SESSION['user_id']
                    ]);

                    $feedback = "Activity \"{$title}\" was successfully published to the student catalog!";
                    $feedbackType = 'success';
                } catch (Exception $e) {
                    error_log('Activity creation error: ' . $e->getMessage());
                    $feedback = 'Database error while creating activity. Please try again.';
                    $feedbackType = 'danger';
                }
            }
        } elseif ($action === 'change_status') {
            $actId = (int)($_POST['activity_id'] ?? 0);
            $newStatus = (int)($_POST['new_status_id'] ?? 3);

            if ($actId > 0 && in_array($newStatus, [1, 3, 6, 7], true)) {
                $stmt = $db->prepare("UPDATE activities SET activity_status_id = :st, updated_at = NOW() WHERE activity_id = :id");
                $stmt->execute([':st' => $newStatus, ':id' => $actId]);
                $feedback = "Activity #{$actId} status updated successfully.";
                $feedbackType = 'success';
            }
        }
    }
}

// 2. Fetch Filter Parameters
$filterStatus = isset($_GET['status']) && $_GET['status'] !== '' ? (int)$_GET['status'] : null;
$searchQuery  = trim((string)($_GET['q'] ?? ''));

$sql = "
    SELECT 
        a.activity_id,
        a.title,
        a.registration_deadline,
        a.start_date,
        a.registration_url,
        a.created_at,
        ac.category_name,
        at.type_name,
        am.mode_name,
        ast.activity_status_id,
        ast.status_code,
        ast.status_name,
        COALESCE(o.organizer_name, 'Unknown') as organizer_name,
        (SELECT COUNT(*) FROM student_activity_enrollments e WHERE e.activity_id = a.activity_id) as enrollment_count
    FROM activities a
    LEFT JOIN activity_categories ac ON a.category_id = ac.category_id
    LEFT JOIN activity_types at ON a.activity_type_id = at.activity_type_id
    LEFT JOIN activity_modes am ON a.mode_id = am.mode_id
    LEFT JOIN activity_statuses ast ON a.activity_status_id = ast.activity_status_id
    LEFT JOIN organizers o ON a.organizer_id = o.organizer_id
    WHERE 1=1
";
$params = [];

if ($filterStatus !== null) {
    $sql .= " AND a.activity_status_id = :status";
    $params[':status'] = $filterStatus;
}
if ($searchQuery !== '') {
    $sql .= " AND (a.title LIKE :q OR o.organizer_name LIKE :q)";
    $params[':q'] = "%{$searchQuery}%";
}

$sql .= " ORDER BY a.activity_id DESC LIMIT 50";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$activities = $stmt->fetchAll();

// Lookups for Form
$categories = $db->query("SELECT * FROM activity_categories ORDER BY category_name")->fetchAll();
$types = $db->query("SELECT * FROM activity_types ORDER BY type_name")->fetchAll();
$modes = $db->query("SELECT * FROM activity_modes ORDER BY mode_id")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Activities — CareerAct Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .admin-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 32px;
            background: #163A70;
            color: #fff;
            border-bottom: 2px solid #1769E8;
        }
        .admin-nav .brand {
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #fff;
            text-decoration: none;
        }
        .admin-nav .brand-badge {
            font-size: 11px;
            background: #0F9D8A;
            padding: 3px 8px;
            border-radius: 4px;
            letter-spacing: 0.5px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .admin-nav .nav-links {
            display: flex;
            gap: 20px;
            align-items: center;
        }
        .admin-nav .nav-links a {
            color: #E4EAF2;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            padding: 6px 12px;
            border-radius: 6px;
            transition: all 0.2s;
        }
        .admin-nav .nav-links a:hover,
        .admin-nav .nav-links a.active {
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
        }
        .admin-container {
            max-width: 1300px;
            margin: 32px auto;
            padding: 0 24px;
        }
        .filter-bar {
            display: flex;
            gap: 14px;
            background: #fff;
            padding: 18px;
            border-radius: 12px;
            border: 1px solid #E4EAF2;
            margin-bottom: 24px;
            align-items: center;
            flex-wrap: wrap;
        }
        .filter-bar input, .filter-bar select {
            padding: 10px 14px;
            border: 1px solid #D0D5DD;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }
        .filter-bar input:focus, .filter-bar select:focus {
            border-color: #1769E8;
        }
        .modal-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #E4EAF2;
            padding: 24px;
            margin-bottom: 32px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 18px;
            margin-bottom: 20px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #344054;
        }
        .form-group input, .form-group select, .form-group textarea {
            padding: 10px 14px;
            border: 1px solid #D0D5DD;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }
        .btn-submit {
            background: #1769E8;
            color: #fff;
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
        }
        .admin-table-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #E4EAF2;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            text-align: left;
        }
        .admin-table th {
            background: #F8FAFC;
            padding: 14px 18px;
            font-weight: 600;
            color: #475467;
            border-bottom: 1px solid #E4EAF2;
        }
        .admin-table td {
            padding: 14px 18px;
            border-bottom: 1px solid #E4EAF2;
            color: #17243B;
            vertical-align: middle;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-published { background: #ECFDF3; color: #027A48; border: 1px solid #A6F4C5; }
        .badge-draft { background: #F2F4F7; color: #344054; border: 1px solid #D0D5DD; }
        .badge-expired { background: #FEF3F2; color: #B42318; border: 1px solid #FECDCA; }
        .alert-bar {
            padding: 14px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
        }
        .alert-success { background: #ECFDF3; color: #027A48; border: 1px solid #A6F4C5; }
        .alert-danger { background: #FEF3F2; color: #B42318; border: 1px solid #FECDCA; }
    </style>
</head>
<body>

    <header class="admin-nav">
        <a href="dashboard.php" class="brand">
            CareerAct
            <span class="brand-badge">Admin Panel</span>
        </a>
        <nav class="nav-links">
            <a href="dashboard.php">Overview</a>
            <a href="activities.php" class="active">Manage Activities</a>
            <a href="students.php">Student Directory</a>
            <a href="../dashboard.php" class="btn-portal" target="_blank">Student View &rarr;</a>
            <a href="../logout.php">Sign Out</a>
        </nav>
    </header>

    <main class="admin-container">
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1 style="font-size: 24px; font-weight: 700; color: #17243B; margin: 0 0 6px 0;">Activity Catalog Management</h1>
                <p style="color: #667085; font-size: 14px; margin: 0;">Add new hackathons, workshops, internships, and opportunities, or modify activity lifecycles.</p>
            </div>
            <button onclick="document.getElementById('addActivityForm').scrollIntoView({behavior: 'smooth'})" class="btn-submit">
                + Create Activity
            </button>
        </div>

        <?php if (!empty($feedback)): ?>
            <div class="alert-bar alert-<?= $feedbackType ?>">
                <?= e($feedback) ?>
            </div>
        <?php endif; ?>

        <!-- Filter Bar -->
        <form method="GET" class="filter-bar">
            <input type="text" name="q" placeholder="Search by title or organizer..." value="<?= e($searchQuery) ?>" style="flex: 1; min-width: 240px;">
            <select name="status">
                <option value="">All Statuses</option>
                <option value="3" <?= $filterStatus === 3 ? 'selected' : '' ?>>Published (Active)</option>
                <option value="1" <?= $filterStatus === 1 ? 'selected' : '' ?>>Draft</option>
                <option value="6" <?= $filterStatus === 6 ? 'selected' : '' ?>>Expired</option>
            </select>
            <button type="submit" style="background: #1769E8; color: #fff; padding: 10px 18px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">Filter</button>
            <?php if ($filterStatus !== null || $searchQuery !== ''): ?>
                <a href="activities.php" style="color: #667085; text-decoration: none; font-size: 13px; font-weight: 500;">Clear Filters</a>
            <?php endif; ?>
        </form>

        <!-- Activities Table -->
        <div class="admin-table-card" style="margin-bottom: 40px;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Activity Title</th>
                        <th>Category / Type</th>
                        <th>Organizer</th>
                        <th>Deadline</th>
                        <th>Status</th>
                        <th>Enrollments</th>
                        <th>Lifecycle Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($activities)): ?>
                        <tr><td colspan="8" style="text-align: center; color: #667085; padding: 32px;">No activities matched the criteria.</td></tr>
                    <?php else: ?>
                        <?php foreach ($activities as $a): ?>
                            <tr>
                                <td>#<?= (int)$a['activity_id'] ?></td>
                                <td>
                                    <strong><a href="../activity_details.php?id=<?= (int)$a['activity_id'] ?>" target="_blank" style="color: #17243B; text-decoration: none;"><?= e($a['title']) ?></a></strong><br>
                                    <span style="font-size: 12px; color: #667085;"><?= e($a['mode_name'] ?? 'Online') ?></span>
                                </td>
                                <td>
                                    <span style="font-weight: 600;"><?= e($a['category_name'] ?? 'General') ?></span><br>
                                    <span style="font-size: 12px; color: #667085;"><?= e($a['type_name'] ?? 'Activity') ?></span>
                                </td>
                                <td><?= e($a['organizer_name']) ?></td>
                                <td>
                                    <?php if (!empty($a['registration_deadline'])): ?>
                                        <?= date('M d, Y', strtotime($a['registration_deadline'])) ?>
                                    <?php else: ?>
                                        <span style="color: #98A2B3;">Rolling</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($a['activity_status_id'] == 3): ?>
                                        <span class="badge badge-published">Published</span>
                                    <?php elseif ($a['activity_status_id'] == 6): ?>
                                        <span class="badge badge-expired">Expired</span>
                                    <?php else: ?>
                                        <span class="badge badge-draft"><?= e($a['status_name']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= (int)$a['enrollment_count'] ?></strong> students
                                </td>
                                <td>
                                    <form method="POST" style="display: inline-flex; gap: 6px;">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="change_status">
                                        <input type="hidden" name="activity_id" value="<?= (int)$a['activity_id'] ?>">
                                        
                                        <?php if ($a['activity_status_id'] != 3): ?>
                                            <button type="submit" name="new_status_id" value="3" style="padding: 5px 10px; font-size: 11px; background: #ECFDF3; color: #027A48; border: 1px solid #A6F4C5; border-radius: 4px; cursor: pointer; font-weight: 600;">Publish</button>
                                        <?php endif; ?>

                                        <?php if ($a['activity_status_id'] != 6): ?>
                                            <button type="submit" name="new_status_id" value="6" style="padding: 5px 10px; font-size: 11px; background: #FEF3F2; color: #B42318; border: 1px solid #FECDCA; border-radius: 4px; cursor: pointer; font-weight: 600;">Expire</button>
                                        <?php endif; ?>

                                        <?php if ($a['activity_status_id'] != 1): ?>
                                            <button type="submit" name="new_status_id" value="1" style="padding: 5px 10px; font-size: 11px; background: #F2F4F7; color: #344054; border: 1px solid #D0D5DD; border-radius: 4px; cursor: pointer; font-weight: 600;">Draft</button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Add Activity Form -->
        <div class="modal-card" id="addActivityForm">
            <h2 style="font-size: 18px; font-weight: 700; color: #17243B; margin-top: 0; margin-bottom: 18px;">Publish New Activity Opportunity</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="create_activity">

                <div class="form-grid">
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Activity Title *</label>
                        <input type="text" name="title" required placeholder="e.g., National Full-Stack Open Source Sprint 2025">
                    </div>

                    <div class="form-group">
                        <label>Category *</label>
                        <select name="category_id" required>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['category_id'] ?>"><?= e($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Activity Type *</label>
                        <select name="activity_type_id" required>
                            <?php foreach ($types as $t): ?>
                                <option value="<?= (int)$t['activity_type_id'] ?>"><?= e($t['type_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Delivery Mode *</label>
                        <select name="mode_id" required>
                            <?php foreach ($modes as $m): ?>
                                <option value="<?= (int)$m['mode_id'] ?>"><?= e($m['mode_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Organizer Name *</label>
                        <input type="text" name="organizer_name" required placeholder="e.g., TechHub Collegiate Foundation">
                    </div>

                    <div class="form-group">
                        <label>Registration Deadline</label>
                        <input type="datetime-local" name="registration_deadline">
                    </div>

                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date">
                    </div>

                    <div class="form-group">
                        <label>Official Registration URL</label>
                        <input type="url" name="registration_url" placeholder="https://devpost.com/hackathon-example">
                    </div>

                    <div class="form-group">
                        <label>Publish Status</label>
                        <select name="activity_status_id">
                            <option value="3">Published (Active for students)</option>
                            <option value="1">Draft (Hidden from students)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 18px;">
                    <label>Short & Detailed Description *</label>
                    <textarea name="description" rows="4" required placeholder="Describe objectives, team size, evaluation criteria, and expected learning outcomes..."></textarea>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Eligibility Requirements</label>
                        <input type="text" name="eligibility_text" placeholder="e.g., Open to Computer Science & IT students">
                    </div>
                    <div class="form-group">
                        <label>Prizes & Benefits</label>
                        <input type="text" name="prize_or_benefits" placeholder="e.g., Certificate of Excellence, $2,000 in prizes, mentorship">
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; margin-top: 16px;">
                    <button type="submit" class="btn-submit">+ Save and Publish Opportunity</button>
                </div>
            </form>
        </div>

    </main>

</body>
</html>
