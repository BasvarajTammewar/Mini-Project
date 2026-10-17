<?php
/**
 * CareerAct — Explore Activities Module
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';

$userId = (int)$_SESSION['user_id'];
$db = getDbConnection();

// Get student_id
$stmtStudent = $db->prepare("SELECT student_id, full_name FROM student_profiles WHERE user_id = :user_id LIMIT 1");
$stmtStudent->execute([':user_id' => $userId]);
$student = $stmtStudent->fetch();
$studentId = $student ? (int)$student['student_id'] : 0;

$successMsg = '';
$errorMsg = '';

// Handle Enrollment Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_activity_id'])) {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $actId = (int)$_POST['enroll_activity_id'];
        
        // Check if already enrolled
        $stmtChk = $db->prepare("
            SELECT enrollment_id FROM student_activity_enrollments 
            WHERE student_id = :student_id AND activity_id = :act_id
        ");
        $stmtChk->execute([':student_id' => $studentId, ':act_id' => $actId]);
        
        if ($stmtChk->fetch()) {
            $errorMsg = 'You are already enrolled or registered in this activity.';
        } else {
            $stmtIns = $db->prepare("
                INSERT INTO student_activity_enrollments (
                    student_id, activity_id, enrollment_status_id, completion_status, enrolled_at
                ) VALUES (
                    :student_id, :act_id, 2, 'in_progress', NOW()
                )
            ");
            $stmtIns->execute([':student_id' => $studentId, ':act_id' => $actId]);
            $successMsg = 'Successfully enrolled! This activity is now active in your portal and dashboard.';
        }
    }
}

// Search and Filter handling
$search = trim((string)($_GET['q'] ?? ''));
$filterType = trim((string)($_GET['type'] ?? ''));

$sql = "
    SELECT 
        a.activity_id,
        a.title,
        a.description,
        a.registration_deadline,
        a.venue_details,
        a.registration_url,
        t.type_name,
        m.mode_name,
        COALESCE(o.organizer_name, 'Campus Organizer') AS organizer_name,
        (SELECT COUNT(*) FROM student_activity_enrollments sae WHERE sae.student_id = :student_id AND sae.activity_id = a.activity_id) AS is_enrolled
    FROM activities a
    JOIN activity_types t ON a.activity_type_id = t.activity_type_id
    JOIN activity_modes m ON a.mode_id = m.mode_id
    LEFT JOIN organizers o ON a.organizer_id = o.organizer_id
    WHERE a.activity_status_id = 3
";
$params = [':student_id' => $studentId];

if ($search !== '') {
    $sql .= " AND (a.title LIKE :search OR a.description LIKE :search OR o.organizer_name LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($filterType !== '') {
    $sql .= " AND t.type_code = :filter_type";
    $params[':filter_type'] = $filterType;
}

$sql .= " ORDER BY a.registration_deadline IS NULL, a.registration_deadline ASC, a.activity_id DESC LIMIT 30";

$stmtActivities = $db->prepare($sql);
$stmtActivities->execute($params);
$activitiesList = $stmtActivities->fetchAll();

// Get list of activity types for filter pills
$typesList = $db->query("SELECT type_code, type_name FROM activity_types ORDER BY is_technical DESC, type_name ASC")->fetchAll();

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Explore Activities — CareerAct</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        .filter-pills {
            display: flex;
            align-items: center;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 8px;
            margin: 16px 0 24px;
        }
        .filter-pill {
            padding: 6px 14px;
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--secondary-text);
            text-decoration: none;
            white-space: nowrap;
            transition: var(--transition);
        }
        .filter-pill:hover, .filter-pill.active {
            background: var(--primary-blue);
            color: var(--white);
            border-color: var(--primary-blue);
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
                    <li><a href="activities.php" class="nav-link active" aria-current="page"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg><span>Explore Activities</span></a></li>
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
                <span class="welcome-title">Explore Activities</span>
                <span class="welcome-subtitle">Hackathons, internships, technical contests, and leadership experiences</span>
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

            <?php if (!empty($errorMsg)): ?>
                <div style="background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B; padding: 14px 18px; border-radius: var(--radius-sm); margin-bottom: 20px; font-weight: 500;">
                    <?= e($errorMsg) ?>
                </div>
            <?php endif; ?>

            <!-- Filter Pills -->
            <div class="filter-pills">
                <a href="activities.php" class="filter-pill <?= empty($filterType) ? 'active' : '' ?>">All Opportunities</a>
                <?php foreach (array_slice($typesList, 0, 8) as $t): ?>
                    <a href="activities.php?type=<?= urlencode($t['type_code']) ?>" class="filter-pill <?= $filterType === $t['type_code'] ? 'active' : '' ?>">
                        <?= e($t['type_name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Activities List -->
            <div>
                <?php if (!empty($activitiesList)): ?>
                    <?php foreach ($activitiesList as $act): ?>
                        <?php 
                            $isEnrolled = (int)$act['is_enrolled'] > 0;
                            $isHack = stripos($act['type_name'], 'hack') !== false;
                        ?>
                        <article class="activity-card">
                            <div class="activity-icon-box <?= $isHack ? 'purple' : 'blue' ?>" aria-hidden="true">
                                <?php if ($isHack): ?>
                                    <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                                <?php else: ?>
                                    <svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                                <?php endif; ?>
                            </div>

                            <div class="activity-card-body">
                                <div class="activity-badges-row">
                                    <span class="activity-pill type"><?= e($act['type_name']) ?></span>
                                    <span class="activity-pill match"><?= e($act['mode_name']) ?></span>
                                    <?php if (!empty($act['registration_deadline'])): ?>
                                        <span class="activity-pill deadline">Deadline: <?= date('M j, Y', strtotime($act['registration_deadline'])) ?></span>
                                    <?php else: ?>
                                        <span class="activity-pill gray">Open Directory</span>
                                    <?php endif; ?>
                                </div>

                                <h2 class="activity-title" style="font-size: 17px;"><?= e($act['title']) ?></h2>

                                <p style="font-size: 13px; color: var(--secondary-text); margin: 4px 0 8px; line-height: 1.5;">
                                    <?= e($act['description'] ?? 'Participate to gain verifiable extracurricular evidence for your career portfolio.') ?>
                                </p>

                                <div class="activity-meta-row" style="margin-bottom: 0;">
                                    <span class="activity-meta-item">
                                        <svg viewBox="0 0 24 24"><path d="M3 21h18M3 7v14M21 7v14M6 11h2M6 15h2M16 11h2M16 15h2M10 21V11h4v10M2 7l10-5 10 5"></path></svg>
                                        <span><?= e($act['organizer_name']) ?></span>
                                    </span>
                                    <?php if (!empty($act['venue_details'])): ?>
                                        <span class="activity-meta-item">
                                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                                            <span><?= e($act['venue_details']) ?></span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="activity-card-action">
                                <?php if ($isEnrolled): ?>
                                    <span style="font-size: 12.5px; font-weight: 700; color: #0F9D8A; display: inline-flex; align-items: center; gap: 4px; padding: 7px 12px; background: #ECFDF5; border-radius: var(--radius-sm);">
                                        ✓ Enrolled
                                    </span>
                                <?php else: ?>
                                    <form method="POST" action="activities.php">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="enroll_activity_id" value="<?= (int)$act['activity_id'] ?>">
                                        <button type="submit" class="btn-view-details" style="background: var(--primary-blue); color: #FFF; border-color: var(--primary-blue);">
                                            Enroll Now
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-panel">
                        <p style="font-weight: 600; font-size: 15px;">No activities found matching your criteria.</p>
                        <a href="activities.php" class="btn-view-details" style="display: inline-block; margin-top: 12px;">Reset Filters</a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
