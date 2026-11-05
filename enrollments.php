<?php
/**
 * CareerAct — Activity Enrollment and Tracking Module (Feature 4)
 * 
 * "My Activities" center allowing students to track their extracurricular journey:
 * Saved -> Registered -> In Progress -> Completed.
 * Includes deadline countdowns, status transitions, and achievement integration.
 * 
 * Database: carrearact2
 */

declare(strict_types=1);

if (!headers_sent()) {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/event_discovery_service.php';

requireCompletedProfile('onboarding.php?msg=complete_profile');

$userId = getCurrentUserId();
$db = getDbConnection();

// Fetch student profile info
$stmtUser = $db->prepare("SELECT name, email FROM users WHERE id = :user_id LIMIT 1");
$stmtUser->execute([':user_id' => $userId]);
$student = $stmtUser->fetch(PDO::FETCH_ASSOC);
$fullName = $student['name'] ?? 'Student';
$initials = strtoupper(substr($fullName, 0, 1));

// Auto-expire past deadline opportunities
autoExpirePastOpportunities($db);

$successMsg = '';
$errorMsg   = '';

// Handle Status Transitions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $action = trim((string)($_POST['action'] ?? ''));
        $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);

        if ($enrollmentId > 0) {
            if ($action === 'start_activity') {
                // Registered -> In Progress
                $stmtStart = $db->prepare("
                    UPDATE enrollments 
                    SET status = 'in_progress', 
                        started_at = COALESCE(started_at, CURDATE()),
                        progress_pct = GREATEST(progress_pct, 25),
                        updated_at = NOW()
                    WHERE id = :eid AND user_id = :uid
                ");
                $stmtStart->execute([':eid' => $enrollmentId, ':uid' => $userId]);
                $successMsg = 'Activity marked as In Progress! Work on your goals and submit completion evidence when done.';
            } elseif ($action === 'cancel_enrollment') {
                // Any -> Cancelled
                $stmtCancel = $db->prepare("
                    UPDATE enrollments 
                    SET status = 'cancelled', updated_at = NOW()
                    WHERE id = :eid AND user_id = :uid
                ");
                $stmtCancel->execute([':eid' => $enrollmentId, ':uid' => $userId]);
                $successMsg = 'Activity has been cancelled and moved to archived.';
            } elseif ($action === 'register_saved') {
                // Saved -> Registered
                $stmtReg = $db->prepare("
                    UPDATE enrollments 
                    SET status = 'registered', updated_at = NOW()
                    WHERE id = :eid AND user_id = :uid
                ");
                $stmtReg->execute([':eid' => $enrollmentId, ':uid' => $userId]);
                $successMsg = 'Confirmed registration! Track this activity in your active progress.';
            }
        }
    } else {
        $errorMsg = 'Security validation failed (invalid CSRF token). Please try again.';
    }
}

// 1. Fetch Counts for Status Cards from Actual Database Records
$stmtCounts = $db->prepare("
    SELECT 
        SUM(CASE WHEN status = 'saved' THEN 1 ELSE 0 END) AS saved_count,
        SUM(CASE WHEN status = 'registered' THEN 1 ELSE 0 END) AS registered_count,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count
    FROM enrollments
    WHERE user_id = :uid
");
$stmtCounts->execute([':uid' => $userId]);
$counts = $stmtCounts->fetch(PDO::FETCH_ASSOC) ?: [];

$savedCount      = (int)($counts['saved_count'] ?? 0);
$registeredCount = (int)($counts['registered_count'] ?? 0);
$inProgressCount = (int)($counts['in_progress_count'] ?? 0);
$completedCount  = (int)($counts['completed_count'] ?? 0);

// Upcoming deadlines count (registered or saved with deadline within 14 days)
$stmtUpcoming = $db->prepare("
    SELECT COUNT(*) 
    FROM enrollments e
    JOIN opportunities o ON e.opportunity_id = o.id
    WHERE e.user_id = :uid 
      AND e.status IN ('saved', 'registered', 'in_progress')
      AND o.registration_deadline IS NOT NULL
      AND o.registration_deadline >= CURDATE()
      AND o.registration_deadline <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
");
$stmtUpcoming->execute([':uid' => $userId]);
$upcomingDeadlinesCount = (int)$stmtUpcoming->fetchColumn();

// 2. Filter Tab
$currentTab = trim((string)($_GET['tab'] ?? 'all'));
$tabCondition = "";
if ($currentTab === 'saved') {
    $tabCondition = "AND e.status = 'saved'";
} elseif ($currentTab === 'registered') {
    $tabCondition = "AND e.status = 'registered'";
} elseif ($currentTab === 'in_progress') {
    $tabCondition = "AND e.status = 'in_progress'";
} elseif ($currentTab === 'completed') {
    $tabCondition = "AND e.status = 'completed'";
} elseif ($currentTab === 'deadlines') {
    $tabCondition = "AND e.status IN ('saved', 'registered', 'in_progress') AND o.registration_deadline IS NOT NULL AND o.registration_deadline >= CURDATE()";
} else {
    $tabCondition = "AND e.status IN ('saved', 'registered', 'in_progress', 'completed')";
}

// 3. Fetch Student Activities
$stmtActivities = $db->prepare("
    SELECT 
        e.id AS enrollment_id,
        e.status,
        e.progress_pct,
        e.started_at,
        e.completed_at,
        e.created_at AS enrolled_at,
        e.notes,
        a.id AS activity_id,
        a.title AS activity_title,
        a.description AS activity_desc,
        a.delivery_mode AS act_delivery_mode,
        a.typical_hours,
        ac.name AS category_name,
        ac.code AS category_code,
        o.id AS opportunity_id,
        COALESCE(o.title, a.title) AS opp_title,
        o.registration_deadline,
        o.start_date,
        o.end_date,
        o.registration_url,
        o.source_url,
        o.state_name,
        o.venue,
        COALESCE(p.name, 'National Academic Partner') AS organizer_name,
        DATEDIFF(o.registration_deadline, CURDATE()) AS days_left
    FROM enrollments e
    JOIN activities a ON e.activity_id = a.id
    LEFT JOIN activity_categories ac ON a.category_id = ac.id
    LEFT JOIN opportunities o ON e.opportunity_id = o.id
    LEFT JOIN providers p ON o.provider_id = p.id
    WHERE e.user_id = :uid {$tabCondition}
    ORDER BY (e.status = 'completed') ASC, 
             (CASE WHEN o.registration_deadline >= CURDATE() THEN 0 ELSE 1 END) ASC,
             e.created_at DESC
");
$stmtActivities->execute([':uid' => $userId]);
$activitiesList = $stmtActivities->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Activities & Tracking — CareerAct</title>
    <meta name="description" content="Manage your extracurricular activity journey from discovery to completion.">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= time() ?>">
    <style>
        svg { fill: none !important; stroke: currentColor !important; display: inline-block; vertical-align: middle; }
        
        /* Metric Summary Cards Grid */
        .metrics-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .metric-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            padding: 18px 20px;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.03);
            display: flex;
            align-items: center;
            gap: 14px;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s ease;
        }
        .metric-card:hover {
            border-color: #1769E8;
            transform: translateY(-2px);
        }
        .metric-icon-box {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .metric-num {
            font-size: 22px;
            font-weight: 800;
            color: #0F172A;
            line-height: 1.1;
        }
        .metric-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748B;
            margin-top: 2px;
        }

        /* Navigation Filter Tabs */
        .tabs-nav-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 24px;
            border-bottom: 1px solid #E2E8F0;
            padding-bottom: 12px;
        }
        .tab-btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            color: #475569;
            text-decoration: none;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .tab-btn:hover {
            color: #1769E8;
            border-color: #CBD5E1;
        }
        .tab-btn.active {
            background: #1769E8;
            color: #FFFFFF;
            border-color: #1769E8;
        }
        .tab-count-pill {
            background: rgba(0,0,0,0.08);
            padding: 2px 7px;
            border-radius: 12px;
            font-size: 11px;
        }
        .tab-btn.active .tab-count-pill {
            background: rgba(255,255,255,0.25);
            color: #FFFFFF;
        }

        /* Activity Tracking Cards Grid */
        .activities-tracking-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        .activity-track-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.2s ease;
        }
        .activity-track-card:hover {
            border-color: #CBD5E1;
            box-shadow: 0 8px 16px rgba(15, 23, 42, 0.06);
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-saved       { background: #EFF6FF; color: #1E40AF; border: 1px solid #BFDBFE; }
        .status-registered  { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; }
        .status-in_progress { background: #EEF2FF; color: #4338CA; border: 1px solid #C7D2FE; }
        .status-completed   { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; }
        .status-cancelled   { background: #F1F5F9; color: #64748B; border: 1px solid #E2E8F0; }

        .deadline-indicator {
            font-size: 11.5px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .deadline-urgent { color: #DC2626; }
        .deadline-normal { color: #475569; }

        .btn-action-primary {
            background: #1769E8;
            color: #FFFFFF;
            font-size: 12.5px;
            font-weight: 700;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-action-primary:hover {
            background: #1255C0;
            color: #FFFFFF;
        }
        .btn-action-success {
            background: #059669;
            color: #FFFFFF;
            font-size: 12.5px;
            font-weight: 700;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-action-success:hover {
            background: #047857;
            color: #FFFFFF;
        }
        .btn-action-secondary {
            background: #FFFFFF;
            color: #475569;
            font-size: 12.5px;
            font-weight: 600;
            padding: 7px 14px;
            border-radius: 6px;
            text-decoration: none;
            border: 1px solid #CBD5E1;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-action-secondary:hover {
            background: #F1F5F9;
        }
    </style>
</head>
<body class="dashboard-body">

    <!-- Sidebar Navigation -->
    <aside class="dashboard-sidebar" id="dashboardSidebar" aria-label="Portal Navigation">
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
                        <a href="enrollments.php" class="nav-link active" aria-current="page">
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
            <a href="logout.php" class="logout-link"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg><span>Logout</span></a>
        </div>
    </aside>

    <!-- Main Content Area -->
    <div class="dashboard-main">
        <header class="dashboard-topbar">
            <div class="topbar-welcome">
                <span class="welcome-title">My Activities</span>
                <span class="welcome-subtitle">Manage your extracurricular opportunities from discovery to completion</span>
            </div>
            <div class="topbar-actions">
                <a href="activities.php" class="btn-view-details" style="padding: 7px 14px;">+ Discover Activities</a>
                <a href="profile.php" class="user-profile-chip">
                    <div class="avatar-circle"><?= e($initials) ?></div>
                    <div class="user-text-info"><span class="user-name"><?= e($fullName) ?></span><span class="user-level">Student</span></div>
                </a>
            </div>
        </header>

        <main class="dashboard-content" style="max-width: 1200px; margin: 0 auto; padding: 24px 20px;">

            <?php if (!empty($successMsg)): ?>
                <div style="background:#ECFDF5; border:1px solid #A7F3D0; color:#065F46; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-size:13.5px;">
                    <?= e($successMsg) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($errorMsg)): ?>
                <div style="background:#FEF2F2; border:1px solid #FECACA; color:#991B1B; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-size:13.5px;">
                    <?= e($errorMsg) ?>
                </div>
            <?php endif; ?>

            <!-- Metrics Summary Cards -->
            <div class="metrics-summary-grid">
                <a href="enrollments.php?tab=saved" class="metric-card">
                    <div class="metric-icon-box" style="background:#EFF6FF; color:#1E40AF;">
                        <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path></svg>
                    </div>
                    <div>
                        <div class="metric-num"><?= $savedCount ?></div>
                        <div class="metric-label">Saved Activities</div>
                    </div>
                </a>

                <a href="enrollments.php?tab=registered" class="metric-card">
                    <div class="metric-icon-box" style="background:#FEF3C7; color:#92400E;">
                        <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg>
                    </div>
                    <div>
                        <div class="metric-num"><?= $registeredCount ?></div>
                        <div class="metric-label">Registered</div>
                    </div>
                </a>

                <a href="enrollments.php?tab=in_progress" class="metric-card">
                    <div class="metric-icon-box" style="background:#EEF2FF; color:#4338CA;">
                        <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    </div>
                    <div>
                        <div class="metric-num"><?= $inProgressCount ?></div>
                        <div class="metric-label">In Progress</div>
                    </div>
                </a>

                <a href="enrollments.php?tab=completed" class="metric-card">
                    <div class="metric-icon-box" style="background:#ECFDF5; color:#065F46;">
                        <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </div>
                    <div>
                        <div class="metric-num"><?= $completedCount ?></div>
                        <div class="metric-label">Completed</div>
                    </div>
                </a>

                <a href="enrollments.php?tab=deadlines" class="metric-card">
                    <div class="metric-icon-box" style="background:#FEF2F2; color:#DC2626;">
                        <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    </div>
                    <div>
                        <div class="metric-num"><?= $upcomingDeadlinesCount ?></div>
                        <div class="metric-label">Closing in ≤14d</div>
                    </div>
                </a>
            </div>

            <!-- Filter Tabs Navigation -->
            <div class="tabs-nav-bar">
                <a href="enrollments.php?tab=all" class="tab-btn <?= ($currentTab === 'all') ? 'active' : '' ?>">
                    All Activities <span class="tab-count-pill"><?= ($savedCount + $registeredCount + $inProgressCount + $completedCount) ?></span>
                </a>
                <a href="enrollments.php?tab=saved" class="tab-btn <?= ($currentTab === 'saved') ? 'active' : '' ?>">
                    Saved <span class="tab-count-pill"><?= $savedCount ?></span>
                </a>
                <a href="enrollments.php?tab=registered" class="tab-btn <?= ($currentTab === 'registered') ? 'active' : '' ?>">
                    Registered <span class="tab-count-pill"><?= $registeredCount ?></span>
                </a>
                <a href="enrollments.php?tab=in_progress" class="tab-btn <?= ($currentTab === 'in_progress') ? 'active' : '' ?>">
                    In Progress <span class="tab-count-pill"><?= $inProgressCount ?></span>
                </a>
                <a href="enrollments.php?tab=completed" class="tab-btn <?= ($currentTab === 'completed') ? 'active' : '' ?>">
                    Completed <span class="tab-count-pill"><?= $completedCount ?></span>
                </a>
                <a href="enrollments.php?tab=deadlines" class="tab-btn <?= ($currentTab === 'deadlines') ? 'active' : '' ?>">
                    Upcoming Deadlines <span class="tab-count-pill"><?= $upcomingDeadlinesCount ?></span>
                </a>
            </div>

            <!-- Activities Cards Grid -->
            <?php if (!empty($activitiesList)): ?>
                <div class="activities-tracking-grid">
                    <?php foreach ($activitiesList as $item): 
                        $status = (string)$item['status'];
                        $daysLeft = $item['days_left'] !== null ? (int)$item['days_left'] : null;
                    ?>
                        <div class="activity-track-card">
                            <div>
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                    <span class="status-badge status-<?= $status ?>">
                                        ● <?= ucfirst(str_replace('_', ' ', $status)) ?>
                                    </span>
                                    <?php if ($daysLeft !== null): ?>
                                        <span class="deadline-indicator <?= ($daysLeft <= 5 && $daysLeft >= 0) ? 'deadline-urgent' : 'deadline-normal' ?>">
                                            <svg width="13" height="13" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                            <?php if ($daysLeft < 0): ?>
                                                Deadline Passed
                                            <?php elseif ($daysLeft === 0): ?>
                                                Closes Today
                                            <?php else: ?>
                                                <?= $daysLeft ?> days left
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <h2 style="font-size: 16px; font-weight: 800; color: #0F172A; margin: 4px 0 6px 0; line-height: 1.35;">
                                    <?= e($item['opp_title']) ?>
                                </h2>

                                <div style="font-size: 12px; color: #64748B; margin-bottom: 12px;">
                                    By <strong><?= e($item['organizer_name']) ?></strong> • <?= ucfirst(e($item['act_delivery_mode'])) ?> (<?= e($item['category_name']) ?>)
                                </div>

                                <div style="font-size: 12.5px; color: #475569; line-height: 1.5; margin-bottom: 14px;">
                                    <?= e(mb_strimwidth($item['activity_desc'], 0, 140, '...')) ?>
                                </div>

                                <?php if ($status === 'in_progress'): ?>
                                    <div style="margin-bottom: 14px;">
                                        <div style="display: flex; justify-content: space-between; font-size: 11.5px; font-weight: 700; color: #4338CA; margin-bottom: 4px;">
                                            <span>In Progress</span>
                                            <span><?= (int)$item['progress_pct'] ?>%</span>
                                        </div>
                                        <div style="height: 6px; background: #E0E7FF; border-radius: 4px; overflow: hidden;">
                                            <div style="height: 100%; width: <?= (int)$item['progress_pct'] ?>%; background: #4F46E5;"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Contextual Action Buttons -->
                            <div style="border-top: 1px solid #F1F5F9; padding-top: 14px; display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;">
                                <div>
                                    <a href="activity_details.php?id=<?= (int)$item['activity_id'] ?><?= !empty($item['opportunity_id']) ? '&opp_id=' . (int)$item['opportunity_id'] : '' ?>" class="btn-action-secondary" style="font-size: 11.5px; padding: 6px 12px;">
                                        Details &rarr;
                                    </a>
                                </div>

                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <?php if ($status === 'saved'): ?>
                                        <form method="POST" action="enrollments.php" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="register_saved">
                                            <input type="hidden" name="enrollment_id" value="<?= (int)$item['enrollment_id'] ?>">
                                            <button type="submit" class="btn-action-primary" title="Confirm you have registered">
                                                ✓ Register
                                            </button>
                                        </form>
                                    <?php elseif ($status === 'registered'): ?>
                                        <form method="POST" action="enrollments.php" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="start_activity">
                                            <input type="hidden" name="enrollment_id" value="<?= (int)$item['enrollment_id'] ?>">
                                            <button type="submit" class="btn-action-primary">
                                                Mark as Started
                                            </button>
                                        </form>
                                    <?php elseif ($status === 'in_progress'): ?>
                                        <a href="complete_activity.php?id=<?= (int)$item['enrollment_id'] ?>" class="btn-action-success">
                                            Complete Activity &rarr;
                                        </a>
                                    <?php elseif ($status === 'completed'): ?>
                                        <a href="portfolio.php" class="btn-action-secondary" style="color: #065F46; border-color: #A7F3D0; background: #ECFDF5;">
                                            ★ View Achievement
                                        </a>
                                    <?php endif; ?>

                                    <?php if (in_array($status, ['saved', 'registered'])): ?>
                                        <form method="POST" action="enrollments.php" style="display:inline;" onsubmit="return confirm('Cancel this activity?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="cancel_enrollment">
                                            <input type="hidden" name="enrollment_id" value="<?= (int)$item['enrollment_id'] ?>">
                                            <button type="submit" style="background: none; border: none; color: #94A3B8; cursor: pointer; font-size: 11px; padding: 4px;" title="Cancel this activity">
                                                Cancel
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 14px; padding: 48px 24px; text-align: center;">
                    <div style="font-size: 18px; font-weight: 800; color: #0F172A; margin-bottom: 8px;">No Activities in this View</div>
                    <p style="font-size: 13.5px; color: #64748B; margin-bottom: 20px;">You don't currently have any activities matching this category.</p>
                    <a href="activities.php" class="btn-action-primary" style="display: inline-flex;">Explore National Opportunities</a>
                </div>
            <?php endif; ?>

        </main>
    </div>
</body>
</html>
