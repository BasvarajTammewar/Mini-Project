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
require_once __DIR__ . '/includes/partials/robot_svg.php';

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

// Handle system and redirect messages
$msgCode = trim((string)($_GET['msg'] ?? ''));
if ($msgCode === 'unauthorized_skill') {
    $alertMessage = 'Eligibility Policy: Gemini assesses only skills and activities explicitly entered in your profile. You cannot assess skills you have not claimed.';
    $alertType = 'danger';
} elseif ($msgCode === 'skill_not_found') {
    $alertMessage = 'The requested skill was not found in the catalog.';
    $alertType = 'danger';
} elseif ($msgCode === 'eval_completed') {
    $alertMessage = 'Assessment submitted and successfully evaluated by Gemini AI! Your report and competency scores are saved below.';
    $alertType = 'success';
} elseif ($msgCode === 'invalid_session') {
    $alertMessage = 'Assessment session was invalid or has already been finalized.';
    $alertType = 'danger';
} elseif ($msgCode === 'no_assessments_yet') {
    $alertMessage = 'You have not completed any skill assessments yet. Select an entered skill below to start your first assessment.';
    $alertType = 'info';
}

// =========================================================================
// ACTION: START NEW ASSESSMENT (GENERATE SCENARIOS VIA GEMINI)
// =========================================================================
if ($action === 'start' && $skillIdParam > 0) {
    // 1. Strict Eligibility Check: Student can ONLY assess skills explicitly claimed in their profile or activities
    if (!$assessService->isSkillEligibleForAssessment($userId, $skillIdParam)) {
        header("Location: skills_assessment.php?msg=unauthorized_skill");
        exit;
    }

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
        // Load verified existing activities context for realistic scenario generation
        $existingActivities = $assessService->getStudentExistingActivitiesContext($userId);

        $context = [
            'career_title'        => $activeGoal['career_title'] ?? '',
            'experience'          => $student['experience_level'] ?? 'beginner',
            'existing_activities' => $existingActivities
        ];

        // Fetch previous questions asked to this student for this skill to prevent question repetition
        $previousQuestions = $assessService->getStudentPreviousQuestions($userId, $skillIdParam);
        $excludeScenarios = array_column($previousQuestions, 'scenario_text');

        // Request Gemini API to generate 5 scenario-based questions with strict non-repetition exclusions
        $genResult = $gemini->generateScenarioQuestions($skillData['name'], $skillData['category_name'], $context, $excludeScenarios);
        $questions = $genResult['questions'];

        // Extra safeguard: verify and replace any accidental duplicates against previous questions
        if (!empty($previousQuestions)) {
            foreach ($questions as $qIdx => $qItem) {
                if ($assessService->isQuestionDuplicate($qItem['scenario'] ?? '', $previousQuestions, 0.55)) {
                    $single = $gemini->generateSingleScenarioQuestion(
                        $skillData['name'],
                        $skillData['category_name'],
                        $context,
                        array_merge($excludeScenarios, array_column($questions, 'scenario'))
                    );
                    if (!empty($single)) {
                        $questions[$qIdx] = $single;
                    }
                }
            }
        }

        // Persist session to database with immutable snapshot of student input and fingerprints
        $newAssessmentId = $assessService->createAssessmentSession($userId, $skillIdParam, $questions, $context);

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
// ACTION: AJAX RECORD ANSWER & FINALIZE EVALUATION (CONVERSATIONAL FLOW)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_submit_answer'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Security token invalid. Please refresh the page and try again.']);
        exit;
    }

    $postAssessmentId = (int)($_POST['assessment_id'] ?? 0);
    $qId = (int)($_POST['question_id'] ?? 0);
    $qIdx = (int)($_POST['question_index'] ?? 1);
    $answerText = trim((string)($_POST['answer_text'] ?? ''));

    if ($postAssessmentId <= 0 || empty($answerText)) {
        echo json_encode(['success' => false, 'error' => 'Assessment ID or answer text is missing.']);
        exit;
    }

    $session = $assessService->getAssessmentSession($postAssessmentId, $userId);
    if (!$session || $session['status'] === 'completed') {
        echo json_encode(['success' => false, 'error' => 'Assessment session is invalid or already finalized.']);
        exit;
    }

    // Save this question's answer in database
    $assessService->saveAssessmentAnswer($postAssessmentId, $qId, $answerText, null, null);

    $totalQuestions = count($session['questions']);
    if ($qIdx >= $totalQuestions) {
        // All 5 answers completed! Final evaluation via Gemini
        $stmtAll = $db->prepare("
            SELECT q.id AS question_id, q.question_index, q.scenario_text, q.evaluation_criteria,
                   a.answer_text
            FROM ai_assessment_questions q
            LEFT JOIN ai_assessment_answers a ON q.id = a.question_id AND a.assessment_id = :aid
            WHERE q.assessment_id = :aid2
            ORDER BY q.question_index ASC
        ");
        $stmtAll->execute([':aid' => $postAssessmentId, ':aid2' => $postAssessmentId]);
        $allRows = $stmtAll->fetchAll();

        $qaList = [];
        $answersMap = [];
        foreach ($allRows as $r) {
            $idx = (int)$r['question_index'];
            $ans = ($idx === $qIdx) ? $answerText : ($r['answer_text'] ?? '');
            $answersMap[$idx] = $ans;
            $qaList[] = [
                'question' => $r['scenario_text'],
                'answer'   => $ans,
                'focus'    => $r['evaluation_criteria'] ?: 'Applied reasoning'
            ];
        }

        try {
            $evalResult = $gemini->evaluateStudentAnswers($session['skill_name'], $session['category_name'], $qaList);
            $assessService->finalizeAssessmentEvaluation($postAssessmentId, $userId, $answersMap, $evalResult);

            echo json_encode([
                'success'      => true,
                'is_completed' => true,
                'redirect_url' => 'skills_assessment.php?action=result&id=' . $postAssessmentId . '&msg=eval_completed'
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error'   => 'Evaluation engine error: ' . $e->getMessage()
            ]);
            exit;
        }
    } else {
        // Intermediary step saved successfully
        echo json_encode([
            'success'      => true,
            'is_completed' => false,
            'next_index'   => $qIdx + 1
        ]);
        exit;
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

// Unread notifications count for topbar & sidebar
$stmtNotifs = $db->prepare("
    SELECT COUNT(*) 
    FROM notifications 
    WHERE user_id = :user_id 
      AND is_read = 0 
      AND (is_dismissed IS NULL OR is_dismissed = 0)
");
$stmtNotifs->execute([':user_id' => $userId]);
$unreadNotificationsCount = (int)$stmtNotifs->fetchColumn();
$expLevel = ucfirst($student['experience_level'] ?? 'Beginner');

$historyList = $assessService->getAssessmentHistory($userId);
$assessableSkillsData = $assessService->getStudentAssessableSkills($userId);
$skillsSummary = $assessableSkillsData['summary'];
$categorizedSkills = $assessableSkillsData['categorized'];

// Prepare view data
$assessmentDetails = null;
$recommendedActivities = [];
$criteriaPillars = [];
$improvementStatus = 'Needs Improvement';
$statusColor = '#DC2626';
$statusBg = '#FEF2F2';
$progressBarColor = '#EF4444';
$needsImprovementHeadline = '';
$needsImprovementExplanation = '';
$showImprovementUrgency = true;
$careerTitle = 'Industry Professional';

if ($action === 'result' && $assessmentId <= 0 && !empty($historyList)) {
    $assessmentId = (int)$historyList[0]['assessment_id'];
}

if ($action === 'take' && $assessmentId > 0) {
    $assessmentDetails = $assessService->getAssessmentSession($assessmentId, $userId);
    if (!$assessmentDetails) {
        $action = 'dashboard';
    }
} elseif ($action === 'result' && $assessmentId > 0) {
    $assessmentDetails = $assessService->getAssessmentSession($assessmentId, $userId);
    if (!$assessmentDetails) {
        $action = 'dashboard';
    } else {
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

        // Target career
        require_once __DIR__ . '/includes/event_discovery_service.php';
        $targetCareer = getStudentTargetCareer($db, $userId);
        $careerTitle = $targetCareer['career_title'] ?? 'Industry Professional';

        // Skill-gap analysis
        require_once __DIR__ . '/includes/skill_gap_service.php';
        $gapService = new SkillGapService($db);
        $gapAnalysis = $gapService->getSavedSkillGapAnalysis($assessmentId, $userId);
        if (!$gapAnalysis) {
            $gapAnalysis = $gapService->generateSkillGapAnalysis($assessmentId, $userId);
        }

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

        // Criteria pillars
        $criteriaPillars = [
            [
                'id'          => 'decision_making',
                'name'        => 'Decision Making & Practical Approach',
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
    <link rel="stylesheet" href="assets/css/robot_assessment.css?v=<?= filemtime(__DIR__ . '/assets/css/robot_assessment.css') ?>">
    <style>
        /* Strict SVG guard (excluding robot SVG to preserve gradients and glows) */
        svg:not(.robot-svg):not(.robot-svg *) {
            display: inline-block;
            vertical-align: middle;
            fill: none !important;
            stroke: currentColor;
            stroke-width: 2;
            box-sizing: border-box;
            max-width: 100%;
        }
        .robot-svg, .robot-svg * {
            box-sizing: border-box;
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

        /* Combined Results, Skill-Gap & Recommendations Styles */
        .results-container {
            max-width: 1080px;
            margin: 0 auto 32px auto;
        }
        .attempt-tabs-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 4px 0 16px 0;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
            overflow-x: auto;
        }
        .attempt-tab {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            background: #FFFFFF;
            border: 1px solid var(--border-color);
            color: var(--secondary-text);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            white-space: nowrap;
        }
        .attempt-tab:hover {
            border-color: var(--primary-blue);
            color: var(--primary-blue);
            background-color: var(--light-blue);
        }
        .attempt-tab.active {
            background-color: var(--primary-blue) !important;
            color: #FFFFFF !important;
            border-color: var(--primary-blue) !important;
            box-shadow: 0 4px 12px rgba(23, 105, 232, 0.25);
        }
        .attempt-tab.active span {
            color: #FFFFFF !important;
        }
        .score-hero-card {
            background: #FFFFFF;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 32px 36px;
            box-shadow: var(--card-shadow);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 28px;
            flex-wrap: wrap;
        }
        .score-hero-info {
            flex: 1;
            min-width: 280px;
        }
        .score-hero-skill-title {
            font-size: 26px;
            font-weight: 800;
            color: var(--dark-navy);
            letter-spacing: -0.02em;
            margin-bottom: 4px;
        }
        .score-hero-meta {
            font-size: 13px;
            color: var(--muted-text);
            margin-bottom: 18px;
        }
        .score-number-display {
            font-size: 48px;
            font-weight: 800;
            color: var(--dark-navy);
            line-height: 1;
            display: flex;
            align-items: baseline;
            gap: 8px;
            margin-bottom: 8px;
        }
        .score-number-display span.sub-pct {
            font-size: 20px;
            font-weight: 600;
            color: var(--muted-text);
        }
        .score-badge-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }
        .progress-bar-track {
            width: 100%;
            height: 12px;
            background: #E2E8F0;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 10px;
            position: relative;
        }
        .progress-bar-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 1.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .improvement-card {
            background: #FFFFFF;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 28px 32px;
            box-shadow: var(--card-shadow);
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }
        .improvement-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 6px;
            height: 100%;
            background: <?= $progressBarColor ?>;
        }
        .improvement-card-badge {
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: <?= $statusColor ?>;
            margin-bottom: 8px;
            display: inline-block;
        }
        .improvement-card-headline {
            font-size: 20px;
            font-weight: 800;
            color: var(--dark-navy);
            margin-bottom: 8px;
        }
        .improvement-card-text {
            font-size: 14.5px;
            line-height: 1.6;
            color: var(--secondary-text);
            margin-bottom: 18px;
        }
        .skills-to-improve-list {
            margin: 0;
            padding-left: 20px;
            font-size: 13.5px;
            color: #92400E;
            line-height: 1.6;
        }
        .skill-gap-section {
            margin-bottom: 32px;
        }
        .section-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .section-title {
            font-size: 19px;
            font-weight: 800;
            color: var(--dark-navy);
            letter-spacing: -0.01em;
        }
        .criteria-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 16px;
        }
        .criterion-card {
            background: #FFFFFF;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: var(--transition);
        }
        .criterion-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 14px rgba(22, 58, 112, 0.08);
            border-color: #CBD5E1;
        }
        .criterion-name {
            font-size: 14px;
            font-weight: 700;
            color: var(--dark-navy);
            margin-bottom: 8px;
            line-height: 1.35;
            min-height: 38px;
        }
        .criterion-score-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .criterion-score-number {
            font-size: 26px;
            font-weight: 800;
            color: var(--dark-navy);
        }
        .criterion-status-pill {
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .criterion-tip {
            font-size: 12px;
            color: var(--muted-text);
            line-height: 1.45;
            margin-top: 14px;
            padding-top: 10px;
            border-top: 1px dashed var(--border-color);
        }
        .recs-section {
            margin-bottom: 32px;
        }
        .recs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 18px;
        }
        .rec-card {
            background: #FFFFFF;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 22px;
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: var(--transition);
        }
        .rec-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(22, 58, 112, 0.09);
            border-color: #CBD5E1;
        }
        .rec-category-tag {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--primary-blue);
            background: var(--light-blue);
            padding: 4px 10px;
            border-radius: var(--radius-sm);
            margin-bottom: 10px;
        }
        .rec-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--dark-navy);
            line-height: 1.35;
            margin-bottom: 6px;
        }
        .rec-meta-row {
            font-size: 12.5px;
            color: var(--muted-text);
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .rec-skills-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 14px;
        }
        .rec-skill-pill {
            font-size: 11.5px;
            font-weight: 600;
            color: #334155;
            background: #F1F5F9;
            padding: 3px 8px;
            border-radius: 4px;
        }
        .rec-why-box {
            background: #F8FAFC;
            border-left: 3px solid var(--primary-blue);
            padding: 10px 12px;
            border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
            font-size: 12.5px;
            color: #475569;
            line-height: 1.5;
            margin-bottom: 18px;
        }
        .rec-actions-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 14px;
            border-top: 1px solid var(--border-color);
            gap: 10px;
        }
        .ai-feedback-card {
            background: linear-gradient(180deg, #F5F7FF 0%, #FFFFFF 100%);
            border: 1px solid #C7D2FE;
            border-radius: var(--radius-md);
            padding: 28px 32px;
            box-shadow: 0 4px 16px rgba(79, 70, 229, 0.06);
            margin-bottom: 28px;
            position: relative;
        }
        .ai-feedback-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .ai-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #EEF2FF;
            color: #4338CA;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 4px 10px;
            border-radius: 20px;
            border: 1px solid #E0E7FF;
        }
        .ai-feedback-text {
            font-size: 14.5px;
            line-height: 1.7;
            color: #1E293B;
            font-style: italic;
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
                        <a href="skills_assessment.php" class="nav-link active" aria-current="page">
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

                <?php if (empty($skillsSummary['has_assessable_skills']) || $skillsSummary['student_skills_count'] === 0): ?>
                    <!-- Empty State: No saved skills or existing activities -->
                    <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 48px 24px; text-align: center; margin-bottom: 32px; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
                        <div style="width: 64px; height: 64px; margin: 0 auto 16px; border-radius: 50%; background: #EFF6FF; display: flex; align-items: center; justify-content: center; color: #1769E8;">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        </div>
                        <h2 style="font-size: 20px; font-weight: 800; color: #163A70; margin-bottom: 8px;">No Saved Skills or Activities Found</h2>
                        <p style="font-size: 14px; color: #64748B; max-width: 560px; margin: 0 auto 24px; line-height: 1.6;">
                            Gemini AI Skill Assessment evaluates only the skills and extracurricular activities you have explicitly entered and claimed in your profile. You currently have no saved skills or past activities to benchmark.
                        </p>
                        <a href="my_skills.php" class="btn-take-assess" style="display: inline-flex; align-items: center; gap: 8px; text-decoration: none; padding: 12px 24px; border-radius: 8px; font-size: 14px; font-weight: 700; width: auto;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                            <span>Add Skills in My Existing Activities & Skills</span>
                        </a>
                    </div>
                <?php else: ?>

                    <!-- Strict Eligibility Policy Banner -->
                    <div style="background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 10px; padding: 14px 18px; margin-bottom: 24px; display: flex; align-items: flex-start; gap: 12px;">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1D4ED8" stroke-width="2" style="flex-shrink: 0; margin-top: 2px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                        <div style="font-size: 13.5px; color: #1E40AF; line-height: 1.5;">
                            <strong>Strict Skill Eligibility Rule:</strong> Gemini AI assesses only the skills you have explicitly claimed in your profile and verified through past activities. It does not infer unproven skills from career goals, degrees, or future recommendations.
                        </div>
                    </div>

                    <?php
                        $existingActs = $assessService->getStudentExistingActivitiesContext($userId);
                        if (!empty($existingActs)):
                    ?>
                        <!-- Existing Activities Informing Assessment -->
                        <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px 20px; margin-bottom: 24px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <div style="font-size: 13.5px; font-weight: 700; color: #163A70;">
                                    Your Verified Past Activities Context
                                </div>
                                <span class="badge-pill info" style="font-size: 11px;"><?= count($existingActs) ?> Verified</span>
                            </div>
                            <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                                <?php foreach ($existingActs as $ea): ?>
                                    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; padding: 6px 12px; font-size: 12px; color: #334155;">
                                        <strong><?= e($ea['title']) ?></strong> <?= !empty($ea['role']) ? '• ' . e($ea['role']) : '' ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Section: Soft Skills -->
                    <?php if (!empty($categorizedSkills['soft'])): ?>
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
                    <?php endif; ?>

                    <!-- Section: Technical Skills -->
                    <?php if (!empty($categorizedSkills['technical'])): ?>
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
                    <?php endif; ?>

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

                <?php endif; ?>

            <!-- =================================================================
                 VIEW 2: 2D AI ROBOT INTERVIEWER & CONVERSATIONAL ASSESSMENT INTERFACE
                 ================================================================= -->
            <?php elseif ($action === 'take' && $assessmentDetails): ?>

                <div class="robot-assessment-container">
                    <div class="robot-assessment-grid">
                        
                        <!-- LEFT PANEL: 2D AI Robot Interviewer Booth -->
                        <aside class="robot-interviewer-panel" aria-label="AI Interviewer">
                            <div class="robot-panel-header">
                                <div class="robot-status-badge">
                                    <span class="status-pulse-dot"></span>
                                    <span id="robotStatusText">AI Interviewer Active</span>
                                </div>
                                <span class="robot-core-tag">CareerAct AI</span>
                            </div>

                            <!-- Robot 2D Stage with Ambient Glow -->
                            <div class="robot-stage-wrapper">
                                <?php renderRobotSvg('speaking'); ?>
                            </div>

                            <!-- Conversational Robot Speech Bubble -->
                            <div class="robot-speech-card" aria-live="polite">
                                <div class="robot-speech-title">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                                    <span>AI Interviewer</span>
                                </div>
                                <p class="robot-speech-text" id="robotSpeechText">
                                    Hello <?= e($firstName) ?>! Preparing your real-world scenarios...
                                </p>
                            </div>

                            <!-- Assessment Meta Details Card -->
                            <div class="robot-meta-box">
                                <div class="meta-row">
                                    <span class="meta-label">Skill Evaluated:</span>
                                    <span class="meta-value"><?= e($assessmentDetails['skill_name']) ?></span>
                                </div>
                                <div class="meta-row">
                                    <span class="meta-label">Domain Cluster:</span>
                                    <span class="meta-value"><?= e($assessmentDetails['category_name']) ?></span>
                                </div>
                                <div class="meta-row">
                                    <span class="meta-label">Assessment Attempt:</span>
                                    <span class="meta-value">Attempt #<?= (int)$assessmentDetails['attempt_number'] ?></span>
                                </div>
                                <div class="meta-row">
                                    <span class="meta-label">Total Scenarios:</span>
                                    <span class="meta-value"><?= count($assessmentDetails['questions']) ?> Challenges</span>
                                </div>
                            </div>
                        </aside>

                        <!-- RIGHT PANEL: Conversational Assessment Flow -->
                        <section class="conversational-chat-panel" aria-label="Assessment Challenge">
                            <!-- Top Bar with Live Progress Track -->
                            <div class="chat-panel-topbar">
                                <div class="chat-progress-info">
                                    <div class="chat-progress-label" id="chatProgressLabel">
                                        Question 1 of <?= count($assessmentDetails['questions']) ?> <span style="font-size:11.5px; color:#1769E8; font-weight:600;">(20% Completed)</span>
                                    </div>
                                    <div class="chat-progress-track">
                                        <div class="chat-progress-fill" id="chatProgressFill" style="width: 20%;"></div>
                                    </div>
                                </div>
                                <a href="skills_assessment.php" class="chat-exit-btn" onclick="return confirm('Are you sure you want to pause and exit? Your completed answers are saved.');">
                                    Exit Assessment
                                </a>
                            </div>

                            <!-- Conversational Thread Body -->
                            <div class="chat-thread-body">
                                
                                <!-- Question Cards (1 to 5) -->
                                <?php foreach ($assessmentDetails['questions'] as $idx => $q): ?>
                                    <?php 
                                        $qNum = $idx + 1;
                                        $scenarioCat = !empty($q['scenario_category']) ? $q['scenario_category'] : $assessmentDetails['category_name'];
                                        $difficulty = !empty($q['difficulty_level']) ? $q['difficulty_level'] : 'Vocational Standard';
                                    ?>
                                    <div class="question-card-unit" id="qCard-<?= $qNum ?>" data-question-id="<?= (int)$q['id'] ?>" style="display: <?= $qNum === 1 ? 'block' : 'none' ?>;">
                                        <div class="ai-question-bubble">
                                            <div class="ai-question-bubble-header">
                                                <div class="ai-interviewer-tag">
                                                    <div class="ai-interviewer-avatar">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="10" rx="2"></rect><circle cx="12" cy="5" r="2"></circle><path d="M12 7v4"></path></svg>
                                                    </div>
                                                    <span>Challenge Scenario <?= $qNum ?> of <?= count($assessmentDetails['questions']) ?></span>
                                                </div>
                                                <div class="question-badges">
                                                    <span class="badge-scenario-category"><?= e($scenarioCat) ?></span>
                                                    <span class="badge-difficulty"><?= e($difficulty) ?></span>
                                                </div>
                                            </div>

                                            <div class="scenario-main-text">
                                                <?= nl2br(e($q['scenario_text'])) ?>
                                            </div>

                                            <div class="scenario-focus-pill">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#1769E8" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                                                <span>Evaluation Focus: <strong><?= e($q['evaluation_criteria'] ?: 'Applied Strategy & Practical Resolution') ?></strong></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <!-- Student Response Bubble -->
                                <div class="student-response-container">
                                    <div class="student-response-header">
                                        <div class="student-tag">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1769E8" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                            <span>Your Proposed Approach & Resolution</span>
                                        </div>
                                        <span style="font-size: 11.5px; color: #94A3B8;">Shortcut: Ctrl + Enter to send</span>
                                    </div>

                                    <textarea 
                                        id="conversationalAnswer" 
                                        class="conversational-textarea"
                                        placeholder="Describe the concrete actions you would take, your sequence of problem-solving steps, and how you would verify success..."
                                        rows="5"
                                    ></textarea>

                                    <div class="response-footer-bar">
                                        <div class="word-count-indicator" id="wordCounterIndicator">
                                            0 words (minimum 15 recommended)
                                        </div>

                                        <button type="button" class="btn-conversational-submit" id="btnSubmitResponse" disabled>
                                            <span>Send Response</span>
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                                        </button>
                                    </div>

                                    <!-- Thinking Loader Overlay (shown during Gemini evaluation) -->
                                    <div class="thinking-loader-overlay" id="thinkingOverlay">
                                        <div class="thinking-spinner"></div>
                                        <span>AI Interviewer is evaluating your practical approach and reasoning...</span>
                                    </div>
                                </div>

                                <!-- Past Answers Trail in this session -->
                                <div class="chat-history-accordion" id="chatHistoryWrapper" style="display: none;">
                                    <div class="chat-history-title">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                        <span>Previous Answers Recorded in this Session</span>
                                    </div>
                                    <div id="chatHistoryList"></div>
                                </div>

                            </div>
                        </section>

                    </div>
                </div>

                <!-- Fallback Hidden Form for Non-JS / Standard Submissions -->
                <form id="standardAssessmentForm" method="POST" action="skills_assessment.php" style="display:none;">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="assessment_id" value="<?= (int)$assessmentDetails['id'] ?>">
                    <input type="hidden" name="submit_assessment_answers" value="1">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <input type="hidden" name="answers[<?= $i ?>]" id="hidden_ans_<?= $i ?>" value="">
                    <?php endfor; ?>
                </form>

                <script src="assets/js/robot_interviewer.js?v=<?= filemtime(__DIR__ . '/assets/js/robot_interviewer.js') ?>"></script>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        window.careerActInterviewer = new RobotInterviewer({
                            assessmentId: <?= (int)$assessmentDetails['id'] ?>,
                            totalQuestions: <?= count($assessmentDetails['questions']) ?>,
                            currentQuestionIndex: 1,
                            csrfToken: <?= json_encode($csrfToken) ?>,
                            skillName: <?= json_encode($assessmentDetails['skill_name']) ?>,
                            studentName: <?= json_encode($firstName) ?>
                        });
                    });
                </script>

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
                            <?php
                                $scoreExplanation = '';
                                if ($overallScore >= 80) {
                                    $scoreExplanation = 'Strongly Demonstrated — You exhibited high proficiency, proactive decision-making, and effective problem resolution in this competency.';
                                } elseif ($overallScore >= 50) {
                                    $scoreExplanation = 'Partially Demonstrated — You showed a solid foundational understanding with clear opportunities to strengthen practical execution and root-cause analysis.';
                                } else {
                                    $scoreExplanation = 'Not Yet Demonstrated — Needs structured hands-on experience and focused practice to develop real-world problem-solving confidence.';
                                }
                            ?>
                            <div style="font-size: 12.5px; font-weight: 600; color: #163A70; margin-bottom: 8px; background: #F1F5F9; border-radius: 6px; padding: 6px 10px;">
                                <?= e($scoreExplanation) ?>
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

                    <!-- AI Developmental Guidance Notice -->
                    <div style="background: #F8FAFC; border: 1px dashed #CBD5E1; border-radius: 8px; padding: 14px 18px; margin-top: 24px; text-align: center;">
                        <p style="font-size: 12.5px; color: #64748B; margin: 0; line-height: 1.5;">
                            <strong>AI Guidance Notice:</strong> This skill assessment is generated and evaluated by Google Gemini AI based on your practical scenario answers. Results serve as developmental feedback and vocational benchmarking, and do not constitute formal professional certification or accreditation.
                        </p>
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
                                            <?php if (!empty($hist['score_delta_label'])): ?>
                                                <span class="badge-pill <?= (strpos($hist['score_delta_label'], '+') !== false) ? 'success' : 'neutral' ?>" style="font-size:11px; font-weight:700;">
                                                    <?= e($hist['score_delta_label']) ?>
                                                </span>
                                            <?php endif; ?>
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
                                            <a href="assessment_results.php?id=<?= (int)$hist['assessment_id'] ?>" class="btn-retake" style="padding: 8px 14px; font-size: 12px; text-decoration: none; border-radius: 6px;">
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
