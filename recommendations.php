<?php
/**
 * CareerAct — Personalized AI Activity Recommendations (Feature 3)
 * 
 * Recommends curated, verified extracurricular opportunities based on the student's
 * career goals, existing skills, assessed skill gaps, and location preferences.
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
require_once __DIR__ . '/includes/recommendation_service.php';
require_once __DIR__ . '/includes/activity_discovery_service.php';

requireCompletedProfile('onboarding.php?msg=complete_profile');

$userId = getCurrentUserId();
$db = getDbConnection();

// Fetch student profile
$stmtUser = $db->prepare("
    SELECT u.id, u.name, u.email, sp.city, sp.preferred_mode, sp.experience_level, sp.education_level
    FROM users u
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    WHERE u.id = :uid
    LIMIT 1
");
$stmtUser->execute([':uid' => $userId]);
$student = $stmtUser->fetch(PDO::FETCH_ASSOC);

$fullName = trim($student['name'] ?? 'Student');
$initials = strtoupper(substr($fullName, 0, 1));

// Retrieve student's target career
$targetCareer = getStudentTargetCareer($db, $userId);
if (!$targetCareer) {
    header('Location: career_goals.php');
    exit;
}
$careerTitle = $targetCareer['career_title'];

// Auto-expire past deadline opportunities
autoExpirePastOpportunities($db);

$successMsg = '';
$errorMsg   = '';

// Handle POST actions: Save, Dismiss, or Refresh
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'save_opp') {
            $oppId = (int)$_POST['opp_id'];
            $actId = (int)$_POST['act_id'];
            $res = saveOrEnrollOpportunity($db, $userId, $actId, $oppId, 'saved');
            if ($res['success']) {
                $successMsg = 'Opportunity saved to your "Saved Activities" list!';
            }
        } elseif ($action === 'dismiss_opp') {
            $oppId = (int)$_POST['opp_id'];
            if (!isset($_SESSION['dismissed_opps'])) {
                $_SESSION['dismissed_opps'] = [];
            }
            $_SESSION['dismissed_opps'][] = $oppId;
            $successMsg = 'Activity marked as not relevant. We will tailor future recommendations accordingly.';
        } elseif ($action === 'refresh_recs') {
            $recService = new RecommendationService($db);
            $recService->generatePersonalizedRecommendations($userId, true);
            $successMsg = 'AI recommendations refreshed based on your latest profile and activities!';
        }
    } else {
        $errorMsg = 'Security validation failed (invalid CSRF token). Please try again.';
    }
}

// Fetch assessed skill gaps
$stmtGaps = $db->prepare("
    SELECT s.id, s.name AS skill_name, ga.overall_score, ga.gap_status
    FROM ai_skill_gap_analyses ga
    JOIN skills s ON ga.skill_id = s.id
    WHERE ga.user_id = :uid
    ORDER BY ga.id DESC
");
$stmtGaps->execute([':uid' => $userId]);
$skillGaps = $stmtGaps->fetchAll(PDO::FETCH_ASSOC);

// Fetch student claimed skills
$stmtSkills = $db->prepare("
    SELECT s.name 
    FROM student_skills ss
    JOIN skills s ON ss.skill_id = s.id
    WHERE ss.user_id = :uid
");
$stmtSkills->execute([':uid' => $userId]);
$claimedSkills = $stmtSkills->fetchAll(PDO::FETCH_COLUMN);

// Fetch enrolled or dismissed opportunity IDs to exclude
$stmtEnrolled = $db->prepare("
    SELECT opportunity_id FROM enrollments WHERE user_id = :uid AND opportunity_id IS NOT NULL
");
$stmtEnrolled->execute([':uid' => $userId]);
$enrolledOppIds = $stmtEnrolled->fetchAll(PDO::FETCH_COLUMN);
$dismissedOppIds = $_SESSION['dismissed_opps'] ?? [];
$excludeOppIds = array_unique(array_merge($enrolledOppIds, $dismissedOppIds));

// Query verified active opportunities from carrearact2
$discoveryService = new ActivityDiscoveryService($db);

$sqlExclude = !empty($excludeOppIds) ? "AND o.id NOT IN (" . implode(',', array_map('intval', $excludeOppIds)) . ")" : "";

$stmtOpps = $db->prepare("
    SELECT 
        o.id,
        o.title,
        o.description,
        o.delivery_mode,
        o.state_name,
        o.venue,
        o.registration_deadline,
        o.start_date,
        o.end_date,
        o.registration_url,
        o.source_url,
        o.cost_amount,
        o.cost_currency,
        o.verification_status,
        a.id AS activity_id,
        a.title AS activity_title,
        ac.name AS category_name,
        ac.code AS category_code,
        COALESCE(p.name, 'National Academic Partner') AS organizer_name
    FROM opportunities o
    JOIN activities a ON o.activity_id = a.id
    JOIN activity_categories ac ON a.category_id = ac.id
    LEFT JOIN providers p ON o.provider_id = p.id
    WHERE o.status = 'published'
      AND (o.registration_deadline IS NULL OR o.registration_deadline >= CURDATE())
      AND ac.is_active = 1
      {$sqlExclude}
    ORDER BY o.id DESC
    LIMIT 20
");
$stmtOpps->execute();
$candidateOpps = $stmtOpps->fetchAll(PDO::FETCH_ASSOC);

// Score each opportunity deterministically and rank
$rankedRecommendations = [];

foreach ($candidateOpps as $opp) {
    // Fetch skills developed for this activity
    $stmtActSkills = $db->prepare("
        SELECT s.name 
        FROM activity_skills ask
        JOIN skills s ON ask.skill_id = s.id
        WHERE ask.activity_id = :aid
    ");
    $stmtActSkills->execute([':aid' => $opp['activity_id']]);
    $actSkills = $stmtActSkills->fetchAll(PDO::FETCH_COLUMN);
    $opp['skills_developed'] = !empty($actSkills) ? $actSkills : ['Problem Solving', 'Practical Application'];

    $matchResult = $discoveryService->calculateMatchScore($opp, $student ?: [], $targetCareer, $skillGaps);
    $opp['match_score'] = $matchResult['match_score'];
    $opp['match_factors'] = $matchResult['factors'];
    $opp['primary_reason'] = $matchResult['primary_reason'];

    // Construct tailored explanation
    $opp['explanation'] = "Recommended for your {$careerTitle} pathway. Participating helps you bridge developmental gaps, strengthen practical application, and build tangible evidence for your portfolio.";

    $rankedRecommendations[] = $opp;
}

// Sort by match score descending
usort($rankedRecommendations, fn($a, $b) => $b['match_score'] <=> $a['match_score']);
$topRecommendations = array_slice($rankedRecommendations, 0, 8);

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Opportunities Chosen for Your Future — CareerAct</title>
    <meta name="description" content="AI-Powered Personalized Extracurricular Activity Recommendations based on your career goals and competencies.">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= time() ?>">
    <style>
        svg { fill: none !important; stroke: currentColor !important; display: inline-block; vertical-align: middle; }
        
        /* Modern AI Hero Header */
        .recs-hero {
            background: linear-gradient(135deg, #163A70 0%, #1769E8 60%, #4F46E5 100%);
            border-radius: 16px;
            padding: 32px;
            color: #FFFFFF;
            margin-bottom: 24px;
            position: relative;
            box-shadow: 0 8px 24px rgba(23, 105, 232, 0.2);
            overflow: hidden;
        }
        .recs-hero::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 320px;
            height: 320px;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .recs-tag-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.35);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        /* AI Analysis Progress Live Banner */
        .ai-progress-banner {
            background: #FFFFFF;
            border: 1px solid #BFDBFE;
            border-radius: 14px;
            padding: 18px 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 14px rgba(23, 105, 232, 0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }
        .ai-pulse-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: #10B981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
            animation: pulse-ring 1.8s infinite;
        }
        @keyframes pulse-ring {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.5); }
            70% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        /* Recommendations Cards Grid */
        .recs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 22px;
            margin-bottom: 32px;
        }
        .rec-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 16px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.25s ease;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
            position: relative;
        }
        .rec-card:hover {
            transform: translateY(-4px);
            border-color: #1769E8;
            box-shadow: 0 12px 24px -6px rgba(23, 105, 232, 0.16);
        }
        .match-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #ECFDF5;
            color: #065F46;
            border: 1px solid #A7F3D0;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 800;
        }
        .category-pill {
            display: inline-flex;
            align-items: center;
            background: #F1F5F9;
            color: #475569;
            padding: 3px 9px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .rec-title {
            font-size: 17px;
            font-weight: 800;
            color: #0F172A;
            margin: 10px 0 6px 0;
            line-height: 1.35;
        }
        .rec-organizer {
            font-size: 12.5px;
            color: #64748B;
            margin-bottom: 12px;
        }
        .rec-explanation-box {
            background: #F8FAFC;
            border: 1px solid #F1F5F9;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 12.5px;
            color: #334155;
            line-height: 1.5;
            margin-bottom: 14px;
        }
        .rec-skills-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-bottom: 16px;
        }
        .rec-skill-tag {
            font-size: 11px;
            font-weight: 600;
            color: #1D4ED8;
            background: #EFF6FF;
            padding: 2px 8px;
            border-radius: 4px;
            border: 1px solid #DBEAFE;
        }
        .rec-actions {
            border-top: 1px solid #F1F5F9;
            padding-top: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .btn-view-rec {
            font-size: 12.5px;
            font-weight: 700;
            color: #FFFFFF;
            background: #1769E8;
            padding: 7px 14px;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .btn-view-rec:hover {
            background: #1255C0;
            color: #FFFFFF;
        }
        .btn-dismiss-rec {
            background: none;
            border: none;
            color: #94A3B8;
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
            padding: 6px 8px;
        }
        .btn-dismiss-rec:hover {
            color: #DC2626;
            text-decoration: underline;
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
                <span class="welcome-title">Personalized Recommendations</span>
                <span class="welcome-subtitle">Tailored for: <?= e($careerTitle) ?></span>
            </div>
            <div class="topbar-actions">
                <form method="POST" action="recommendations.php" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="refresh_recs">
                    <button type="submit" class="btn-view-details" style="padding: 7px 14px; cursor: pointer; border: 1px solid #CBD5E1; background: #FFF;">
                        ↻ Refresh Recommendations
                    </button>
                </form>
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

            <!-- Hero Section -->
            <div class="recs-hero">
                <div class="recs-tag-pill">
                    ✦ AI Personalized Discovery
                </div>
                <h1 style="font-size: 26px; font-weight: 800; margin: 0 0 10px 0; line-height: 1.3;">
                    Opportunities Chosen for Your Future
                </h1>
                <p style="font-size: 14.5px; color: rgba(255,255,255,0.9); margin: 0; max-width: 780px; line-height: 1.6;">
                    AI is analyzing your career goals and finding activities that can help you grow. These verified opportunities match your skills, address assessed development areas, and strengthen your professional portfolio.
                </p>
            </div>

            <!-- AI Live Analysis Status Banner -->
            <div class="ai-progress-banner">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div class="ai-pulse-dot"></div>
                    <div>
                        <div style="font-size: 13.5px; font-weight: 700; color: #0F172A;">
                            AI Recommendation Engine Active
                        </div>
                        <div style="font-size: 12px; color: #64748B;">
                            Cross-referenced against <?= count($claimedSkills) ?> claimed skills, <?= count($skillGaps) ?> assessed development areas, and verified Indian opportunities.
                        </div>
                    </div>
                </div>
                <div style="font-size: 12px; font-weight: 700; color: #1769E8; background: #EEF4FF; padding: 6px 12px; border-radius: 6px;">
                    Target: <?= e($careerTitle) ?>
                </div>
            </div>

            <!-- Opportunities Cards Grid -->
            <?php if (!empty($topRecommendations)): ?>
                <div class="recs-grid">
                    <?php foreach ($topRecommendations as $rec): ?>
                        <div class="rec-card">
                            <div>
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                    <span class="category-pill"><?= e($rec['category_name']) ?></span>
                                    <span class="match-badge">
                                        ✦ Match: <?= (int)$rec['match_score'] ?>%
                                    </span>
                                </div>

                                <h2 class="rec-title"><?= e($rec['title']) ?></h2>
                                <div class="rec-organizer">
                                    By <strong><?= e($rec['organizer_name']) ?></strong> • <?= ucfirst(e($rec['delivery_mode'])) ?> (<?= e($rec['state_name']) ?>)
                                </div>

                                <div class="rec-explanation-box">
                                    <strong>Why Recommended:</strong> <?= e($rec['primary_reason']) ?>
                                </div>

                                <div class="rec-skills-wrap">
                                    <?php foreach ($rec['skills_developed'] as $sk): ?>
                                        <span class="rec-skill-tag"><?= e($sk) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="rec-actions">
                                <div>
                                    <a href="activity_details.php?id=<?= (int)$rec['activity_id'] ?>&opp_id=<?= (int)$rec['id'] ?>" class="btn-view-rec">
                                        View Details &rarr;
                                    </a>
                                </div>
                                <div style="display: flex; align-items: center; gap: 4px;">
                                    <form method="POST" action="recommendations.php" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="save_opp">
                                        <input type="hidden" name="opp_id" value="<?= (int)$rec['id'] ?>">
                                        <input type="hidden" name="act_id" value="<?= (int)$rec['activity_id'] ?>">
                                        <button type="submit" class="btn-dismiss-rec" title="Save to Saved Activities">
                                            ☆ Save
                                        </button>
                                    </form>
                                    <form method="POST" action="recommendations.php" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="dismiss_opp">
                                        <input type="hidden" name="opp_id" value="<?= (int)$rec['id'] ?>">
                                        <button type="submit" class="btn-dismiss-rec" title="Don't show this activity again">
                                            Dismiss
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 14px; padding: 48px 24px; text-align: center;">
                    <div style="font-size: 18px; font-weight: 800; color: #0F172A; margin-bottom: 8px;">No More Pending Recommendations</div>
                    <p style="font-size: 13.5px; color: #64748B; margin-bottom: 20px;">You have reviewed or enrolled in the current recommendations matching your profile.</p>
                    <a href="activities.php" class="btn-view-rec">Explore All Activities</a>
                </div>
            <?php endif; ?>

        </main>
    </div>
</body>
</html>
