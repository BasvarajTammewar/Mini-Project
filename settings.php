<?php
/**
 * CareerAct — Student Settings & Notification Preferences
 * Module 14: In-App Notification Preferences & Account Settings
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/notification_service.php';

requireCompletedProfile('onboarding.php?msg=complete_profile');

$userId = getCurrentUserId();
$db = getDbConnection();
$csrfToken = getCsrfToken();

$feedback = '';
$feedbackType = 'info';

// Handle Saving Notification Preferences
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $feedback = 'Security token invalid or expired. Please refresh.';
        $feedbackType = 'danger';
    } else {
        $action = trim($_POST['action'] ?? '');
        if ($action === 'save_notification_preferences') {
            $prefs = [
                'notify_deadlines'        => !empty($_POST['notify_deadlines']),
                'notify_status_changes'   => !empty($_POST['notify_status_changes']),
                'notify_assessments'      => !empty($_POST['notify_assessments']),
                'notify_resume_portfolio' => !empty($_POST['notify_resume_portfolio']),
                'notify_announcements'    => !empty($_POST['notify_announcements']),
            ];

            if (saveStudentNotificationPreferences($db, $userId, $prefs)) {
                $feedback = 'Notification preferences updated successfully.';
                $feedbackType = 'success';
            } else {
                $feedback = 'Failed to update preferences.';
                $feedbackType = 'danger';
            }
        }
    }
}

$stmtUser = $db->prepare("SELECT id, name, email, role, created_at FROM users WHERE id = :user_id LIMIT 1");
$stmtUser->execute([':user_id' => $userId]);
$student = $stmtUser->fetch();
$fullName = $student['name'] ?? 'Student';
$initials = strtoupper(substr($fullName, 0, 1));

$preferences = getStudentNotificationPreferences($db, $userId);
$unreadCount = getUnreadNotificationCount($db, $userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — CareerAct</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        .settings-toggle-card {
            background: #FFFFFF;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 18px 22px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
            flex-shrink: 0;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
            background-color: #CBD5E1; transition: .2s; border-radius: 24px;
        }
        .toggle-slider:before {
            position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px;
            background-color: white; transition: .2s; border-radius: 50%;
        }
        input:checked + .toggle-slider { background-color: var(--primary-color); }
        input:checked + .toggle-slider:before { transform: translateX(20px); }
    </style>
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
                        <a href="my_skills.php" class="nav-link">
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
                        <a href="settings.php" class="nav-link active" aria-current="page">
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
                <span class="welcome-title">Account & Notification Settings</span>
                <span class="welcome-subtitle">Manage in-app notification preferences and student profile controls.</span>
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
            <div style="max-width: 740px; margin: 20px auto;">

                <!-- Feedback Alert -->
                <?php if (!empty($feedback)): ?>
                    <div style="background: <?= $feedbackType === 'success' ? '#ECFDF5' : '#FEF2F2' ?>; border: 1px solid <?= $feedbackType === 'success' ? '#A7F3D0' : '#FECACA' ?>; color: <?= $feedbackType === 'success' ? '#065F46' : '#991B1B' ?>; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; font-weight: 500;">
                        <?= htmlspecialchars($feedback) ?>
                    </div>
                <?php endif; ?>

                <!-- Section 1: In-App Notification Preferences (Module 14) -->
                <div style="background:#FFF; border:1px solid #E4EAF2; border-radius:12px; padding:28px; box-shadow:var(--card-shadow); margin-bottom:24px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                        <div>
                            <h2 style="font-size:18px; font-weight:800; color:#163A70;">Notification Preferences</h2>
                            <p style="font-size:13px; color:#667085; margin-top:2px;">
                                Customize what alerts are delivered to your in-app notification center.
                            </p>
                        </div>
                        <a href="notifications.php" class="btn-view-details" style="font-size:12px; padding:6px 12px;">
                            View Notification Center &rarr;
                        </a>
                    </div>

                    <form method="POST" action="settings.php">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="save_notification_preferences">

                        <div class="settings-toggle-card">
                            <div>
                                <strong style="font-size:14px; color:#163A70; display:block; margin-bottom:2px;">
                                    Activity Deadlines & Event Reminders
                                </strong>
                                <span style="font-size:12.5px; color:#667085;">
                                    Advance notices for verified registration deadlines and event dates of saved or enrolled activities.
                                </span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="notify_deadlines" value="1" <?= $preferences['notify_deadlines'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="settings-toggle-card">
                            <div>
                                <strong style="font-size:14px; color:#163A70; display:block; margin-bottom:2px;">
                                    Activity Status Changes
                                </strong>
                                <span style="font-size:12.5px; color:#667085;">
                                    Confirmations when you mark an activity registered or completed, or when organizers update event dates.
                                </span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="notify_status_changes" value="1" <?= $preferences['notify_status_changes'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="settings-toggle-card">
                            <div>
                                <strong style="font-size:14px; color:#163A70; display:block; margin-bottom:2px;">
                                    Assessment & Recommendation Updates
                                </strong>
                                <span style="font-size:12.5px; color:#667085;">
                                    Alerts when AI skill evaluations, skill-gap analysis, or activity recommendations finish processing.
                                </span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="notify_assessments" value="1" <?= $preferences['notify_assessments'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="settings-toggle-card">
                            <div>
                                <strong style="font-size:14px; color:#163A70; display:block; margin-bottom:2px;">
                                    Resume & Portfolio Updates
                                </strong>
                                <span style="font-size:12.5px; color:#667085;">
                                    Confirmations when resume versions are saved or when public portfolio visibility is toggled.
                                </span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="notify_resume_portfolio" value="1" <?= $preferences['notify_resume_portfolio'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="settings-toggle-card">
                            <div>
                                <strong style="font-size:14px; color:#163A70; display:block; margin-bottom:2px;">
                                    Platform Announcements
                                </strong>
                                <span style="font-size:12.5px; color:#667085;">
                                    Broadcast updates, new features, and student opportunities from CareerAct administrators.
                                </span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="notify_announcements" value="1" <?= $preferences['notify_announcements'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div style="margin-top:20px; display:flex; justify-content:flex-end;">
                            <button type="submit" class="hero-btn-white" style="background:#1769E8; color:#fff; border:none; padding:10px 20px; font-weight:600; cursor:pointer;">
                                Save Notification Preferences
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Section 2: Account Information -->
                <div style="background:#FFF; border:1px solid #E4EAF2; border-radius:12px; padding:28px; box-shadow:var(--card-shadow);">
                    <h2 style="font-size:18px; font-weight:800; color:#163A70; margin-bottom:16px;">Student Account Information</h2>
                    <div style="display:grid; grid-template-columns:140px 1fr; gap:12px; font-size:14px; margin-bottom:24px; padding-bottom:20px; border-bottom:1px solid #E4EAF2;">
                        <div style="color:#667085; font-weight:600;">Full Name:</div>
                        <div style="color:#163A70; font-weight:700;"><?= e($student['name']) ?></div>
                        <div style="color:#667085; font-weight:600;">Email:</div>
                        <div style="color:#163A70;"><?= e($student['email']) ?></div>
                        <div style="color:#667085; font-weight:600;">Account Role:</div>
                        <div><span style="font-size:12px; font-weight:700; background:#EEF3FF; color:#1769E8; padding:3px 10px; border-radius:6px;"><?= e(ucfirst($student['role'])) ?></span></div>
                    </div>

                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        <a href="profile.php" class="hero-btn-white" style="background:#1769E8; color:#fff; display:inline-flex;">View My Profile</a>
                        <a href="onboarding.php?edit=1" class="btn-view-details" style="display:inline-flex;">Edit Profile Data</a>
                        <a href="logout.php" class="btn-view-details" style="color:#DC2626; border-color:#FCA5A5; display:inline-flex;">Sign Out</a>
                    </div>
                </div>

            </div>
        </main>
    </div>
</body>
</html>
