<?php
/**
 * CareerAct — Module 4: Career Details & Skill Benchmarking
 * 
 * In-depth career specification including responsibilities, education pathway,
 * multi-tier skill comparison against student competencies, career growth ladder,
 * related career roles, and recommended extracurricular activities.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/career_service.php';

requireCompletedProfile('onboarding.php?msg=complete_profile');

$userId = getCurrentUserId();
$db = getDbConnection();
$careerService = new CareerService($db);

$careerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($careerId <= 0) {
    header("Location: career_goals.php?msg=invalid_career");
    exit;
}

$successMsg = '';
$errorMsg = '';

// Handle Setting as Primary Career Goal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_primary_goal'])) {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        if ($careerService->setPrimaryCareerGoal($userId, $careerId)) {
            $successMsg = 'This career has been set as your Primary Career Goal! Your dashboard pathways and recommendations are now aligned.';
        } else {
            $errorMsg = 'Unable to set career goal. Please try again.';
        }
    } else {
        $errorMsg = 'Security token expired. Please refresh the page.';
    }
}

// Fetch complete career details payload
$career = $careerService->getCareerDetails($careerId, $userId);
if (!$career) {
    header("Location: career_goals.php?msg=career_not_found");
    exit;
}

// Student profile context
$stmtUser = $db->prepare("
    SELECT u.name, u.email, sp.experience_level, sp.education_level, sp.field_of_study, sp.institution
    FROM users u
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    WHERE u.id = :id
");
$stmtUser->execute([':id' => $userId]);
$student = $stmtUser->fetch();
$fullName = $student['name'] ?? 'Student';
$nameParts = explode(' ', trim($fullName));
$firstName = $nameParts[0];
$initials = strtoupper(substr($firstName, 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));

$comparison = $career['skill_comparison'];
$readinessPct = $comparison['readiness_pct'];
$matchScore = $career['match_score'];
$growth = $career['growth_progression'];
$isGoal = $career['is_primary_goal'];

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($career['title']) ?> — Career Details & Pathway — CareerAct</title>
    <meta name="description" content="In-depth profile, required competencies, education pathway, and activity recommendations for <?= e($career['title']) ?>">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
    <style>
        /* Strict SVG guard */
        svg {
            display: inline-block;
            vertical-align: middle;
            fill: none !important;
            stroke: currentColor;
            stroke-width: 2;
            box-sizing: border-box;
            max-width: 100%;
        }
        .sidebar-brand-icon svg { width: 20px !important; height: 20px !important; }
        .sidebar-nav svg, .logout-link svg { width: 18px !important; height: 18px !important; }

        /* Breadcrumb Bar */
        .breadcrumb-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #64748B;
            margin-bottom: 20px;
        }
        .breadcrumb-bar a {
            color: #1769E8;
            text-decoration: none;
            font-weight: 600;
        }
        .breadcrumb-bar a:hover {
            text-decoration: underline;
        }

        /* Detail Header Hero */
        .detail-header-card {
            background: #FFFFFF;
            border: 1.5px solid #E2E8F0;
            border-radius: 12px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 20px;
        }
        .detail-header-card.is-active-goal {
            border-color: #1769E8;
            background: linear-gradient(180deg, #FFFFFF 0%, #FAFCFF 100%);
            box-shadow: 0 0 0 1.5px #1769E8, 0 10px 24px rgba(23, 105, 232, 0.1);
        }
        .detail-career-title {
            font-size: 26px;
            font-weight: 800;
            color: #163A70;
            margin: 0 0 8px 0;
            letter-spacing: -0.4px;
        }
        .detail-career-domain {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            font-size: 13px;
            color: #64748B;
        }

        /* Section Layout Containers */
        .details-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }
        @media (max-width: 992px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        .section-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
        }
        .section-card-title {
            font-size: 17px;
            font-weight: 800;
            color: #163A70;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 16px 0;
            padding-bottom: 12px;
            border-bottom: 1.5px solid #F1F5F9;
        }

        /* Readiness Gauge Widget */
        .readiness-widget {
            background: #F8FAFD;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .readiness-score-box {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 22px;
            flex-shrink: 0;
        }
        .readiness-score-box.high { background: #EDFDF5; color: #059669; border: 3px solid #10B981; }
        .readiness-score-box.mid  { background: #FFFBEB; color: #D97706; border: 3px solid #F59E0B; }
        .readiness-score-box.low  { background: #FEF2F2; color: #DC2626; border: 3px solid #EF4444; }

        /* Skill Comparison Rows */
        .skill-compare-table {
            width: 100%;
            border-collapse: collapse;
        }
        .skill-compare-table th {
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            color: #64748B;
            text-transform: uppercase;
            padding: 8px 12px;
            background: #F8FAFC;
            border-bottom: 1px solid #E2E8F0;
        }
        .skill-compare-table td {
            padding: 12px;
            border-bottom: 1px solid #F1F5F9;
            font-size: 13.5px;
            color: #334155;
            vertical-align: middle;
        }
        .level-dots {
            display: inline-flex;
            gap: 3px;
        }
        .level-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #E2E8F0;
        }
        .level-dot.filled {
            background: #1769E8;
        }
        .level-dot.student {
            background: #10B981;
        }

        /* Career Growth Ladder */
        .growth-ladder {
            display: flex;
            flex-direction: column;
            gap: 12px;
            position: relative;
            padding-left: 20px;
        }
        .growth-ladder::before {
            content: '';
            position: absolute;
            left: 6px;
            top: 10px;
            bottom: 10px;
            width: 2px;
            background: #CBD5E1;
        }
        .growth-node {
            position: relative;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            padding: 12px 16px;
        }
        .growth-node::before {
            content: '';
            position: absolute;
            left: -19px;
            top: 18px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #1769E8;
            border: 2px solid #FFFFFF;
        }
        .growth-node.current {
            background: #EEF4FF;
            border-color: #1769E8;
        }

        /* Action Buttons */
        .btn-primary-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            background: #1769E8;
            color: #FFFFFF;
            border: none;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s;
        }
        .btn-primary-action:hover {
            background: #1355BD;
        }
        .btn-secondary-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 18px;
            background: #F8FAFD;
            color: #163A70;
            border: 1px solid #CBD5E1;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-secondary-action:hover {
            background: #EDF2F7;
        }
    </style>
</head>
<body class="dashboard-body">

    <!-- 1. Sidebar Navigation (14 Links) -->
    <aside class="dashboard-sidebar" id="dashboardSidebar" aria-label="Portal Navigation">
        <div>
            <div class="sidebar-header">
                <a href="dashboard.php" class="sidebar-brand-wrapper">
                    <div class="sidebar-brand-icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
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
                        <a href="career_goals.php" class="nav-link active" aria-current="page">
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
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <div class="dashboard-main">
        <!-- Top Navigation Bar -->
        <header class="dashboard-topbar">
            <div style="display:flex; align-items:center; gap:12px;">
                <button type="button" class="mobile-toggle-btn" id="mobileToggleBtn" aria-label="Toggle navigation" style="background:none; border:none; cursor:pointer;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                </button>
                <div class="topbar-greeting">
                    <span class="greeting-text">Career Pathway Details</span>
                    <span class="greeting-subtext">Competencies, qualifications, growth ladders, and extracurricular alignment</span>
                </div>
            </div>

            <div class="topbar-actions">
                <a href="career_goals.php" class="btn-secondary-action" style="padding: 6px 14px; font-size: 12.5px;">&larr; Back to Career Catalog</a>
                <div class="avatar-circle"><?= e($initials) ?></div>
            </div>
        </header>

        <!-- Main Content Viewport -->
        <main class="dashboard-content" style="max-width: 1240px; margin: 0 auto;">

            <!-- Breadcrumbs -->
            <div class="breadcrumb-bar">
                <a href="dashboard.php">Dashboard</a>
                <span>&rsaquo;</span>
                <a href="career_goals.php">Career Exploration</a>
                <span>&rsaquo;</span>
                <a href="career_goals.php?field=<?= (int)$career['field_id'] ?>"><?= e($career['field_name']) ?></a>
                <span>&rsaquo;</span>
                <span style="color:#1E293B; font-weight:700;"><?= e($career['title']) ?></span>
            </div>

            <?php if (!empty($successMsg)): ?>
                <div style="padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 13.5px; background: #EDFDF5; color: #065F46; border: 1px solid #6EE7B7;">
                    <?= e($successMsg) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errorMsg)): ?>
                <div style="padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 13.5px; background: #FEF2F2; color: #991B1B; border: 1px solid #F87171;">
                    <?= e($errorMsg) ?>
                </div>
            <?php endif; ?>

            <!-- Header Card -->
            <section class="detail-header-card <?= $isGoal ? 'is-active-goal' : '' ?>">
                <div>
                    <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px;">
                        <span class="badge-pill primary" style="background: #EEF4FF; color: #1769E8; border: 1px solid #C7D7FE;">
                            <?= e($career['field_name']) ?>
                        </span>
                        <?php if ($isGoal): ?>
                            <span class="badge-pill success">Current Primary Career Goal</span>
                        <?php else: ?>
                            <span class="badge-pill <?= $matchScore >= 80 ? 'success' : 'info' ?>">
                                <?= $matchScore ?>% Profile Compatibility
                            </span>
                        <?php endif; ?>
                    </div>

                    <h1 class="detail-career-title"><?= e($career['title']) ?></h1>

                    <div class="detail-career-domain">
                        <span>Subfield: <strong><?= e($career['subfield_name']) ?></strong></span>
                        <span>•</span>
                        <span>Experience Level: <strong><?= ucfirst($career['typical_level'] ?: 'Any') ?></strong></span>
                        <?php if (!empty($career['alt_titles'])): ?>
                            <span>•</span>
                            <span>Also known as: <em><?= e(implode(', ', $career['alt_titles'])) ?></em></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; align-items: center;">
                    <?php if (!$isGoal): ?>
                        <form action="career_details.php?id=<?= $careerId ?>" method="POST" style="margin: 0;">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="set_primary_goal" value="1">
                            <button type="submit" class="btn-primary-action">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                                <span>Set as My Career Goal</span>
                            </button>
                        </form>
                    <?php else: ?>
                        <div style="display:flex; align-items:center; gap:6px; color:#059669; font-weight:700; font-size:13.5px;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            <span>Target Goal Active</span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- 2-Column Responsive Layout -->
            <div class="details-grid">

                <!-- Left Column (Main Specifications) -->
                <div>
                    <!-- Section A: Career Overview & Responsibilities -->
                    <div class="section-card" id="sectionOverview">
                        <h2 class="section-card-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#1769E8" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                            <span>A. Career Overview & Core Responsibilities</span>
                        </h2>

                        <div style="font-size: 14.5px; color: #334155; line-height: 1.6; margin-bottom: 20px;">
                            <?= nl2br(e($career['description'] ?: 'This role focuses on executing specialized functions, collaborating across cross-functional teams, and solving domain-specific challenges.')) ?>
                        </div>

                        <?php if (!empty($career['responsibilities'])): ?>
                            <h3 style="font-size: 14px; font-weight: 700; color: #163A70; margin-bottom: 8px;">Key Responsibilities:</h3>
                            <div style="font-size: 13.5px; color: #475569; line-height: 1.6; margin-bottom: 20px;">
                                <?= nl2br(e($career['responsibilities'])) ?>
                            </div>
                        <?php endif; ?>

                        <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 14px; font-size: 12.5px; color: #64748B;">
                            <strong>Typical Work Environments:</strong> Corporate enterprises, research facilities, creative studios, remote teams, and specialized consulting firms.
                        </div>
                    </div>

                    <!-- Section B: Required Skills & Skill Comparison -->
                    <div class="section-card" id="sectionSkills">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 16px; border-bottom: 1.5px solid #F1F5F9; padding-bottom: 12px; flex-wrap:wrap; gap:8px;">
                            <h2 style="font-size: 17px; font-weight: 800; color: #163A70; display:flex; align-items:center; gap:8px; margin:0;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0F9D8A" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                                <span>B. Required Skills & Personal Readiness Comparison</span>
                            </h2>
                            <a href="skills_assessment.php?action=result" class="btn-secondary-action" style="padding: 6px 14px; font-size: 12px;">
                                View Skill-Gap in Assessment &rarr;
                            </a>
                        </div>

                        <!-- Readiness Widget -->
                        <div class="readiness-widget">
                            <?php 
                                $gaugeClass = ($readinessPct >= 75) ? 'high' : (($readinessPct >= 45) ? 'mid' : 'low');
                            ?>
                            <div class="readiness-score-box <?= $gaugeClass ?>">
                                <span><?= $readinessPct ?>%</span>
                                <span style="font-size: 10px; font-weight: 600; opacity: 0.85;">Readiness</span>
                            </div>
                            <div style="flex: 1;">
                                <div style="font-size: 15px; font-weight: 800; color: #163A70; margin-bottom: 4px;">
                                    <?= $comparison['possessed_count'] ?> of <?= $comparison['total_required'] ?> Required Competencies Acquired
                                </div>
                                <div style="font-size: 13px; color: #64748B; line-height: 1.5;">
                                    Based on your verified skills in database. Take AI scenario assessments to demonstrate pending competencies or enroll in extracurricular activities to close identified gaps.
                                </div>
                            </div>
                        </div>

                        <!-- Comparison Matrix Table -->
                        <?php if (empty($comparison['possessed_skills']) && empty($comparison['gap_skills'])): ?>
                            <div style="padding: 16px; text-align: center; color: #64748B; font-size: 13px;">
                                General professional skills apply to this role.
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="skill-compare-table">
                                    <thead>
                                        <tr>
                                            <th>Required Skill</th>
                                            <th>Category</th>
                                            <th>Required Level</th>
                                            <th>Your Current Level</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Acquired / Possessed Skills -->
                                        <?php foreach ($comparison['possessed_skills'] as $psk): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= e($psk['skill_name']) ?></strong>
                                                    <div style="font-size: 11px; color: #64748B;">Importance: <?= (int)$psk['importance'] ?>/5</div>
                                                </td>
                                                <td><span class="badge-pill neutral" style="font-size: 10.5px;"><?= e($psk['category_name']) ?></span></td>
                                                <td>
                                                    <span style="font-weight: 700; color: #163A70;">Level <?= (int)$psk['required_level'] ?>/5</span>
                                                </td>
                                                <td>
                                                    <span style="font-weight: 700; color: #059669;">Level <?= (int)$psk['current_level'] ?>/5</span>
                                                    <div style="font-size: 10.5px; color: #059669;"><?= ucfirst($psk['source']) ?></div>
                                                </td>
                                                <td>
                                                    <span class="badge-pill success" style="font-size: 10.5px;">Acquired</span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>

                                        <!-- Skill Gaps & Development Needs -->
                                        <?php foreach ($comparison['gap_skills'] as $gsk): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= e($gsk['skill_name']) ?></strong>
                                                    <div style="font-size: 11px; color: #64748B;">Importance: <?= (int)$gsk['importance'] ?>/5</div>
                                                </td>
                                                <td><span class="badge-pill neutral" style="font-size: 10.5px;"><?= e($gsk['category_name']) ?></span></td>
                                                <td>
                                                    <span style="font-weight: 700; color: #163A70;">Level <?= (int)$gsk['required_level'] ?>/5</span>
                                                </td>
                                                <td>
                                                    <?php if ($gsk['current_level'] > 0): ?>
                                                        <span style="font-weight: 700; color: #D97706;">Level <?= (int)$gsk['current_level'] ?>/5</span>
                                                        <div style="font-size: 10.5px; color: #D97706;">Needs +<?= (int)$gsk['gap'] ?> Level</div>
                                                    <?php else: ?>
                                                        <span style="color: #94A3B8; font-size: 12px;">Not in profile</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($gsk['current_level'] > 0): ?>
                                                        <span class="badge-pill warning" style="font-size: 10.5px;">Level Gap</span>
                                                    <?php else: ?>
                                                        <span class="badge-pill danger" style="font-size: 10.5px;">Needs Development</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Section C: Education & Qualifications -->
                    <div class="section-card" id="sectionEducation">
                        <h2 class="section-card-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8A46EA" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>
                            <span>C. Education & Qualifications Pathway</span>
                        </h2>

                        <div style="margin-bottom: 16px;">
                            <div style="font-size: 12px; font-weight: 700; color: #64748B; text-transform: uppercase; margin-bottom: 6px;">
                                Typical Academic Path:
                            </div>
                            <div style="font-size: 14.5px; color: #334155; line-height: 1.6;">
                                <?= e($career['education_path'] ?: 'Bachelor’s degree in a relevant discipline (or equivalent vocational experience and certified credentials).') ?>
                            </div>
                        </div>

                        <!-- Student's current education alignment -->
                        <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 16px; margin-top: 14px;">
                            <div style="font-size: 11.5px; font-weight: 700; color: #1769E8; text-transform: uppercase;">
                                Your Education Profile Alignment
                            </div>
                            <div style="font-size: 14px; font-weight: 700; color: #163A70; margin-top: 4px;">
                                <?= e(ucfirst($student['education_level'] ?? 'Undergraduate')) ?> in <?= e($student['field_of_study'] ?? 'General Studies') ?>
                            </div>
                            <div style="font-size: 12px; color: #64748B; margin-top: 2px;">
                                Institution: <?= e($student['institution'] ?? 'Current Campus') ?>
                            </div>
                        </div>
                    </div>

                    <!-- Section D: Career Growth & Progression Ladder -->
                    <div class="section-card" id="sectionGrowth">
                        <h2 class="section-card-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#D97706" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                            <span>D. Career Growth & Progression Stages</span>
                        </h2>

                        <div class="growth-ladder">
                            <div class="growth-node">
                                <div style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase;">Stage 1 • Entry Level (Years 0-2)</div>
                                <div style="font-size: 14.5px; font-weight: 700; color: #163A70; margin-top: 2px;"><?= e($growth['entry_stage']) ?></div>
                                <div style="font-size: 12px; color: #64748B; margin-top: 2px;">Focus on foundational execution, tools mastery, and collaborative team support.</div>
                            </div>

                            <div class="growth-node current">
                                <div style="font-size: 11px; font-weight: 700; color: #1769E8; text-transform: uppercase;">Stage 2 • Core Practitioner (Years 2-5)</div>
                                <div style="font-size: 14.5px; font-weight: 800; color: #163A70; margin-top: 2px;"><?= e($growth['mid_stage']) ?></div>
                                <div style="font-size: 12px; color: #64748B; margin-top: 2px;">Independent delivery of complex projects, cross-functional ownership, and mentoring.</div>
                            </div>

                            <div class="growth-node">
                                <div style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase;">Stage 3 • Senior Specialist (Years 5-8)</div>
                                <div style="font-size: 14.5px; font-weight: 700; color: #163A70; margin-top: 2px;"><?= e($growth['senior_stage']) ?></div>
                                <div style="font-size: 12px; color: #64748B; margin-top: 2px;">Architecture, strategic decision-making, and high-impact initiatives.</div>
                            </div>

                            <div class="growth-node">
                                <div style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase;">Stage 4 • Strategic Leadership (Years 8+)</div>
                                <div style="font-size: 14.5px; font-weight: 700; color: #163A70; margin-top: 2px;"><?= e($growth['leadership_stage']) ?></div>
                                <div style="font-size: 12px; color: #64748B; margin-top: 2px;">Organizational direction, portfolio stewardship, and strategic vision.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column (Related Careers & Activities) -->
                <div>
                    <!-- Section E: Related Careers -->
                    <div class="section-card" id="sectionRelatedCareers">
                        <h2 class="section-card-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#1769E8" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            <span>E. Related Careers</span>
                        </h2>

                        <?php if (empty($career['related_careers'])): ?>
                            <div style="font-size: 13px; color: #64748B; padding: 12px 0;">
                                No adjacent roles mapped in this subfield.
                            </div>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                <?php foreach ($career['related_careers'] as $rc): ?>
                                    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 14px; transition: border-color 0.15s;">
                                        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                            <a href="career_details.php?id=<?= (int)$rc['career_id'] ?>" style="font-size: 14.5px; font-weight: 700; color: #163A70; text-decoration: none;">
                                                <?= e($rc['career_title']) ?>
                                            </a>
                                            <span class="badge-pill neutral" style="font-size: 10px;">
                                                <?= e(ucfirst($rc['relation_type'] ?? 'Related')) ?>
                                            </span>
                                        </div>
                                        <div style="font-size: 12px; color: #64748B; margin: 4px 0 8px 0; line-height: 1.4;">
                                            <?= e(substr($rc['description'] ?? '', 0, 80)) ?>...
                                        </div>
                                        <a href="career_details.php?id=<?= (int)$rc['career_id'] ?>" style="font-size: 12px; font-weight: 700; color: #1769E8; text-decoration: none;">
                                            Explore Role &rarr;
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Section F: Related Extracurricular Activities -->
                    <div class="section-card" id="sectionActivities">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 12px; border-bottom: 1.5px solid #F1F5F9; padding-bottom: 10px;">
                            <h2 style="font-size: 16px; font-weight: 800; color: #163A70; display:flex; align-items:center; gap:8px; margin:0;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg>
                                <span>F. Recommended Activities</span>
                            </h2>
                            <a href="activities.php" style="font-size: 11.5px; font-weight: 700; color: #1769E8; text-decoration: none;">All &rarr;</a>
                        </div>

                        <div style="font-size: 12px; color: #64748B; margin-bottom: 14px;">
                            Campus & online activities curated to develop skills required for <?= e($career['title']) ?>:
                        </div>

                        <?php if (empty($career['extracurricular_activities'])): ?>
                            <div style="font-size: 13px; color: #64748B; padding: 12px 0;">
                                Explore our activities catalog to enroll in upcoming hackathons and projects.
                            </div>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                <?php foreach ($career['extracurricular_activities'] as $act): ?>
                                    <div style="background: #FAFCFF; border: 1px solid #E4EAF2; border-radius: 8px; padding: 14px;">
                                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px;">
                                            <span style="font-size: 13.5px; font-weight: 700; color: #163A70;">
                                                <?= e($act['activity_title']) ?>
                                            </span>
                                            <span class="badge-pill neutral" style="font-size: 9.5px;">
                                                <?= e(ucfirst($act['delivery_mode'] ?? 'Online')) ?>
                                            </span>
                                        </div>
                                        <div style="font-size: 11.5px; color: #64748B; margin-bottom: 8px; line-height: 1.4;">
                                            <?= e(substr($act['description'] ?? '', 0, 85)) ?>...
                                        </div>
                                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11.5px; border-top: 1px solid #EEF2F6; padding-top: 8px;">
                                            <span style="color: #059669; font-weight: 600;">Relevance: <?= (int)($act['relevance'] ?? 3) ?>/5</span>
                                            <a href="activities.php?id=<?= (int)$act['activity_id'] ?>" style="color: #1769E8; font-weight: 700; text-decoration: none;">
                                                Details &rarr;
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 16px; text-align: center;">
                            <a href="activities.php" class="btn-secondary-action" style="width: 100%; box-sizing: border-box; font-size: 12.5px;">
                                Browse Full Extracurricular Catalog
                            </a>
                        </div>
                    </div>
                </div>

            </div>

        </main>
    </div>

    <script>
        // Mobile sidebar toggle
        document.getElementById('mobileToggleBtn')?.addEventListener('click', () => {
            document.getElementById('dashboardSidebar')?.classList.toggle('mobile-open');
        });
    </script>
</body>
</html>

