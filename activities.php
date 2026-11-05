<?php
/**
 * CareerAct — Module 9: Explore Activities & Events Across India
 * 
 * Screen 1: Category Tiles Only (Hackathons, Competitions, Internships, Workshops,
 *           Projects, Leadership, Volunteering, Research, Conferences, Quizzes,
 *           Mentorships, Entrepreneurship, Festivals, Sports).
 * Screen 2: Dedicated Category Opportunity Listing across Indian States, Cities & All India,
 *           with Gemini AI Discovery Intelligence, scope filters, verified source URLs,
 *           and non-contaminating Save/Enroll actions.
 * 
 * Database: carrearact2
 */

declare(strict_types=1);

// Prevent browser from caching stale CSS or layout states
if (!headers_sent()) {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/event_discovery_service.php';
require_once __DIR__ . '/includes/activity_discovery_service.php';

// Enforce authentication & profile completion
requireCompletedProfile('onboarding.php?msg=complete_profile');

$userId = getCurrentUserId();
$db = getDbConnection();

// 1. Retrieve Authenticated Student Details
$stmtUser = $db->prepare("
    SELECT 
        u.id, u.name, u.email, u.role,
        sp.city, sp.preferred_mode, sp.experience_level, sp.education_level
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.id = :user_id
    LIMIT 1
");
$stmtUser->execute([':user_id' => $userId]);
$student = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header('Location: logout.php');
    exit;
}

$fullName  = trim($student['name'] ?? 'Student');
$nameParts = explode(' ', $fullName);
$firstName = $nameParts[0];
$initials  = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));

// 2. Handle POST Actions (Save Opportunity / Enroll Activity / Update Preferred City)
$successMsg = '';
$errorMsg   = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (verifyCsrfToken($submittedToken)) {
        
        // Action A: Update Preferred Location
        if (isset($_POST['set_preferred_city'])) {
            $newCity = trim((string)$_POST['set_preferred_city']);
            if ($newCity !== '') {
                setStudentPreferredCity($db, $userId, $newCity);
                $student['city'] = $newCity;
                $successMsg = "Preferred location updated to {$newCity}. Showing opportunities in your area.";
            }
        }

        // Action B: Save / Enroll in an Opportunity (Strictly Future Opportunity - Does NOT Mark Completed)
        if (isset($_POST['enroll_opp_id'])) {
            $oppId = (int)$_POST['enroll_opp_id'];
            $actId = (int)($_POST['activity_id'] ?? 0);
            $actionType = trim((string)($_POST['action_type'] ?? 'saved')); // 'saved' or 'registered'

            if ($actId > 0) {
                $res = saveOrEnrollOpportunity($db, $userId, $actId, $oppId, $actionType);
                if ($res['success']) {
                    $successMsg = ($actionType === 'saved') 
                        ? 'Opportunity saved to your portal under "Saved Activities". It will not alter your past experience until verified completion.' 
                        : 'Successfully registered for this opportunity! Track your progress in "My Enrollments".';
                }
            }
        }

        // Action C: Live AI Activity Discovery
        if (isset($_POST['run_ai_discovery'])) {
            $discoveryService = new ActivityDiscoveryService($db);
            $discResult = $discoveryService->discoverActivitiesLive($userId, ['location' => $studentPreferredCity]);
            $successMsg = "✦ AI Activity Discovery Complete! Synchronized {$discResult['ingested_count']} verified opportunities across India aligned with your career goal ({$discResult['career_title']}).";
            $aiDiscoveryProgress = $discResult['progress_steps'];
        }
    } else {
        $errorMsg = 'Security validation failed (invalid CSRF token). Please try again.';
    }
}

// 3. Indian States, Union Territories & City Taxonomy
$indianStatesData = getIndianStatesAndCities();
$studentPreferredCity = getStudentPreferredCity($db, $userId);

// Resolve Current State:
$selectedState = trim((string)($_GET['state'] ?? ''));
if ($selectedState === '') {
    foreach ($indianStatesData as $stName => $stInfo) {
        if (in_array(strtolower($studentPreferredCity), array_map('strtolower', $stInfo['cities']), true)) {
            $selectedState = $stName;
            break;
        }
    }
    if ($selectedState === '') {
        $selectedState = 'All India';
    }
}

// Resolve Current City:
$selectedCity = trim((string)($_GET['city'] ?? ''));
if ($selectedCity === '' && $selectedState !== 'All India') {
    $selectedCity = $studentPreferredCity;
}

$isAllIndia = ($selectedState === 'All India' || strtolower($selectedState) === 'all' || strtolower($selectedCity) === 'all india');

// 4. Retrieve Student's Target Career Goal
$activeGoal = getStudentTargetCareer($db, $userId);
$careerId   = $activeGoal ? (int)$activeGoal['career_id'] : 4;

// 5. Determine Screen Mode: Screen 1 (Category Tiles Only) vs. Screen 2 (Category Opportunity Listing)
$categoryParam = trim((string)($_GET['category'] ?? ($_GET['cat_id'] ?? '')));
$isCategoryView = ($categoryParam !== '');

$selectedCategory = null;
$opportunitiesList = [];
$totalOpenOpportunities = 0;
$aiDiscoveryInsights = null;

if ($isCategoryView) {
    // =========================================================================
    // SCREEN 2: Category Opportunity Listing
    // =========================================================================
    $selectedCategory = getActivityCategoryDetails($db, $categoryParam);
    if (!$selectedCategory) {
        header('Location: activities.php');
        exit;
    }

    $oppSearchQuery = trim((string)($_GET['q'] ?? ''));
    $filterScope    = trim((string)($_GET['scope'] ?? 'all'));
    $filterMode     = trim((string)($_GET['mode'] ?? 'all'));
    $filterDeadline = trim((string)($_GET['deadline'] ?? 'all'));
    $filterElig     = trim((string)($_GET['elig'] ?? 'all'));
    $filterCost     = trim((string)($_GET['cost'] ?? 'all'));
    $filterSort     = trim((string)($_GET['sort'] ?? 'deadline'));

    $filters = [
        'category_id'     => (int)$selectedCategory['id'],
        'state'           => $isAllIndia ? 'all' : $selectedState,
        'city'            => ($isAllIndia || $selectedCity === 'all') ? 'all' : $selectedCity,
        'scope_level'     => $filterScope,
        'delivery_mode'   => $filterMode,
        'deadline_filter' => $filterDeadline,
        'eligibility'     => $filterElig !== 'all' ? $filterElig : '',
        'cost_type'       => $filterCost !== 'all' ? $filterCost : null,
        'search'          => $oppSearchQuery,
        'sort_by'         => $filterSort,
    ];

    $opportunitiesList = getEventOpportunities($db, $filters, $userId);
    $totalOpenOpportunities = count($opportunitiesList);

    // Retrieve Gemini AI Discovery Intelligence & Knowledge Base
    $aiDiscoveryInsights = getCategoryAiDiscoveryInsights(
        $db,
        (string)$selectedCategory['code'],
        $isAllIndia ? 'all' : $selectedState,
        ($isAllIndia || $selectedCity === 'all') ? 'all' : $selectedCity,
        $activeGoal['career_title'] ?? ''
    );
} else {
    // =========================================================================
    // SCREEN 1: Category Tiles / Cards Grid Only
    // =========================================================================
    $categorySearchQuery = trim((string)($_GET['cat_q'] ?? ($_GET['q'] ?? '')));
    $categoriesCatalog = getActivityCategoriesCatalog($db, $userId, $categorySearchQuery);
}

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isCategoryView ? e($selectedCategory['name']) . ' Opportunities Across India' : 'Explore Activities Across India' ?> — CareerAct</title>
    <meta name="description" content="Discover verified extracurricular activities, hackathons, competitions, workshops, and student opportunities across India.">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= time() ?>">
    <style>
        /* Location Bar Container */
        .location-selector-bar {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            padding: 16px 22px;
            margin-bottom: 24px;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }
        .location-left-group {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .location-label-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 700;
            color: #0F172A;
        }
        .loc-select {
            height: 40px;
            padding: 0 14px;
            border-radius: 8px;
            border: 1px solid #CBD5E1;
            background: #F8FAFC;
            font-size: 13px;
            font-weight: 600;
            color: #1E293B;
            outline: none;
            transition: all 0.2s ease;
        }
        .loc-select:focus {
            border-color: #1769E8;
            background: #FFFFFF;
            box-shadow: 0 0 0 3px rgba(23, 105, 232, 0.12);
        }
        .loc-input-city {
            height: 40px;
            padding: 0 14px;
            border-radius: 8px;
            border: 1px solid #CBD5E1;
            background: #F8FAFC;
            font-size: 13px;
            color: #1E293B;
            outline: none;
            width: 170px;
        }
        .loc-input-city:focus {
            border-color: #1769E8;
            background: #FFFFFF;
        }
        .loc-apply-btn {
            background: #1769E8;
            color: #FFFFFF;
            font-size: 13px;
            font-weight: 700;
            padding: 9px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .loc-apply-btn:hover {
            background: #1255C0;
        }

        /* Screen 1: Category Tiles Grid */
        .category-tiles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 36px;
        }
        .category-tile-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 16px;
            padding: 24px;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 180px;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
            cursor: pointer;
        }
        .category-tile-card:hover {
            transform: translateY(-4px);
            border-color: #1769E8;
            box-shadow: 0 12px 24px -6px rgba(23, 105, 232, 0.16);
        }
        .tile-icon-box {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }
        .tile-category-name {
            font-size: 18px;
            font-weight: 800;
            color: #0F172A;
            line-height: 1.3;
            margin: 0 0 6px 0;
        }
        .tile-bottom-meta {
            margin-top: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-top: 1px solid #F1F5F9;
            padding-top: 12px;
        }
        .tile-opps-count {
            font-size: 12px;
            font-weight: 700;
            color: #059669;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .tile-opps-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background-color: #10B981;
        }

        /* Screen 2: Gemini AI Discovery Intelligence Box */
        .ai-discovery-box {
            background: linear-gradient(135deg, #F8FAFC 0%, #EEF4FF 100%);
            border: 1px solid #BFDBFE;
            border-radius: 14px;
            padding: 22px 26px;
            margin-bottom: 24px;
            box-shadow: 0 4px 14px rgba(23, 105, 232, 0.06);
        }
        .ai-status-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .ai-status-pill {
            font-size: 11.5px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #DBEAFE;
            color: #1D4ED8;
            border: 1px solid #93C5FD;
        }
        .ai-summary-text {
            font-size: 13.5px;
            color: #334155;
            line-height: 1.6;
            margin-bottom: 14px;
        }
        .ai-insights-split {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
            margin-bottom: 12px;
        }
        .ai-insight-block {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            padding: 14px 18px;
        }
        .ai-block-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748B;
            margin-bottom: 8px;
        }
        .ai-list-bullet {
            margin: 0;
            padding-left: 18px;
            font-size: 12.5px;
            color: #334155;
            line-height: 1.5;
        }
        .ai-list-bullet li {
            margin-bottom: 4px;
        }
        .ai-disclaimer-note {
            font-size: 11.5px;
            color: #64748B;
            border-top: 1px dashed #CBD5E1;
            padding-top: 10px;
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Screen 2: Opportunity Cards Grid */
        .opps-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 22px;
            margin-bottom: 36px;
        }
        .opp-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.2s ease;
        }
        .opp-card:hover {
            transform: translateY(-3px);
            border-color: #CBD5E1;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
        }
        .opp-scope-pill {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 3px 9px;
            border-radius: 12px;
            display: inline-block;
        }
        .scope-national {
            background: #FEF3C7;
            color: #92400E;
            border: 1px solid #FDE68A;
        }
        .scope-state {
            background: #EFF6FF;
            color: #1E40AF;
            border: 1px solid #BFDBFE;
        }
        .scope-local {
            background: #F1F5F9;
            color: #475569;
            border: 1px solid #E2E8F0;
        }
        .scope-intl {
            background: #ECFDF5;
            color: #065F46;
            border: 1px solid #A7F3D0;
        }
        .opp-card-title {
            font-size: 17px;
            font-weight: 800;
            color: #0F172A;
            margin: 8px 0 6px 0;
            line-height: 1.35;
        }
        .opp-organizer-row {
            font-size: 12.5px;
            color: #64748B;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .opp-organizer-row strong {
            color: #1E293B;
        }
        .opp-meta-table {
            background: #F8FAFC;
            border: 1px solid #F1F5F9;
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 14px;
            font-size: 12px;
        }
        .opp-meta-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
            color: #475569;
        }
        .opp-meta-row:last-child {
            margin-bottom: 0;
        }
        .opp-desc-text {
            font-size: 13px;
            color: #475569;
            line-height: 1.5;
            margin-bottom: 14px;
        }
        .opp-elig-box {
            background: #FFFBEB;
            border: 1px solid #FEF3C7;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 11.5px;
            color: #92400E;
            margin-bottom: 16px;
            line-height: 1.4;
        }
        .opp-actions-row {
            border-top: 1px solid #F1F5F9;
            padding-top: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }
        .opp-official-link {
            font-size: 12.5px;
            font-weight: 700;
            color: #1769E8;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .opp-official-link:hover {
            text-decoration: underline;
        }
        .opp-btn-action {
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .opp-btn-save {
            background: #F1F5F9;
            color: #334155;
            border: 1px solid #CBD5E1;
        }
        .opp-btn-save:hover {
            background: #E2E8F0;
        }
        .opp-btn-enroll {
            background: #1769E8;
            color: #FFFFFF;
        }
        .opp-btn-enroll:hover {
            background: #1255C0;
        }
        .opp-badge-saved {
            background: #ECFDF5;
            color: #065F46;
            border: 1px solid #A7F3D0;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 700;
        }
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
                        <a href="activities.php" class="nav-link active" aria-current="page">
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

    <!-- Main Content Area -->
    <div class="dashboard-main">
        <header class="dashboard-topbar">
            <div class="topbar-welcome">
                <span class="welcome-title">Explore Activities Across India</span>
                <span class="welcome-subtitle">Module 9: National & State-Level Opportunity Discovery</span>
            </div>
            <div class="topbar-actions">
                <a href="skills_assessment.php?action=result#recommendations" class="btn-view-details" style="padding: 7px 14px;">View AI Recommendations &rarr;</a>
                <a href="profile.php" class="user-profile-chip">
                    <div class="avatar-circle"><?= e($initials) ?></div>
                    <div class="user-text-info"><span class="user-name"><?= e($fullName) ?></span><span class="user-level">Student</span></div>
                </a>
            </div>
        </header>

        <main class="dashboard-content">

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

            <!-- Location Selector Bar (All India, States, Cities & Custom) -->
            <form method="GET" action="activities.php" class="location-selector-bar">
                <?php if ($isCategoryView): ?>
                    <input type="hidden" name="category" value="<?= e($selectedCategory['code']) ?>">
                <?php endif; ?>

                <div class="location-left-group">
                    <span class="location-label-pill">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#1769E8" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                        Location in India:
                    </span>

                    <select name="state" id="stateSelect" class="loc-select" onchange="updateCityDropdown(this.value)">
                        <option value="All India" <?= ($selectedState === 'All India') ? 'selected' : '' ?>>🇮🇳 All India (National & Online)</option>
                        <option value="online" <?= ($selectedState === 'online') ? 'selected' : '' ?>>🌐 Online / Virtual Only</option>
                        <optgroup label="Indian States & UTs">
                            <?php foreach (array_keys($indianStatesData) as $st): ?>
                                <option value="<?= e($st) ?>" <?= ($selectedState === $st) ? 'selected' : '' ?>><?= e($st) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>

                    <select name="city" id="citySelect" class="loc-select">
                        <option value="all">All Cities / Districts</option>
                        <?php 
                        $citiesForState = $indianStatesData[$selectedState]['cities'] ?? [];
                        foreach ($citiesForState as $cName):
                        ?>
                            <option value="<?= e($cName) ?>" <?= (strcasecmp($selectedCity, $cName) === 0) ? 'selected' : '' ?>><?= e($cName) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <input type="text" name="custom_city" placeholder="Or type any Indian town" value="<?= (!in_array(strtolower($selectedCity), array_merge(['all', 'all india'], array_map('strtolower', $citiesForState)))) ? e($selectedCity) : '' ?>" class="loc-input-city" title="Enter any custom city, district, or college town in India">
                </div>

                <div style="display: flex; align-items: center; gap: 10px;">
                    <button type="submit" class="loc-apply-btn">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        Discover Opportunities
                    </button>
                    <?php if (!$isAllIndia && !empty($selectedCity) && $selectedCity !== 'all'): ?>
                        <button type="button" onclick="setDefaultCity('<?= e($selectedCity) ?>')" class="btn-view-details" style="font-size: 12px; padding: 7px 12px;" title="Save as default location in profile">
                            Set as Profile City
                        </button>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (!$isCategoryView): ?>
                <!-- =============================================================
                     SCREEN 1: ACTIVITY CATEGORIES (TILES ONLY)
                     ============================================================= -->
                <div class="discovery-hero">
                    <div class="career-track-pill">
                        <span>Extracurricular Discovery</span> • <span><?= $isAllIndia ? 'All India Scope' : e($selectedState) ?></span>
                    </div>
                    <h1 class="discovery-title">Discover Opportunities. Build Your Future.</h1>
                    <p class="discovery-subtitle">
                        Explore extracurricular activities that match your interests, develop your skills, and bring you closer to your dream career.
                    </p>
                    <div style="margin-top: 16px;">
                        <form method="POST" action="activities.php" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="run_ai_discovery" value="1">
                            <button type="submit" class="hero-btn-white" style="display:inline-flex; align-items:center; gap:8px; background:rgba(255,255,255,0.25); color:#fff; border:1px solid rgba(255,255,255,0.45); backdrop-filter:blur(8px); padding:10px 20px; border-radius:30px; font-weight:700; cursor:pointer;">
                                ✦ Run AI Activity Discovery
                            </button>
                        </form>
                    </div>
                </div>

                <?php if (!empty($aiDiscoveryProgress)): ?>
                    <div style="background:#FFFFFF; border:1px solid #BFDBFE; border-radius:14px; padding:20px 24px; margin-bottom:24px; box-shadow:0 4px 16px rgba(23,105,232,0.06);">
                        <div style="font-size:14px; font-weight:800; color:#163A70; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                            <span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:#10B981;"></span>
                            AI Opportunity Discovery Progress
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:10px;">
                            <?php foreach ($aiDiscoveryProgress as $step): ?>
                                <div style="display:flex; align-items:center; gap:8px; font-size:12.5px; color:#334155; background:#F8FAFC; padding:8px 12px; border-radius:8px; border:1px solid #E2E8F0;">
                                    <span style="color:#059669; font-weight:800;">✓</span>
                                    <span><?= e($step['text']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Category Search Bar -->
                <form method="GET" action="activities.php" class="category-search-bar">
                    <input type="hidden" name="state" value="<?= e($selectedState) ?>">
                    <input type="hidden" name="city" value="<?= e($selectedCity) ?>">
                    <div class="cat-search-input-wrapper">
                        <span class="cat-search-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        </span>
                        <input type="text" name="cat_q" class="cat-search-input" placeholder="Find hackathons, competitions, workshops, and more..." value="<?= e($categorySearchQuery ?? '') ?>">
                    </div>
                    <button type="submit" class="hero-btn-white" style="background:#1769E8; color:#fff; border:none; height:44px; padding:0 20px; font-weight:700; border-radius:10px; cursor:pointer;">
                        Filter Tiles
                    </button>
                </form>

                <!-- Category Tiles Grid -->
                <div class="category-tiles-grid">
                    <?php foreach ($categoriesCatalog as $cat): 
                        $code = (string)$cat['code'];
                    ?>
                        <a href="activities.php?category=<?= urlencode($code) ?>&state=<?= urlencode($selectedState) ?>&city=<?= urlencode($selectedCity) ?>" class="category-tile-card" id="catTile_<?= strtolower(e($cat['code'])) ?>">
                            <div>
                                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                    <div class="tile-icon-box" style="background: <?= e($cat['bg_color']) ?>; border: 1px solid <?= e($cat['border_color']) ?>; color: <?= e($cat['icon_color']) ?>;">
                                        <?= $cat['icon_svg'] ?>
                                    </div>
                                    <?php if ($cat['is_recommended']): ?>
                                        <span class="tile-rec-badge">Recommended</span>
                                    <?php endif; ?>
                                </div>
                                <h2 class="tile-category-name"><?= e($cat['name']) ?></h2>
                            </div>

                            <div class="tile-bottom-meta">
                                <span class="tile-opps-count">
                                    <span class="tile-opps-dot"></span>
                                    <?= (int)$cat['verified_opps_count'] ?> Verified in Catalog
                                </span>
                                <span class="tile-explore-link">
                                    <span>Explore</span>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                </span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <!-- =============================================================
                     SCREEN 2: CATEGORY OPPORTUNITY LISTING
                     ============================================================= -->

                <!-- Breadcrumb Navigation -->
                <div class="breadcrumb-nav">
                    <a href="activities.php?state=<?= urlencode($selectedState) ?>&city=<?= urlencode($selectedCity) ?>" class="breadcrumb-link">&larr; Back to Explore Activities</a>
                    <span class="breadcrumb-sep">&bull;</span>
                    <span class="breadcrumb-current"><?= e($selectedCategory['name']) ?> Opportunities</span>
                </div>

                <!-- Category Header Banner -->
                <div class="category-header-banner">
                    <div class="cat-header-left">
                        <div class="cat-header-icon-box" style="background: <?= e($selectedCategory['bg_color']) ?>; border: 1px solid <?= e($selectedCategory['border_color']) ?>; color: <?= e($selectedCategory['icon_color']) ?>;">
                            <?= $selectedCategory['icon_svg'] ?>
                        </div>
                        <div>
                            <h1 class="cat-header-title"><?= e($selectedCategory['name']) ?> in India</h1>
                            <p class="cat-header-subtitle">
                                Showing verified extracurricular opportunities conducted across <?= $isAllIndia ? 'all states and national hubs in India' : e($selectedState) . ' (' . e($selectedCity) . ')' ?> and India-eligible online tracks.
                            </p>
                        </div>
                    </div>
                    <div>
                        <span class="cat-opps-badge">
                            <?= (int)$totalOpenOpportunities ?> Verified Listings
                        </span>
                    </div>
                </div>

                <!-- Gemini AI Opportunity Discovery & Intelligence Panel -->
                <?php if ($aiDiscoveryInsights): ?>
                    <div class="ai-discovery-box">
                        <div class="ai-status-row">
                            <span class="ai-status-pill">
                                <?= $aiDiscoveryInsights['is_live_ai'] ? '✦ Gemini AI Discovery Engine (India Coverage)' : '✓ Verified Database Records (Live Discovery Fallback Active)' ?>
                            </span>
                            <span style="font-size: 12px; color: #64748B; font-weight: 600;">
                                Region: <?= e($aiDiscoveryInsights['location_display']) ?>
                            </span>
                        </div>

                        <div class="ai-summary-text">
                            <?= e($aiDiscoveryInsights['summary']) ?>
                        </div>

                        <div class="ai-insights-split">
                            <div class="ai-insight-block">
                                <div class="ai-block-title">Key Active Academic & Regional Hubs</div>
                                <ul class="ai-list-bullet">
                                    <?php foreach ($aiDiscoveryInsights['top_hubs'] as $hub): ?>
                                        <li><?= e($hub) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <div class="ai-insight-block">
                                <div class="ai-block-title">Collegiate Preparation & Eligibility Advice</div>
                                <ul class="ai-list-bullet">
                                    <?php foreach ($aiDiscoveryInsights['prep_tips'] as $tip): ?>
                                        <li><?= e($tip) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>

                        <?php if (!empty($aiDiscoveryInsights['official_portals'])): ?>
                            <div style="font-size: 12px; color: #475569; margin-bottom: 8px;">
                                <strong>Official Portals to Monitor:</strong>
                                <?php foreach ($aiDiscoveryInsights['official_portals'] as $port): ?>
                                    <a href="<?= e($port['url']) ?>" target="_blank" rel="noopener noreferrer" style="margin-right: 12px; color: #1769E8; font-weight: 700; text-decoration: underline;">
                                        <?= e($port['name']) ?> &#8599;
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="ai-disclaimer-note">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                            <span><?= e($aiDiscoveryInsights['disclaimer']) ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Filter & Search Control Panel -->
                <form method="GET" action="activities.php" class="discovery-filter-panel">
                    <input type="hidden" name="category" value="<?= e($selectedCategory['code']) ?>">
                    <input type="hidden" name="state" value="<?= e($selectedState) ?>">
                    <input type="hidden" name="city" value="<?= e($selectedCity) ?>">

                    <div class="filter-row-top">
                        <div class="filter-search-box">
                            <input type="text" name="q" class="filter-search-input" placeholder="Search <?= e($selectedCategory['name']) ?> (e.g. Smart India, Shaastra, IoT, AI)..." value="<?= e($oppSearchQuery ?? '') ?>">
                        </div>

                        <select name="scope" class="filter-select">
                            <option value="all" <?= ($filterScope === 'all') ? 'selected' : '' ?>>All Scopes</option>
                            <option value="national" <?= ($filterScope === 'national') ? 'selected' : '' ?>>National Level (All India)</option>
                            <option value="state" <?= ($filterScope === 'state') ? 'selected' : '' ?>>State Level</option>
                            <option value="local" <?= ($filterScope === 'local') ? 'selected' : '' ?>>Local Hub Only</option>
                            <option value="international_eligible" <?= ($filterScope === 'international_eligible') ? 'selected' : '' ?>>India-Eligible Online</option>
                        </select>

                        <select name="mode" class="filter-select">
                            <option value="all" <?= ($filterMode === 'all') ? 'selected' : '' ?>>All Formats</option>
                            <option value="offline" <?= ($filterMode === 'offline') ? 'selected' : '' ?>>In-Person (Offline)</option>
                            <option value="online" <?= ($filterMode === 'online') ? 'selected' : '' ?>>Online / Virtual</option>
                            <option value="hybrid" <?= ($filterMode === 'hybrid') ? 'selected' : '' ?>>Hybrid</option>
                        </select>

                        <select name="deadline" class="filter-select">
                            <option value="all" <?= ($filterDeadline === 'all') ? 'selected' : '' ?>>All Deadlines</option>
                            <option value="closing_soon" <?= ($filterDeadline === 'closing_soon') ? 'selected' : '' ?>>Closing Soon (&le; 7 Days)</option>
                            <option value="this_month" <?= ($filterDeadline === 'this_month') ? 'selected' : '' ?>>Upcoming This Month</option>
                        </select>

                        <select name="cost" class="filter-select">
                            <option value="all" <?= ($filterCost === 'all') ? 'selected' : '' ?>>All Costs</option>
                            <option value="free" <?= ($filterCost === 'free') ? 'selected' : '' ?>>Free to Enter</option>
                            <option value="paid" <?= ($filterCost === 'paid') ? 'selected' : '' ?>>Paid / Subsidized</option>
                        </select>

                        <button type="submit" class="hero-btn-white" style="background:#1769E8; color:#fff; border:none; height:42px; padding:0 18px; font-weight:700; border-radius:10px; cursor:pointer;">
                            Apply Filters
                        </button>
                    </div>
                </form>

                <!-- Opportunity Listings Grid -->
                <?php if (empty($opportunitiesList)): ?>
                    <div style="background:#FFF; border:1px solid #E2E8F0; border-radius:14px; padding:48px 24px; text-align:center; max-width:680px; margin:20px auto;">
                        <div style="width:56px; height:56px; background:#EEF4FF; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px;">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#1769E8" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        </div>
                        <h2 style="font-size:18px; font-weight:800; color:#0F172A; margin:0 0 8px 0;">No Verified Opportunities Found</h2>
                        <p style="color:#64748B; font-size:13.5px; line-height:1.5; margin-bottom:20px;">
                            No verified opportunities found for this category and location in <?= e($selectedCity !== 'all' ? "{$selectedCity}, {$selectedState}" : $selectedState) ?>. Try selecting "All India" or clearing the format and deadline filters.
                        </p>
                        <a href="activities.php?category=<?= urlencode($selectedCategory['code']) ?>&state=All+India" class="loc-apply-btn" style="display:inline-flex;">
                            Search All India Opportunities &rarr;
                        </a>
                    </div>
                <?php else: ?>
                    <div class="opps-cards-grid">
                        <?php foreach ($opportunitiesList as $opp): 
                            $scope = (string)($opp['scope_level'] ?? 'local');
                            $scopeClass = 'scope-local';
                            $scopeLabel = 'Local Hub';
                            if ($scope === 'national') {
                                $scopeClass = 'scope-national';
                                $scopeLabel = 'National Level (All India)';
                            } elseif ($scope === 'state') {
                                $scopeClass = 'scope-state';
                                $scopeLabel = 'State Level (' . ($opp['state_name'] ?? 'State') . ')';
                            } elseif ($scope === 'international_eligible' || !empty($opp['is_international_eligible'])) {
                                $scopeClass = 'scope-intl';
                                $scopeLabel = 'International Online (Eligible for India)';
                            }
                        ?>
                            <article class="opportunity-card opp-card">
                                <div>
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
                                        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                            <span class="opp-scope-pill <?= $scopeClass ?>">
                                                <?= e($scopeLabel) ?>
                                            </span>
                                            <?php if (!empty($opp['match_score'])): ?>
                                                <span style="font-size:11px; font-weight:800; color:#065F46; background:#ECFDF5; border:1px solid #A7F3D0; padding:2px 8px; border-radius:12px;">
                                                    ✦ <?= (int)$opp['match_score'] ?>% Match
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($opp['days_remaining'] !== null && $opp['days_remaining'] <= 7): ?>
                                            <span style="font-size:10.5px; font-weight:800; color:#DC2626; background:#FEF2F2; border:1px solid #FECACA; padding:2px 8px; border-radius:10px;">
                                                Closing Soon (<?= (int)$opp['days_remaining'] ?>d)
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <h3 class="opp-card-title"><?= e($opp['title']) ?></h3>

                                    <div class="opp-organizer-row">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#16A34A" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                                        <span>Organizer: <strong><?= e($opp['organizer_name']) ?></strong></span>
                                    </div>

                                    <div class="opp-meta-table">
                                        <div class="opp-meta-row">
                                            <span>Location:</span>
                                            <strong><?= e($opp['city_name']) ?><?= !empty($opp['state_name']) ? ', ' . e($opp['state_name']) : '' ?></strong>
                                        </div>
                                        <div class="opp-meta-row">
                                            <span>Format:</span>
                                            <strong style="text-transform:capitalize;"><?= e($opp['delivery_mode']) ?></strong>
                                        </div>
                                        <div class="opp-meta-row">
                                            <span>Registration Deadline:</span>
                                            <strong><?= !empty($opp['registration_deadline']) ? date('M j, Y', strtotime($opp['registration_deadline'])) : 'Open' ?></strong>
                                        </div>
                                        <div class="opp-meta-row">
                                            <span>Cost:</span>
                                            <strong><?= ((float)$opp['cost_amount'] > 0) ? '₹' . number_format((float)$opp['cost_amount'], 2) : 'Free' ?></strong>
                                        </div>
                                    </div>

                                    <div class="opp-desc-text">
                                        <?= e($opp['description'] ?? 'Verified extracurricular opportunity.') ?>
                                    </div>

                                    <?php if (!empty($opp['eligibility'])): ?>
                                        <div class="opp-elig-box">
                                            <strong>Eligibility:</strong> <?= e($opp['eligibility']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="opp-actions-row">
                                    <?php if (!empty($opp['registration_url'])): ?>
                                        <a href="<?= e($opp['registration_url']) ?>" target="_blank" rel="noopener noreferrer" class="opp-official-link">
                                            <span>Official Portal</span>
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="7" y1="17" x2="17" y2="7"></line><polyline points="7 7 17 7 17 17"></polyline></svg>
                                        </a>
                                    <?php else: ?>
                                        <span></span>
                                    <?php endif; ?>

                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <a href="activity_details.php?id=<?= (int)$opp['activity_id'] ?>&opp_id=<?= (int)$opp['id'] ?>" class="btn-view-details" style="padding: 6px 12px; font-size: 11.5px; text-decoration: none;">
                                            View Details &rarr;
                                        </a>
                                        <?php if ($opp['is_enrolled']): ?>
                                            <span class="opp-badge-saved">
                                                <?= ($opp['enrollment_status'] === 'registered') ? '✓ Enrolled' : '★ Saved' ?>
                                            </span>
                                        <?php else: ?>
                                            <form method="POST" action="activities.php" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="enroll_opp_id" value="<?= (int)$opp['id'] ?>">
                                                <input type="hidden" name="activity_id" value="<?= (int)$opp['activity_id'] ?>">
                                                <input type="hidden" name="action_type" value="saved">
                                                <button type="submit" class="opp-btn-action opp-btn-save" title="Save to your personal portal">Save</button>
                                            </form>
                                            <form method="POST" action="activities.php" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="enroll_opp_id" value="<?= (int)$opp['id'] ?>">
                                                <input type="hidden" name="activity_id" value="<?= (int)$opp['activity_id'] ?>">
                                                <input type="hidden" name="action_type" value="registered">
                                                <button type="submit" class="opp-btn-action opp-btn-enroll">Enroll</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Strict Separation Notice Footer -->
                <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:10px; padding:18px 24px; font-size:13px; color:#64748B; line-height:1.5; margin-bottom:32px;">
                    <strong style="color:#334155;">Important Separation Notice:</strong>
                    Saving or enrolling in an opportunity adds it to your <em>Saved Activities</em> or <em>My Enrollments</em> portal for milestone tracking. It is strictly a future activity and does <strong>not</strong> mark it as completed or add it to <em>My Existing Activities & Skills</em> until you provide verified completion evidence.
                </div>

            <?php endif; ?>

        </main>
    </div>

    <!-- Dynamic City Dropdown Updater -->
    <script>
        const indianStates = <?= json_encode($indianStatesData) ?>;

        function updateCityDropdown(selectedState) {
            const citySelect = document.getElementById('citySelect');
            if (!citySelect) return;

            citySelect.innerHTML = '<option value="all">All Cities / Districts</option>';

            if (selectedState && indianStates[selectedState] && indianStates[selectedState].cities) {
                indianStates[selectedState].cities.forEach(city => {
                    const opt = document.createElement('option');
                    opt.value = city;
                    opt.textContent = city;
                    citySelect.appendChild(opt);
                });
            }
        }

        function setDefaultCity(cityName) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'activities.php';

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?= e($csrfToken) ?>';
            form.appendChild(csrfInput);

            const cityInput = document.createElement('input');
            cityInput.type = 'hidden';
            cityInput.name = 'set_preferred_city';
            cityInput.value = cityName;
            form.appendChild(cityInput);

            document.body.appendChild(form);
            form.submit();
        }
    </script>
</body>
</html>
