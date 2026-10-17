<?php
/**
 * CareerAct — Activity Details & Roadmap Relevance Module
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';

$userId = (int)$_SESSION['user_id'];
$db = getDbConnection();

// Fetch student profile
$stmtStudent = $db->prepare("SELECT student_id, full_name FROM student_profiles WHERE user_id = :user_id LIMIT 1");
$stmtStudent->execute([':user_id' => $userId]);
$student = $stmtStudent->fetch();
$studentId = $student ? (int)$student['student_id'] : 0;

$activityId = (int)($_GET['id'] ?? 0);
if ($activityId <= 0) {
    header('Location: activities.php');
    exit;
}

$successMsg = '';
$errorMsg = '';

// Handle Enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_enroll'])) {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $stmtChk = $db->prepare("SELECT enrollment_id FROM student_activity_enrollments WHERE student_id = :sid AND activity_id = :aid");
        $stmtChk->execute([':sid' => $studentId, ':aid' => $activityId]);
        if ($stmtChk->fetch()) {
            $errorMsg = 'You are already enrolled in this activity.';
        } else {
            $ins = $db->prepare("
                INSERT INTO student_activity_enrollments (student_id, activity_id, enrollment_status_id, completion_status, enrolled_at)
                VALUES (:sid, :aid, 2, 'in_progress', NOW())
            ");
            $ins->execute([':sid' => $studentId, ':aid' => $activityId]);
            $successMsg = 'Successfully enrolled in this activity! It is now tracked on your dashboard.';
        }
    }
}

// Fetch Activity Details
$stmtAct = $db->prepare("
    SELECT 
        a.*,
        t.type_name,
        m.mode_name,
        COALESCE(o.organizer_name, 'Campus Organizer') AS organizer_name,
        o.website_url AS organizer_website
    FROM activities a
    JOIN activity_types t ON a.activity_type_id = t.activity_type_id
    JOIN activity_modes m ON a.mode_id = m.mode_id
    LEFT JOIN organizers o ON a.organizer_id = o.organizer_id
    WHERE a.activity_id = :aid
    LIMIT 1
");
$stmtAct->execute([':aid' => $activityId]);
$activity = $stmtAct->fetch();

if (!$activity) {
    header('Location: activities.php');
    exit;
}

// Check Enrollment Status
$stmtEnroll = $db->prepare("SELECT * FROM student_activity_enrollments WHERE student_id = :sid AND activity_id = :aid LIMIT 1");
$stmtEnroll->execute([':sid' => $studentId, ':aid' => $activityId]);
$enrollment = $stmtEnroll->fetch();

// Fetch Skills Tagged to this Activity
$stmtSkills = $db->prepare("
    SELECT s.skill_name 
    FROM activity_skills ask
    JOIN skills s ON ask.skill_id = s.skill_id
    WHERE ask.activity_id = :aid
");
$stmtSkills->execute([':aid' => $activityId]);
$activitySkills = $stmtSkills->fetchAll(PDO::FETCH_COLUMN);

// Fetch Student's Active Career Goal for Relevance Context
$stmtGoal = $db->prepare("
    SELECT cp.career_title 
    FROM student_career_goals scg 
    JOIN career_paths cp ON scg.career_id = cp.career_id 
    WHERE scg.student_id = :sid AND scg.is_active = 1 LIMIT 1
");
$stmtGoal->execute([':sid' => $studentId]);
$careerTitle = $stmtGoal->fetchColumn() ?: 'Target Track';

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($activity['title']) ?> — CareerAct</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        .details-container {
            max-width: 900px;
        }
        .details-card {
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 32px;
            box-shadow: var(--card-shadow);
            margin-bottom: 24px;
        }
        .relevance-callout {
            background: #EFF6FF;
            border: 1px solid #BFDBFE;
            border-radius: var(--radius-md);
            padding: 18px 20px;
            margin: 20px 0;
            color: #1E40AF;
        }
    </style>
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
                    <li><a href="activities.php" class="nav-link active"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg><span>Explore Activities</span></a></li>
                    <li><a href="career_goals.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path><line x1="4" y1="22" x2="4" y2="15"></line></svg><span>Career Goals</span></a></li>
                    <li><a href="skills_assessment.php" class="nav-link"><svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg><span>Skills Assessment</span></a></li>
                    <li><a href="enrollments.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg><span>My Enrollments</span></a></li>
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
                <span class="welcome-title">Activity Overview</span>
                <span class="welcome-subtitle"><?= e($activity['type_name']) ?> • <?= e($activity['mode_name']) ?></span>
            </div>
            <div class="topbar-actions">
                <a href="activities.php" class="btn-view-details">← Back to Activities</a>
            </div>
        </header>

        <main class="dashboard-content">
            <div class="details-container">
                <?php if (!empty($successMsg)): ?>
                    <div style="background: #ECFDF5; border: 1px solid #A7F3D0; color: #065F46; padding: 14px 18px; border-radius: var(--radius-sm); margin-bottom: 20px; font-weight: 500;">
                        <?= e($successMsg) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errorMsg)): ?>
                    <div style="background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B; padding: 14px 18px; border-radius: var(--radius-sm); margin-bottom: 20px; font-weight: 500;">
                        <?= e($errorMsg) ?>
                    </div>
                <?php endif; ?>

                <div class="details-card">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                        <span class="activity-pill type"><?= e($activity['type_name']) ?></span>
                        <span class="activity-pill match"><?= e($activity['mode_name']) ?></span>
                        <?php if (!empty($activity['registration_deadline'])): ?>
                            <span class="activity-pill deadline">Registration Closes: <?= date('M j, Y', strtotime($activity['registration_deadline'])) ?></span>
                        <?php endif; ?>
                    </div>

                    <h1 style="font-size: 26px; font-weight: 800; color: var(--dark-navy); margin: 6px 0 12px;"><?= e($activity['title']) ?></h1>

                    <p style="font-size: 14px; color: var(--secondary-text); margin-bottom: 18px;">
                        Organized by <strong><?= e($activity['organizer_name']) ?></strong>
                        <?php if (!empty($activity['organizer_website'])): ?>
                            (<a href="<?= e($activity['organizer_website']) ?>" target="_blank" rel="noopener noreferrer" style="color: var(--primary-blue);">Official Website</a>)
                        <?php endif; ?>
                    </p>

                    <!-- Roadmap Relevance Callout -->
                    <div class="relevance-callout">
                        <strong style="font-size: 14px; display: block; margin-bottom: 4px;">🎯 Relevance to your <?= e($careerTitle) ?> Roadmap:</strong>
                        <p style="font-size: 13px; line-height: 1.5; margin: 0;">
                            Participating in this opportunity allows you to develop hands-on competency, collaborate in build sprints, and gain verified extracurricular evidence to strengthen your ATS portfolio.
                        </p>
                    </div>

                    <h3 style="font-size: 16px; font-weight: 800; color: var(--dark-navy); margin: 24px 0 8px;">Description & Overview</h3>
                    <p style="font-size: 14px; line-height: 1.6; color: var(--main-text); margin-bottom: 20px;">
                        <?= nl2br(e($activity['description'] ?? 'Join collegiate peers and mentors in this extracurricular experience.')) ?>
                    </p>

                    <?php if (!empty($activitySkills)): ?>
                        <h3 style="font-size: 16px; font-weight: 800; color: var(--dark-navy); margin: 24px 0 8px;">Target Competencies & Skills</h3>
                        <div class="activity-tags-row" style="margin-bottom: 24px;">
                            <?php foreach ($activitySkills as $skName): ?>
                                <span class="skill-tag" style="padding: 6px 12px; font-size: 12px;"><?= e($skName) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Action Bar -->
                    <div style="display: flex; align-items: center; justify-content: space-between; border-top: 1px solid var(--border-color); padding-top: 24px; gap: 16px; flex-wrap: wrap;">
                        <div>
                            <?php if (!empty($activity['registration_url'])): ?>
                                <a href="<?= e($activity['registration_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn-view-details" style="font-size: 13.5px; padding: 10px 18px;">
                                    ↗ Visit External Registration Site
                                </a>
                            <?php endif; ?>
                        </div>

                        <div>
                            <?php if ($enrollment): ?>
                                <?php if ($enrollment['completion_status'] === 'completed'): ?>
                                    <span style="font-size: 14px; font-weight: 700; color: #0F9D8A; padding: 10px 18px; background: #ECFDF5; border-radius: var(--radius-sm);">
                                        ✓ Completed & Verified on Transcript
                                    </span>
                                <?php else: ?>
                                    <a href="complete_activity.php?id=<?= (int)$enrollment['enrollment_id'] ?>" class="btn-view-pathway" style="background: #0F9D8A;">
                                        Submit Evidence & Mark Complete →
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <form method="POST" action="activity_details.php?id=<?= $activityId ?>" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action_enroll" value="1">
                                    <button type="submit" class="btn-view-pathway">
                                        Enroll in CareerAct Portal
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
