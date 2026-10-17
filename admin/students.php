<?php
/**
 * CareerAct — Admin Student Directory & Assessment Review
 */

declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';

$db = getDbConnection();
$csrfToken = getCsrfToken();
$feedback = '';
$feedbackType = 'info';

// Handle Student Actions: Toggle status or approve verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $feedback = 'Invalid session token. Please try again.';
        $feedbackType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'toggle_account_status') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $newStatusCode = $_POST['status_code'] ?? 'active';

            if ($userId > 0 && in_array($newStatusCode, ['active', 'suspended', 'locked'], true)) {
                $statusId = (int)$db->query("SELECT account_status_id FROM account_statuses WHERE status_code = '{$newStatusCode}' LIMIT 1")->fetchColumn();
                if ($statusId) {
                    $stmt = $db->prepare("UPDATE users SET account_status_id = :st WHERE user_id = :id");
                    $stmt->execute([':st' => $statusId, ':id' => $userId]);
                    $feedback = "User account #{$userId} status changed to {$newStatusCode}.";
                    $feedbackType = 'success';
                }
            }
        } elseif ($action === 'verify_enrollment') {
            $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
            $ver = $_POST['verification'] ?? 'verified';
            if ($enrollmentId > 0 && in_array($ver, ['verified', 'rejected'], true)) {
                $stmt = $db->prepare("
                    UPDATE student_activity_enrollments
                    SET completion_verification = :ver, updated_at = NOW()
                    WHERE enrollment_id = :id
                ");
                $stmt->execute([':ver' => $ver, ':id' => $enrollmentId]);
                $feedback = "Enrollment #{$enrollmentId} marked as {$ver}.";
                $feedbackType = 'success';
            }
        }
    }
}

// Search and Filtering
$searchQuery = trim((string)($_GET['q'] ?? ''));
$filterPath  = isset($_GET['path_id']) && $_GET['path_id'] !== '' ? (int)$_GET['path_id'] : null;

$sql = "
    SELECT 
        u.user_id,
        u.email,
        ast.status_code,
        ast.status_name,
        sp.student_id,
        sp.full_name,
        sp.college_name,
        sp.degree_name,
        sp.branch_name,
        sp.academic_year,
        sp.profile_completed_at,
        cp.career_title,
        cp.career_id,
        (SELECT readiness_score FROM student_assessment_records sar WHERE sar.student_id = sp.student_id ORDER BY sar.assessment_id DESC LIMIT 1) as latest_score,
        (SELECT decision_path FROM student_assessment_records sar WHERE sar.student_id = sp.student_id ORDER BY sar.assessment_id DESC LIMIT 1) as latest_path,
        (SELECT COUNT(*) FROM student_activity_enrollments sae WHERE sae.student_id = sp.student_id) as enrollment_count,
        (SELECT COUNT(*) FROM student_activity_enrollments sae WHERE sae.student_id = sp.student_id AND sae.completion_status = 'completed') as completion_count
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    LEFT JOIN account_statuses ast ON u.account_status_id = ast.account_status_id
    LEFT JOIN student_profiles sp ON u.user_id = sp.user_id
    LEFT JOIN student_career_goals scg ON sp.student_id = scg.student_id AND scg.is_active = 1
    LEFT JOIN career_paths cp ON scg.career_id = cp.career_id
    WHERE r.role_code = 'student'
";
$params = [];

if ($searchQuery !== '') {
    $sql .= " AND (sp.full_name LIKE :q OR u.email LIKE :q OR sp.college_name LIKE :q)";
    $params[':q'] = "%{$searchQuery}%";
}
if ($filterPath !== null) {
    $sql .= " AND cp.career_id = :path_id";
    $params[':path_id'] = $filterPath;
}

$sql .= " ORDER BY u.user_id DESC LIMIT 100";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Career Paths for filter
$careerPaths = $db->query("SELECT career_id, career_title FROM career_paths ORDER BY career_title")->fetchAll();

// If inspecting a specific student
$inspectStudent = null;
$studentProjects = [];
$studentEnrollments = [];

if (isset($_GET['student_id'])) {
    $inspectId = (int)$_GET['student_id'];
    $stmtProj = $db->prepare("SELECT * FROM student_projects WHERE student_id = :id ORDER BY project_id DESC");
    $stmtProj->execute([':id' => $inspectId]);
    $studentProjects = $stmtProj->fetchAll();

    $stmtEnr = $db->prepare("
        SELECT 
            sae.*,
            a.title as activity_title,
            at.type_name
        FROM student_activity_enrollments sae
        JOIN activities a ON sae.activity_id = a.activity_id
        LEFT JOIN activity_types at ON a.activity_type_id = at.activity_type_id
        WHERE sae.student_id = :id
        ORDER BY sae.enrollment_id DESC
    ");
    $stmtEnr->execute([':id' => $inspectId]);
    $studentEnrollments = $stmtEnr->fetchAll();

    foreach ($students as $st) {
        if ((int)$st['student_id'] === $inspectId) {
            $inspectStudent = $st;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Directory & Portfolios — CareerAct Admin</title>
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
        .admin-table-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #E4EAF2;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            margin-bottom: 32px;
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
        .badge-path-a { background: #ECFDF3; color: #027A48; border: 1px solid #A6F4C5; }
        .badge-path-b { background: #EEF3FF; color: #1769E8; border: 1px solid #D1E0FF; }
        .badge-path-c { background: #FFFAEB; color: #B54708; border: 1px solid #FEDF89; }
        .badge-active { background: #ECFDF3; color: #027A48; }
        .badge-suspended { background: #FEF3F2; color: #B42318; }
        .modal-drawer {
            background: #fff;
            border: 2px solid #1769E8;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 32px;
            box-shadow: 0 8px 30px rgba(23,105,232,0.1);
        }
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
            <a href="activities.php">Manage Activities</a>
            <a href="students.php" class="active">Student Directory</a>
            <a href="../dashboard.php" class="btn-portal" target="_blank">Student View &rarr;</a>
            <a href="../logout.php">Sign Out</a>
        </nav>
    </header>

    <main class="admin-container">
        
        <div style="margin-bottom: 24px;">
            <h1 style="font-size: 24px; font-weight: 700; color: #17243B; margin: 0 0 6px 0;">Student Directory & Activity Records</h1>
            <p style="color: #667085; font-size: 14px; margin: 0;">Review enrolled candidates, their target career goals, assessment scores, and submitted extracurricular achievements.</p>
        </div>

        <?php if (!empty($feedback)): ?>
            <div class="alert-bar alert-<?= $feedbackType ?>">
                <?= e($feedback) ?>
            </div>
        <?php endif; ?>

        <!-- Student Deep-Dive Drawer (When Selected) -->
        <?php if ($inspectStudent): ?>
            <div class="modal-drawer">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px; border-bottom: 1px solid #E4EAF2; padding-bottom: 14px;">
                    <div>
                        <h2 style="font-size: 20px; font-weight: 700; color: #17243B; margin: 0 0 4px 0;"><?= e($inspectStudent['full_name']) ?></h2>
                        <p style="color: #667085; font-size: 13px; margin: 0;">
                            <?= e($inspectStudent['email']) ?> &bull; <?= e($inspectStudent['college_name'] ?? 'College') ?> &bull; <?= e($inspectStudent['degree_name'] ?? '') ?> (Year <?= (int)$inspectStudent['academic_year'] ?>)
                        </p>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <span class="badge badge-<?= $inspectStudent['latest_path'] === 'path_a' ? 'path-a' : ($inspectStudent['latest_path'] === 'path_c' ? 'path-c' : 'path-b') ?>">
                            Score: <?= (int)$inspectStudent['latest_score'] ?>% (<?= strtoupper(str_replace('_', ' ', $inspectStudent['latest_path'] ?? 'path_b')) ?>)
                        </span>
                        <a href="students.php" style="padding: 6px 12px; background: #F3F6FA; color: #475467; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600;">Close Review &times;</a>
                    </div>
                </div>

                <!-- Projects submitted -->
                <h3 style="font-size: 15px; font-weight: 700; color: #17243B; margin: 16px 0 10px 0;">Portfolio Projects & Evidence (<?= count($studentProjects) ?>)</h3>
                <?php if (empty($studentProjects)): ?>
                    <p style="font-size: 13px; color: #667085;">No specific project records submitted yet.</p>
                <?php else: ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; margin-bottom: 20px;">
                        <?php foreach ($studentProjects as $proj): ?>
                            <div style="background: #F8FAFC; border: 1px solid #E4EAF2; border-radius: 8px; padding: 14px;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                                    <strong><?= e($proj['project_title']) ?></strong>
                                    <span style="font-size: 11px; background: #EEF3FF; color: #1769E8; padding: 2px 6px; border-radius: 4px;">Project</span>
                                </div>
                                <p style="font-size: 12px; color: #475467; margin: 4px 0; line-height: 1.4;">
                                    <?= e($proj['description'] ?? 'No description provided') ?>
                                </p>
                                <?php if (!empty($proj['project_url'])): ?>
                                    <a href="<?= e($proj['project_url']) ?>" target="_blank" style="font-size: 12px; color: #1769E8; font-weight: 600; text-decoration: none;">View Project Link &nearr;</a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Enrolled & Completed Activities -->
                <h3 style="font-size: 15px; font-weight: 700; color: #17243B; margin: 16px 0 10px 0;">Platform Activity Enrollments (<?= count($studentEnrollments) ?>)</h3>
                <?php if (empty($studentEnrollments)): ?>
                    <p style="font-size: 13px; color: #667085;">Student has not enrolled in any platform activities yet.</p>
                <?php else: ?>
                    <table class="admin-table" style="border: 1px solid #E4EAF2; border-radius: 8px; margin-top: 8px;">
                        <thead>
                            <tr>
                                <th>Activity</th>
                                <th>Status</th>
                                <th>Enrolled Date</th>
                                <th>Reflection & Skills</th>
                                <th>Evidence URL</th>
                                <th>Verification Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($studentEnrollments as $enr): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($enr['activity_title']) ?></strong><br>
                                        <span style="font-size: 11px; color: #667085;"><?= e($enr['type_name'] ?? 'Activity') ?></span>
                                    </td>
                                    <td>
                                        <?php if ($enr['completion_status'] === 'completed'): ?>
                                            <span style="color: #027A48; font-weight: 600; font-size: 12px;">Completed</span>
                                        <?php else: ?>
                                            <span style="color: #1769E8; font-weight: 600; font-size: 12px;">Enrolled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('M d, Y', strtotime($enr['enrolled_at'])) ?></td>
                                    <td style="max-width: 250px; font-size: 12px; line-height: 1.4;">
                                        <?= e($enr['student_reflection'] ? mb_strimwidth($enr['student_reflection'], 0, 80, '...') : 'No reflection') ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($enr['proof_url'])): ?>
                                            <a href="<?= e($enr['proof_url']) ?>" target="_blank" style="color: #1769E8; font-size: 12px; font-weight: 600;">View Proof &nearr;</a>
                                        <?php else: ?>
                                            <span style="font-size: 12px; color: #98A2B3;">No link</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($enr['completion_status'] === 'completed'): ?>
                                            <?php if ($enr['completion_verification'] === 'verified'): ?>
                                                <span class="badge badge-active" style="font-size: 11px;">Verified</span>
                                            <?php else: ?>
                                                <form method="POST" style="display: inline-flex; gap: 4px;">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="verify_enrollment">
                                                    <input type="hidden" name="enrollment_id" value="<?= (int)$enr['enrollment_id'] ?>">
                                                    <button type="submit" name="verification" value="verified" style="padding: 4px 8px; font-size: 11px; background: #0F9D8A; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: 600;">Verify</button>
                                                </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="font-size: 12px; color: #98A2B3;">In progress</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Search & Filter Bar -->
        <form method="GET" class="filter-bar">
            <input type="text" name="q" placeholder="Search by student name, email, or college..." value="<?= e($searchQuery) ?>" style="flex: 1; min-width: 260px;">
            <select name="path_id">
                <option value="">All Career Paths</option>
                <?php foreach ($careerPaths as $cp): ?>
                    <option value="<?= (int)$cp['path_id'] ?>" <?= $filterPath === (int)$cp['path_id'] ? 'selected' : '' ?>>
                        <?= e($cp['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" style="background: #1769E8; color: #fff; padding: 10px 18px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">Filter</button>
            <?php if ($filterPath !== null || $searchQuery !== ''): ?>
                <a href="students.php" style="color: #667085; text-decoration: none; font-size: 13px; font-weight: 500;">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Students Table -->
        <div class="admin-table-card">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Candidate</th>
                        <th>College / Academic Info</th>
                        <th>Career Goal</th>
                        <th>Readiness Score</th>
                        <th>Engagements</th>
                        <th>Account Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                        <tr><td colspan="7" style="text-align: center; color: #667085; padding: 32px;">No students found matching your criteria.</td></tr>
                    <?php else: ?>
                        <?php foreach ($students as $st): ?>
                            <tr>
                                <td>
                                    <strong><?= e($st['full_name'] ?? 'Candidate') ?></strong><br>
                                    <span style="font-size: 12px; color: #667085;"><?= e($st['email']) ?></span>
                                </td>
                                <td>
                                    <span style="font-weight: 600;"><?= e($st['college_name'] ?? 'Not completed') ?></span><br>
                                    <span style="font-size: 12px; color: #667085;"><?= e($st['degree_name'] ?? '') ?> &bull; Year <?= (int)$st['academic_year'] ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($st['career_title'])): ?>
                                        <span style="font-weight: 600; color: #163A70;"><?= e($st['career_title']) ?></span>
                                    <?php else: ?>
                                        <span style="color: #98A2B3; font-style: italic;">No goal set</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($st['latest_score'] !== null): ?>
                                        <span class="badge badge-<?= $st['latest_path'] === 'path_a' ? 'path-a' : ($st['latest_path'] === 'path_c' ? 'path-c' : 'path-b') ?>">
                                            <?= (int)$st['latest_score'] ?>% (<?= strtoupper(str_replace('_', ' ', $st['latest_path'] ?? 'path_b')) ?>)
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #98A2B3; font-size: 12px;">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= (int)$st['enrollment_count'] ?></strong> enrolled &bull; <strong><?= (int)$st['completion_count'] ?></strong> done
                                </td>
                                <td>
                                    <span class="badge badge-<?= $st['status_code'] === 'active' ? 'active' : 'suspended' ?>">
                                        <?= ucfirst(e($st['status_code'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 6px;">
                                        <a href="students.php?student_id=<?= (int)$st['student_id'] ?>" style="padding: 6px 12px; background: #EEF3FF; color: #1769E8; font-size: 12px; font-weight: 600; text-decoration: none; border-radius: 6px;">Review Portfolio &rarr;</a>
                                        
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="toggle_account_status">
                                            <input type="hidden" name="user_id" value="<?= (int)$st['user_id'] ?>">
                                            <?php if ($st['status_code'] === 'active'): ?>
                                                <button type="submit" name="status_code" value="suspended" style="padding: 6px 10px; background: #FEF3F2; color: #B42318; border: 1px solid #FECDCA; font-size: 11px; font-weight: 600; border-radius: 6px; cursor: pointer;" title="Suspend Account">Suspend</button>
                                            <?php else: ?>
                                                <button type="submit" name="status_code" value="active" style="padding: 6px 10px; background: #ECFDF3; color: #027A48; border: 1px solid #A6F4C5; font-size: 11px; font-weight: 600; border-radius: 6px; cursor: pointer;" title="Activate Account">Activate</button>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>

</body>
</html>
