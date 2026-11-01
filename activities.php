<?php
/**
 * CareerAct — Module: Personalized Extracurricular Activities & Event Discovery
 * 
 * Recommends extracurricular activities targeted to the student's career goal,
 * allows preferred city selection (Pune, Mumbai, Bengaluru, Delhi, Hyderabad, Online),
 * and discovers verified real-world events and opportunities with automatic expiration.
 */

declare(strict_types=1);

// Prevent browser from caching stale CSS or layout states
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/event_discovery_service.php';

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

// Dynamic greeting
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');

// 2. Handle POST Actions (City update, Save Opportunity, Enroll Activity)
$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (verifyCsrfToken($submittedToken)) {
        
        // Action A: Update Preferred City
        if (isset($_POST['set_preferred_city'])) {
            $newCity = trim((string)$_POST['set_preferred_city']);
            if ($newCity !== '') {
                setStudentPreferredCity($db, $userId, $newCity);
                $student['city'] = $newCity;
                $successMsg = "Preferred city updated to {$newCity}. Showing opportunities in your area.";
            }
        }

        // Action B: Save / Enroll in an Opportunity
        if (isset($_POST['enroll_opp_id'])) {
            $oppId = (int)$_POST['enroll_opp_id'];
            $actId = (int)($_POST['activity_id'] ?? 0);
            $actionType = trim((string)($_POST['action_type'] ?? 'saved')); // 'saved' or 'registered'

            if ($actId > 0) {
                $res = saveOrEnrollOpportunity($db, $userId, $actId, $oppId, $actionType);
                if ($res['success']) {
                    $successMsg = ($actionType === 'saved') 
                        ? 'Opportunity saved to your portal! You can access it anytime under "Saved Activities".' 
                        : 'Successfully registered for this opportunity! Track your progress in "My Enrollments".';
                }
            }
        }

        // Action C: Direct Activity Enrollment
        if (isset($_POST['enroll_activity_id'])) {
            $actId = (int)$_POST['enroll_activity_id'];
            $res = saveOrEnrollOpportunity($db, $userId, $actId, null, 'registered');
            if ($res['success']) {
                $successMsg = 'Activity added to your active enrollments tracker!';
            }
        }
    } else {
        $errorMsg = 'Security validation failed (invalid CSRF token). Please try again.';
    }
}

// 3. Retrieve Student's Target Career Goal & Recommended Activities
$activeGoal = getStudentTargetCareer($db, $userId);
$careerId   = $activeGoal ? (int)$activeGoal['career_id'] : 4; // Default to Software Developer (ID 4) if none set
$recommendedActivities = getRecommendedActivities($db, $careerId, $userId);

// 4. Retrieve Supported Cities & Student's Current Preferred City
$supportedCities = getSupportedCities($db);
$currentCity = isset($_GET['city']) ? trim((string)$_GET['city']) : getStudentPreferredCity($db, $userId);

// 5. Filter & Search Handling
$searchQuery   = trim((string)($_GET['q'] ?? ''));
$filterActivity = isset($_GET['activity_id']) ? (int)$_GET['activity_id'] : 0;
$filterMode    = trim((string)($_GET['mode'] ?? 'all'));
$filterCat     = isset($_GET['cat_id']) ? (int)$_GET['cat_id'] : 0;
$filterCost    = trim((string)($_GET['cost'] ?? 'all'));

$filters = [
    'city'          => $currentCity,
    'activity_id'   => $filterActivity,
    'delivery_mode' => $filterMode,
    'category_id'   => $filterCat,
    'cost_type'     => $filterCost !== 'all' ? $filterCost : null,
    'search'        => $searchQuery,
];

$opportunitiesList = getEventOpportunities($db, $filters, $userId);
$totalOpenOpportunities = count($opportunitiesList);

// Find active selected activity title if filtered
$selectedActivityTitle = '';
if ($filterActivity > 0) {
    foreach ($recommendedActivities as $ra) {
        if ((int)$ra['activity_id'] === $filterActivity) {
            $selectedActivityTitle = $ra['title'];
            break;
        }
    }
    if ($selectedActivityTitle === '') {
        $stmtActName = $db->prepare("SELECT title FROM activities WHERE id = :id LIMIT 1");
        $stmtActName->execute([':id' => $filterActivity]);
        $selectedActivityTitle = (string)$stmtActName->fetchColumn();
    }
}

// Categories list for filter dropdown
$categoriesList = $db->query("SELECT id, name FROM activity_categories ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Explore Extracurricular Activities & Real-World Events — CareerAct</title>
    <meta name="description" content="Discover extracurricular activities and verified real-world opportunities tailored to your career goal.">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= time() ?>">
    <style>
        /* Critical SVG & Dimension Guard to Guarantee Clean Rendering */
        svg, svg path, svg circle, svg polygon, svg rect, svg line, svg polyline {
            fill: none !important;
            box-sizing: border-box;
        }
        svg {
            display: inline-block !important;
            vertical-align: middle !important;
            stroke: currentColor !important;
            stroke-width: 2 !important;
            flex-shrink: 0 !important;
        }
        .sidebar-brand-icon svg { width: 20px !important; height: 20px !important; }
        .sidebar-nav svg, .logout-link svg { width: 18px !important; height: 18px !important; }
        .search-icon svg { width: 16px !important; height: 16px !important; }
        .user-chevron svg { width: 14px !important; height: 14px !important; }

        /* Discovery Top Banner */
        .discovery-banner {
            background: linear-gradient(135deg, #1769E8 0%, #163A70 100%);
            border-radius: 16px;
            padding: 28px 32px;
            color: #FFFFFF;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(23, 105, 232, 0.25);
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .banner-top-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
        }
        .banner-heading-wrap {
            max-width: 700px;
        }
        .banner-track-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .banner-title {
            font-size: 24px;
            font-weight: 800;
            margin: 0 0 6px;
            letter-spacing: -0.4px;
        }
        .banner-subtitle {
            font-size: 13.5px;
            color: rgba(255, 255, 255, 0.88);
            margin: 0;
            line-height: 1.5;
        }
        .banner-goal-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #FFFFFF;
            color: #1769E8;
            font-size: 13px;
            font-weight: 700;
            padding: 10px 18px;
            border-radius: 10px;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .banner-goal-btn:hover {
            background: #F8FAFD;
            transform: translateY(-1px);
        }

        /* Preferred City Selector Strip */
        .city-selector-strip {
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .city-strip-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12.5px;
            font-weight: 700;
            color: #FFFFFF;
        }
        .city-pills-wrap {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .city-pill-btn {
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #FFFFFF;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s ease;
        }
        .city-pill-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        .city-pill-btn.active {
            background: #FFFFFF;
            color: #163A70;
            border-color: #FFFFFF;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        .city-pill-count {
            background: rgba(0, 0, 0, 0.15);
            font-size: 10.5px;
            padding: 1px 6px;
            border-radius: 10px;
        }
        .city-pill-btn.active .city-pill-count {
            background: #EEF3FF;
            color: #1769E8;
            font-weight: 700;
        }

        /* Career-Targeted Recommended Activities Grid */
        .section-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .section-main-title {
            font-size: 18px;
            font-weight: 800;
            color: #163A70;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .activities-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 18px;
            margin-bottom: 32px;
        }
        .activity-rec-card {
            background: #FFFFFF;
            border: 1px solid #E4EAF2;
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(22, 58, 112, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.2s ease;
            position: relative;
        }
        .activity-rec-card:hover {
            border-color: #1769E8;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px -4px rgba(23, 105, 232, 0.12);
        }
        .activity-rec-card.selected {
            border: 2px solid #1769E8;
            background: #FAFDFF;
        }
        .act-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
            gap: 8px;
        }
        .act-category-badge {
            background: #EEF3FF;
            color: #1769E8;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .act-gap-badge {
            background: #EDFDF5;
            color: #059669;
            font-size: 10.5px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .act-card-title {
            font-size: 15px;
            font-weight: 700;
            color: #163A70;
            margin: 0 0 8px;
            line-height: 1.4;
        }
        .act-why-benefit {
            background: #F8FAFD;
            border-left: 3px solid #1769E8;
            padding: 8px 10px;
            font-size: 12px;
            color: #475569;
            border-radius: 0 6px 6px 0;
            margin-bottom: 12px;
            line-height: 1.4;
        }
        .act-skills-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-bottom: 16px;
        }
        .act-skill-chip {
            background: #F1F5F9;
            color: #334155;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 4px;
        }
        .act-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 12px;
            border-top: 1px solid #F1F5F9;
        }
        .btn-filter-events {
            background: #1769E8;
            color: #FFFFFF;
            font-size: 12px;
            font-weight: 700;
            padding: 7px 14px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        .btn-filter-events:hover {
            background: #1356be;
        }
        .btn-filter-events.active {
            background: #0F9D8A;
        }

        /* Filter Toolbar */
        .filter-toolbar {
            background: #FFFFFF;
            border: 1px solid #E4EAF2;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
            box-shadow: 0 2px 6px rgba(22, 58, 112, 0.03);
        }
        .search-field-wrap {
            flex: 1;
            min-width: 240px;
            position: relative;
        }
        .search-field-input {
            width: 100%;
            height: 40px;
            padding: 8px 14px 8px 36px;
            background: #F8FAFD;
            border: 1px solid #E4EAF2;
            border-radius: 8px;
            font-size: 13.5px;
            color: #17243B;
            outline: none;
            transition: border-color 0.2s;
        }
        .search-field-input:focus {
            border-color: #1769E8;
            background: #FFFFFF;
        }
        .filter-controls-group {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .filter-select {
            height: 40px;
            padding: 0 12px;
            background: #F8FAFD;
            border: 1px solid #E4EAF2;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            outline: none;
            cursor: pointer;
        }
        .filter-select:focus {
            border-color: #1769E8;
        }
        .btn-clear-filters {
            font-size: 12.5px;
            font-weight: 700;
            color: #DC2626;
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 8px;
            background: #FEF2F2;
            transition: all 0.2s;
        }
        .btn-clear-filters:hover {
            background: #FEE2E2;
        }

        /* Opportunities / Events Grid */
        .opportunities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .event-card {
            background: #FFFFFF;
            border: 1px solid #E4EAF2;
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 2px 10px rgba(22, 58, 112, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.2s ease;
            position: relative;
        }
        .event-card:hover {
            border-color: #CBD5E1;
            transform: translateY(-2px);
            box-shadow: 0 8px 24px -4px rgba(22, 58, 112, 0.1);
        }
        .event-card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            gap: 8px;
        }
        .event-format-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .event-format-pill.online { background: #EEF2FF; color: #4F46E5; }
        .event-format-pill.offline { background: #EDFDF5; color: #059669; }
        .event-format-pill.hybrid { background: #FFF7EB; color: #D97706; }

        .deadline-countdown-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
        }
        .deadline-countdown-badge.urgent {
            background: #FEF2F2;
            color: #DC2626;
            border: 1px solid #FCA5A5;
        }
        .deadline-countdown-badge.normal {
            background: #F1F5F9;
            color: #475569;
        }

        .event-title {
            font-size: 16px;
            font-weight: 800;
            color: #163A70;
            margin: 0 0 6px;
            line-height: 1.35;
        }
        .event-organizer {
            font-size: 12.5px;
            color: #64748B;
            font-weight: 600;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .event-desc {
            font-size: 13px;
            color: #475569;
            line-height: 1.5;
            margin-bottom: 14px;
        }
        .event-meta-table {
            background: #F8FAFD;
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-size: 12px;
        }
        .event-meta-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #475569;
        }
        .event-meta-row strong {
            color: #163A70;
        }
        .event-card-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 8px;
        }
        .btn-register-official {
            flex: 1;
            background: #1769E8;
            color: #FFFFFF;
            text-align: center;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        .btn-register-official:hover {
            background: #1356be;
            box-shadow: 0 4px 12px rgba(23, 105, 232, 0.25);
        }
        .btn-save-opp {
            background: #FFFFFF;
            border: 1px solid #CBD5E1;
            color: #475569;
            padding: 9px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s ease;
        }
        .btn-save-opp:hover {
            background: #F1F5F9;
            border-color: #94A3B8;
            color: #163A70;
        }
        .btn-save-opp.saved {
            background: #EDFDF5;
            border-color: #A7F3D0;
            color: #059669;
            font-weight: 700;
        }

        /* Alert Banners */
        .alert-banner {
            padding: 12px 18px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-banner.success {
            background: #EDFDF5;
            color: #059669;
            border: 1px solid #A7F3D0;
        }
        .alert-banner.error {
            background: #FEF2F2;
            color: #DC2626;
            border: 1px solid #FCA5A5;
        }

        /* Empty State */
        .empty-discovery-state {
            background: #FFFFFF;
            border: 1px dashed #CBD5E1;
            border-radius: 14px;
            padding: 48px 24px;
            text-align: center;
            max-width: 600px;
            margin: 40px auto;
        }
        .empty-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #EEF3FF;
            color: #1769E8;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        .empty-icon svg {
            width: 24px !important;
            height: 24px !important;
        }
        .empty-title {
            font-size: 17px;
            font-weight: 800;
            color: #163A70;
            margin: 0 0 6px;
        }
        .empty-desc {
            font-size: 13.5px;
            color: #64748B;
            line-height: 1.5;
            margin: 0 0 16px;
        }
    </style>
</head>
<body class="dashboard-body">

    <!-- Mobile Drawer Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- ====================================================================
         1. SIDEBAR NAVIGATION (14 ITEMS)
         ==================================================================== -->
    <aside class="dashboard-sidebar" id="dashboardSidebar" aria-label="Portal Navigation">
        <div>
            <!-- Branding Header -->
            <div class="sidebar-header">
                <a href="dashboard.php" class="sidebar-brand-wrapper">
                    <div class="sidebar-brand-icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
                    </div>
                    <div class="sidebar-brand-text">
                        <span class="sidebar-brand-name">CareerAct</span>
                        <span class="sidebar-brand-subtitle">STUDENT PORTAL</span>
                    </div>
                </a>
            </div>

            <!-- Navigation Links -->
            <nav>
                <ul class="sidebar-nav">
                    <li><a href="dashboard.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect></svg><span>Dashboard</span></a></li>
                    <li><a href="profile.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><span>My Profile</span></a></li>
                    <li><a href="career_goals.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg><span>Career Exploration</span></a></li>
                    <li><a href="my_skills.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg><span>My Skills</span></a></li>
                    <li><a href="skills_assessment.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg><span>Skill Assessment</span></a></li>
                    <li><a href="skill_gap.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg><span>Skill-Gap Analysis</span></a></li>
                    <li><a href="recommendations.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path></svg><span>Recommendations</span></a></li>
                    <li><a href="activities.php" class="nav-link active" aria-current="page"><svg width="18" height="18" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg><span>Explore Activities</span></a></li>
                    <li><a href="saved_activities.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path></svg><span>Saved Activities</span></a></li>
                    <li><a href="enrollments.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg><span>My Enrollments</span></a></li>
                    <li><a href="portfolio.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg><span>My Portfolio</span></a></li>
                    <li><a href="resume.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg><span>Resume Builder</span></a></li>
                    <li><a href="settings.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg><span>Settings</span></a></li>
                </ul>
            </nav>
        </div>

        <div class="sidebar-footer">
            <a href="logout.php" class="logout-link" id="sidebarLogout">
                <svg width="18" height="18" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- ====================================================================
         2. MAIN VIEWPORT
         ==================================================================== -->
    <div class="dashboard-main">

        <!-- Top Navigation Bar -->
        <header class="dashboard-topbar">
            <button type="button" class="mobile-toggle-btn" id="mobileMenuBtn" style="display: none;" aria-label="Toggle navigation menu">
                <svg width="22" height="22" viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
            </button>

            <div class="topbar-welcome">
                <span class="welcome-title"><?= e($greeting) ?>, <?= e($firstName) ?>!</span>
                <span class="welcome-subtitle">Explore activities that build verified skills for your career pathway.</span>
            </div>

            <div class="topbar-actions">
                <div class="user-profile-chip" style="cursor: default;">
                    <div class="avatar-circle"><?= e($initials) ?></div>
                    <div class="user-text-info">
                        <span class="user-name"><?= e($fullName) ?></span>
                        <span class="user-level"><?= e(ucfirst($student['experience_level'] ?? 'Student')) ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Shell -->
        <main class="dashboard-content">

            <!-- Flash Notifications -->
            <?php if ($successMsg): ?>
                <div class="alert-banner success" role="alert">
                    <svg width="18" height="18" viewBox="0 0 24 24" style="stroke:#059669;"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <span><?= e($successMsg) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($errorMsg): ?>
                <div class="alert-banner error" role="alert">
                    <svg width="18" height="18" viewBox="0 0 24 24" style="stroke:#DC2626;"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                    <span><?= e($errorMsg) ?></span>
                </div>
            <?php endif; ?>

            <!-- ================================================================
                 A. TOP BANNER: TARGET CAREER & PREFERRED CITY SELECTOR
                 ================================================================ -->
            <section class="discovery-banner" aria-label="Target Career and City Selector">
                <div class="banner-top-row">
                    <div class="banner-heading-wrap">
                        <div class="banner-track-chip">
                            <svg width="14" height="14" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg>
                            <span>Target Career Goal</span>
                        </div>
                        <h1 class="banner-title"><?= e($activeGoal['career_title'] ?? 'Software Developer') ?></h1>
                        <p class="banner-subtitle">
                            Curated extracurricular activities designed to develop the professional skills, portfolio evidence, and competencies required for this role.
                        </p>
                    </div>

                    <a href="career_goals.php" class="banner-goal-btn" title="Change target career goal">
                        <svg width="16" height="16" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        <span>Change Career Goal</span>
                    </a>
                </div>

                <!-- Preferred City Selector Strip -->
                <div class="city-selector-strip">
                    <div class="city-strip-label">
                        <svg width="16" height="16" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                        <span>Preferred City Hub:</span>
                    </div>

                    <div class="city-pills-wrap">
                        <?php foreach ($supportedCities as $sc): ?>
                            <?php 
                                $isCityActive = (
                                    ($sc['code'] === 'all' && (strtolower($currentCity) === 'all' || empty($currentCity))) ||
                                    ($sc['code'] === 'online' && strtolower($currentCity) === 'online') ||
                                    (stripos($currentCity, $sc['name']) !== false)
                                );
                                $cityUrl = 'activities.php?' . http_build_query(array_merge($_GET, ['city' => $sc['name']]));
                            ?>
                            <a href="<?= e($cityUrl) ?>" class="city-pill-btn <?= $isCityActive ? 'active' : '' ?>">
                                <span><?= e($sc['display']) ?></span>
                                <span class="city-pill-count"><?= $sc['count'] ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- ================================================================
                 B. SECTION 1: CAREER-TARGETED RECOMMENDED ACTIVITIES
                 ================================================================ -->
            <section aria-label="Career-Targeted Activities">
                <div class="section-header-row">
                    <div>
                        <h2 class="section-main-title">
                            <svg width="20" height="20" viewBox="0 0 24 24" style="stroke:#1769E8;"><circle cx="12" cy="12" r="10"></circle><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                            <span>Recommended Activities for <?= e($activeGoal['career_title'] ?? 'Your Goal') ?></span>
                        </h2>
                        <span style="font-size: 13px; color: #64748B;">High-impact activities proven to cultivate target competencies</span>
                    </div>

                    <?php if ($filterActivity > 0): ?>
                        <a href="activities.php?city=<?= urlencode($currentCity) ?>" style="font-size: 12.5px; font-weight: 700; color: #1769E8; text-decoration: none;">
                            Show All Activities (<?= count($recommendedActivities) ?>) &rarr;
                        </a>
                    <?php endif; ?>
                </div>

                <div class="activities-card-grid">
                    <?php foreach ($recommendedActivities as $act): ?>
                        <?php $isSelected = ($filterActivity === (int)$act['activity_id']); ?>
                        <div class="activity-rec-card <?= $isSelected ? 'selected' : '' ?>">
                            <div>
                                <div class="act-card-header">
                                    <span class="act-category-badge"><?= e($act['category_name']) ?></span>
                                    <?php if ($act['closes_gap']): ?>
                                        <span class="act-gap-badge" title="Directly addresses your identified skill gap">
                                            <svg width="12" height="12" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                            <span>Closes Gap</span>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <h3 class="act-card-title"><?= e($act['title']) ?></h3>

                                <div class="act-why-benefit">
                                    <strong>Why it helps:</strong> <?= e($act['why_beneficial']) ?>
                                </div>

                                <?php if (!empty($act['skills_developed'])): ?>
                                    <div class="act-skills-list">
                                        <?php foreach ($act['skills_developed'] as $sk): ?>
                                            <span class="act-skill-chip"><?= e($sk['name']) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="act-card-footer">
                                <span style="font-size: 11.5px; color: #64748B; font-weight: 600;">
                                    <?= $act['live_opportunities_count'] ?> Live Event<?= $act['live_opportunities_count'] === 1 ? '' : 's' ?>
                                </span>

                                <?php if ($isSelected): ?>
                                    <a href="activities.php?city=<?= urlencode($currentCity) ?>" class="btn-filter-events active" title="Clear activity filter">
                                        <svg width="14" height="14" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                        <span>Showing Events</span>
                                    </a>
                                <?php else: ?>
                                    <a href="activities.php?activity_id=<?= $act['activity_id'] ?>&city=<?= urlencode($currentCity) ?>" class="btn-filter-events">
                                        <span>Explore Events</span>
                                        <svg width="14" height="14" viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- ================================================================
                 C. SECTION 2: LIVE REAL-WORLD EVENTS & OPPORTUNITIES
                 ================================================================ -->
            <section aria-label="Available Real-World Events">
                <div class="section-header-row">
                    <div>
                        <h2 class="section-main-title">
                            <svg width="20" height="20" viewBox="0 0 24 24" style="stroke:#0F9D8A;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            <span>Live Events & Opportunities</span>
                            <span style="font-size: 14px; color: #64748B; font-weight: 600;">(<?= $totalOpenOpportunities ?> available)</span>
                        </h2>
                        <span style="font-size: 13px; color: #64748B;">
                            Verified competitions, workshops, and sprints in <strong><?= e($currentCity) ?></strong> and virtual platforms
                        </span>
                    </div>
                </div>

                <!-- Active Filter Banner if filtered by activity -->
                <?php if ($filterActivity > 0 && $selectedActivityTitle): ?>
                    <div style="background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 10px; padding: 12px 16px; margin-bottom: 18px; display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-size: 13px; color: #166534; font-weight: 600;">
                            Filtering opportunities for activity: <strong><?= e($selectedActivityTitle) ?></strong>
                        </span>
                        <a href="activities.php?city=<?= urlencode($currentCity) ?>" style="font-size: 12.5px; font-weight: 700; color: #15803D; text-decoration: none;">
                            Clear Filter &times;
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Filters & Search Toolbar -->
                <div class="filter-toolbar">
                    <form action="activities.php" method="GET" class="search-field-wrap">
                        <input type="hidden" name="city" value="<?= e($currentCity) ?>">
                        <?php if ($filterActivity > 0): ?>
                            <input type="hidden" name="activity_id" value="<?= $filterActivity ?>">
                        <?php endif; ?>
                        
                        <span class="search-icon" style="position: absolute; left: 12px; top: 12px; color: #94A3B8;">
                            <svg width="16" height="16" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        </span>
                        <input 
                            type="text" 
                            name="q" 
                            class="search-field-input" 
                            placeholder="Search by event title, organizer, or venue..."
                            value="<?= e($searchQuery) ?>"
                        >
                    </form>

                    <div class="filter-controls-group">
                        <!-- Mode Filter -->
                        <select class="filter-select" onchange="location = this.value;">
                            <option value="<?= e('activities.php?' . http_build_query(array_merge($_GET, ['mode' => 'all']))) ?>" <?= $filterMode === 'all' ? 'selected' : '' ?>>All Formats</option>
                            <option value="<?= e('activities.php?' . http_build_query(array_merge($_GET, ['mode' => 'offline']))) ?>" <?= $filterMode === 'offline' ? 'selected' : '' ?>>In-Person / Offline</option>
                            <option value="<?= e('activities.php?' . http_build_query(array_merge($_GET, ['mode' => 'online']))) ?>" <?= $filterMode === 'online' ? 'selected' : '' ?>>Online / Virtual</option>
                            <option value="<?= e('activities.php?' . http_build_query(array_merge($_GET, ['mode' => 'hybrid']))) ?>" <?= $filterMode === 'hybrid' ? 'selected' : '' ?>>Hybrid</option>
                        </select>

                        <!-- Cost Filter -->
                        <select class="filter-select" onchange="location = this.value;">
                            <option value="<?= e('activities.php?' . http_build_query(array_merge($_GET, ['cost' => 'all']))) ?>" <?= $filterCost === 'all' ? 'selected' : '' ?>>All Prices</option>
                            <option value="<?= e('activities.php?' . http_build_query(array_merge($_GET, ['cost' => 'free']))) ?>" <?= $filterCost === 'free' ? 'selected' : '' ?>>Free Registration</option>
                            <option value="<?= e('activities.php?' . http_build_query(array_merge($_GET, ['cost' => 'paid']))) ?>" <?= $filterCost === 'paid' ? 'selected' : '' ?>>Paid / Ticketed</option>
                        </select>

                        <?php if ($searchQuery !== '' || $filterMode !== 'all' || $filterCost !== 'all' || $filterActivity > 0): ?>
                            <a href="activities.php?city=<?= urlencode($currentCity) ?>" class="btn-clear-filters">
                                Reset Filters
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Opportunities List Grid -->
                <?php if (empty($opportunitiesList)): ?>
                    <div class="empty-discovery-state">
                        <div class="empty-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        </div>
                        <h3 class="empty-title">No matching opportunities found</h3>
                        <p class="empty-desc">
                            We couldn't find any open events matching your current filters in <strong><?= e($currentCity) ?></strong>. Try switching to "All Hubs" or clearing filter criteria.
                        </p>
                        <a href="activities.php?city=All" class="btn-filter-events" style="display:inline-flex;">
                            Browse All Cities & Online Opportunities
                        </a>
                    </div>
                <?php else: ?>
                    <div class="opportunities-grid">
                        <?php foreach ($opportunitiesList as $opp): ?>
                            <?php 
                                $isClosingSoon = $opp['is_closing_soon'];
                                $isEnrolled = $opp['is_enrolled'];
                                $enrollmentStatus = $opp['enrollment_status'];
                                $costLabel = (empty($opp['cost_amount']) || (float)$opp['cost_amount'] == 0) 
                                    ? 'Free' 
                                    : ($opp['cost_currency'] . ' ' . number_format((float)$opp['cost_amount'], 2));
                            ?>
                            <div class="event-card">
                                <div>
                                    <div class="event-card-top">
                                        <span class="event-format-pill <?= e($opp['delivery_mode']) ?>">
                                            <?php if ($opp['delivery_mode'] === 'online'): ?>
                                                <svg width="12" height="12" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line></svg>
                                                <span>Online</span>
                                            <?php elseif ($opp['delivery_mode'] === 'offline'): ?>
                                                <svg width="12" height="12" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                                <span><?= e($opp['city_name']) ?></span>
                                            <?php else: ?>
                                                <svg width="12" height="12" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                                                <span>Hybrid • <?= e($opp['city_name']) ?></span>
                                            <?php endif; ?>
                                        </span>

                                        <?php if ($opp['days_remaining'] !== null): ?>
                                            <span class="deadline-countdown-badge <?= $isClosingSoon ? 'urgent' : 'normal' ?>">
                                                <?= $opp['days_remaining'] === 0 ? 'Closes Today' : ($opp['days_remaining'] === 1 ? '1 day left' : $opp['days_remaining'] . ' days left') ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <h3 class="event-title"><?= e($opp['title']) ?></h3>
                                    
                                    <div class="event-organizer">
                                        <svg width="14" height="14" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                                        <span><?= e($opp['organizer_name']) ?></span>
                                    </div>

                                    <p class="event-desc"><?= e($opp['description']) ?></p>

                                    <!-- Event Metadata Table -->
                                    <div class="event-meta-table">
                                        <?php if (!empty($opp['venue'])): ?>
                                            <div class="event-meta-row">
                                                <span>Venue:</span>
                                                <strong title="<?= e($opp['venue']) ?>" style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= e($opp['venue']) ?></strong>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($opp['start_date'])): ?>
                                            <div class="event-meta-row">
                                                <span>Event Dates:</span>
                                                <strong><?= date('M j, Y', strtotime($opp['start_date'])) ?><?= !empty($opp['end_date']) && $opp['end_date'] !== $opp['start_date'] ? ' – ' . date('M j, Y', strtotime($opp['end_date'])) : '' ?></strong>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($opp['registration_deadline'])): ?>
                                            <div class="event-meta-row">
                                                <span>Register by:</span>
                                                <strong style="color: <?= $isClosingSoon ? '#DC2626' : '#163A70' ?>;"><?= date('M j, Y', strtotime($opp['registration_deadline'])) ?></strong>
                                            </div>
                                        <?php endif; ?>
                                        <div class="event-meta-row">
                                            <span>Registration Fee:</span>
                                            <strong style="color: <?= $costLabel === 'Free' ? '#059669' : '#163A70' ?>;"><?= e($costLabel) ?></strong>
                                        </div>
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div class="event-card-actions">
                                    <!-- Official Registration CTA -->
                                    <a 
                                        href="<?= e($opp['registration_url'] ?: $opp['source_url'] ?: 'https://unstop.com') ?>" 
                                        target="_blank" 
                                        rel="noopener noreferrer" 
                                        class="btn-register-official"
                                        title="Open official registration portal in a new tab"
                                    >
                                        <span>Register on Website</span>
                                        <svg width="14" height="14" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                                    </a>

                                    <!-- Save Opportunity Action Form -->
                                    <form action="activities.php?<?= http_build_query($_GET) ?>" method="POST" style="margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="enroll_opp_id" value="<?= $opp['id'] ?>">
                                        <input type="hidden" name="activity_id" value="<?= $opp['activity_id'] ?>">
                                        
                                        <?php if ($isEnrolled && $enrollmentStatus === 'saved'): ?>
                                            <button type="submit" name="action_type" value="registered" class="btn-save-opp saved" title="Saved! Click to mark as registered">
                                                <svg width="14" height="14" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                <span>Saved</span>
                                            </button>
                                        <?php elseif ($isEnrolled && $enrollmentStatus === 'registered'): ?>
                                            <button type="button" class="btn-save-opp saved" style="cursor: default;" title="You are registered for this opportunity">
                                                <svg width="14" height="14" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                <span>Registered</span>
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" name="action_type" value="saved" class="btn-save-opp" title="Save this opportunity to your portal">
                                                <svg width="14" height="14" viewBox="0 0 24 24"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path></svg>
                                                <span>Save</span>
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </section>

        </main>
    </div>

    <!-- UI Interaction Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile Menu Toggle
            const mobileBtn = document.getElementById('mobileMenuBtn');
            const sidebar = document.getElementById('dashboardSidebar');
            const overlay = document.getElementById('sidebarOverlay');

            if (mobileBtn && sidebar && overlay) {
                mobileBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('open');
                    overlay.classList.toggle('show');
                });

                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('show');
                });
            }
        });
    </script>
</body>
</html>
