<?php
/**
 * CareerAct — Module 4: Career Exploration & Discovery
 * 
 * Central hub for discovering career pathways across all domains,
 * filtering by industry, searching by roles/skills, and evaluating
 * personalized compatibility based on the student's profile.
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

// Handle direct details view redirect if id is passed
if (isset($_GET['id']) && (int)$_GET['id'] > 0 && !isset($_POST['set_primary_goal'])) {
    header("Location: career_details.php?id=" . (int)$_GET['id']);
    exit;
}

// Student profile context
$stmtUser = $db->prepare("
    SELECT u.name, u.email, sp.experience_level, sp.education_level, sp.field_of_study
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

$successMsg = '';
$errorMsg = '';

// Handle Career Goal Selection / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_primary_goal'])) {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $chosenCareerId = (int)$_POST['career_id'];
        if ($careerService->setPrimaryCareerGoal($userId, $chosenCareerId)) {
            $successMsg = 'Primary Career Goal successfully updated! Your pathway recommendations have been realigned.';
        } else {
            $errorMsg = 'Unable to set career goal. Please verify the selected career.';
        }
    } else {
        $errorMsg = 'Security token expired. Please refresh the page.';
    }
}

// Fetch Filter Parameters
$searchQuery = trim((string)($_GET['q'] ?? ''));
$filterFieldId = (int)($_GET['field'] ?? 0);
$filterLevel = trim((string)($_GET['level'] ?? ''));
$sortMode = trim((string)($_GET['sort'] ?? 'match'));

$filters = [
    'q'        => $searchQuery,
    'field_id' => $filterFieldId,
    'level'    => $filterLevel,
    'sort'     => $sortMode
];

// Fetch Career Fields for filter pills
$allFields = $careerService->getAllCareerFields();

// Fetch Careers matching search and filters
$careers = $careerService->searchAndFilterCareers($filters, $userId);

// Fetch current active goal details
$stmtActiveGoal = $db->prepare("
    SELECT 
        c.id AS career_id, 
        c.title AS career_title, 
        c.description,
        cf.name AS field_name,
        csf.name AS subfield_name,
        c.typical_level
    FROM student_career_goals scg
    JOIN careers c ON scg.career_id = c.id
    JOIN career_subfields csf ON c.subfield_id = csf.id
    JOIN career_fields cf ON csf.field_id = cf.id
    WHERE scg.user_id = :user_id AND scg.is_primary = 1
    LIMIT 1
");
$stmtActiveGoal->execute([':user_id' => $userId]);
$activeGoal = $stmtActiveGoal->fetch();

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Career Exploration — CareerAct</title>
    <meta name="description" content="Explore vocational pathways, discover roles, and benchmark your career readiness.">
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

        /* Exploration Page Components */
        .explore-hero {
            background: linear-gradient(135deg, #163A70 0%, #1769E8 100%);
            color: #FFFFFF;
            border-radius: 12px;
            padding: 26px 30px;
            margin-bottom: 24px;
            box-shadow: 0 8px 24px rgba(23, 105, 232, 0.16);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .explore-hero-title {
            font-size: 22px;
            font-weight: 800;
            margin: 0 0 6px 0;
            letter-spacing: -0.3px;
        }
        .explore-hero-desc {
            font-size: 13.5px;
            opacity: 0.92;
            max-width: 680px;
            margin: 0;
            line-height: 1.55;
        }

        /* Active Goal Callout Ribbon */
        .active-goal-banner {
            background: #FFFFFF;
            border: 2px solid #1769E8;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 14px rgba(23, 105, 232, 0.08);
            flex-wrap: wrap;
            gap: 14px;
        }

        /* Search & Filter Controls */
        .controls-panel {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        .search-filter-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .search-input-box {
            flex: 2;
            min-width: 260px;
            position: relative;
        }
        .search-input-box input {
            width: 100%;
            padding: 11px 16px 11px 40px;
            border: 1px solid #CBD5E1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .search-input-box input:focus {
            border-color: #1769E8;
            box-shadow: 0 0 0 3px rgba(23, 105, 232, 0.15);
        }
        .search-input-box svg {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94A3B8;
        }
        .select-filter-box {
            flex: 1;
            min-width: 160px;
        }
        .select-filter-box select {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #CBD5E1;
            border-radius: 8px;
            font-size: 13.5px;
            outline: none;
            background: #FFFFFF;
            box-sizing: border-box;
            cursor: pointer;
        }
        .btn-filter-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 11px 20px;
            background: #1769E8;
            color: #FFFFFF;
            border: none;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-filter-action:hover {
            background: #1355BD;
        }
        .btn-clear-filters {
            background: #F1F5F9;
            color: #475569;
            border: 1px solid #CBD5E1;
        }
        .btn-clear-filters:hover {
            background: #E2E8F0;
        }

        /* Domain Filter Pills */
        .domain-pills-bar {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 6px;
            scrollbar-width: thin;
        }
        .domain-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 20px;
            font-size: 12.5px;
            font-weight: 600;
            color: #475569;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .domain-pill:hover {
            background: #EDF2F7;
            color: #163A70;
            border-color: #CBD5E1;
        }
        .domain-pill.active {
            background: #1769E8;
            color: #FFFFFF;
            border-color: #1769E8;
        }

        /* Career Grid & Cards */
        .career-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 20px;
        }
        .career-card {
            background: #FFFFFF;
            border: 1.5px solid #E2E8F0;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
        }
        .career-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 24px rgba(22, 58, 112, 0.08);
            border-color: #CBD5E1;
        }
        .career-card.active-goal {
            border-color: #1769E8;
            box-shadow: 0 0 0 1.5px #1769E8, 0 8px 20px rgba(23, 105, 232, 0.12);
            background: linear-gradient(180deg, #FFFFFF 0%, #FAFCFF 100%);
        }
        .career-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        .career-card-title {
            font-size: 17px;
            font-weight: 800;
            color: #163A70;
            line-height: 1.35;
        }
        .career-card-meta {
            font-size: 12px;
            color: #64748B;
            margin-bottom: 12px;
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .career-card-desc {
            font-size: 13.5px;
            color: #475569;
            line-height: 1.5;
            margin-bottom: 16px;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            min-height: 60px;
        }
        .skills-tag-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 18px;
        }
        .skill-tag-pill {
            font-size: 11px;
            font-weight: 600;
            padding: 4px 9px;
            background: #F1F5F9;
            color: #334155;
            border-radius: 6px;
            border: 1px solid #E2E8F0;
        }
        .career-card-footer {
            display: flex;
            gap: 8px;
            align-items: center;
            padding-top: 14px;
            border-top: 1px solid #F1F5F9;
        }
        .btn-view-career {
            flex: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 9px 14px;
            background: #1769E8;
            color: #FFFFFF;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.15s;
        }
        .btn-view-career:hover {
            background: #1355BD;
        }
        .btn-set-goal {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 9px 12px;
            background: #F8FAFD;
            color: #163A70;
            border: 1px solid #CBD5E1;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-set-goal:hover {
            background: #EDF2F7;
            border-color: #94A3B8;
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
                    <li><a href="dashboard.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect></svg><span>Dashboard</span></a></li>
                    <li><a href="profile.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><span>My Profile</span></a></li>
                    <li><a href="career_goals.php" class="nav-link active" aria-current="page"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg><span>Career Exploration</span></a></li>
                    <li><a href="my_skills.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg><span>My Skills</span></a></li>
                    <li><a href="skills_assessment.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg><span>Skill Assessment</span></a></li>
                    <li><a href="skill_gap.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg><span>Skill-Gap Analysis</span></a></li>
                    <li><a href="recommendations.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path></svg><span>Recommendations</span></a></li>
                    <li><a href="activities.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg><span>Explore Activities</span></a></li>
                    <li><a href="saved_activities.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path></svg><span>Saved Activities</span></a></li>
                    <li><a href="enrollments.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg><span>My Enrollments</span></a></li>
                    <li><a href="portfolio.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg><span>My Portfolio</span></a></li>
                    <li><a href="resume.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg><span>Resume Builder</span></a></li>
                    <li><a href="settings.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg><span>Settings</span></a></li>
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
                    <span class="greeting-text">Career Exploration</span>
                    <span class="greeting-subtext">Discover career roles, required competencies, and personalized pathways</span>
                </div>
            </div>

            <div class="topbar-actions">
                <div class="avatar-circle"><?= e($initials) ?></div>
            </div>
        </header>

        <!-- Main Content Viewport -->
        <main class="dashboard-content" style="max-width: 1240px; margin: 0 auto;">

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

            <!-- Hero Banner -->
            <section class="explore-hero" aria-label="Career Exploration Overview">
                <div>
                    <div style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; opacity: 0.85; margin-bottom: 4px;">
                        Module 4 • Multi-Domain Career Explorer
                    </div>
                    <h1 class="explore-hero-title">Discover Your Ideal Professional Pathway</h1>
                    <p class="explore-hero-desc">
                        Explore careers across Technology, Engineering, Healthcare, Business, Law, Creative Arts, and Public Services. Benchmark your current competencies and identify growth milestones.
                    </p>
                </div>
                <div>
                    <span class="badge-pill" style="background: rgba(255,255,255,0.2); color:#FFFFFF; font-size: 12px; padding: 6px 14px;">
                        <?= count($careers) ?> Careers Available
                    </span>
                </div>
            </section>

            <!-- Active Career Goal Callout Ribbon -->
            <?php if ($activeGoal): ?>
                <div class="active-goal-banner">
                    <div style="display: flex; align-items: center; gap: 14px;">
                        <div style="width: 44px; height: 44px; border-radius: 8px; background: #EEF4FF; display:flex; align-items:center; justify-content:center; color:#1769E8; flex-shrink:0;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg>
                        </div>
                        <div>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span style="font-size: 11px; font-weight: 700; color: #1769E8; text-transform: uppercase; letter-spacing: 0.5px;">Your Primary Career Goal</span>
                                <span class="badge-pill success" style="font-size: 10px;">Active Track</span>
                            </div>
                            <div style="font-size: 17px; font-weight: 800; color: #163A70; margin-top: 2px;">
                                <?= e($activeGoal['career_title']) ?>
                            </div>
                            <div style="font-size: 12px; color: #64748B;">
                                Domain: <strong><?= e($activeGoal['field_name']) ?></strong> • <?= e($activeGoal['subfield_name']) ?>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <a href="career_details.php?id=<?= (int)$activeGoal['career_id'] ?>" class="btn-view-career" style="width: auto; padding: 8px 18px;">
                            <span>View Goal Pathway &rarr;</span>
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div style="background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 10px; padding: 14px 18px; margin-bottom: 24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div>
                        <strong style="color: #92400E; font-size: 14px;">No Primary Career Goal Selected Yet</strong>
                        <div style="color: #B45309; font-size: 12.5px; margin-top: 2px;">Select a career below to align your skill benchmarks and extracurricular recommendations.</div>
                    </div>
                    <span style="font-size: 12px; color: #D97706; font-weight: 600;">Choose a career below</span>
                </div>
            <?php endif; ?>

            <!-- Search & Filter Control Panel -->
            <div class="controls-panel">
                <form action="career_goals.php" method="GET">
                    <div class="search-filter-row">
                        <!-- Search Box -->
                        <div class="search-input-box">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            <input 
                                type="text" 
                                name="q" 
                                value="<?= e($searchQuery) ?>" 
                                placeholder="Search by career title, domain, required skill, or keywords..."
                                aria-label="Search Careers"
                            >
                        </div>

                        <!-- Level Filter -->
                        <div class="select-filter-box">
                            <select name="level" aria-label="Filter by Experience Level">
                                <option value="">All Experience Levels</option>
                                <option value="entry" <?= $filterLevel === 'entry' ? 'selected' : '' ?>>Entry Level</option>
                                <option value="mid" <?= $filterLevel === 'mid' ? 'selected' : '' ?>>Mid Level</option>
                                <option value="senior" <?= $filterLevel === 'senior' ? 'selected' : '' ?>>Senior Level</option>
                            </select>
                        </div>

                        <!-- Sort Filter -->
                        <div class="select-filter-box">
                            <select name="sort" aria-label="Sort Order">
                                <option value="match" <?= $sortMode === 'match' ? 'selected' : '' ?>>Highest Match First</option>
                                <option value="title" <?= $sortMode === 'title' ? 'selected' : '' ?>>Alphabetical (A-Z)</option>
                                <option value="skills" <?= $sortMode === 'skills' ? 'selected' : '' ?>>Most Skills Required</option>
                            </select>
                        </div>

                        <!-- Actions -->
                        <button type="submit" class="btn-filter-action">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            <span>Search</span>
                        </button>

                        <?php if (!empty($searchQuery) || $filterFieldId > 0 || !empty($filterLevel)): ?>
                            <a href="career_goals.php" class="btn-filter-action btn-clear-filters" title="Reset all filters">
                                <span>Clear Filters</span>
                            </a>
                        <?php endif; ?>
                    </div>

                    <!-- Domain Filter Pills -->
                    <div style="font-size: 11.5px; font-weight: 700; color: #64748B; text-transform: uppercase; margin-bottom: 8px;">
                        Career Domains:
                    </div>
                    <div class="domain-pills-bar">
                        <a href="career_goals.php<?= !empty($searchQuery) ? '?q=' . urlencode($searchQuery) : '' ?>" class="domain-pill <?= $filterFieldId === 0 ? 'active' : '' ?>">
                            All Domains
                        </a>
                        <?php foreach ($allFields as $f): ?>
                            <?php $isActiveField = ($filterFieldId === (int)$f['field_id']); ?>
                            <a 
                                href="career_goals.php?field=<?= (int)$f['field_id'] ?><?= !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : '' ?>" 
                                class="domain-pill <?= $isActiveField ? 'active' : '' ?>"
                            >
                                <span><?= e($f['field_name']) ?></span>
                                <span style="font-size: 10.5px; opacity: 0.8;">(<?= (int)$f['career_count'] ?>)</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>

            <!-- Career Count and Match Summary -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 16px; flex-wrap:wrap; gap:8px;">
                <div style="font-size: 14px; color: #475569;">
                    Showing <strong><?= count($careers) ?></strong> careers
                    <?php if (!empty($searchQuery)): ?>
                        matching <em>"<?= e($searchQuery) ?>"</em>
                    <?php endif; ?>
                    <?php if ($filterFieldId > 0): ?>
                        in selected domain
                    <?php endif; ?>
                </div>
            </div>

            <!-- Career Cards Grid -->
            <?php if (empty($careers)): ?>
                <div class="module-empty-state" style="padding: 48px 20px;">
                    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="1.8"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <div class="module-empty-title">No Matching Careers Found</div>
                    <div class="module-empty-desc">
                        No careers matched your search criteria. Try using different keywords, searching for a specific skill, or clearing your domain filter.
                    </div>
                    <a href="career_goals.php" class="btn-filter-action" style="margin-top: 14px; width: auto; display: inline-flex;">
                        Reset All Filters
                    </a>
                </div>
            <?php else: ?>
                <div class="career-cards-grid">
                    <?php foreach ($careers as $cr): ?>
                        <?php 
                            $isGoal = $cr['is_primary_goal'];
                            $matchPct = (int)$cr['match_pct'];
                            $matchBadgeClass = ($matchPct >= 80) ? 'success' : (($matchPct >= 50) ? 'warning' : 'neutral');
                        ?>
                        <div class="career-card <?= $isGoal ? 'active-goal' : '' ?>">
                            <div>
                                <div class="career-card-header">
                                    <h3 class="career-card-title"><?= e($cr['career_title']) ?></h3>
                                    <?php if ($isGoal): ?>
                                        <span class="badge-pill success" style="font-size: 10.5px;">Active Goal</span>
                                    <?php else: ?>
                                        <span class="badge-pill <?= $matchBadgeClass ?>" style="font-size: 10.5px;">
                                            <?= $matchPct ?>% Match
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="career-card-meta">
                                    <span class="badge-pill primary" style="font-size: 10px; background: #EEF4FF; color: #1769E8; border: 1px solid #C7D7FE;">
                                        <?= e($cr['field_name']) ?>
                                    </span>
                                    <span style="font-size: 11.5px; color: #64748B;">
                                        <?= e($cr['subfield_name']) ?>
                                    </span>
                                    <span style="font-size: 11.5px; color: #94A3B8;">•</span>
                                    <span style="font-size: 11.5px; color: #64748B;">
                                        <?= ucfirst($cr['typical_level'] ?: 'All Levels') ?>
                                    </span>
                                </div>

                                <p class="career-card-desc">
                                    <?= e($cr['description'] ?: 'Explore role responsibilities, required technical and soft competencies, and growth milestones.') ?>
                                </p>

                                <!-- Key Required Skills -->
                                <div style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; margin-bottom: 6px;">
                                    Key Required Skills:
                                </div>
                                <div class="skills-tag-list">
                                    <?php if (!empty($cr['required_skills'])): ?>
                                        <?php foreach ($cr['required_skills'] as $rsk): ?>
                                            <span class="skill-tag-pill">
                                                <?= e($rsk['skill_name']) ?> <span style="color:#1769E8; font-weight:700;">L<?= (int)$rsk['required_level'] ?></span>
                                            </span>
                                        <?php endforeach; ?>
                                        <?php if ($cr['total_required_skills_count'] > count($cr['required_skills'])): ?>
                                            <span class="skill-tag-pill" style="background:#FFFFFF; color:#64748B; border-style:dashed;">
                                                +<?= ($cr['total_required_skills_count'] - count($cr['required_skills'])) ?> more
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="font-size: 12px; color: #94A3B8;">General vocational skills</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="career-card-footer">
                                <a href="career_details.php?id=<?= (int)$cr['career_id'] ?>" class="btn-view-career">
                                    <span>View Career Details</span>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                </a>

                                <?php if (!$isGoal): ?>
                                    <form action="career_goals.php" method="POST" style="margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="career_id" value="<?= (int)$cr['career_id'] ?>">
                                        <input type="hidden" name="set_primary_goal" value="1">
                                        <button type="submit" class="btn-set-goal" title="Set as your primary target career goal">
                                            <span>Set as Goal</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

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
