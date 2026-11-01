<?php
/**
 * CareerAct — Saved Activities Module
 * Module 9: Saved Activities View
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';

requireCompletedProfile('onboarding.php?msg=complete_profile');

$userId = getCurrentUserId();
$db = getDbConnection();

$stmtUser = $db->prepare("SELECT name, email FROM users WHERE id = :user_id LIMIT 1");
$stmtUser->execute([':user_id' => $userId]);
$student = $stmtUser->fetch();
$fullName = $student['name'] ?? 'Student';
$initials = strtoupper(substr($fullName, 0, 1));

// Fetch saved activities
$savedStmt = $db->prepare("
    SELECT e.id AS enrollment_id, e.activity_id, e.created_at,
           a.title, a.delivery_mode, a.difficulty, a.description,
           ac.name AS category_name
    FROM enrollments e
    JOIN activities a ON e.activity_id = a.id
    LEFT JOIN activity_categories ac ON a.category_id = ac.id
    WHERE e.user_id = :user_id AND e.status = 'saved'
    ORDER BY e.created_at DESC
");
$savedStmt->execute([':user_id' => $userId]);
$savedActivities = $savedStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saved Activities — CareerAct</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body class="dashboard-body">

    <!-- Sidebar Navigation -->
    <aside class="dashboard-sidebar" id="dashboardSidebar" aria-label="Portal Navigation">
        <div>
            <div class="sidebar-header">
                <a href="dashboard.php" class="sidebar-brand-wrapper">
                    <div class="sidebar-brand-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
                    </div>
                    <div class="sidebar-brand-text">
                        <span class="sidebar-brand-name">CareerAct</span>
                        <span class="sidebar-brand-subtitle">STUDENT PORTAL</span>
                    </div>
                </a>
            </div>

            <nav>
                <ul class="sidebar-nav">
                    <li><a href="dashboard.php" class="nav-link"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect></svg><span>Dashboard</span></a></li>
                    <li><a href="profile.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><span>My Profile</span></a></li>
                    <li><a href="career_goals.php" class="nav-link"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg><span>Career Exploration</span></a></li>
                    <li><a href="my_skills.php" class="nav-link"><svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg><span>My Skills</span></a></li>
                    <li><a href="skills_assessment.php" class="nav-link"><svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg><span>Skill Assessment</span></a></li>
                    <li><a href="skill_gap.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg><span>Skill-Gap Analysis</span></a></li>
                    <li><a href="recommendations.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path></svg><span>Recommendations</span></a></li>
                    <li><a href="activities.php" class="nav-link"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg><span>Explore Activities</span></a></li>
                    <li><a href="saved_activities.php" class="nav-link active" aria-current="page"><svg viewBox="0 0 24 24"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path></svg><span>Saved Activities</span></a></li>
                    <li><a href="enrollments.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg><span>My Enrollments</span></a></li>
                    <li><a href="portfolio.php" class="nav-link"><svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg><span>My Portfolio</span></a></li>
                    <li><a href="resume.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg><span>Resume Builder</span></a></li>
                    <li><a href="settings.php" class="nav-link"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg><span>Settings</span></a></li>
                </ul>
            </nav>
        </div>

        <div class="sidebar-footer">
            <a href="logout.php" class="logout-link" id="sidebarLogout">
                <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <div class="dashboard-main">
        <header class="dashboard-topbar">
            <div class="topbar-welcome">
                <span class="welcome-title">Saved Activities</span>
                <span class="welcome-subtitle">Bookmarked Extracurricular Opportunities</span>
            </div>
            <div class="topbar-actions">
                <a href="dashboard.php" class="btn-view-details" style="padding: 7px 14px;">&larr; Back to Dashboard</a>
                <a href="profile.php" class="user-profile-chip">
                    <div class="avatar-circle"><?= e($initials) ?></div>
                    <div class="user-text-info"><span class="user-name"><?= e($fullName) ?></span><span class="user-level">Student</span></div>
                </a>
            </div>
        </header>

        <main class="dashboard-content">
            <?php if (empty($savedActivities)): ?>
                <div style="background:#FFF; border:1px solid #E4EAF2; border-radius:12px; padding:48px 24px; text-align:center; box-shadow:var(--card-shadow); max-width:760px; margin:20px auto;">
                    <div style="width:56px; height:56px; background:#EEF3FF; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px;">
                        <svg viewBox="0 0 24 24" width="28" height="28" stroke="#1769E8" stroke-width="2" fill="none"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path></svg>
                    </div>
                    <h1 style="font-size:20px; font-weight:800; color:#163A70; margin-bottom:10px;">No Saved Activities Yet</h1>
                    <p style="color:#667085; font-size:14px; line-height:1.6; max-width:480px; margin:0 auto 24px;">
                        When you browse the activity catalog, you can save opportunities to review later or register for when ready.
                    </p>
                    <div style="display:flex; justify-content:center; gap:12px;">
                        <a href="activities.php" class="hero-btn-white" style="background:#1769E8; color:#fff; display:inline-flex;">Browse Activities</a>
                        <a href="dashboard.php" class="btn-view-details" style="display:inline-flex;">Return to Dashboard</a>
                    </div>
                </div>
            <?php else: ?>
                <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:18px;">
                    <?php foreach ($savedActivities as $sa): ?>
                        <div style="background:#FFF; border:1px solid #E4EAF2; border-radius:12px; padding:20px; box-shadow:var(--card-shadow);">
                            <span style="font-size:11px; font-weight:700; color:#1769E8; background:#EEF3FF; padding:3px 8px; border-radius:6px;"><?= e($sa['category_name'] ?? 'General') ?></span>
                            <h2 style="font-size:16px; font-weight:700; color:#163A70; margin:10px 0 6px;"><?= e($sa['title']) ?></h2>
                            <p style="font-size:13px; color:#667085; margin-bottom:12px;"><?= e(substr($sa['description'] ?? '', 0, 120)) ?>...</p>
                            <a href="activities.php?id=<?= (int)$sa['activity_id'] ?>" class="btn-view-details" style="display:inline-block;">View Activity</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>

