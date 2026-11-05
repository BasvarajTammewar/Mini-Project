<?php
/**
 * CareerAct — Activity & Opportunity Details Module (Feature 2)
 * 
 * Displays complete extracurricular activity intelligence, organizer verification,
 * career relevance, AI-generated match breakdown, and official registration handling.
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
require_once __DIR__ . '/includes/activity_discovery_service.php';

requireCompletedProfile('onboarding.php?msg=complete_profile');

$userId = getCurrentUserId();
$db = getDbConnection();

// Fetch student profile info
$stmtUser = $db->prepare("
    SELECT u.id, u.name, u.email, sp.city, sp.preferred_mode, sp.experience_level
    FROM users u
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    WHERE u.id = :uid
    LIMIT 1
");
$stmtUser->execute([':uid' => $userId]);
$student = $stmtUser->fetch(PDO::FETCH_ASSOC);

$fullName = trim($student['name'] ?? 'Student');
$initials = strtoupper(substr($fullName, 0, 1));

// Resolve Activity & Opportunity IDs
$activityId = (int)($_GET['id'] ?? ($_GET['act_id'] ?? 0));
$opportunityId = (int)($_GET['opp_id'] ?? 0);

if ($activityId <= 0 && $opportunityId <= 0) {
    header('Location: activities.php');
    exit;
}

// If opportunity ID provided but not activity ID, look it up
if ($opportunityId > 0 && $activityId <= 0) {
    $stmtLookup = $db->prepare("SELECT activity_id FROM opportunities WHERE id = :oid LIMIT 1");
    $stmtLookup->execute([':oid' => $opportunityId]);
    $activityId = (int)$stmtLookup->fetchColumn();
}

$successMsg = '';
$errorMsg   = '';

// Handle POST actions: Save Opportunity or Confirm Registration
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'save') {
            $res = saveOrEnrollOpportunity($db, $userId, $activityId, $opportunityId > 0 ? $opportunityId : null, 'saved');
            if ($res['success']) {
                $successMsg = 'Activity saved to your "Saved Activities" list!';
            } else {
                $errorMsg = $res['message'] ?? 'Could not save activity.';
            }
        } elseif ($action === 'confirm_registration') {
            $res = saveOrEnrollOpportunity($db, $userId, $activityId, $opportunityId > 0 ? $opportunityId : null, 'registered');
            if ($res['success']) {
                $successMsg = 'Registration confirmed! This activity is now tracked under "My Activities".';
            } else {
                $errorMsg = $res['message'] ?? 'Could not register for activity.';
            }
        }
    } else {
        $errorMsg = 'Security validation failed (invalid CSRF token). Please try again.';
    }
}

// Fetch complete activity details
$stmtAct = $db->prepare("
    SELECT 
        a.id AS activity_id,
        a.title AS activity_title,
        a.description AS activity_desc,
        a.delivery_mode,
        a.difficulty,
        a.typical_hours,
        a.eligibility AS act_eligibility,
        a.prerequisites,
        a.learning_outcomes,
        ac.id AS category_id,
        ac.code AS category_code,
        ac.name AS category_name
    FROM activities a
    JOIN activity_categories ac ON a.category_id = ac.id
    WHERE a.id = :aid
    LIMIT 1
");
$stmtAct->execute([':aid' => $activityId]);
$activity = $stmtAct->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    header('Location: activities.php');
    exit;
}

// Fetch opportunity details if exists
$opportunity = null;
if ($opportunityId > 0) {
    $stmtOpp = $db->prepare("
        SELECT 
            o.*,
            COALESCE(p.name, 'National Academic Partner') AS provider_name,
            p.website AS provider_website
        FROM opportunities o
        LEFT JOIN providers p ON o.provider_id = p.id
        WHERE o.id = :oid
        LIMIT 1
    ");
    $stmtOpp->execute([':oid' => $opportunityId]);
    $opportunity = $stmtOpp->fetch(PDO::FETCH_ASSOC);
}

// If no specific opportunity requested, find best matching active opportunity for this activity
if (!$opportunity) {
    $stmtOppFallback = $db->prepare("
        SELECT 
            o.*,
            COALESCE(p.name, 'National Academic Partner') AS provider_name,
            p.website AS provider_website
        FROM opportunities o
        LEFT JOIN providers p ON o.provider_id = p.id
        WHERE o.activity_id = :aid AND o.status = 'published'
        ORDER BY (o.registration_deadline >= CURDATE()) DESC, o.id DESC
        LIMIT 1
    ");
    $stmtOppFallback->execute([':aid' => $activityId]);
    $opportunity = $stmtOppFallback->fetch(PDO::FETCH_ASSOC);
    if ($opportunity) {
        $opportunityId = (int)$opportunity['id'];
    }
}

// Check current enrollment status for student
$stmtEnroll = $db->prepare("
    SELECT id, status, progress_pct, notes, started_at, completed_at, created_at
    FROM enrollments 
    WHERE user_id = :uid AND activity_id = :aid
    LIMIT 1
");
$stmtEnroll->execute([':uid' => $userId, ':aid' => $activityId]);
$currentEnrollment = $stmtEnroll->fetch(PDO::FETCH_ASSOC);

// Fetch skills developed
$stmtSkills = $db->prepare("
    SELECT s.name 
    FROM activity_skills ask
    JOIN skills s ON ask.skill_id = s.id
    WHERE ask.activity_id = :aid
");
$stmtSkills->execute([':aid' => $activityId]);
$skillsDeveloped = $stmtSkills->fetchAll(PDO::FETCH_COLUMN);

if (empty($skillsDeveloped) && !empty($activity['learning_outcomes'])) {
    $skillsDeveloped = array_map('trim', explode(',', $activity['learning_outcomes']));
}

// Fetch student target career for contextual AI relevance
$targetCareer = getStudentTargetCareer($db, $userId);
$careerTitle = $targetCareer['career_title'] ?? 'Software Developer';

// Fetch assessed skill gaps for score calculation
$stmtGaps = $db->prepare("
    SELECT s.name AS skill_name
    FROM ai_skill_gap_analyses ga
    JOIN skills s ON ga.skill_id = s.id
    WHERE ga.user_id = :uid
");
$stmtGaps->execute([':uid' => $userId]);
$skillGaps = $stmtGaps->fetchAll(PDO::FETCH_ASSOC);

// Calculate Deterministic AI Match Score
$discoveryService = new ActivityDiscoveryService($db);
$oppDataForScore = [
    'title'            => $opportunity['title'] ?? $activity['activity_title'],
    'description'      => $opportunity['description'] ?? $activity['activity_desc'],
    'category_code'    => $activity['category_code'],
    'delivery_mode'    => $opportunity['delivery_mode'] ?? $activity['delivery_mode'],
    'state_name'       => $opportunity['state_name'] ?? 'All India',
    'venue'            => $opportunity['venue'] ?? '',
    'skills_developed' => $skillsDeveloped,
    'verification_status' => $opportunity['verification_status'] ?? 'verified'
];
$matchInfo = $discoveryService->calculateMatchScore($oppDataForScore, $student ?: [], $targetCareer, $skillGaps);

// AI Recommendation Explanation
$aiExplanation = "This {$activity['category_name']} directly aligns with your career target in {$careerTitle}. Participating enables you to put theoretical knowledge into practical, collaborative problem-solving while generating demonstrable portfolio artifacts.";

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($opportunity['title'] ?? $activity['activity_title']) ?> — CareerAct</title>
    <meta name="description" content="Detailed opportunity information, official registration verification, and career development relevance.">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= time() ?>">
    <style>
        svg { fill: none !important; stroke: currentColor !important; display: inline-block; vertical-align: middle; }
        .details-hero {
            background: linear-gradient(135deg, #163A70 0%, #1769E8 60%, #4F46E5 100%);
            border-radius: 16px;
            padding: 32px;
            color: #FFFFFF;
            margin-bottom: 24px;
            position: relative;
            box-shadow: 0 8px 24px rgba(23, 105, 232, 0.2);
        }
        .details-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }
        @media (max-width: 991px) {
            .details-grid { grid-template-columns: 1fr; }
        }
        .details-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        }
        .details-card-title {
            font-size: 16px;
            font-weight: 800;
            color: #0F172A;
            margin: 0 0 16px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .skill-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #EEF4FF;
            color: #1769E8;
            font-size: 12px;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 20px;
            border: 1px solid #BFDBFE;
            margin: 0 6px 6px 0;
        }
        .match-score-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #ECFDF5;
            color: #065F46;
            border: 1px solid #A7F3D0;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 800;
        }
        .action-cta-box {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }
        .btn-register-official {
            display: block;
            width: 100%;
            background: #1769E8;
            color: #FFFFFF;
            font-size: 14px;
            font-weight: 700;
            padding: 12px 20px;
            border-radius: 8px;
            text-decoration: none;
            text-align: center;
            transition: all 0.2s ease;
            box-sizing: border-box;
            margin-bottom: 10px;
        }
        .btn-register-official:hover {
            background: #1255C0;
            color: #FFFFFF;
        }
        .btn-confirm-reg {
            display: block;
            width: 100%;
            background: #059669;
            color: #FFFFFF;
            font-size: 13.5px;
            font-weight: 700;
            padding: 10px 18px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-bottom: 10px;
        }
        .btn-confirm-reg:hover {
            background: #047857;
        }
        .btn-save-activity {
            display: block;
            width: 100%;
            background: #FFFFFF;
            color: #1E293B;
            border: 1px solid #CBD5E1;
            font-size: 13px;
            font-weight: 700;
            padding: 9px 18px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-save-activity:hover {
            background: #F1F5F9;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #F1F5F9;
            font-size: 13px;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            color: #64748B;
            font-weight: 600;
        }
        .info-val {
            color: #0F172A;
            font-weight: 700;
            text-align: right;
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
            <a href="logout.php" class="logout-link"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg><span>Logout</span></a>
        </div>
    </aside>

    <!-- Main Content Area -->
    <div class="dashboard-main">
        <header class="dashboard-topbar">
            <div class="topbar-welcome">
                <span class="welcome-title">Activity Details</span>
                <span class="welcome-subtitle"><?= e($activity['category_name']) ?> • Verified Student Opportunity</span>
            </div>
            <div class="topbar-actions">
                <a href="activities.php" class="btn-view-details" style="padding: 7px 14px;">&larr; Back to Explore</a>
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

            <!-- Hero Banner -->
            <div class="details-hero">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
                    <span style="background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 20px; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
                        <?= e($activity['category_name']) ?>
                    </span>
                    <span class="match-score-badge">
                        ✦ AI Match Score: <?= $matchInfo['match_score'] ?>%
                    </span>
                </div>
                <h1 style="font-size: 26px; font-weight: 800; margin: 0 0 10px 0; line-height: 1.3;">
                    <?= e($opportunity['title'] ?? $activity['activity_title']) ?>
                </h1>
                <p style="font-size: 14.5px; color: rgba(255,255,255,0.9); margin: 0; max-width: 800px; line-height: 1.6;">
                    Organized by <strong><?= e($opportunity['provider_name'] ?? 'National Academic Partner') ?></strong> • Verified for Indian University Students
                </p>
            </div>

            <!-- Main Layout Grid -->
            <div class="details-grid">
                
                <!-- Left Column: Full Description, Learning Outcomes, AI Explanation -->
                <div>
                    <!-- About & Description -->
                    <div class="details-card">
                        <h2 class="details-card-title">
                            <svg width="20" height="20" viewBox="0 0 24 24" stroke="#1769E8" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                            Activity Overview & Description
                        </h2>
                        <div style="font-size: 14px; color: #334155; line-height: 1.7; margin-bottom: 20px;">
                            <?= nl2br(e($opportunity['description'] ?? $activity['activity_desc'])) ?>
                        </div>

                        <?php if (!empty($activity['eligibility']) || !empty($opportunity['eligibility'])): ?>
                            <div style="background: #FFFBEB; border: 1px solid #FEF3C7; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px;">
                                <div style="font-size: 12px; font-weight: 800; color: #92400E; text-transform: uppercase; margin-bottom: 4px;">Eligibility Criteria</div>
                                <div style="font-size: 13px; color: #78350F; line-height: 1.5;">
                                    <?= e($opportunity['eligibility'] ?? $activity['eligibility']) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <h3 style="font-size: 14px; font-weight: 800; color: #0F172A; margin: 18px 0 10px 0;">Skills Developed & Tested:</h3>
                        <div>
                            <?php foreach ($skillsDeveloped as $sk): ?>
                                <span class="skill-pill">
                                    <svg width="12" height="12" viewBox="0 0 24 24" stroke="#1769E8" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                    <?= e($sk) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- AI Career Relevance Box -->
                    <div class="details-card" style="border-left: 4px solid #1769E8; background: #F8FAFC;">
                        <h2 class="details-card-title">
                            <svg width="20" height="20" viewBox="0 0 24 24" stroke="#1769E8" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                            Career Relevance: <?= e($careerTitle) ?>
                        </h2>
                        <p style="font-size: 13.5px; color: #334155; line-height: 1.6; margin: 0 0 14px 0;">
                            <?= e($aiExplanation) ?>
                        </p>
                        <?php if (!empty($matchInfo['factors'])): ?>
                            <div style="font-size: 12.5px; color: #475569;">
                                <strong>Why this opportunity matches you:</strong>
                                <ul style="margin: 6px 0 0 0; padding-left: 20px; line-height: 1.6;">
                                    <?php foreach ($matchInfo['factors'] as $factor): ?>
                                        <li><?= e($factor) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Column: Registration CTA, Metadata, Organizer -->
                <div>
                    <!-- Registration & Tracking Actions -->
                    <div class="details-card">
                        <div class="action-cta-box">
                            <span style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: 0.5px;">Current Tracking Status</span>
                            <div style="font-size: 16px; font-weight: 800; color: #0F172A; margin: 4px 0 16px 0;">
                                <?php if ($currentEnrollment): ?>
                                    <span style="color: #059669;">● <?= ucfirst(str_replace('_', ' ', $currentEnrollment['status'])) ?></span>
                                <?php else: ?>
                                    <span style="color: #64748B;">Not Enrolled</span>
                                <?php endif; ?>
                            </div>

                            <?php 
                            $regUrl = !empty($opportunity['registration_url']) ? $opportunity['registration_url'] : (!empty($opportunity['source_url']) ? $opportunity['source_url'] : '#'); 
                            ?>

                            <a href="<?= e($regUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn-register-official">
                                Register on Official Website &#8599;
                            </a>
                            <p style="font-size: 11px; color: #64748B; margin: 0 0 14px 0; line-height: 1.4;">
                                Opening the official link does not mark you as registered. Once registered on their site, confirm below to track your progress.
                            </p>

                            <form method="POST" action="activity_details.php?id=<?= $activityId ?>&opp_id=<?= $opportunityId ?>">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="confirm_registration">
                                <button type="submit" class="btn-confirm-reg">
                                    ✓ Confirm My Registration
                                </button>
                            </form>

                            <?php if (!$currentEnrollment || $currentEnrollment['status'] !== 'saved'): ?>
                                <form method="POST" action="activity_details.php?id=<?= $activityId ?>&opp_id=<?= $opportunityId ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="save">
                                    <button type="submit" class="btn-save-activity">
                                        ☆ Save for Later
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Opportunity Metadata Table -->
                    <div class="details-card">
                        <h2 class="details-card-title">Event Logistics</h2>
                        <div class="info-row">
                            <span class="info-label">Mode:</span>
                            <span class="info-val"><?= ucfirst(e($opportunity['delivery_mode'] ?? $activity['delivery_mode'])) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Location:</span>
                            <span class="info-val"><?= e($opportunity['state_name'] ?? 'All India') ?><?= !empty($opportunity['venue']) ? ' (' . e($opportunity['venue']) . ')' : '' ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Registration Deadline:</span>
                            <span class="info-val" style="color: #DC2626;">
                                <?= !empty($opportunity['registration_deadline']) ? date('M j, Y', strtotime($opportunity['registration_deadline'])) : 'Open' ?>
                            </span>
                        </div>
                        <?php if (!empty($opportunity['start_date'])): ?>
                            <div class="info-row">
                                <span class="info-label">Event Dates:</span>
                                <span class="info-val">
                                    <?= date('M j, Y', strtotime($opportunity['start_date'])) ?>
                                    <?= !empty($opportunity['end_date']) && $opportunity['end_date'] !== $opportunity['start_date'] ? ' - ' . date('M j, Y', strtotime($opportunity['end_date'])) : '' ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <div class="info-row">
                            <span class="info-label">Cost:</span>
                            <span class="info-val" style="color: #059669;">
                                <?= (empty($opportunity['cost_amount']) || (float)$opportunity['cost_amount'] <= 0) ? 'Free Participation' : '₹' . number_format((float)$opportunity['cost_amount'], 2) ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Organizer:</span>
                            <span class="info-val"><?= e($opportunity['provider_name'] ?? 'Academic Partner') ?></span>
                        </div>
                        <?php if (!empty($opportunity['provider_website'])): ?>
                            <div class="info-row">
                                <span class="info-label">Official Portal:</span>
                                <span class="info-val">
                                    <a href="<?= e($opportunity['provider_website']) ?>" target="_blank" rel="noopener noreferrer" style="color: #1769E8; text-decoration: underline;">
                                        Visit Website &#8599;
                                    </a>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </main>
    </div>
</body>
</html>
