<?php
/**
 * CareerAct — My Enrollments Module
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';

$userId = (int)$_SESSION['user_id'];
$db = getDbConnection();

// Get student_id
$stmtStudent = $db->prepare("SELECT student_id FROM student_profiles WHERE user_id = :user_id LIMIT 1");
$stmtStudent->execute([':user_id' => $userId]);
$student = $stmtStudent->fetch();
$studentId = $student ? (int)$student['student_id'] : 0;

$successMsg = '';

// Handle Completion Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_completed_id'])) {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $enrollmentId = (int)$_POST['mark_completed_id'];
        $stmtUpdate = $db->prepare("
            UPDATE student_activity_enrollments 
            SET completion_status = 'completed', 
                enrollment_status_id = 5,
                completion_verification = 'verified',
                completed_at = NOW()
            WHERE enrollment_id = :eid AND student_id = :sid
        ");
        $stmtUpdate->execute([':eid' => $enrollmentId, ':sid' => $studentId]);
        $successMsg = 'Activity marked as completed & verified! Your dashboard stats have been updated.';
    }
}

// Fetch Student Enrollments
$stmt = $db->prepare("
    SELECT 
        sae.enrollment_id,
        sae.completion_status,
        sae.completion_verification,
        sae.enrolled_at,
        sae.completed_at,
        a.title,
        a.description,
        t.type_name,
        m.mode_name,
        COALESCE(o.organizer_name, 'Campus Organizer') AS organizer_name
    FROM student_activity_enrollments sae
    JOIN activities a ON sae.activity_id = a.activity_id
    JOIN activity_types t ON a.activity_type_id = t.activity_type_id
    JOIN activity_modes m ON a.mode_id = m.mode_id
    LEFT JOIN organizers o ON a.organizer_id = o.organizer_id
    WHERE sae.student_id = :student_id
    ORDER BY sae.completion_status ASC, sae.enrolled_at DESC
");
$stmt->execute([':student_id' => $studentId]);
$enrollments = $stmt->fetchAll();

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Enrollments — CareerAct</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body class="dashboard-body">

    <!-- Sidebar Navigation -->
    <aside class="dashboard-sidebar" aria-label="Portal Navigation">
        <div>
            <div class="sidebar-header">
                <a href="dashboard.php" class="sidebar-brand-wrapper">
                    <div class="sidebar-brand-icon"><svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg></div>
                    <div class="sidebar-brand-text">
                        <span class="sidebar-brand-name">CareerAct</span>
                        <span class="sidebar-brand-subtitle">STUDENT PORTAL</span>
                    </div>
                </a>
            </div>
            <nav>
                <ul class="sidebar-nav">
                    <li><a href="dashboard.php" class="nav-link"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect></svg><span>Dashboard</span></a></li>
                    <li><a href="activities.php" class="nav-link"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg><span>Explore Activities</span></a></li>
                    <li><a href="career_goals.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path><line x1="4" y1="22" x2="4" y2="15"></line></svg><span>Career Goals</span></a></li>
                    <li><a href="skills_assessment.php" class="nav-link"><svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg><span>Skills Assessment</span></a></li>
                    <li><a href="enrollments.php" class="nav-link active" aria-current="page"><svg viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg><span>My Enrollments</span></a></li>
                    <li><a href="resume.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg><span>My Resume</span></a></li>
                    <li><a href="profile.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><span>My Profile</span></a></li>
                </ul>
            </nav>
        </div>
        <div class="sidebar-footer">
            <a href="logout.php" class="logout-link"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg><span>Logout</span></a>
        </div>
    </aside>

    <div class="dashboard-main">
        <header class="dashboard-topbar">
            <div class="topbar-welcome">
                <span class="welcome-title">My Enrollments</span>
                <span class="welcome-subtitle">Track registered, in-progress, and verified extracurricular experiences</span>
            </div>
            <div class="topbar-actions">
                <a href="dashboard.php" class="btn-view-details">← Back to Dashboard</a>
            </div>
        </header>

        <main class="dashboard-content">
            <?php if (!empty($successMsg)): ?>
                <div style="background: #ECFDF5; border: 1px solid #A7F3D0; color: #065F46; padding: 14px 18px; border-radius: var(--radius-sm); margin-bottom: 20px; font-weight: 500;">
                    <?= e($successMsg) ?>
                </div>
            <?php endif; ?>

            <div>
                <?php if (!empty($enrollments)): ?>
                    <?php foreach ($enrollments as $item): ?>
                        <?php $isComp = ($item['completion_status'] === 'completed'); ?>
                        <article class="activity-card">
                            <div class="activity-icon-box <?= $isComp ? 'green' : 'blue' ?>" style="<?= $isComp ? 'background: #ECFDF5; color: #0F9D8A;' : '' ?>">
                                <?php if ($isComp): ?>
                                    <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                <?php else: ?>
                                    <svg viewBox="0 0 24 24"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path></svg>
                                <?php endif; ?>
                            </div>

                            <div class="activity-card-body">
                                <div class="activity-badges-row">
                                    <span class="activity-pill type"><?= e($item['type_name']) ?></span>
                                    <?php if ($isComp): ?>
                                        <span class="activity-pill match">✓ Completed & Verified</span>
                                    <?php else: ?>
                                        <span class="activity-pill deadline" style="background: #EEF3FF; color: #1769E8;">⏳ In Progress</span>
                                    <?php endif; ?>
                                </div>

                                <h2 class="activity-title" style="font-size: 17px;"><?= e($item['title']) ?></h2>
                                <p style="font-size: 12.5px; color: var(--secondary-text); margin: 2px 0 8px;">
                                    Organized by <strong><?= e($item['organizer_name']) ?></strong> • <?= e($item['mode_name']) ?>
                                </p>
                                <span style="font-size: 11.5px; color: #94A3B8;">
                                    Enrolled: <?= date('M j, Y', strtotime($item['enrolled_at'])) ?>
                                    <?php if (!empty($item['completed_at'])): ?>
                                        • Completed: <?= date('M j, Y', strtotime($item['completed_at'])) ?>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div class="activity-card-action">
                                <?php if (!$isComp): ?>
                                    <form method="POST" action="enrollments.php">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="mark_completed_id" value="<?= (int)$item['enrollment_id'] ?>">
                                        <button type="submit" class="btn-view-details" style="background: #0F9D8A; color: #FFF; border-color: #0F9D8A;">
                                            Mark Completed
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size: 12px; font-weight: 700; color: #0F9D8A;">
                                        Verified on Transcript
                                    </span>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-panel">
                        <p style="font-weight: 600; font-size: 15px;">You have no enrolled activities yet.</p>
                        <p style="font-size: 13px; margin: 6px 0 16px;">Enroll in hackathons, workshops, or contests to start tracking your progress.</p>
                        <a href="activities.php" class="btn-view-pathway" style="display: inline-flex;">Explore Opportunities</a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
