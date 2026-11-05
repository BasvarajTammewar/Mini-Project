<?php
/**
 * CareerAct — Student In-App Notification Center
 * Module 14: Notifications, Deadline Reminders & Alerts
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/notification_service.php';

requireCompletedProfile('onboarding.php?msg=complete_profile');

$userId = getCurrentUserId();
$db = getDbConnection();

// Fetch student profile for header
$stmtUser = $db->prepare("SELECT name, email FROM users WHERE id = :user_id LIMIT 1");
$stmtUser->execute([':user_id' => $userId]);
$student = $stmtUser->fetch();
$fullName = $student['name'] ?? 'Student';
$initials = strtoupper(substr($fullName, 0, 1));

$csrfToken = getCsrfToken();
$feedback = '';
$feedbackType = 'info';

// Handle Actions (Mark read, mark all read, dismiss, save preferences)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $feedback = 'Security token invalid or expired. Please refresh.';
        $feedbackType = 'danger';
    } else {
        $action = trim($_POST['action'] ?? '');

        if ($action === 'mark_read') {
            $notifId = (int)($_POST['notification_id'] ?? 0);
            if ($notifId > 0 && markNotificationAsRead($db, $userId, $notifId)) {
                $feedback = 'Notification marked as read.';
                $feedbackType = 'success';
            }
        } elseif ($action === 'mark_all_read') {
            $count = markAllNotificationsAsRead($db, $userId);
            $feedback = "Marked {$count} notifications as read.";
            $feedbackType = 'success';
        } elseif ($action === 'dismiss') {
            $notifId = (int)($_POST['notification_id'] ?? 0);
            if ($notifId > 0 && dismissNotification($db, $userId, $notifId)) {
                $feedback = 'Notification dismissed.';
                $feedbackType = 'success';
            }
        } elseif ($action === 'save_preferences') {
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

// Current Filter & View Tab
$currentTab = trim($_GET['tab'] ?? 'inbox');
$unreadOnly = isset($_GET['filter']) && $_GET['filter'] === 'unread';
$typeFilter = !empty($_GET['type']) ? trim($_GET['type']) : null;

$unreadCount = getUnreadNotificationCount($db, $userId);
$notifications = getStudentNotifications($db, $userId, 50, 0, $unreadOnly, $typeFilter);
$preferences = getStudentNotificationPreferences($db, $userId);

// Helper for type badges
function getNotificationTypeBadge(string $type): array
{
    $map = [
        'deadline_reminder'      => ['label' => 'Deadline Alert', 'color' => '#DC2626', 'bg' => '#FEF2F2'],
        'event_reminder'         => ['label' => 'Event Reminder', 'color' => '#D97706', 'bg' => '#FFFBEB'],
        'activity_status_change' => ['label' => 'Activity Status', 'color' => '#10B981', 'bg' => '#ECFDF5'],
        'opportunity_archived'   => ['label' => 'Opportunity Update', 'color' => '#64748B', 'bg' => '#F1F5F9'],
        'assessment_completed'   => ['label' => 'Assessment', 'color' => '#2563EB', 'bg' => '#EFF6FF'],
        'skill_gap_ready'        => ['label' => 'Skill Gap', 'color' => '#7C3AED', 'bg' => '#F5F3FF'],
        'recommendations_ready'  => ['label' => 'Recommendation', 'color' => '#059669', 'bg' => '#ECFDF5'],
        'resume_saved'           => ['label' => 'Resume', 'color' => '#0284C7', 'bg' => '#F0F9FF'],
        'portfolio_shared'       => ['label' => 'Portfolio', 'color' => '#9333EA', 'bg' => '#FAF5FF'],
        'platform_announcement'  => ['label' => 'Announcement', 'color' => '#4338CA', 'bg' => '#EEF2FF'],
        'account_security'       => ['label' => 'Security', 'color' => '#DC2626', 'bg' => '#FEF2F2'],
    ];

    return $map[$type] ?? ['label' => 'Notification', 'color' => '#475569', 'bg' => '#F8FAFC'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications & Reminders — CareerAct</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        .notif-container {
            max-width: 960px;
            margin: 24px auto;
        }
        .notif-header-card {
            background: #FFFFFF;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 24px 28px;
            box-shadow: var(--card-shadow);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }
        .notif-title-area h1 {
            font-size: 24px;
            font-weight: 800;
            color: var(--navy-primary);
            letter-spacing: -0.02em;
        }
        .notif-title-area p {
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .notif-tabs {
            display: flex;
            align-items: center;
            gap: 8px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 20px;
        }
        .notif-tab {
            padding: 10px 18px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            border-bottom: 2px solid transparent;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }
        .notif-tab:hover {
            color: var(--navy-primary);
        }
        .notif-tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        .notif-badge-pill {
            background: #EFF6FF;
            color: var(--primary-color);
            font-size: 11px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 10px;
        }
        .notif-badge-unread {
            background: #DC2626;
            color: #FFFFFF;
        }
        .notif-card-item {
            background: #FFFFFF;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .notif-card-item:hover {
            border-color: #CBD5E1;
            box-shadow: 0 4px 10px rgba(0,0,0,0.06);
        }
        .notif-card-item.unread {
            background: #F8FAFD;
            border-left: 4px solid var(--primary-color);
        }
        .notif-meta-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }
        .notif-type-tag {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 3px 8px;
            border-radius: 6px;
        }
        .notif-item-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--navy-primary);
            margin-bottom: 6px;
        }
        .notif-item-msg {
            font-size: 14px;
            line-height: 1.5;
            color: var(--text-dark);
            margin-bottom: 12px;
        }
        .notif-actions-row {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13px;
        }
        .notif-link-btn {
            color: var(--primary-color);
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .notif-link-btn:hover {
            text-decoration: underline;
        }
        .notif-btn-subtle {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 12px;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            transition: background 0.15s ease;
        }
        .notif-btn-subtle:hover {
            background: #F1F5F9;
            color: var(--text-dark);
        }
        .settings-toggle-card {
            background: #FFFFFF;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
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
                        <a href="notifications.php" class="nav-link active" aria-current="page">
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
                <span class="welcome-title">Notifications & Reminders</span>
                <span class="welcome-subtitle">Stay informed on activity deadlines, assessment status, and announcements.</span>
            </div>
            <div class="topbar-actions">
                <a href="dashboard.php" class="btn-view-details" style="padding: 7px 14px;">&larr; Back to Dashboard</a>
                <div class="user-profile-chip">
                    <div class="avatar-circle"><?= e($initials) ?></div>
                    <div class="user-text-info"><span class="user-name"><?= e($fullName) ?></span><span class="user-level">Student</span></div>
                </div>
            </div>
        </header>

        <main class="dashboard-content">
            <div class="notif-container">

                <!-- Alert Feedback -->
                <?php if (!empty($feedback)): ?>
                    <div style="background: <?= $feedbackType === 'success' ? '#ECFDF5' : '#FEF2F2' ?>; border: 1px solid <?= $feedbackType === 'success' ? '#A7F3D0' : '#FECACA' ?>; color: <?= $feedbackType === 'success' ? '#065F46' : '#991B1B' ?>; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; font-weight: 500;">
                        <?= htmlspecialchars($feedback) ?>
                    </div>
                <?php endif; ?>

                <!-- Header Card -->
                <div class="notif-header-card">
                    <div class="notif-title-area">
                        <h1>In-App Notification Center</h1>
                        <p>Track deadlines, verified opportunity dates, and important CareerAct milestones.</p>
                    </div>
                    <?php if ($unreadCount > 0): ?>
                        <form method="POST" action="notifications.php">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit" class="btn-view-details" style="padding: 8px 16px; font-size: 13px; font-weight: 600;">
                                ✓ Mark All as Read (<?= $unreadCount ?>)
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Navigation Tabs -->
                <div class="notif-tabs">
                    <a href="notifications.php?tab=inbox" class="notif-tab <?= $currentTab === 'inbox' && !$unreadOnly ? 'active' : '' ?>">
                        All Notifications
                        <span class="notif-badge-pill"><?= count($notifications) ?></span>
                    </a>
                    <a href="notifications.php?tab=inbox&filter=unread" class="notif-tab <?= $unreadOnly ? 'active' : '' ?>">
                        Unread
                        <?php if ($unreadCount > 0): ?>
                            <span class="notif-badge-pill notif-badge-unread"><?= $unreadCount ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="notifications.php?tab=preferences" class="notif-tab <?= $currentTab === 'preferences' ? 'active' : '' ?>">
                        Preferences
                    </a>
                </div>

                <?php if ($currentTab === 'preferences'): ?>
                    <!-- TAB: Preferences Settings -->
                    <div style="background: #FFFFFF; border: 1px solid var(--border-color); border-radius: var(--border-radius); padding: 28px; box-shadow: var(--card-shadow);">
                        <h2 style="font-size: 18px; font-weight: 700; color: var(--navy-primary); margin-bottom: 6px;">
                            In-App Notification Preferences
                        </h2>
                        <p style="font-size: 13.5px; color: var(--text-muted); margin-bottom: 24px;">
                            Choose which categories of in-app notifications you wish to receive. Essential security and account notices are always delivered.
                        </p>

                        <form method="POST" action="notifications.php?tab=preferences">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="action" value="save_preferences">

                            <!-- Category 1: Deadlines -->
                            <div class="settings-toggle-card">
                                <div>
                                    <strong style="font-size: 14.5px; color: var(--navy-primary); display: block; margin-bottom: 3px;">
                                        Activity Deadlines & Event Reminders
                                    </strong>
                                    <span style="font-size: 13px; color: var(--text-muted);">
                                        Advance notices for registration deadlines and event dates of your saved or enrolled opportunities.
                                    </span>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="notify_deadlines" value="1" <?= $preferences['notify_deadlines'] ? 'checked' : '' ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <!-- Category 2: Status Changes -->
                            <div class="settings-toggle-card">
                                <div>
                                    <strong style="font-size: 14.5px; color: var(--navy-primary); display: block; margin-bottom: 3px;">
                                        Activity Status Changes & Confirmations
                                    </strong>
                                    <span style="font-size: 13px; color: var(--text-muted);">
                                        Confirmations when you register, advance progress, or when an opportunity is updated/archived.
                                    </span>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="notify_status_changes" value="1" <?= $preferences['notify_status_changes'] ? 'checked' : '' ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <!-- Category 3: Assessments -->
                            <div class="settings-toggle-card">
                                <div>
                                    <strong style="font-size: 14.5px; color: var(--navy-primary); display: block; margin-bottom: 3px;">
                                        Assessments & Recommendations
                                    </strong>
                                    <span style="font-size: 13px; color: var(--text-muted);">
                                        Alerts when your AI skill evaluations, skill-gap analysis, or activity recommendations finish processing.
                                    </span>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="notify_assessments" value="1" <?= $preferences['notify_assessments'] ? 'checked' : '' ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <!-- Category 4: Resume & Portfolio -->
                            <div class="settings-toggle-card">
                                <div>
                                    <strong style="font-size: 14.5px; color: var(--navy-primary); display: block; margin-bottom: 3px;">
                                        Resume & Portfolio Updates
                                    </strong>
                                    <span style="font-size: 13px; color: var(--text-muted);">
                                        Confirmations when resume versions are saved or when public portfolio visibility is toggled.
                                    </span>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="notify_resume_portfolio" value="1" <?= $preferences['notify_resume_portfolio'] ? 'checked' : '' ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <!-- Category 5: Announcements -->
                            <div class="settings-toggle-card">
                                <div>
                                    <strong style="font-size: 14.5px; color: var(--navy-primary); display: block; margin-bottom: 3px;">
                                        General Platform Announcements
                                    </strong>
                                    <span style="font-size: 13px; color: var(--text-muted);">
                                        Broadcast updates, platform features, and student opportunities from CareerAct administrators.
                                    </span>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="notify_announcements" value="1" <?= $preferences['notify_announcements'] ? 'checked' : '' ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <div style="margin-top: 24px; display: flex; justify-content: flex-end;">
                                <button type="submit" class="hero-btn-white" style="background: var(--primary-color); color: #fff; border: none; padding: 10px 22px; font-weight: 600; cursor: pointer;">
                                    Save Notification Preferences
                                </button>
                            </div>
                        </form>
                    </div>

                <?php else: ?>
                    <!-- TAB: Notification List -->
                    <?php if (empty($notifications)): ?>
                        <div style="background: #FFFFFF; border: 1px solid var(--border-color); border-radius: var(--border-radius); padding: 56px 24px; text-align: center; box-shadow: var(--card-shadow);">
                            <div style="font-size: 40px; margin-bottom: 12px;">🎉</div>
                            <h2 style="font-size: 18px; font-weight: 700; color: var(--navy-primary); margin-bottom: 6px;">
                                You're all caught up!
                            </h2>
                            <p style="font-size: 14px; color: var(--text-muted); max-width: 440px; margin: 0 auto 20px auto;">
                                <?= $unreadOnly ? 'You have no unread notifications right now.' : 'No notifications in your inbox. When deadlines approach or activities update, alerts will appear here.' ?>
                            </p>
                            <?php if ($unreadOnly): ?>
                                <a href="notifications.php?tab=inbox" class="btn-view-details" style="padding: 8px 16px;">
                                    View All Past Notifications
                                </a>
                            <?php else: ?>
                                <a href="activities.php" class="btn-view-details" style="padding: 8px 16px;">
                                    Explore Extracurricular Opportunities
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div>
                            <?php foreach ($notifications as $n): ?>
                                <?php 
                                $badge = getNotificationTypeBadge($n['notification_type']);
                                ?>
                                <div class="notif-card-item <?= empty($n['is_read']) ? 'unread' : '' ?>">
                                    <div style="flex: 1;">
                                        <div class="notif-meta-row">
                                            <span class="notif-type-tag" style="background: <?= $badge['bg'] ?>; color: <?= $badge['color'] ?>;">
                                                <?= htmlspecialchars($badge['label']) ?>
                                            </span>
                                            <span style="font-size: 12px; color: var(--text-muted);">
                                                <?= date('M j, Y • g:i A', strtotime($n['created_at'])) ?>
                                            </span>
                                            <?php if (empty($n['is_read'])): ?>
                                                <span style="font-size: 11px; font-weight: 700; color: var(--primary-color); display: inline-flex; align-items: center; gap: 4px;">
                                                    <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:var(--primary-color);"></span>
                                                    Unread
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="notif-item-title"><?= htmlspecialchars($n['title']) ?></div>
                                        <div class="notif-item-msg"><?= htmlspecialchars($n['message']) ?></div>

                                        <div class="notif-actions-row">
                                            <?php if (!empty($n['link'])): ?>
                                                <a href="<?= htmlspecialchars($n['link']) ?>" class="notif-link-btn">
                                                    View Details &rarr;
                                                </a>
                                            <?php endif; ?>

                                            <?php if (empty($n['is_read'])): ?>
                                                <form method="POST" action="notifications.php" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                    <input type="hidden" name="action" value="mark_read">
                                                    <input type="hidden" name="notification_id" value="<?= $n['id'] ?>">
                                                    <button type="submit" class="notif-btn-subtle">
                                                        Mark as Read
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="POST" action="notifications.php" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                <input type="hidden" name="action" value="dismiss">
                                                <input type="hidden" name="notification_id" value="<?= $n['id'] ?>">
                                                <button type="submit" class="notif-btn-subtle" onclick="return confirm('Dismiss this notification?')">
                                                    Dismiss
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

            </div>
        </main>
    </div>

</body>
</html>

