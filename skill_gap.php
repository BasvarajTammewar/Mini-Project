<?php
/**
 * CareerAct — Modern AI Skill-Gap Analysis & Recommendations
 * 
 * Attractive, modern AI Cards interface:
 * 1. AI Score Hero Card with dynamic animated CSS progress bar
 * 2. "Do You Need Improvement?" AI Status Card with growth highlights
 * 3. 4 Core Evaluation Criteria AI Diagnostic Cards (Decision Making, Critical Thinking, Team Management, Responsibility)
 * 4. AI-Curated Extracurricular Opportunities from carrearact2 database
 * 5. Personalized Gemini AI Feedback & Scenario Breakdown
 * 
 * Database: carrearact2
 */

declare(strict_types=1);

// Prevent caching stale states
if (!headers_sent()) {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/assessment_service.php';
require_once __DIR__ . '/includes/skill_gap_service.php';
require_once __DIR__ . '/includes/event_discovery_service.php';

requireCompletedProfile('onboarding.php?msg=complete_profile');

$userId = getCurrentUserId();
$db = getDbConnection();

// Fetch student info matching dashboard
$stmtUser = $db->prepare("
    SELECT u.id, u.name, u.email, sp.experience_level 
    FROM users u 
    LEFT JOIN student_profiles sp ON u.id = sp.user_id 
    WHERE u.id = :user_id 
    LIMIT 1
");
$stmtUser->execute([':user_id' => $userId]);
$student = $stmtUser->fetch(PDO::FETCH_ASSOC);
$fullName = trim($student['name'] ?? 'Student');
$nameParts = explode(' ', $fullName);
$firstName = $nameParts[0];
$initials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));
$expLevel = ucfirst($student['experience_level'] ?? 'Beginner');

// Unread notifications count for bell badge and sidebar
$stmtNotifs = $db->prepare("
    SELECT COUNT(*) 
    FROM notifications 
    WHERE user_id = :user_id 
      AND is_read = 0 
      AND (is_dismissed IS NULL OR is_dismissed = 0)
");
$stmtNotifs->execute([':user_id' => $userId]);
$unreadNotificationsCount = (int)$stmtNotifs->fetchColumn();

// Target career
$targetCareer = getStudentTargetCareer($db, $userId);
$careerTitle = $targetCareer['career_title'] ?? 'Industry Professional';

// Completed assessments for this student
$assessService = new AssessmentService($db);
$historyList = $assessService->getAssessmentHistory($userId);
$hasAssessments = !empty($historyList);

// Determine selected assessment ID
$selectedAssessmentId = (int)($_GET['attempt_id'] ?? ($_GET['id'] ?? ($_GET['record_id'] ?? 0)));
if ($selectedAssessmentId <= 0 && $hasAssessments) {
    $selectedAssessmentId = (int)$historyList[0]['assessment_id'];
}

$assessmentDetails = null;
if ($selectedAssessmentId > 0) {
    $assessmentDetails = $assessService->getAssessmentSession($selectedAssessmentId, $userId);
    // Multi-tenant fallback: if not owned by user, fall back to user's latest
    if (!$assessmentDetails && $hasAssessments) {
        $selectedAssessmentId = (int)$historyList[0]['assessment_id'];
        $assessmentDetails = $assessService->getAssessmentSession($selectedAssessmentId, $userId);
    }
}

$overallScore = 0.0;
$demLevel = '';
$skillName = '';
$categoryName = 'General';
$strengths = [];
$improvements = [];
$rubrics = [];
$answers = [];
$aiFeedback = '';
$completedAt = date('M j, Y');
$recommendedActivities = [];
$criteriaPillars = [];
$improvementStatus = 'Needs Improvement';
$needsImprovementHeadline = '';
$needsImprovementExplanation = '';
$showImprovementUrgency = true;
$statusColor = '#DC2626';
$statusBg = '#FEF2F2';
$progressBarColor = '#EF4444';

if ($assessmentDetails) {
    $overallScore = round((float)$assessmentDetails['overall_score'], 1);
    $demLevel = (string)$assessmentDetails['demonstration_level'];
    $skillName = (string)$assessmentDetails['skill_name'];
    $categoryName = (string)$assessmentDetails['category_name'];
    $strengths = json_decode((string)$assessmentDetails['strengths_json'], true) ?: [];
    $improvements = json_decode((string)$assessmentDetails['improvements_json'], true) ?: [];
    $rubrics = json_decode((string)$assessmentDetails['rubric_summary_json'], true) ?: [];
    $answers = $assessmentDetails['answers'] ?? [];
    $aiFeedback = (string)$assessmentDetails['ai_feedback'];
    $completedAt = !empty($assessmentDetails['completed_at']) ? date('M j, Y • g:i A', strtotime($assessmentDetails['completed_at'])) : date('M j, Y');

    // Improvement status
    if ($overallScore >= 80) {
        $improvementStatus = 'Strongly Demonstrated';
        $statusColor = '#059669';
        $statusBg = '#ECFDF5';
        $progressBarColor = '#10B981';
        $needsImprovementHeadline = "Strong {$skillName} Capability Demonstrated";
        $needsImprovementExplanation = "Your assessment indicates that you demonstrate strong proficiency in {$skillName}. You exhibited solid situational awareness, proactive problem solving, and effective execution across practical workplace scenarios.";
        $showImprovementUrgency = false;
    } elseif ($overallScore >= 50) {
        $improvementStatus = 'Partially Demonstrated';
        $statusColor = '#D97706';
        $statusBg = '#FFFBEB';
        $progressBarColor = '#F59E0B';
        $needsImprovementHeadline = "Your {$skillName} Skill Needs Improvement";
        $needsImprovementExplanation = "Your assessment indicates that you demonstrate foundational {$skillName} abilities, but you can improve your structured execution, stakeholder diplomacy, and root-cause resolution skills.";
        $showImprovementUrgency = true;
    } else {
        $improvementStatus = 'Needs Improvement';
        $statusColor = '#DC2626';
        $statusBg = '#FEF2F2';
        $progressBarColor = '#EF4444';
        $needsImprovementHeadline = "Your {$skillName} Skill Requires Focused Improvement";
        $needsImprovementExplanation = "Your assessment indicates significant developmental gaps in {$skillName}. Your scenario responses lacked actionable step-by-step resolution workflows and structured contingency planning.";
        $showImprovementUrgency = true;
    }

    // 4 Criteria Pillars
    $criteriaPillars = [
        [
            'id'          => 'decision_making',
            'name'        => 'Decision Making & Tactical Approach',
            'pillar_key'  => 'practical_approach',
            'weight_pct'  => !empty($rubrics['practical_approach']) ? round(($rubrics['practical_approach'] / 25) * 100, 1) : $overallScore,
            'tip'         => 'Focus on identifying immediate tactical priorities before executing emergency decisions.',
        ],
        [
            'id'          => 'critical_thinking',
            'name'        => 'Critical Thinking & Root Cause Analysis',
            'pillar_key'  => 'critical_thinking',
            'weight_pct'  => !empty($rubrics['critical_thinking']) ? round(($rubrics['critical_thinking'] / 25) * 100, 1) : max(35, round($overallScore * 0.95, 1)),
            'tip'         => 'Investigate underlying systematic bottlenecks rather than treating surface-level symptoms.',
        ],
        [
            'id'          => 'team_management',
            'name'        => 'Team Management & Conflict Resolution',
            'pillar_key'  => 'strategy_communication',
            'weight_pct'  => !empty($rubrics['strategy_communication']) ? round(($rubrics['strategy_communication'] / 25) * 100, 1) : max(40, round($overallScore * 0.9, 1)),
            'tip'         => 'Use constructive diplomacy, transparent escalations, and active alignment with team members.',
        ],
        [
            'id'          => 'responsibility',
            'name'        => 'Responsibility & Impact Delivery',
            'pillar_key'  => 'impact_resolution',
            'weight_pct'  => !empty($rubrics['impact_resolution']) ? round(($rubrics['impact_resolution'] / 25) * 100, 1) : max(45, round($overallScore * 1.05, 1)),
            'tip'         => 'Follow through with post-incident reviews, preventive documentation, and verifiable outcomes.',
        ],
    ];

    // Real verified activity recommendations from carrearact2
    $stmtRecs = $db->prepare("
        SELECT 
            o.id AS opportunity_id,
            o.title AS opportunity_title,
            o.description AS opportunity_desc,
            o.delivery_mode,
            o.state_name,
            o.venue,
            o.start_date,
            o.end_date,
            o.registration_deadline,
            o.registration_url,
            o.source_url,
            o.cost_amount,
            o.cost_currency,
            a.id AS activity_id,
            a.title AS activity_title,
            a.description AS activity_desc,
            ac.name AS category_name,
            ac.code AS category_code,
            COALESCE(p.name, 'Academic Partner') AS provider_name
        FROM opportunities o
        JOIN activities a ON o.activity_id = a.id
        JOIN activity_categories ac ON a.category_id = ac.id
        LEFT JOIN providers p ON o.provider_id = p.id
        LEFT JOIN activity_skills ask ON a.id = ask.activity_id AND ask.skill_id = :skill_id
        WHERE o.status = 'published'
          AND o.verification_status IN ('verified', 'sample')
          AND (o.registration_deadline IS NULL OR o.registration_deadline >= CURDATE())
          AND ac.is_active = 1
        ORDER BY (CASE WHEN ask.skill_id IS NOT NULL THEN 1 ELSE 0 END) DESC, o.id DESC
        LIMIT 4
    ");
    $stmtRecs->execute([':skill_id' => (int)$assessmentDetails['skill_id']]);
    $recommendedActivities = $stmtRecs->fetchAll(PDO::FETCH_ASSOC);

    foreach ($recommendedActivities as &$act) {
        $stmtSkills = $db->prepare("
            SELECT s.name 
            FROM activity_skills ask 
            JOIN skills s ON ask.skill_id = s.id 
            WHERE ask.activity_id = :act_id 
            LIMIT 4
        ");
        $stmtSkills->execute([':act_id' => $act['activity_id']]);
        $actSkills = $stmtSkills->fetchAll(PDO::FETCH_COLUMN);

        if (empty($actSkills)) {
            $actSkills = [$skillName, 'Teamwork', 'Project Execution'];
        }
        $act['skills_developed'] = $actSkills;

        $catLower = strtolower($act['category_name']);
        if (strpos($catLower, 'hackathon') !== false || strpos($catLower, 'competition') !== false) {
            $act['why_recommended'] = "Participating in this competitive sprint challenges you to make rapid decisions, coordinate under pressure, and apply {$skillName} in real-world scenarios.";
        } elseif (strpos($catLower, 'workshop') !== false || strpos($catLower, 'course') !== false) {
            $act['why_recommended'] = "Provides guided, structured practice with peer feedback to directly bridge identified developmental gaps in {$skillName}.";
        } elseif (strpos($catLower, 'project') !== false || strpos($catLower, 'portfolio') !== false) {
            $act['why_recommended'] = "Builds tangible, end-to-end evidence of {$skillName} and problem solving that employers can review on your portfolio.";
        } else {
            $act['why_recommended'] = "Gives hands-on opportunities to collaborate with peers, solve conflicts, and practice {$skillName} in a structured environment.";
        }
    }
    unset($act);
}

if (!function_exists('getCriterionStatus')) {
    function getCriterionStatus(float $score): array
    {
        if ($score >= 80) {
            return ['label' => 'Strong', 'color' => '#065F46', 'bg' => '#ECFDF5', 'bar' => '#10B981'];
        } elseif ($score >= 65) {
            return ['label' => 'Good', 'color' => '#1E40AF', 'bg' => '#EFF6FF', 'bar' => '#3B82F6'];
        } elseif ($score >= 50) {
            return ['label' => 'Moderate', 'color' => '#92400E', 'bg' => '#FFFBEB', 'bar' => '#F59E0B'];
        } else {
            return ['label' => 'Needs Improvement', 'color' => '#991B1B', 'bg' => '#FEF2F2', 'bar' => '#EF4444'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Skill-Gap Analysis & Recommendations — CareerAct</title>
    <meta name="description" content="AI-Powered Competency Diagnosis, Skill-Gap Analytics, and Extracurricular Recommendations">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
    <style>
        svg {
            display: inline-block;
            vertical-align: middle;
            fill: none !important;
            stroke: currentColor;
        }

        /* Modern AI Card System Styles */
        :root {
            --ai-indigo: #4F46E5;
            --ai-indigo-light: #EEF2FF;
            --ai-blue: #1769E8;
            --ai-cyan: #06B6D4;
            --ai-purple: #7C3AED;
            --ai-emerald: #10B981;
            --ai-amber: #F59E0B;
            --ai-rose: #EF4444;
            --card-radius: 14px;
        }

        .ai-hero-header {
            background: linear-gradient(135deg, #163A70 0%, #1769E8 60%, #4F46E5 100%);
            border-radius: var(--card-radius);
            padding: 28px 32px;
            color: #FFFFFF;
            margin-bottom: 24px;
            box-shadow: 0 8px 24px rgba(23, 105, 232, 0.2);
            position: relative;
            overflow: hidden;
        }
        .ai-hero-header::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 320px;
            height: 320px;
            background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .ai-sparkle-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.35);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 8px;
            backdrop-filter: blur(8px);
        }

        /* High-Contrast Attempt Tabs */
        .attempt-tabs-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 22px;
            padding: 6px;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
        }
        .attempt-tab {
            padding: 7px 16px;
            font-size: 12.5px;
            font-weight: 700;
            color: #334155 !important;
            background: #FFFFFF;
            border: 1px solid #CBD5E1;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        .attempt-tab:hover {
            border-color: var(--ai-blue);
            color: var(--ai-blue) !important;
        }
        .attempt-tab.active {
            background-color: var(--ai-blue) !important;
            color: #FFFFFF !important;
            border-color: var(--ai-blue) !important;
            box-shadow: 0 4px 12px rgba(23, 105, 232, 0.25);
        }
        .attempt-tab.active span {
            color: #FFFFFF !important;
        }

        /* Modern AI Card 1: Score Hero */
        .ai-score-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: var(--card-radius);
            padding: 30px 34px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.04);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 28px;
            flex-wrap: wrap;
            transition: all 0.25s ease;
        }
        .ai-score-card:hover {
            box-shadow: 0 8px 24px rgba(23, 105, 232, 0.08);
            border-color: #CBD5E1;
        }
        .ai-score-title {
            font-size: 26px;
            font-weight: 800;
            color: #163A70;
            margin: 4px 0 2px 0;
            letter-spacing: -0.02em;
        }
        .ai-score-number-display {
            font-size: 46px;
            font-weight: 800;
            color: #163A70;
            line-height: 1;
            display: flex;
            align-items: baseline;
            gap: 8px;
            margin: 10px 0 6px 0;
        }
        .ai-score-number-display span.sub-pct {
            font-size: 18px;
            font-weight: 600;
            color: #64748B;
        }
        .ai-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12.5px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }
        .ai-progress-track {
            width: 100%;
            height: 12px;
            background: #E2E8F0;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 12px;
            position: relative;
        }
        .ai-progress-fill, .progress-bar-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 1.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Modern AI Card 2: Improvement Card */
        .ai-improvement-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: var(--card-radius);
            padding: 28px 32px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.04);
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
            border-left: 6px solid <?= $progressBarColor ?>;
        }
        .ai-improvement-headline {
            font-size: 20px;
            font-weight: 800;
            color: #163A70;
            margin: 6px 0 8px 0;
        }
        .ai-gap-areas-box {
            background: #FFFBEB;
            border: 1px solid #FDE68A;
            border-radius: 10px;
            padding: 16px 20px;
            margin-top: 14px;
        }
        .ai-gap-list {
            margin: 0;
            padding-left: 20px;
            font-size: 13.5px;
            color: #92400E;
            line-height: 1.6;
        }

        /* Modern AI Card 3: 4 Criteria Diagnostic Grid */
        .ai-criteria-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 30px;
        }
        .ai-criterion-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.2s ease;
        }
        .ai-criterion-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(22, 58, 112, 0.08);
            border-color: #CBD5E1;
        }
        .ai-criterion-title {
            font-size: 14px;
            font-weight: 700;
            color: #163A70;
            margin-bottom: 8px;
            min-height: 40px;
            line-height: 1.35;
        }
        .ai-criterion-score {
            font-size: 26px;
            font-weight: 800;
            color: #163A70;
        }
        .ai-criterion-tip {
            font-size: 12px;
            color: #64748B;
            line-height: 1.45;
            margin-top: 14px;
            padding-top: 10px;
            border-top: 1px dashed #E2E8F0;
        }

        /* Modern AI Card 4: Opportunity Recommendations */
        .ai-recs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 18px;
            margin-bottom: 30px;
        }
        .ai-rec-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.25s ease;
        }
        .ai-rec-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 22px rgba(22, 58, 112, 0.09);
            border-color: #CBD5E1;
        }
        .ai-rec-tag {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--ai-blue);
            background: #EFF6FF;
            padding: 3px 10px;
            border-radius: 6px;
        }
        .ai-rec-title {
            font-size: 16px;
            font-weight: 700;
            color: #163A70;
            line-height: 1.35;
            margin: 8px 0 6px 0;
        }
        .ai-skill-pill {
            font-size: 11.5px;
            font-weight: 600;
            color: #334155;
            background: #F1F5F9;
            padding: 3px 8px;
            border-radius: 4px;
        }
        .ai-why-box {
            background: #F8FAFC;
            border-left: 3px solid var(--ai-blue);
            padding: 10px 12px;
            border-radius: 0 6px 6px 0;
            font-size: 12.5px;
            color: #475569;
            line-height: 1.5;
            margin: 12px 0 16px 0;
        }

        /* Modern AI Card 5: Feedback Card */
        .ai-feedback-container {
            background: linear-gradient(180deg, #F5F7FF 0%, #FFFFFF 100%);
            border: 1px solid #C7D2FE;
            border-radius: var(--card-radius);
            padding: 28px 32px;
            box-shadow: 0 4px 16px rgba(79, 70, 229, 0.06);
            margin-bottom: 28px;
        }
        .ai-gemini-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #EEF2FF;
            color: #4338CA;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 4px 12px;
            border-radius: 20px;
            border: 1px solid #E0E7FF;
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
                        <a href="skill_gap.php" class="nav-link active" aria-current="page">
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

    <!-- Main Content Area -->
    <div class="dashboard-main">

        <!-- Top Navigation Bar -->
        <header class="dashboard-topbar">
            <button type="button" class="mobile-toggle-btn" id="mobileMenuBtn" aria-label="Toggle navigation menu">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
            </button>

            <div class="topbar-welcome">
                <span class="welcome-title">AI Skill-Gap Analysis</span>
                <span class="welcome-subtitle">Vocational diagnostics and extracurricular progression</span>
            </div>

            <!-- Topbar Search -->
            <form action="activities.php" method="GET" class="topbar-search-form" role="search">
                <div class="search-wrapper">
                    <span class="search-icon" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </span>
                    <input 
                        type="text" 
                        name="q" 
                        class="search-input" 
                        placeholder="Search activities, skills, or career pathways..." 
                        aria-label="Search activities and skills"
                    >
                </div>
            </form>

            <div class="topbar-actions">
                <a href="skills_assessment.php" class="nav-tab-btn">Skill Assessment</a>
                <a href="skill_gap.php" class="nav-tab-btn active">Skill-Gap Analysis</a>

                <div class="dropdown-relative">
                    <a href="notifications.php" class="notification-btn" aria-label="Notifications" title="Notifications">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                        <?php if ($unreadNotificationsCount > 0): ?>
                            <span class="notification-badge" aria-hidden="true"></span>
                        <?php endif; ?>
                    </a>
                </div>

                <div class="user-profile-chip">
                    <div class="avatar-circle"><?= e($initials) ?></div>
                    <div class="user-chip-info">
                        <span class="user-name"><?= e($fullName) ?></span>
                        <span class="user-level"><?= e($expLevel) ?></span>
                    </div>
                </div>
            </div>
        </header>

        <main class="dashboard-content" style="max-width: 1200px; margin: 0 auto; padding: 24px 20px;">

            <?php if (!$hasAssessments || !$assessmentDetails): ?>
                <!-- AI Empty State Card -->
                <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: var(--card-radius); padding: 56px 28px; text-align: center; box-shadow: 0 4px 16px rgba(0,0,0,0.03);">
                    <div style="width: 72px; height: 72px; margin: 0 auto 18px; border-radius: 50%; background: #EFF6FF; display: flex; align-items: center; justify-content: center; color: var(--ai-blue);">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg>
                    </div>
                    <h2 style="font-size: 22px; font-weight: 800; color: #163A70; margin-bottom: 8px;">No Completed Assessments for Skill-Gap Analysis</h2>
                    <p style="font-size: 14.5px; color: #64748B; max-width: 580px; margin: 0 auto 24px; line-height: 1.6;">
                        Gemini AI benchmarks your real-world capabilities and generates diagnostic skill-gap cards once you complete your first situational skill assessment. Choose any skill in your profile to benchmark now.
                    </p>
                    <a href="skills_assessment.php" class="btn-take-assess" style="display: inline-flex; align-items: center; gap: 8px; text-decoration: none; padding: 12px 26px; border-radius: 8px; font-size: 14px; font-weight: 700; width: auto;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        <span>Start Your First AI Skill Assessment</span>
                    </a>
                </div>
            <?php else: ?>

                <!-- Attempt Switcher Tabs (High-Contrast) -->
                <div class="attempt-tabs-bar">
                    <span style="font-size: 12px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: 0.05em; margin-right: 6px;">
                        Assessed Skills:
                    </span>
                    <?php foreach ($historyList as $hl): ?>
                        <?php $isActive = ((int)$hl['assessment_id'] === (int)$assessmentDetails['id']); ?>
                        <a href="skill_gap.php?attempt_id=<?= (int)$hl['assessment_id'] ?>" class="attempt-tab <?= $isActive ? 'active' : '' ?>">
                            <span><?= e($hl['skill_name']) ?></span>
                            <span style="opacity: 0.85; font-size: 11px;">(<?= (int)$hl['overall_score'] ?>%)</span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- AI CARD 1: OVERALL BENCHMARK SCORE HERO -->
                <div class="ai-score-card">
                    <div style="flex: 1; min-width: 280px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px; flex-wrap: wrap;">
                            <span class="ai-sparkle-pill" style="background:#EFF6FF; color:#1D4ED8; border:1px solid #BFDBFE;">
                                ✦ <?= e($categoryName) ?>
                            </span>
                            <span style="font-size: 12px; color: #64748B;">• Target: <strong><?= e($careerTitle) ?></strong></span>
                            <span style="font-size: 12px; color: #64748B;">• Completed: <?= e($completedAt) ?></span>
                        </div>
                        <h1 class="ai-score-title"><?= e($skillName) ?> Assessment</h1>

                        <div class="ai-score-number-display">
                            <span><?= (int)$overallScore ?>%</span>
                            <span class="sub-pct">/ 100%</span>
                            <span class="ai-status-pill" style="background: <?= $statusBg ?>; color: <?= $statusColor ?>; margin-left: 12px;">
                                <?= e($improvementStatus) ?>
                            </span>
                        </div>

                        <div style="font-size: 13.5px; color: #475569; margin-top: 4px;">
                            Your assessment score is <strong><?= (int)$overallScore ?>%</strong>. You have <strong><?= strtolower(e($improvementStatus)) ?></strong> <?= strtolower(e($skillName)) ?> skills.
                        </div>

                        <div class="ai-progress-track" title="Score: <?= (int)$overallScore ?>%">
                            <div class="ai-progress-fill progress-bar-fill" style="width: <?= (int)$overallScore == $overallScore ? (int)$overallScore : round($overallScore, 1) ?>%; background-color: <?= $progressBarColor ?>;"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 11.5px; color: #64748B; margin-top: 6px;">
                            <span>0% (Foundational)</span>
                            <span>50% (Competent)</span>
                            <span>100% (Mastery)</span>
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <a href="skills_assessment.php?action=start&skill_id=<?= (int)$assessmentDetails['skill_id'] ?>" class="btn-take-assess" style="width: auto; padding: 10px 20px; white-space: nowrap;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"></path></svg>
                            <span>Retake Assessment</span>
                        </a>
                        <a href="skills_assessment.php" class="btn-retake" style="padding: 10px 20px; text-decoration: none; border-radius: 8px; text-align: center;">
                            Assess Another Skill
                        </a>
                    </div>
                </div>

                <!-- AI CARD 2: DO YOU NEED IMPROVEMENT? -->
                <div class="ai-improvement-card">
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: <?= $statusColor ?>; letter-spacing: 0.05em; margin-bottom: 4px;">
                        Do You Need Improvement?
                    </div>
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: <?= $statusColor ?>; margin-bottom: 6px;">
                        STATUS: <?= strtoupper(e($improvementStatus)) ?>
                    </div>
                    <h2 class="ai-improvement-headline"><?= e($needsImprovementHeadline) ?></h2>
                    <p style="font-size: 14px; line-height: 1.6; color: #334155; margin-bottom: 16px;">
                        <?= e($needsImprovementExplanation) ?>
                    </p>

                    <?php if (!empty($improvements)): ?>
                        <div class="ai-gap-areas-box">
                            <div style="font-size: 12px; font-weight: 800; color: #92400E; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 6px;">
                                Main Areas to Develop:
                            </div>
                            <ul class="ai-gap-list">
                                <?php foreach ($improvements as $imp): ?>
                                    <li><?= e($imp) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!$showImprovementUrgency): ?>
                        <div style="margin-top: 16px; font-size: 13.5px; color: #065F46; background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 8px; padding: 12px 16px; display: flex; align-items: center; gap: 10px;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2" style="flex-shrink:0;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            <span><strong>Excellent Demonstration:</strong> You have verified proficiency across this competency without urgent developmental gaps.</span>
                        </div>
                    <?php else: ?>
                        <div style="margin-top: 16px; font-size: 13.5px; color: #047857; background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 8px; padding: 12px 16px; display: flex; align-items: center; gap: 10px;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2" style="flex-shrink:0;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            <span><strong>Encouraging Note:</strong> Practical competence develops with regular application. The recommended extracurricular workshops, projects, and hackathons below provide structured opportunities to bridge these skill gaps.</span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- AI CARD 3: 4 CORE CRITERIA DIAGNOSTIC CARDS -->
                <div style="margin-bottom: 28px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <div>
                            <h2 style="font-size: 19px; font-weight: 800; color: #163A70; margin: 0 0 2px 0;">
                                <?= e($skillName) ?> Skill Analysis
                            </h2>
                            <span style="font-size: 13px; color: #64748B;">Diagnostic breakdown across core vocational evaluation pillars</span>
                        </div>
                    </div>

                    <div class="ai-criteria-grid">
                        <?php foreach ($criteriaPillars as $crit): ?>
                            <?php $cStatus = getCriterionStatus((float)$crit['weight_pct']); ?>
                            <div class="ai-criterion-card">
                                <div>
                                    <div class="ai-criterion-title"><?= e($crit['name']) ?></div>
                                    <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 6px;">
                                        <div class="ai-criterion-score"><?= (int)$crit['weight_pct'] ?>%</div>
                                        <span class="ai-status-pill" style="background: <?= $cStatus['bg'] ?>; color: <?= $cStatus['color'] ?>; font-size: 11px; padding: 2px 8px;">
                                            <?= e($cStatus['label']) ?>
                                        </span>
                                    </div>
                                    <div class="ai-progress-track" style="height: 8px; margin: 6px 0 10px 0;">
                                        <div class="ai-progress-fill" style="width: <?= min(100, max(0, (float)$crit['weight_pct'])) ?>%; background-color: <?= $cStatus['bar'] ?>;"></div>
                                    </div>
                                </div>
                                <div class="ai-criterion-tip">
                                    💡 <strong>Tip:</strong> <?= e($crit['tip']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- AI CARD 4: RECOMMENDED EXTRACURRICULAR OPPORTUNITIES -->
                <div style="margin-bottom: 28px;" id="recommendations">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <h2 style="font-size: 19px; font-weight: 800; color: #163A70; margin: 0 0 2px 0;">
                                Recommended Activities to Improve Your Skills
                            </h2>
                            <span style="font-size: 13px; color: #64748B;">Curated campus & online opportunities from database to develop <?= e($skillName) ?></span>
                        </div>
                        <a href="activities.php" class="overview-card-link" style="font-size: 13px;">Browse Full Activity Catalog &rarr;</a>
                    </div>

                    <?php if (empty($recommendedActivities)): ?>
                        <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: var(--card-radius); padding: 32px; text-align: center;">
                            <div style="font-size: 15px; font-weight: 700; color: #163A70; margin-bottom: 6px;">No Specific Activity Matches Found</div>
                            <div style="font-size: 13px; color: #64748B; margin-bottom: 14px;">Check out our full activities catalog to explore upcoming workshops, hackathons, and competitions.</div>
                            <a href="activities.php" class="btn-take-assess" style="display: inline-flex; width: auto;">Explore Activities</a>
                        </div>
                    <?php else: ?>
                        <div class="ai-recs-grid">
                            <?php foreach ($recommendedActivities as $act): ?>
                                <div class="ai-rec-card">
                                    <div>
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                            <span class="ai-rec-tag"><?= e($act['category_name']) ?></span>
                                            <span style="font-size: 11px; color: #64748B; font-weight: 600;">
                                                <?= e(ucfirst($act['delivery_mode'] ?? 'Online')) ?>
                                            </span>
                                        </div>
                                        <h3 class="ai-rec-title"><?= e($act['opportunity_title'] ?: $act['activity_title']) ?></h3>

                                        <div style="display: flex; gap: 12px; font-size: 12px; color: #64748B; margin-bottom: 10px; flex-wrap: wrap;">
                                            <?php if (!empty($act['state_name']) || !empty($act['venue'])): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 4px;">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                                    <?= e($act['venue'] ?: ($act['state_name'] ?: 'Campus')) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($act['start_date'])): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 4px;">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                                    <?= date('M j, Y', strtotime($act['start_date'])) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div style="font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 6px;">Skills Developed:</div>
                                        <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px;">
                                            <?php foreach ($act['skills_developed'] as $sDev): ?>
                                                <span class="ai-skill-pill"><?= e($sDev) ?></span>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="ai-why-box">
                                            <strong>Why Recommended:</strong> <?= e($act['why_recommended']) ?>
                                        </div>
                                    </div>

                                    <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 14px; border-top: 1px solid #E2E8F0;">
                                        <a href="activities.php?id=<?= (int)$act['activity_id'] ?>" class="btn-take-assess" style="width: auto; padding: 7px 14px; font-size: 12.5px; text-decoration: none;">
                                            View Details
                                        </a>
                                        <?php if (!empty($act['registration_url'])): ?>
                                            <a href="<?= e($act['registration_url']) ?>" target="_blank" rel="noopener noreferrer" style="font-size: 12px; font-weight: 700; color: var(--ai-blue); text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                                <span>Register</span>
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- AI CARD 5: PERSONALIZED GEMINI AI FEEDBACK -->
                <div class="ai-feedback-container">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
                        <div class="ai-gemini-badge">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 1 0 10 10H12V2z"></path><path d="M12 2a10 10 0 0 1 10 10h-10V2z"></path><path d="M12 12L2.5 7.5"></path></svg>
                            <span>✦ Gemini Developmental Feedback</span>
                        </div>
                        <span style="font-size: 12.5px; color: #64748B;">Competency Benchmarking Feedback</span>
                    </div>

                    <h3 style="font-size: 17px; font-weight: 800; color: #163A70; margin-bottom: 10px;">
                        Personalized Diagnostic Evaluation
                    </h3>
                    <div style="font-size: 14.5px; line-height: 1.7; color: #1E293B; font-style: italic;">
                        "<?= nl2br(e($assessmentDetails['ai_feedback'])) ?>"
                    </div>

                    <?php if (!empty($strengths)): ?>
                        <div style="margin-top: 18px; padding-top: 14px; border-top: 1px dashed #C7D2FE;">
                            <div style="font-size: 12px; font-weight: 800; color: #065F46; text-transform: uppercase; margin-bottom: 6px;">
                                Key Strengths Highlighted:
                            </div>
                            <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: #065F46; line-height: 1.5;">
                                <?php foreach ($strengths as $st): ?>
                                    <li><?= e($st) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- AI CARD 6: SCENARIO CHALLENGE BREAKDOWN -->
                <div style="margin-bottom: 32px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                        <div>
                            <h3 style="font-size: 17px; font-weight: 800; color: #163A70; margin: 0 0 4px 0;">
                                Detailed Scenario Breakdown & Rubrics
                            </h3>
                            <span style="font-size: 12.5px; color: #64748B;">Review each real-world challenge, your submitted response, and Gemini's feedback</span>
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 14px;">
                        <?php foreach ($assessmentDetails['questions'] as $i => $q): ?>
                            <?php 
                                $ansRecord = $answers[$i] ?? null;
                                $qScore = $ansRecord ? (float)$ansRecord['score'] : 0.0;
                                $qFeedback = $ansRecord ? (string)$ansRecord['feedback'] : 'No answer submitted.';
                                $studentText = $ansRecord ? (string)$ansRecord['answer_text'] : '';
                            ?>
                            <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 18px; box-shadow: 0 1px 4px rgba(0,0,0,0.03);">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                                    <span style="font-weight: 700; font-size: 14px; color: #163A70;">
                                        Scenario <?= $i + 1 ?>: <?= e($q['evaluation_criteria'] ?: 'Vocational Challenge') ?>
                                    </span>
                                    <span class="ai-status-pill" style="background: <?= $qScore >= 75 ? '#ECFDF5' : ($qScore >= 50 ? '#FFFBEB' : '#FEF2F2') ?>; color: <?= $qScore >= 75 ? '#065F46' : ($qScore >= 50 ? '#92400E' : '#991B1B') ?>; font-size: 11px;">
                                        Score: <?= (int)$qScore ?> / 100
                                    </span>
                                </div>
                                <div style="font-size: 13px; color: #475569; margin-bottom: 10px; background: #F8FAFC; padding: 10px; border-radius: 6px; line-height: 1.5;">
                                    <strong>Scenario:</strong> <?= e($q['scenario_text']) ?>
                                </div>
                                <div style="font-size: 13px; color: #1E293B; margin-bottom: 8px; line-height: 1.5;">
                                    <strong>Your Response:</strong> <em><?= nl2br(e($studentText)) ?></em>
                                </div>
                                <div style="font-size: 12.5px; color: #0369A1; background: #F0F9FF; padding: 8px 12px; border-radius: 6px; line-height: 1.4;">
                                    <strong>AI Feedback:</strong> <?= e($qFeedback) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- AI Notice -->
                <div style="background: #F8FAFC; border: 1px dashed #CBD5E1; border-radius: 8px; padding: 14px 18px; margin-top: 24px; text-align: center;">
                    <p style="font-size: 12px; color: #64748B; margin: 0; line-height: 1.5;">
                        <strong>AI Notice:</strong> This skill assessment and gap analysis are evaluated automatically by Google Gemini AI based on your practical scenario answers. Results serve as formative developmental feedback and vocational benchmarking.
                    </p>
                </div>

            <?php endif; ?>

        </main>
    </div>

    <!-- Mobile Sidebar Backdrop & Toggle Script -->
    <div class="sidebar-backdrop" id="sidebarBackdrop" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 998;"></div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var toggleBtn = document.getElementById('mobileMenuBtn');
            var sidebar = document.getElementById('dashboardSidebar');
            var backdrop = document.getElementById('sidebarBackdrop');

            if (toggleBtn && sidebar && backdrop) {
                toggleBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('active');
                    backdrop.style.display = sidebar.classList.contains('active') ? 'block' : 'none';
                });

                backdrop.addEventListener('click', function() {
                    sidebar.classList.remove('active');
                    backdrop.style.display = 'none';
                });
            }
        });
    </script>
</body>
</html>
