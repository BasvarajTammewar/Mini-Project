<?php
/**
 * CareerAct — Admin Dashboard & Platform Overview
 */

declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';

$db = getDbConnection();
$adminEmail = $_SESSION['email'] ?? 'admin@careeract.edu';
$csrfToken = getCsrfToken();

// Handle quick approval/rejection of evidence from dashboard
$feedback = '';
$feedbackType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $feedback = 'Invalid session token. Please try again.';
        $feedbackType = 'danger';
    } else {
        $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
        $action = $_POST['action'];

        if ($enrollmentId > 0 && in_array($action, ['verify_evidence', 'reject_evidence'], true)) {
            $newVerification = ($action === 'verify_evidence') ? 'verified' : 'rejected';
            $stmt = $db->prepare("
                UPDATE student_activity_enrollments
                SET completion_verification = :ver,
                    updated_at = NOW()
                WHERE enrollment_id = :id
            ");
            $stmt->execute([
                ':ver' => $newVerification,
                ':id'  => $enrollmentId
            ]);
            $feedback = ($newVerification === 'verified') 
                ? "Enrollment #{$enrollmentId} evidence successfully verified and marked verified!" 
                : "Enrollment #{$enrollmentId} evidence was rejected.";
            $feedbackType = ($newVerification === 'verified') ? 'success' : 'warning';
        }
    }
}

// 1. Fetch Platform Stats
$totalStudents = (int)$db->query("
    SELECT COUNT(*) FROM users u 
    JOIN roles r ON u.role_id = r.role_id 
    WHERE r.role_code = 'student'
")->fetchColumn();

$totalActivities = (int)$db->query("SELECT COUNT(*) FROM activities")->fetchColumn();
$publishedActivities = (int)$db->query("SELECT COUNT(*) FROM activities WHERE activity_status_id = 3")->fetchColumn();
$expiredActivities = (int)$db->query("SELECT COUNT(*) FROM activities WHERE activity_status_id = 6")->fetchColumn();
$totalEnrollments = (int)$db->query("SELECT COUNT(*) FROM student_activity_enrollments")->fetchColumn();
$completedActivities = (int)$db->query("SELECT COUNT(*) FROM student_activity_enrollments WHERE completion_status = 'completed'")->fetchColumn();
$pendingVerifications = (int)$db->query("
    SELECT COUNT(*) FROM student_activity_enrollments 
    WHERE completion_status = 'completed' AND completion_verification = 'pending'
")->fetchColumn();

// 2. Fetch Pending Evidence Submissions
$stmtPending = $db->query("
    SELECT 
        e.enrollment_id,
        e.student_id,
        e.completed_at,
        e.proof_url,
        e.certificate_reference,
        e.student_reflection,
        e.skills_gained_text,
        sp.full_name as student_name,
        sp.college_name,
        a.title as activity_title,
        at.type_name
    FROM student_activity_enrollments e
    JOIN student_profiles sp ON e.student_id = sp.student_id
    JOIN activities a ON e.activity_id = a.activity_id
    LEFT JOIN activity_types at ON a.activity_type_id = at.activity_type_id
    WHERE e.completion_status = 'completed' AND e.completion_verification = 'pending'
    ORDER BY e.completed_at DESC
    LIMIT 10
");
$pendingList = $stmtPending->fetchAll();

// 3. Fetch Recent Enrollments
$stmtRecent = $db->query("
    SELECT 
        e.enrollment_id,
        e.enrolled_at,
        e.completion_status,
        sp.full_name as student_name,
        a.title as activity_title,
        es.status_name as enrollment_status_name
    FROM student_activity_enrollments e
    JOIN student_profiles sp ON e.student_id = sp.student_id
    JOIN activities a ON e.activity_id = a.activity_id
    LEFT JOIN enrollment_statuses es ON e.enrollment_status_id = es.enrollment_status_id
    ORDER BY e.enrolled_at DESC
    LIMIT 8
");
$recentEnrollments = $stmtRecent->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — CareerAct Management Console</title>
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
        .admin-nav .nav-links a.btn-portal {
            background: #1769E8;
            color: #fff;
            font-weight: 600;
        }
        .admin-container {
            max-width: 1300px;
            margin: 32px auto;
            padding: 0 24px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: #fff;
            border: 1px solid #E4EAF2;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
        }
        .stat-card .label {
            font-size: 13px;
            color: #667085;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .stat-card .value {
            font-size: 32px;
            font-weight: 700;
            color: #17243B;
            margin-bottom: 4px;
        }
        .stat-card .subtext {
            font-size: 13px;
            color: #0F9D8A;
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .section-header h2 {
            font-size: 18px;
            font-weight: 700;
            color: #17243B;
            margin: 0;
        }
        .admin-table-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #E4EAF2;
            overflow: hidden;
            margin-bottom: 32px;
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
        .admin-table tr:hover {
            background: #F8FBFF;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-pending { background: #FEF3F2; color: #B42318; border: 1px solid #FECDCA; }
        .badge-verified { background: #ECFDF3; color: #027A48; border: 1px solid #A6F4C5; }
        .badge-enrolled { background: #EEF3FF; color: #1769E8; border: 1px solid #D1E0FF; }
        .action-btn-group {
            display: flex;
            gap: 8px;
        }
        .btn-sm {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            text-decoration: none;
        }
        .btn-approve { background: #0F9D8A; color: #fff; }
        .btn-reject { background: #D92D20; color: #fff; }
        .btn-secondary { background: #F3F6FA; color: #475467; border: 1px solid #D0D5DD; }
        .alert-bar {
            padding: 14px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
        }
        .alert-success { background: #ECFDF3; color: #027A48; border: 1px solid #A6F4C5; }
        .alert-warning { background: #FFFAEB; color: #B54708; border: 1px solid #FEDF89; }
        .alert-danger { background: #FEF3F2; color: #B42318; border: 1px solid #FECDCA; }
    </style>
</head>
<body>

    <!-- Admin Navigation Header -->
    <header class="admin-nav">
        <a href="dashboard.php" class="brand">
            CareerAct
            <span class="brand-badge">Admin Panel</span>
        </a>
        <nav class="nav-links">
            <a href="dashboard.php" class="active">Overview</a>
            <a href="activities.php">Manage Activities</a>
            <a href="students.php">Student Directory</a>
            <a href="../dashboard.php" class="btn-portal" target="_blank">Student View &rarr;</a>
            <a href="../logout.php">Sign Out</a>
        </nav>
    </header>

    <main class="admin-container">
        
        <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="font-size: 26px; font-weight: 700; color: #17243B; margin: 0 0 6px 0;">Platform Administration</h1>
                <p style="color: #667085; font-size: 14px; margin: 0;">Welcome, <strong><?= e($adminEmail) ?></strong>. Manage extracurricular catalog, verify student achievement records, and monitor engagement.</p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="activities.php?action=create" class="btn btn-primary" style="text-decoration: none; padding: 10px 18px; border-radius: 8px; background: #1769E8; color: #fff; font-weight: 600; font-size: 14px;">+ Add Activity</a>
                <a href="students.php" class="btn btn-secondary" style="text-decoration: none; padding: 10px 18px; border-radius: 8px; font-weight: 600; font-size: 14px;">Review Students</a>
            </div>
        </div>

        <?php if (!empty($feedback)): ?>
            <div class="alert-bar alert-<?= $feedbackType ?>">
                <?= e($feedback) ?>
            </div>
        <?php endif; ?>

        <!-- Key Metrics Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="label">Total Students</span>
                <span class="value"><?= $totalStudents ?></span>
                <span class="subtext">Registered candidates</span>
            </div>
            <div class="stat-card">
                <span class="label">Total Activities</span>
                <span class="value"><?= $totalActivities ?></span>
                <span class="subtext"><?= $publishedActivities ?> active &bull; <?= $expiredActivities ?> expired</span>
            </div>
            <div class="stat-card">
                <span class="label">Student Enrollments</span>
                <span class="value"><?= $totalEnrollments ?></span>
                <span class="subtext"><?= $completedActivities ?> completed</span>
            </div>
            <div class="stat-card" style="border-left: 4px solid <?= $pendingVerifications > 0 ? '#D92D20' : '#0F9D8A' ?>;">
                <span class="label">Pending Verifications</span>
                <span class="value" style="color: <?= $pendingVerifications > 0 ? '#D92D20' : '#0F9D8A' ?>;"><?= $pendingVerifications ?></span>
                <span class="subtext"><?= $pendingVerifications > 0 ? 'Action required' : 'All verified' ?></span>
            </div>
        </div>

        <!-- Pending Evidence Submissions -->
        <div class="section-header">
            <h2>Pending Student Activity Verifications (<?= count($pendingList) ?>)</h2>
            <span style="font-size: 13px; color: #667085;">Evidence and reflections submitted by students</span>
        </div>

        <div class="admin-table-card">
            <?php if (empty($pendingList)): ?>
                <div style="padding: 40px; text-align: center; color: #667085;">
                    <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="#0F9D8A" style="margin-bottom: 12px;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p style="font-size: 15px; font-weight: 600; color: #17243B; margin-bottom: 4px;">No Pending Verifications</p>
                    <p style="font-size: 13px; margin: 0;">All completed student activities and evidence submissions have been reviewed.</p>
                </div>
            <?php else: ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Activity</th>
                            <th>Completed Date</th>
                            <th>Submitted Evidence</th>
                            <th>Student Reflection</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingList as $item): ?>
                            <tr>
                                <td>
                                    <strong><?= e($item['student_name']) ?></strong><br>
                                    <span style="font-size: 12px; color: #667085;"><?= e($item['college_name'] ?? 'College') ?></span>
                                </td>
                                <td>
                                    <span style="font-weight: 600;"><?= e($item['activity_title']) ?></span><br>
                                    <span style="font-size: 12px; color: #667085;"><?= e($item['type_name'] ?? 'Activity') ?></span>
                                </td>
                                <td>
                                    <?= date('M d, Y', strtotime($item['completed_at'])) ?>
                                </td>
                                <td>
                                    <?php if (!empty($item['proof_url'])): ?>
                                        <a href="<?= e($item['proof_url']) ?>" target="_blank" style="color: #1769E8; font-weight: 600; text-decoration: none; font-size: 13px;">View Proof URL &nearr;</a><br>
                                    <?php endif; ?>
                                    <?php if (!empty($item['certificate_reference'])): ?>
                                        <span style="font-size: 12px; color: #475467;">Cert: <?= e($item['certificate_reference']) ?></span>
                                    <?php endif; ?>
                                    <?php if (empty($item['proof_url']) && empty($item['certificate_reference'])): ?>
                                        <span style="font-size: 12px; color: #98A2B3;">Self-reported reflection only</span>
                                    <?php endif; ?>
                                </td>
                                <td style="max-width: 280px; font-size: 13px; color: #475467; line-height: 1.4;">
                                    <?= e($item['student_reflection'] ? mb_strimwidth($item['student_reflection'], 0, 100, '...') : 'No reflection entered.') ?>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline-flex; gap: 6px;">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="enrollment_id" value="<?= (int)$item['enrollment_id'] ?>">
                                        <button type="submit" name="action" value="verify_evidence" class="btn-sm btn-approve" title="Approve & Mark Verified">Approve</button>
                                        <button type="submit" name="action" value="reject_evidence" class="btn-sm btn-reject" title="Reject Submission">Reject</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Recent Activity Enrollments -->
        <div class="section-header">
            <h2>Recent Activity Enrollments</h2>
            <a href="students.php" style="font-size: 13px; color: #1769E8; font-weight: 600; text-decoration: none;">View All &rarr;</a>
        </div>

        <div class="admin-table-card">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Enrollment ID</th>
                        <th>Student Name</th>
                        <th>Activity Title</th>
                        <th>Status</th>
                        <th>Enrolled At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentEnrollments)): ?>
                        <tr><td colspan="5" style="text-align: center; color: #667085; padding: 24px;">No enrollments recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentEnrollments as $r): ?>
                            <tr>
                                <td>#<?= (int)$r['enrollment_id'] ?></td>
                                <td><strong><?= e($r['student_name']) ?></strong></td>
                                <td><?= e($r['activity_title']) ?></td>
                                <td>
                                    <?php if ($r['completion_status'] === 'completed'): ?>
                                        <span class="badge badge-verified">Completed</span>
                                    <?php else: ?>
                                        <span class="badge badge-enrolled"><?= e($r['enrollment_status_name'] ?? 'Enrolled') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('M d, Y h:i A', strtotime($r['enrolled_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>

</body>
</html>
