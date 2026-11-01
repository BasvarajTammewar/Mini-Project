<?php
/**
 * CareerAct — AI-Powered Real-World Skill Assessment Module
 * 
 * Generates dynamic, practical workplace scenarios via Google Gemini API,
 * evaluates student responses against multi-dimensional rubrics (0-100),
 * benchmarks competencies, and recommends real extracurricular activities.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/gemini_service.php';
require_once __DIR__ . '/includes/assessment_service.php';

requireCompletedProfile('onboarding.php?msg=complete_profile');

$userId = getCurrentUserId();
$db = getDbConnection();
$gemini = new GeminiService();
$assessService = new AssessmentService($db);

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

// Active career goal
$stmtGoal = $db->prepare("
    SELECT cg.id AS career_id, cg.title AS career_title, COALESCE(cf.name, 'General') AS field_name
    FROM student_career_goals scg
    JOIN careers cg ON scg.career_id = cg.id
    LEFT JOIN career_subfields csf ON cg.subfield_id = csf.id
    LEFT JOIN career_fields cf ON csf.field_id = cf.id
    WHERE scg.user_id = :user_id AND scg.is_primary = 1
    LIMIT 1
");
$stmtGoal->execute([':user_id' => $userId]);
$activeGoal = $stmtGoal->fetch();

// Determine Action View
$action = $_GET['action'] ?? 'dashboard';
$assessmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$skillIdParam = isset($_GET['skill_id']) ? (int)$_GET['skill_id'] : 0;

$alertMessage = '';
$alertType = 'info';

// =========================================================================
// ACTION: START NEW ASSESSMENT (GENERATE SCENARIOS VIA GEMINI)
// =========================================================================
if ($action === 'start' && $skillIdParam > 0) {
    // Verify skill exists in database
    $stmtSk = $db->prepare("
        SELECT s.id, s.name, COALESCE(sc.name, 'General') AS category_name, s.description
        FROM skills s
        LEFT JOIN skill_categories sc ON s.category_id = sc.id
        WHERE s.id = :id AND s.is_active = 1
    ");
    $stmtSk->execute([':id' => $skillIdParam]);
    $skillData = $stmtSk->fetch();

    if (!$skillData) {
        header("Location: skills_assessment.php?msg=skill_not_found");
        exit;
    }

    try {
        // Request Gemini API to generate 5 scenario-based questions
        $context = [
            'career_title' => $activeGoal['career_title'] ?? '',
            'experience'   => $student['experience_level'] ?? 'beginner'
        ];
        $genResult = $gemini->generateScenarioQuestions($skillData['name'], $skillData['category_name'], $context);
        $questions = $genResult['questions'];

        // Persist session to database
        $newAssessmentId = $assessService->createAssessmentSession($userId, $skillIdParam, $questions);

        // Redirect to taking view
        header("Location: skills_assessment.php?action=take&id=" . $newAssessmentId);
        exit;
    } catch (Exception $e) {
        $alertMessage = 'Failed to generate assessment questions: ' . htmlspecialchars($e->getMessage());
        $alertType = 'danger';
        $action = 'dashboard';
    }
}

// =========================================================================
// ACTION: SUBMIT ASSESSMENT ANSWERS (EVALUATION VIA GEMINI)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assessment_answers'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid security token. Please refresh and try again.');
    }

    $postAssessmentId = (int)($_POST['assessment_id'] ?? 0);
    $answers = (array)($_POST['answers'] ?? []);

    $session = $assessService->getAssessmentSession($postAssessmentId, $userId);
    if (!$session || $session['status'] === 'completed') {
        header("Location: skills_assessment.php?msg=invalid_session");
        exit;
    }

    // Format questions and student answers for Gemini evaluation
    $qaList = [];
    foreach ($session['questions'] as $q) {
        $idx = (int)$q['question_index'];
        $userAns = trim((string)($answers[$idx] ?? ''));
        $qaList[] = [
            'question' => $q['scenario_text'],
            'answer'   => $userAns,
            'focus'    => $q['evaluation_criteria'] ?? 'Applied reasoning'
        ];
    }

    try {
        // Evaluate via Gemini Answer Evaluation Engine
        $evalResult = $gemini->evaluateStudentAnswers($session['skill_name'], $session['category_name'], $qaList);

        // Finalize and save results transactionally
        $assessService->finalizeAssessmentEvaluation($postAssessmentId, $userId, $answers, $evalResult);

        header("Location: skills_assessment.php?action=result&id=" . $postAssessmentId . "&msg=eval_completed");
        exit;
    } catch (Exception $e) {
        $alertMessage = 'Evaluation service error: ' . htmlspecialchars($e->getMessage()) . '. Your answers are preserved below; please click Submit again to retry.';
        $alertType = 'danger';
        $action = 'take';
        $assessmentId = $postAssessmentId;
    }
}

// Prepare view data
$assessmentDetails = null;
$recommendedActivities = [];
if (($action === 'take' || $action === 'result') && $assessmentId > 0) {
    $assessmentDetails = $assessService->getAssessmentSession($assessmentId, $userId);
    if (!$assessmentDetails) {
        $action = 'dashboard';
    } elseif ($action === 'result') {
        $recommendedActivities = $assessService->getRecommendedActivitiesForSkill(
            (int)$assessmentDetails['skill_id'],
            $userId,
            4
        );
    }
}

$historyList = $assessService->getAssessmentHistory($userId);
$assessableSkillsData = $assessService->getStudentAssessableSkills($userId);
$skillsSummary = $assessableSkillsData['summary'];
$categorizedSkills = $assessableSkillsData['categorized'];

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Skill Assessment — CareerAct</title>
    <meta name="description" content="AI-Powered Real-World Scenario Skill Assessment and Competency Benchmarking">
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

        /* Assessment Specific Styling */
        .assess-header-card {
            background: linear-gradient(135deg, #163A70 0%, #1769E8 100%);
            color: #FFFFFF;
            border-radius: 12px;
            padding: 24px 28px;
            margin-bottom: 24px;
            box-shadow: 0 8px 24px rgba(23, 105, 232, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .assess-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-mini-card {
            background: #FFFFFF;
            border: 1px solid #E4EAF2;
            border-radius: 10px;
            padding: 18px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.03);
        }
        .stat-mini-val {
            font-size: 26px;
            font-weight: 800;
            color: #163A70;
            margin-top: 4px;
        }
        .stat-mini-label {
            font-size: 12px;
            font-weight: 600;
            color: #667085;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .skill-catalog-cluster {
            margin-bottom: 28px;
        }
        .cluster-heading {
            font-size: 16px;
            font-weight: 800;
            color: #163A70;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 2px solid #EDF2F7;
        }
        .skill-catalog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
        }
        .skill-card {
            background: #FFFFFF;
            border: 1px solid #E4EAF2;
            border-radius: 10px;
            padding: 18px;
            transition: transform 0.15s, box-shadow 0.15s;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .skill-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(22, 58, 112, 0.08);
            border-color: #CBD5E1;
        }
        .skill-card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        .skill-card-name {
            font-size: 15px;
            font-weight: 700;
            color: #163A70;
        }
        .skill-card-category {
            font-size: 11.5px;
            color: #667085;
            margin-bottom: 12px;
        }
        .skill-card-badges {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }
        .btn-take-assess {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            width: 100%;
            padding: 9px 14px;
            background: #1769E8;
            color: #FFFFFF;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s;
        }
        .btn-take-assess:hover {
            background: #1355BD;
        }
        .btn-retake {
            background: #F8FAFD;
            color: #163A70;
            border: 1px solid #CBD5E1;
        }
        .btn-retake:hover {
            background: #EDF2F7;
        }

        /* Multi-step Question Interface */
        .scenario-box {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-left: 4px solid #1769E8;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .scenario-title {
            font-size: 13px;
            font-weight: 700;
            color: #1769E8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .scenario-text {
            font-size: 15.5px;
            font-weight: 500;
            color: #1E293B;
            line-height: 1.6;
        }
        .answer-textarea {
            width: 100%;
            min-height: 160px;
            padding: 14px;
            border: 1px solid #CBD5E1;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            color: #1E293B;
            line-height: 1.5;
            resize: vertical;
            box-sizing: border-box;
            outline: none;
        }
        .answer-textarea:focus {
            border-color: #1769E8;
            box-shadow: 0 0 0 3px rgba(23, 105, 232, 0.15);
        }
        .char-counter {
            font-size: 11.5px;
            color: #64748B;
            text-align: right;
            margin-top: 4px;
        }

        /* Step Progress */
        .step-pill-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
        }
        .step-pill {
            flex: 1;
            height: 6px;
            background: #E2E8F0;
            border-radius: 3px;
            transition: background 0.3s;
        }
        .step-pill.active {
            background: #1769E8;
        }
        .step-pill.filled {
            background: #10B981;
        }

        /* Score Gauge */
        .score-hero-container {
            display: flex;
            align-items: center;
            gap: 24px;
            background: #F8FAFD;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .score-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            box-shadow: 0 4px 14px rgba(0,0,0,0.06);
            flex-shrink: 0;
        }
        .score-circle.green { background: #EDFDF5; color: #059669; border: 3px solid #10B981; }
        .score-circle.amber { background: #FFFBEB; color: #D97706; border: 3px solid #F59E0B; }
        .score-circle.red   { background: #FEF2F2; color: #DC2626; border: 3px solid #EF4444; }

        .rubric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 14px;
        }
        .rubric-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            padding: 12px 14px;
        }

        /* Modal Overlay */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-content {
            background: #FFFFFF;
            border-radius: 12px;
            max-width: 650px;
            width: 100%;
            max-height: 85vh;
            overflow-y: auto;
            padding: 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
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
                    <li><a href="dashboard.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect></svg><span>Dashboard</span></a></li>
                    <li><a href="profile.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><span>My Profile</span></a></li>
                    <li><a href="career_goals.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg><span>Career Exploration</span></a></li>
                    <li><a href="my_skills.php" class="nav-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg><span>My Skills</span></a></li>
                    <li><a href="skills_assessment.php" class="nav-link active" aria-current="page"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg><span>Skill Assessment</span></a></li>
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
                    <span class="greeting-text">AI Skill Assessment</span>
                    <span class="greeting-subtext">Real-world situational competency evaluation via Google Gemini</span>
                </div>
            </div>

            <div class="topbar-actions">
                <a href="skills_assessment.php" class="nav-tab-btn <?= $action === 'dashboard' ? 'active' : '' ?>">Catalog</a>
                <a href="skills_assessment.php?action=history" class="nav-tab-btn <?= $action === 'history' ? 'active' : '' ?>">History (<?= count($historyList) ?>)</a>
                <div class="avatar-circle"><?= e($initials) ?></div>
            </div>
        </header>

        <main class="dashboard-content" style="max-width: 1200px; margin: 0 auto;">

            <?php if (!empty($alertMessage)): ?>
                <div style="padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 13.5px; background: <?= $alertType === 'danger' ? '#FEF2F2; color: #991B1B; border: 1px solid #F87171;' : '#EDFDF5; color: #065F46; border: 1px solid #6EE7B7;' ?>">
                    <?= e($alertMessage) ?>
                </div>
            <?php endif; ?>

            <!-- =================================================================
                 VIEW 1: ASSESSMENT DASHBOARD & SKILL CATALOG
                 ================================================================= -->
            <?php if ($action === 'dashboard'): ?>

                <div class="assess-header-card">
                    <div>
                        <div style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; opacity: 0.85; margin-bottom: 4px;">
                            CareerAct Competency Verification
                        </div>
                        <h1 style="font-size: 22px; font-weight: 800; margin: 0 0 8px 0;">Demonstrate Your Real-World Abilities</h1>
                        <p style="font-size: 13.5px; opacity: 0.9; max-width: 650px; margin: 0; line-height: 1.5;">
                            Rather than passive multiple-choice questions, Gemini presents authentic workplace & campus scenarios testing whether you can practically demonstrate the skills in your profile.
                        </p>
                    </div>
                    <?php if (!$gemini->isConfigured()): ?>
                        <div style="background: rgba(255, 255, 255, 0.15); border: 1px dashed rgba(255, 255, 255, 0.4); border-radius: 8px; padding: 10px 14px; font-size: 12px; max-width: 300px;">
                            <strong>Local / Fallback Mode:</strong> Add your <code>GEMINI_API_KEY</code> to <code>.env</code> or system environment for live dynamic Gemini generation.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Stats Overview -->
                <div class="assess-stats-grid">
                    <div class="stat-mini-card">
                        <div class="stat-mini-label">Skills Assessed</div>
                        <div class="stat-mini-val"><?= $skillsSummary['assessed_skills_count'] ?> / <?= $skillsSummary['student_skills_count'] ?></div>
                        <div style="font-size: 11.5px; color: #64748B; margin-top: 4px;">Profile Competencies</div>
                    </div>
                    <div class="stat-mini-card">
                        <div class="stat-mini-label">Average Score</div>
                        <div class="stat-mini-val" style="color: #1769E8;"><?= $skillsSummary['average_score'] > 0 ? $skillsSummary['average_score'] . '%' : 'N/A' ?></div>
                        <div style="font-size: 11.5px; color: #64748B; margin-top: 4px;">Across verified tests</div>
                    </div>
                    <div class="stat-mini-card">
                        <div class="stat-mini-label">Total Attempts</div>
                        <div class="stat-mini-val"><?= count($historyList) ?></div>
                        <div style="font-size: 11.5px; color: #64748B; margin-top: 4px;">Recorded in database</div>
                    </div>
                    <div class="stat-mini-card">
                        <div class="stat-mini-label">Target Career Goal</div>
                        <div class="stat-mini-val" style="font-size: 17px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?= e($skillsSummary['career_title'] ?: 'General Track') ?>
                        </div>
                        <div style="font-size: 11.5px; color: #64748B; margin-top: 4px;">Primary pathway</div>
                    </div>
                </div>

                <!-- Section: Soft Skills -->
                <div class="skill-catalog-cluster">
                    <div class="cluster-heading">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#1769E8" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        <span>Soft & Interpersonal Skills</span>
                        <span style="font-size: 12px; font-weight: 500; color: #64748B;">(Leadership, Communication, Teamwork, Problem-Solving)</span>
                    </div>
                    <div class="skill-catalog-grid">
                        <?php foreach ($categorizedSkills['soft'] as $sk): ?>
                            <?php include __DIR__ . '/includes/partials/skill_card_partial.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Section: Technical Skills -->
                <div class="skill-catalog-cluster">
                    <div class="cluster-heading">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0F9D8A" stroke-width="2"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>
                        <span>Technical & Engineering Skills</span>
                        <span style="font-size: 12px; font-weight: 500; color: #64748B;">(Programming, Database, Troubleshooting, Web)</span>
                    </div>
                    <div class="skill-catalog-grid">
                        <?php foreach ($categorizedSkills['technical'] as $sk): ?>
                            <?php include __DIR__ . '/includes/partials/skill_card_partial.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Section: Professional & Other Skills -->
                <?php if (!empty($categorizedSkills['professional']) || !empty($categorizedSkills['other'])): ?>
                    <div class="skill-catalog-cluster">
                        <div class="cluster-heading">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#8A46EA" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                            <span>Professional & Career Competencies</span>
                            <span style="font-size: 12px; font-weight: 500; color: #64748B;">(Planning, Execution, Research, Governance)</span>
                        </div>
                        <div class="skill-catalog-grid">
                            <?php foreach (array_merge($categorizedSkills['professional'], $categorizedSkills['other']) as $sk): ?>
                                <?php include __DIR__ . '/includes/partials/skill_card_partial.php'; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <!-- =================================================================
                 VIEW 2: MULTI-STEP SCENARIO ASSESSMENT INTERFACE
                 ================================================================= -->
            <?php elseif ($action === 'take' && $assessmentDetails): ?>

                <div class="dashboard-section" style="max-width: 860px; margin: 0 auto;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <div style="font-size: 11.5px; font-weight: 700; color: #1769E8; text-transform: uppercase;">
                                Skill Under Assessment: <?= e($assessmentDetails['category_name']) ?>
                            </div>
                            <h2 style="font-size: 20px; font-weight: 800; color: #163A70; margin: 2px 0;">
                                <?= e($assessmentDetails['skill_name']) ?> Assessment
                            </h2>
                        </div>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <span class="badge-pill info">Attempt #<?= (int)$assessmentDetails['attempt_number'] ?></span>
                            <a href="skills_assessment.php" class="btn-retake" style="padding: 6px 12px; font-size: 12px; text-decoration: none; border-radius: 6px;">Exit</a>
                        </div>
                    </div>

                    <!-- Step Pill Indicators (5 questions) -->
                    <div class="step-pill-bar" id="stepPillBar">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <div class="step-pill <?= $i === 1 ? 'active' : '' ?>" id="pill-step-<?= $i ?>" title="Question <?= $i ?>"></div>
                        <?php endfor; ?>
                    </div>

                    <form id="assessmentForm" method="POST" action="skills_assessment.php">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="assessment_id" value="<?= (int)$assessmentDetails['id'] ?>">
                        <input type="hidden" name="submit_assessment_answers" value="1">

                        <!-- Question Slides -->
                        <?php foreach ($assessmentDetails['questions'] as $idx => $q): ?>
                            <?php $qNum = $idx + 1; ?>
                            <div class="question-slide" id="qSlide-<?= $qNum ?>" style="display: <?= $qNum === 1 ? 'block' : 'none' ?>;">
                                <div class="scenario-box">
                                    <div class="scenario-title">
                                        Scenario <?= $qNum ?> of 5 • Focus: <?= e($q['evaluation_criteria'] ?: 'Reasoning & Action') ?>
                                    </div>
                                    <div class="scenario-text">
                                        <?= nl2br(e($q['scenario_text'])) ?>
                                    </div>
                                </div>

                                <div style="margin-bottom: 20px;">
                                    <label for="ans_<?= $qNum ?>" style="display: block; font-size: 13.5px; font-weight: 700; color: #163A70; margin-bottom: 8px;">
                                        How would you handle this situation? Describe your specific steps, rationale, and follow-up:
                                    </label>
                                    <textarea 
                                        name="answers[<?= $qNum ?>]" 
                                        id="ans_<?= $qNum ?>" 
                                        class="answer-textarea" 
                                        placeholder="Explain the concrete actions you would take, who you would communicate with, and how you would prevent similar issues..."
                                        oninput="updateCharCount(<?= $qNum ?>)"
                                        required
                                    ><?= e($_POST['answers'][$qNum] ?? '') ?></textarea>
                                    <div class="char-counter" id="charCount-<?= $qNum ?>">0 words (minimum 15 recommended)</div>
                                </div>

                                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 24px; padding-top: 16px; border-top: 1px solid #E2E8F0;">
                                    <?php if ($qNum > 1): ?>
                                        <button type="button" class="btn-retake" onclick="goToQuestion(<?= $qNum - 1 ?>)" style="padding: 10px 20px; font-weight: 600; cursor: pointer;">
                                            &larr; Previous Question
                                        </button>
                                    <?php else: ?>
                                        <div></div>
                                    <?php endif; ?>

                                    <?php if ($qNum < 5): ?>
                                        <button type="button" class="btn-take-assess" onclick="goToQuestion(<?= $qNum + 1 ?>)" style="width: auto; padding: 10px 24px; font-weight: 600;">
                                            Next Question &rarr;
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn-take-assess" onclick="openReviewModal()" style="width: auto; padding: 10px 28px; background: #059669; font-weight: 700;">
                                            Review & Submit Assessment
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </form>
                </div>

                <!-- Review & Submit Confirmation Modal -->
                <div class="modal-overlay" id="reviewModal">
                    <div class="modal-content">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                            <h3 style="font-size: 18px; font-weight: 800; color: #163A70; margin: 0;">Review Your Answers</h3>
                            <button type="button" onclick="closeReviewModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748B;">&times;</button>
                        </div>
                        <p style="font-size: 13px; color: #64748B; margin-top: 0;">
                            Please verify your scenario responses before sending them to the Gemini AI evaluation engine.
                        </p>

                        <div id="reviewAnswersSummary" style="margin-bottom: 20px; display: flex; flex-direction: column; gap: 12px;"></div>

                        <div style="background: #F8FAFD; border: 1px solid #E2E8F0; border-radius: 8px; padding: 14px; font-size: 12.5px; color: #163A70; margin-bottom: 20px;">
                            <strong>AI Evaluation Notice:</strong> Your answers will be analyzed across Relevance, Critical Thinking, Strategy & Resolution. Once submitted, your score and verified skill level will be permanently recorded.
                        </div>

                        <div style="display: flex; justify-content: flex-end; gap: 10px;">
                            <button type="button" class="btn-retake" onclick="closeReviewModal()" style="padding: 10px 18px;">Back to Edit</button>
                            <button type="button" class="btn-take-assess" id="finalSubmitBtn" onclick="submitAssessmentForm()" style="width: auto; padding: 10px 24px; background: #059669;">
                                Confirm & Evaluate with Gemini
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Loading State Modal -->
                <div class="modal-overlay" id="loadingModal">
                    <div class="modal-content" style="text-align: center; padding: 40px 24px;">
                        <div style="width: 50px; height: 50px; border: 4px solid #E2E8F0; border-top-color: #1769E8; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 20px auto;"></div>
                        <h3 style="font-size: 18px; font-weight: 800; color: #163A70; margin: 0 0 8px 0;">Evaluating Your Competencies...</h3>
                        <p style="font-size: 13.5px; color: #64748B; margin: 0; line-height: 1.5;">
                            Google Gemini AI is scoring your responses against vocational rubrics, determining strengths, and matching extracurricular recommendations.
                        </p>
                    </div>
                </div>
                <style>@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>

            <!-- =================================================================
                 VIEW 3: ASSESSMENT RESULTS REPORT & RECOMMENDATIONS
                 ================================================================= -->
            <?php elseif ($action === 'result' && $assessmentDetails): ?>

                <?php
                    $overallScore = (float)$assessmentDetails['overall_score'];
                    $demLevel = (string)$assessmentDetails['demonstration_level'];
                    $strengths = json_decode((string)$assessmentDetails['strengths_json'], true) ?: [];
                    $improvements = json_decode((string)$assessmentDetails['improvements_json'], true) ?: [];
                    $rubrics = json_decode((string)$assessmentDetails['rubric_summary_json'], true) ?: [];
                    $answers = $assessmentDetails['answers'] ?? [];

                    $circleColor = 'green';
                    if ($overallScore < 50) {
                        $circleColor = 'red';
                    } elseif ($overallScore < 80) {
                        $circleColor = 'amber';
                    }
                ?>

                <div class="dashboard-section" style="max-width: 900px; margin: 0 auto;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <span class="badge-pill <?= $overallScore >= 80 ? 'success' : ($overallScore >= 50 ? 'warning' : 'danger') ?>">
                                <?= e($demLevel) ?>
                            </span>
                            <h1 style="font-size: 22px; font-weight: 800; color: #163A70; margin: 6px 0 2px 0;">
                                <?= e($assessmentDetails['skill_name']) ?> Assessment Report
                            </h1>
                            <span style="font-size: 12.5px; color: #64748B;">
                                Completed on <?= date('M j, Y • g:i A', strtotime($assessmentDetails['completed_at'] ?? 'now')) ?> • Attempt #<?= (int)$assessmentDetails['attempt_number'] ?>
                            </span>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <a href="skills_assessment.php?action=start&skill_id=<?= (int)$assessmentDetails['skill_id'] ?>" class="btn-take-assess" style="width: auto; padding: 8px 16px;">
                                Retake Assessment
                            </a>
                            <a href="skills_assessment.php" class="btn-retake" style="padding: 8px 16px; text-decoration: none; border-radius: 6px;">
                                All Skills
                            </a>
                        </div>
                    </div>

                    <!-- Score Hero Banner -->
                    <div class="score-hero-container">
                        <div class="score-circle <?= $circleColor ?>">
                            <span style="font-size: 28px;"><?= (int)$overallScore ?></span>
                            <span style="font-size: 11px; font-weight: 600; opacity: 0.8;">/ 100</span>
                        </div>
                        <div style="flex: 1;">
                            <div style="font-size: 16px; font-weight: 800; color: #163A70; margin-bottom: 6px;">
                                <?= e($demLevel) ?> (Score: <?= (int)$overallScore ?>/100)
                            </div>
                            <div style="font-size: 13.5px; color: #334155; line-height: 1.6;">
                                <?= nl2br(e($assessmentDetails['ai_feedback'])) ?>
                            </div>

                            <!-- Rubric Pillars -->
                            <?php if (!empty($rubrics)): ?>
                                <div class="rubric-grid">
                                    <div class="rubric-card">
                                        <div style="font-size: 11px; font-weight: 700; color: #64748B;">Practical Relevance</div>
                                        <div style="font-size: 16px; font-weight: 800; color: #163A70; margin-top: 2px;"><?= (float)($rubrics['practical_approach'] ?? 0) ?>/25</div>
                                    </div>
                                    <div class="rubric-card">
                                        <div style="font-size: 11px; font-weight: 700; color: #64748B;">Critical Thinking</div>
                                        <div style="font-size: 16px; font-weight: 800; color: #163A70; margin-top: 2px;"><?= (float)($rubrics['critical_thinking'] ?? 0) ?>/25</div>
                                    </div>
                                    <div class="rubric-card">
                                        <div style="font-size: 11px; font-weight: 700; color: #64748B;">Strategy & Diplomacy</div>
                                        <div style="font-size: 16px; font-weight: 800; color: #163A70; margin-top: 2px;"><?= (float)($rubrics['strategy_communication'] ?? 0) ?>/25</div>
                                    </div>
                                    <div class="rubric-card">
                                        <div style="font-size: 11px; font-weight: 700; color: #64748B;">Impact Resolution</div>
                                        <div style="font-size: 16px; font-weight: 800; color: #163A70; margin-top: 2px;"><?= (float)($rubrics['impact_resolution'] ?? 0) ?>/25</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Strengths & Improvement Areas Split -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 24px;">
                        <div style="background: #EDFDF5; border: 1px solid #A7F3D0; border-radius: 10px; padding: 18px;">
                            <div style="font-size: 13px; font-weight: 800; color: #065F46; text-transform: uppercase; margin-bottom: 8px;">
                                Key Strengths Demonstrated
                            </div>
                            <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: #065F46; line-height: 1.6;">
                                <?php foreach ($strengths as $st): ?>
                                    <li><?= e($st) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <div style="background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 10px; padding: 18px;">
                            <div style="font-size: 13px; font-weight: 800; color: #92400E; text-transform: uppercase; margin-bottom: 8px;">
                                Growth & Improvement Areas
                            </div>
                            <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: #92400E; line-height: 1.6;">
                                <?php foreach ($improvements as $im): ?>
                                    <li><?= e($im) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>

                    <!-- Curated Real Activity Recommendations from carrearact2 -->
                    <div class="dashboard-section" style="margin-top: 28px; background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                            <div>
                                <h3 style="font-size: 16px; font-weight: 800; color: #163A70; margin: 0;">
                                    Recommended Extracurricular Activities
                                </h3>
                                <div style="font-size: 12px; color: #64748B; margin-top: 2px;">
                                    Real campus and online opportunities from database to develop <?= e($assessmentDetails['skill_name']) ?>
                                </div>
                            </div>
                            <a href="activities.php" class="overview-card-link" style="font-size: 12.5px;">Browse All Catalog &rarr;</a>
                        </div>

                        <?php if (empty($recommendedActivities)): ?>
                            <div style="font-size: 13px; color: #64748B; padding: 16px; text-align: center;">
                                Explore our activities catalog to enroll in upcoming workshops and projects.
                            </div>
                        <?php else: ?>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px;">
                                <?php foreach ($recommendedActivities as $act): ?>
                                    <div style="border: 1px solid #E4EAF2; border-radius: 8px; padding: 14px; background: #FAFCFF; display: flex; flex-direction: column; justify-content: space-between;">
                                        <div>
                                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px;">
                                                <span style="font-size: 13.5px; font-weight: 700; color: #163A70;"><?= e($act['activity_title']) ?></span>
                                                <span class="badge-pill neutral" style="font-size: 10px;"><?= e(ucfirst($act['delivery_mode'] ?? 'Online')) ?></span>
                                            </div>
                                            <div style="font-size: 12px; color: #64748B; margin-bottom: 10px; line-height: 1.4;">
                                                <?= e(substr($act['description'] ?? '', 0, 95)) ?>...
                                            </div>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11.5px; padding-top: 8px; border-top: 1px solid #EEF2F6;">
                                            <span style="color: #1769E8; font-weight: 600;">+<?= (int)($act['gain_level'] ?? 2) ?> Skill Level</span>
                                            <a href="activities.php?id=<?= (int)$act['activity_id'] ?>" style="color: #163A70; font-weight: 700; text-decoration: none;">
                                                View & Enroll &rarr;
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Individual Question Evaluations -->
                    <div style="margin-top: 28px;">
                        <h3 style="font-size: 16px; font-weight: 800; color: #163A70; margin-bottom: 14px;">
                            Detailed Question Breakdown & AI Feedback
                        </h3>

                        <div style="display: flex; flex-direction: column; gap: 14px;">
                            <?php foreach ($assessmentDetails['questions'] as $i => $q): ?>
                                <?php 
                                    $ansRecord = $answers[$i] ?? null;
                                    $qScore = $ansRecord ? (float)$ansRecord['score'] : 0.0;
                                    $qFeedback = $ansRecord ? (string)$ansRecord['feedback'] : 'No answer.';
                                    $studentText = $ansRecord ? (string)$ansRecord['answer_text'] : '';
                                ?>
                                <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 8px; padding: 18px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                        <span style="font-weight: 700; font-size: 13.5px; color: #163A70;">
                                            Question <?= $i + 1 ?>: <?= e($q['evaluation_criteria'] ?: 'Scenario') ?>
                                        </span>
                                        <span class="badge-pill <?= $qScore >= 75 ? 'success' : ($qScore >= 50 ? 'warning' : 'danger') ?>">
                                            Score: <?= (int)$qScore ?>/100
                                        </span>
                                    </div>
                                    <div style="font-size: 13px; color: #475569; margin-bottom: 10px; background: #F8FAFC; padding: 10px; border-radius: 6px;">
                                        <strong>Scenario:</strong> <?= e($q['scenario_text']) ?>
                                    </div>
                                    <div style="font-size: 13px; color: #1E293B; margin-bottom: 8px;">
                                        <strong>Your Answer:</strong> <em><?= nl2br(e($studentText)) ?></em>
                                    </div>
                                    <div style="font-size: 12px; color: #0284C7; background: #F0F9FF; padding: 8px 12px; border-radius: 6px;">
                                        <strong>AI Feedback:</strong> <?= e($qFeedback) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

            <!-- =================================================================
                 VIEW 4: ASSESSMENT HISTORY & RETAKE TRACKER
                 ================================================================= -->
            <?php elseif ($action === 'history'): ?>

                <div class="dashboard-section" style="max-width: 900px; margin: 0 auto;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <div>
                            <h2 style="font-size: 20px; font-weight: 800; color: #163A70; margin: 0 0 4px 0;">
                                Assessment History & Score Progress
                            </h2>
                            <span style="font-size: 13px; color: #64748B;">All historical Gemini scenario attempts are preserved to measure progression</span>
                        </div>
                        <a href="skills_assessment.php" class="btn-take-assess" style="width: auto; padding: 8px 16px;">
                            + Assess New Skill
                        </a>
                    </div>

                    <?php if (empty($historyList)): ?>
                        <div class="module-empty-state" style="padding: 40px 20px;">
                            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="1.8"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                            <div class="module-empty-title">No Assessment History Found</div>
                            <div class="module-empty-desc">Take your first real-world skill assessment to benchmark your practical competencies.</div>
                            <a href="skills_assessment.php" class="btn-take-assess" style="width: auto; margin-top: 12px;">Browse Assessable Skills</a>
                        </div>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <?php foreach ($historyList as $hist): ?>
                                <?php $hScore = (float)$hist['overall_score']; ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; flex-wrap: wrap; gap: 12px;">
                                    <div>
                                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                            <span style="font-size: 15px; font-weight: 800; color: #163A70;"><?= e($hist['skill_name']) ?></span>
                                            <span class="badge-pill neutral"><?= e($hist['category_name']) ?></span>
                                            <span class="badge-pill info">Attempt #<?= (int)$hist['attempt_number'] ?></span>
                                        </div>
                                        <div style="font-size: 12px; color: #64748B;">
                                            Completed: <strong><?= date('M j, Y • g:i A', strtotime($hist['completed_at'])) ?></strong>
                                        </div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 16px;">
                                        <div style="text-align: right;">
                                            <div style="font-size: 18px; font-weight: 800; color: <?= $hScore >= 80 ? '#059669' : ($hScore >= 50 ? '#D97706' : '#DC2626') ?>;">
                                                <?= (int)$hScore ?>%
                                            </div>
                                            <div style="font-size: 11px; font-weight: 600; color: #64748B;"><?= e($hist['demonstration_level']) ?></div>
                                        </div>
                                        <div style="display: flex; gap: 8px;">
                                            <a href="skills_assessment.php?action=result&id=<?= (int)$hist['assessment_id'] ?>" class="btn-retake" style="padding: 8px 14px; font-size: 12px; text-decoration: none; border-radius: 6px;">
                                                View Report
                                            </a>
                                            <a href="skills_assessment.php?action=start&skill_id=<?= (int)$hist['skill_id'] ?>" class="btn-take-assess" style="width: auto; padding: 8px 14px; font-size: 12px;">
                                                Retake
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        </main>
    </div>

    <!-- Interactive Client Script -->
    <script>
        let currentQuestion = 1;
        const totalQuestions = 5;

        function updateCharCount(qNum) {
            const textarea = document.getElementById('ans_' + qNum);
            const counter = document.getElementById('charCount-' + qNum);
            const pill = document.getElementById('pill-step-' + qNum);
            if (!textarea || !counter) return;

            const text = textarea.value.trim();
            const words = text ? text.split(/\s+/).length : 0;
            counter.innerText = words + ' words (minimum 15 recommended)';

            if (words >= 15) {
                counter.style.color = '#059669';
                if (pill) pill.classList.add('filled');
            } else {
                counter.style.color = '#64748B';
                if (pill) pill.classList.remove('filled');
            }
        }

        function goToQuestion(qNum) {
            if (qNum < 1 || qNum > totalQuestions) return;

            // Validate current question minimum answer
            const curTextArea = document.getElementById('ans_' + currentQuestion);
            if (curTextArea && qNum > currentQuestion && curTextArea.value.trim().length < 5) {
                alert('Please enter your response before proceeding to the next scenario.');
                curTextArea.focus();
                return;
            }

            // Hide all slides
            for (let i = 1; i <= totalQuestions; i++) {
                const slide = document.getElementById('qSlide-' + i);
                const pill = document.getElementById('pill-step-' + i);
                if (slide) slide.style.display = 'none';
                if (pill) pill.classList.remove('active');
            }

            // Show selected slide
            const targetSlide = document.getElementById('qSlide-' + qNum);
            const targetPill = document.getElementById('pill-step-' + qNum);
            if (targetSlide) targetSlide.style.display = 'block';
            if (targetPill) targetPill.classList.add('active');

            currentQuestion = qNum;
            window.scrollTo({ top: 120, behavior: 'smooth' });
        }

        function openReviewModal() {
            // Populate answers review
            const reviewContainer = document.getElementById('reviewAnswersSummary');
            if (reviewContainer) {
                let html = '';
                for (let i = 1; i <= totalQuestions; i++) {
                    const ans = (document.getElementById('ans_' + i)?.value || '').trim();
                    const words = ans ? ans.split(/\s+/).length : 0;
                    html += `
                        <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; padding: 10px 14px;">
                            <div style="display:flex; justify-content:space-between; margin-bottom: 4px;">
                                <strong style="font-size: 13px; color: #163A70;">Question ${i}</strong>
                                <span style="font-size: 11px; color: ${words >= 15 ? '#059669' : '#D97706'}; font-weight: 600;">${words} Words</span>
                            </div>
                            <div style="font-size: 12px; color: #475569; max-height: 48px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                ${ans ? ans : '<em style="color:#DC2626;">No response entered</em>'}
                            </div>
                        </div>
                    `;
                }
                reviewContainer.innerHTML = html;
            }

            const modal = document.getElementById('reviewModal');
            if (modal) modal.style.display = 'flex';
        }

        function closeReviewModal() {
            const modal = document.getElementById('reviewModal');
            if (modal) modal.style.display = 'none';
        }

        function submitAssessmentForm() {
            closeReviewModal();
            const loadingModal = document.getElementById('loadingModal');
            if (loadingModal) loadingModal.style.display = 'flex';
            document.getElementById('assessmentForm').submit();
        }

        // Initialize character counters on page load
        document.addEventListener('DOMContentLoaded', () => {
            for (let i = 1; i <= totalQuestions; i++) {
                updateCharCount(i);
            }
        });
    </script>
</body>
</html>
