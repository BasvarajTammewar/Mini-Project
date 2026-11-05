<?php
/**
 * CareerAct — My Skills Module
 * Bridge / Overview for Module 5: Skills Management
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';

requireCompletedProfile('onboarding.php?msg=complete_profile');

$userId = getCurrentUserId();
$db = getDbConnection();

// Fetch student user & profile details
$stmtUser = $db->prepare("
    SELECT u.name, u.email, sp.education_level, sp.field_of_study 
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.id = :user_id LIMIT 1
");
$stmtUser->execute([':user_id' => $userId]);
$student = $stmtUser->fetch();
$fullName = $student['name'] ?? 'Student';
$nameParts = explode(' ', trim($fullName));
$firstName = $nameParts[0];
$initials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));

// Fetch student skills
$skillsStmt = $db->prepare("
    SELECT ss.skill_id, ss.level, ss.source, ss.updated_at,
           s.name AS skill_name, s.skill_type, s.description AS skill_desc,
           sc.name AS category_name,
           sl.label AS level_label
    FROM student_skills ss
    JOIN skills s ON ss.skill_id = s.id
    LEFT JOIN skill_categories sc ON s.category_id = sc.id
    LEFT JOIN skill_levels sl ON ss.level = sl.level
    WHERE ss.user_id = :user_id
    ORDER BY ss.level DESC, s.name ASC
");
$skillsStmt->execute([':user_id' => $userId]);
$skills = $skillsStmt->fetchAll();

// Group skills by category
$categories = [];
foreach ($skills as $s) {
    $cat = $s['category_name'] ?: 'General';
    $categories[$cat][] = $s;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Skills — CareerAct</title>
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
                    <li>
                        <a href="dashboard.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect></svg>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="profile.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            <span>My Profile</span>
                        </a>
                    </li>
                    <li>
                        <a href="career_goals.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg>
                            <span>Career Exploration</span>
                        </a>
                    </li>
                    <li>
                        <a href="my_skills.php" class="nav-link active" aria-current="page">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                            <span>My Skills</span>
                        </a>
                    </li>
                    <li>
                        <a href="skills_assessment.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                            <span>Skill Assessment</span>
                        </a>
                    </li>
                    <li>
                        <a href="skill_gap.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg>
                            <span>Skill-Gap Analysis</span>
                        </a>
                    </li>
                    <li>
                        <a href="activities.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg>
                            <span>Explore Activities</span>
                        </a>
                    </li>
                    <li>
                        <a href="roadmap.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            <span>Career Roadmap</span>
                        </a>
                    </li>
                    <li>
                        <a href="saved_activities.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path></svg>
                            <span>Saved Activities</span>
                        </a>
                    </li>
                    <li>
                        <a href="enrollments.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg>
                            <span>My Enrollments</span>
                        </a>
                    </li>
                    <li>
                        <a href="portfolio.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                            <span>My Portfolio</span>
                        </a>
                    </li>
                    <li>
                        <a href="resume.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            <span>Resume Builder</span>
                        </a>
                    </li>
                    <li>
                        <a href="notifications.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                            <span>Notifications</span><?php if (!empty($unreadNotificationsCount) && $unreadNotificationsCount > 0): ?><span class="badge-pill danger" style="font-size:10px; padding:2px 6px; margin-left:auto; background:#EF4444; color:#fff; border-radius:10px;"><?= $unreadNotificationsCount ?></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="settings.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                            <span>Settings</span>
                        </a>
                    </li>
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
                <span class="welcome-title">My Tracked Skills</span>
                <span class="welcome-subtitle">Competencies & Verification Portfolio</span>
            </div>
            <div class="topbar-actions">
                <a href="dashboard.php" class="btn-view-details" style="padding: 7px 14px;">&larr; Back to Dashboard</a>
                <a href="profile.php" class="user-profile-chip">
                    <div class="avatar-circle"><?= e($initials) ?></div>
                    <div class="user-text-info">
                        <span class="user-name"><?= e($fullName) ?></span>
                        <span class="user-level">Student</span>
                    </div>
                </a>
            </div>
        </header>

        <main class="dashboard-content">
            <section class="hero-banner" style="margin-bottom: 24px;">
                <h1 class="hero-heading">Skills Competency Inventory</h1>
                <p class="hero-desc">
                    Review skills saved during profile onboarding and verified via standardized assessments.
                </p>
                <div class="hero-actions">
                    <a href="skills_assessment.php" class="hero-btn-white">Take Skill Assessment</a>
                    <a href="onboarding.php?edit=1#step-skills" class="hero-btn-glass">Edit Skills in Profile</a>
                </div>
            </section>

            <?php if (empty($skills)): ?>
                <div class="empty-panel" style="background:#FFF; border:1px solid #E4EAF2; border-radius:12px; padding:48px 24px; text-align:center;">
                    <svg viewBox="0 0 24 24" width="48" height="48" stroke="#1769E8" stroke-width="1.8" fill="none" style="margin:0 auto 16px;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                    <h2 style="font-size: 18px; color: #163A70; margin-bottom: 8px;">You haven't added any skills yet.</h2>
                    <p style="color: #667085; font-size: 14px; max-width: 440px; margin: 0 auto 20px;">
                        Add your technical, analytical, and interpersonal skills to benchmark your profile against target careers.
                    </p>
                    <a href="onboarding.php?edit=1#step-skills" class="hero-btn-white" style="background:#1769E8; color:#fff; display:inline-flex;">Add Skills to Profile</a>
                </div>
            <?php else: ?>
                <?php foreach ($categories as $catName => $catSkills): ?>
                    <div style="background:#FFF; border:1px solid #E4EAF2; border-radius:12px; padding:24px; margin-bottom:20px; box-shadow:var(--card-shadow);">
                        <h2 style="font-size:16px; font-weight:800; color:#163A70; margin-bottom:16px; display:flex; align-items:center; justify-content:space-between;">
                            <span><?= e($catName) ?></span>
                            <span style="font-size:12px; font-weight:600; background:#EEF3FF; color:#1769E8; padding:3px 10px; border-radius:20px;"><?= count($catSkills) ?> skills</span>
                        </h2>
                        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:14px;">
                            <?php foreach ($catSkills as $sk): ?>
                                <div style="border:1px solid #E4EAF2; border-radius:8px; padding:14px; background:#FAFCFF;">
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px;">
                                        <span style="font-weight:700; color:#163A70; font-size:14px;"><?= e($sk['skill_name']) ?></span>
                                        <span style="font-size:11px; font-weight:700; padding:2px 8px; border-radius:6px; background:<?= $sk['source'] === 'assessed' ? '#E6F8F3; color:#0F9D8A;' : '#F0F4F8; color:#475569;' ?>">
                                            <?= $sk['source'] === 'assessed' ? 'Assessed' : 'Self-Reported' ?>
                                        </span>
                                    </div>
                                    <div style="font-size:12px; color:#667085; margin-bottom:8px;">
                                        Proficiency: <strong><?= e($sk['level_label'] ?? 'Level ' . $sk['level']) ?></strong>
                                    </div>
                                    <?php if (!empty($sk['skill_desc'])): ?>
                                        <p style="font-size:11.5px; color:#667085; line-height:1.4; margin:0;"><?= e($sk['skill_desc']) ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>

