<?php
/**
 * CareerAct — Automated Verification Suite for AI-Powered Skill Assessment Module
 * 
 * Tests Gemini service integration, scenario question generation, answer evaluation,
 * rubric breakdown, database transactions in carrearact2, activity recommendations,
 * and historical retake progression.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/gemini_service.php';
require_once __DIR__ . '/includes/assessment_service.php';

$pdo = getDbConnection();
$gemini = new GeminiService();
$assessService = new AssessmentService($pdo);

// Find real student user ID from carrearact2
$testStudentId = (int)$pdo->query("SELECT id FROM users WHERE role = 'student' ORDER BY id ASC LIMIT 1")->fetchColumn();
if ($testStudentId <= 0) {
    die("No student user found in database for testing.\n");
}
$testStudentName = $pdo->query("SELECT name FROM users WHERE id = {$testStudentId}")->fetchColumn();

// Find a second student for isolation testing
$testStudent2Id = (int)$pdo->query("SELECT id FROM users WHERE role = 'student' AND id != {$testStudentId} LIMIT 1")->fetchColumn();

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function runTest(string $name, callable $fn): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    echo "Running [{$name}]... ";
    try {
        $result = $fn();
        if ($result === true) {
            echo "PASSED\n";
            $passedTests++;
        } else {
            echo "FAILED: " . ($result ?: 'Assertion failed') . "\n";
            $failedTests++;
        }
    } catch (Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
        $failedTests++;
    }
}

echo "========================================================================\n";
echo " CAREERACT AI SKILL ASSESSMENT VERIFICATION SUITE\n";
echo " Database: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n";
echo " Primary Test Student ID: {$testStudentId} ({$testStudentName})\n";
echo "========================================================================\n\n";

// 1. SKILL IDENTIFICATION TEST
runTest("1. Skill Identification identifies Soft, Technical, and Professional clusters", function() use ($assessService, $testStudentId) {
    $data = $assessService->getStudentAssessableSkills($testStudentId);
    if (empty($data['categorized']['soft'])) {
        return "Soft skills category is empty";
    }
    if (empty($data['categorized']['technical'])) {
        return "Technical skills category is empty";
    }
    
    // Check specific soft skills exist
    $softNames = array_column($data['categorized']['soft'], 'skill_name');
    $hasProblemSolving = false;
    foreach ($softNames as $sn) {
        if (stripos($sn, 'Problem solving') !== false || stripos($sn, 'Critical thinking') !== false || stripos($sn, 'Teamwork') !== false) {
            $hasProblemSolving = true;
            break;
        }
    }
    if (!$hasProblemSolving) {
        return "Expected soft skills (Problem solving / Teamwork) not found in soft cluster";
    }

    return true;
});

// 2. SCENARIO QUESTION GENERATION TEST (SOFT SKILL: LEADERSHIP)
runTest("2. Gemini Service generates 5 realistic situational scenarios for Leadership", function() use ($gemini) {
    $gen = $gemini->generateScenarioQuestions('Leadership', 'Leadership and teamwork');
    if ($gen['skill'] !== 'Leadership') {
        return "Skill name mismatch in generated scenarios";
    }
    if (!isset($gen['questions']) || count($gen['questions']) !== 5) {
        return "Expected exactly 5 questions, got " . count($gen['questions'] ?? []);
    }
    foreach ($gen['questions'] as $i => $q) {
        if (empty($q['scenario']) || strlen($q['scenario']) < 30) {
            return "Question " . ($i + 1) . " scenario text is too short or empty";
        }
        if (empty($q['evaluation_focus'])) {
            return "Question " . ($i + 1) . " missing evaluation focus";
        }
    }
    return true;
});

// 3. SCENARIO QUESTION GENERATION TEST (TECHNICAL SKILL: PROGRAMMING)
runTest("3. Gemini Service generates practical engineering dilemmas for Technical skills", function() use ($gemini) {
    $gen = $gemini->generateScenarioQuestions('Programming fundamentals', 'Digital and programming');
    if (count($gen['questions']) !== 5) {
        return "Expected 5 technical scenario questions";
    }
    // Must test applied engineering dilemmas rather than simple textbook definition
    $firstQ = strtolower($gen['questions'][0]['scenario']);
    if (strpos($firstQ, 'what is') === 0) {
        return "Generated simple textbook definition rather than practical engineering scenario";
    }
    return true;
});

// 4. ASSESSMENT SESSION CREATION & TRANSACTIONAL PERSISTENCE
$createdAssessmentId = 0;
runTest("4. Assessment session persists in ai_skill_assessments and ai_assessment_questions", function() use ($assessService, $gemini, $pdo, $testStudentId, &$createdAssessmentId) {
    // Generate questions for Problem Solving (Skill ID 5)
    $gen = $gemini->generateScenarioQuestions('Problem solving', 'Analytical and problem solving');
    $assessmentId = $assessService->createAssessmentSession($testStudentId, 5, $gen['questions']);
    if ($assessmentId <= 0) {
        return "Failed to insert assessment session";
    }
    $createdAssessmentId = $assessmentId;

    $session = $assessService->getAssessmentSession($assessmentId, $testStudentId);
    if (!$session) {
        return "Session not retrievable via getAssessmentSession";
    }
    if ($session['status'] !== 'in_progress') {
        return "Initial status is not 'in_progress'";
    }
    if (count($session['questions']) !== 5) {
        return "Expected 5 persisted questions in database, found " . count($session['questions']);
    }
    return true;
});

// 5. GEMINI ANSWER EVALUATION ENGINE
$evalResultGlobal = null;
runTest("5. Gemini Answer Evaluation Engine scores responses across 4 rubric pillars (0-100)", function() use ($gemini, $assessService, $testStudentId, &$evalResultGlobal, $createdAssessmentId) {
    $session = $assessService->getAssessmentSession($createdAssessmentId, $testStudentId);
    $qaList = [];
    $mockAnswers = [
        1 => "First, I would isolate the failing system component and analyze the error logs to trace the root cause. Next, I'd collaborate with the team lead to deploy a hotfix while keeping stakeholders updated.",
        2 => "I would evaluate both options by weighing immediate technical debt against project delivery timeline. I'd propose an iterative strategy: apply the quick stabilization step today, and schedule the full architectural refactor for the next sprint.",
        3 => "I would segment the survey data by student cohort and cross-reference timestamps with recent campus events to extract the true underlying signal behind the contradictions.",
        4 => "Under high stakes, I follow a systematic triage sequence: check recent config changes, verify database connection pools, revert to the last stable release, and document the root incident.",
        5 => "I'd map cascading dependencies using architecture diagrams, implement unit and integration guards, and run end-to-end regression tests to verify that fixing one module didn't break adjacent services."
    ];

    foreach ($session['questions'] as $q) {
        $idx = (int)$q['question_index'];
        $qaList[] = [
            'question' => $q['scenario_text'],
            'answer'   => $mockAnswers[$idx],
            'focus'    => $q['evaluation_criteria']
        ];
    }

    $eval = $gemini->evaluateStudentAnswers('Problem solving', 'Analytical and problem solving', $qaList);
    $evalResultGlobal = $eval;

    if ($eval['overall_score'] < 0 || $eval['overall_score'] > 100) {
        return "Overall score outside 0-100 range: " . $eval['overall_score'];
    }
    if (!in_array($eval['demonstration_level'], ['Strongly Demonstrated', 'Partially Demonstrated', 'Not Yet Demonstrated'])) {
        return "Invalid demonstration level: " . $eval['demonstration_level'];
    }
    if (empty($eval['strengths']) || count($eval['strengths']) < 2) {
        return "Expected at least 2 qualitative strengths";
    }
    if (empty($eval['improvement_areas'])) {
        return "Missing improvement areas";
    }
    if (count($eval['question_evaluations']) !== 5) {
        return "Expected 5 individual question evaluations";
    }
    return true;
});

// 6. FINALIZING EVALUATION & UPDATING STUDENT PROFILE COMPETENCIES
runTest("6. Transactional finalization records scores and upgrades student verified skill level", function() use ($assessService, $pdo, $testStudentId, $createdAssessmentId, &$evalResultGlobal) {
    $mockAnswers = [
        1 => "First, I would isolate the failing system component...",
        2 => "I would evaluate both options by weighing immediate technical debt...",
        3 => "I would segment the survey data by student cohort...",
        4 => "Under high stakes, I follow a systematic triage sequence...",
        5 => "I'd map cascading dependencies using architecture diagrams..."
    ];

    $success = $assessService->finalizeAssessmentEvaluation($createdAssessmentId, $testStudentId, $mockAnswers, $evalResultGlobal);
    if (!$success) {
        return "Finalize assessment evaluation returned false";
    }

    $session = $assessService->getAssessmentSession($createdAssessmentId, $testStudentId);
    if ($session['status'] !== 'completed') {
        return "Assessment status is not 'completed'";
    }
    if ($session['overall_score'] <= 0) {
        return "Assessment overall score was not stored";
    }

    // Verify student_skills table updated with assessed source
    $stmtSkill = $pdo->prepare("SELECT level, source FROM student_skills WHERE user_id = :uid AND skill_id = 5");
    $stmtSkill->execute([':uid' => $testStudentId]);
    $updatedSkill = $stmtSkill->fetch();
    if (!$updatedSkill) {
        return "student_skills record not found for user {$testStudentId}, skill 5";
    }
    if ($updatedSkill['source'] !== 'assessed') {
        return "Skill source was not updated to 'assessed'";
    }

    return true;
});

// 7. REAL ACTIVITY RECOMMENDATION MATCHING
runTest("7. System matches real extracurricular activities from carrearact2 for the assessed skill", function() use ($assessService, $testStudentId) {
    // Problem solving = skill ID 5
    $recs = $assessService->getRecommendedActivitiesForSkill(5, $testStudentId, 4);
    if (empty($recs)) {
        return "No activity recommendations returned for Problem solving";
    }
    if (count($recs) > 4) {
        return "Returned more than the requested limit of 4 activities";
    }
    foreach ($recs as $rec) {
        if (empty($rec['activity_title'])) {
            return "Activity record missing activity_title";
        }
    }
    return true;
});

// 8. ASSESSMENT HISTORY & RETAKE SUPPORT
runTest("8. Assessment history records multiple attempts separately without overwriting", function() use ($assessService, $gemini, $testStudentId, &$evalResultGlobal) {
    // Start second attempt for the same skill (Skill ID 5)
    $gen2 = $gemini->generateScenarioQuestions('Problem solving', 'Analytical and problem solving');
    $attempt2Id = $assessService->createAssessmentSession($testStudentId, 5, $gen2['questions']);

    $mockAnswers2 = [
        1 => "Retake attempt answer 1",
        2 => "Retake attempt answer 2",
        3 => "Retake attempt answer 3",
        4 => "Retake attempt answer 4",
        5 => "Retake attempt answer 5"
    ];
    $assessService->finalizeAssessmentEvaluation($attempt2Id, $testStudentId, $mockAnswers2, $evalResultGlobal);

    // Fetch history for user, skill 5
    $history = $assessService->getAssessmentHistory($testStudentId, 5);
    if (count($history) < 2) {
        return "Expected at least 2 attempts in history for skill 5, found " . count($history);
    }

    // Verify attempt numbers
    $attemptNumbers = array_column($history, 'attempt_number');
    if (!in_array(1, $attemptNumbers) || !in_array(2, $attemptNumbers)) {
        return "Attempt numbers 1 and 2 not both found in history: " . json_encode($attemptNumbers);
    }

    return true;
});

// 9. CROSS-STUDENT ISOLATION & DATA SECURITY
runTest("9. Cross-student isolation prevents Student B from reading Student A's assessment session", function() use ($assessService, $testStudent2Id, $createdAssessmentId) {
    $unauthorized = $assessService->getAssessmentSession($createdAssessmentId, $testStudent2Id);
    if ($unauthorized !== null) {
        return "Security breach: Student {$testStudent2Id} was able to retrieve Student's assessment details";
    }
    return true;
});

echo "\n========================================================================\n";
echo " TEST RESULTS: {$passedTests} / {$totalTests} PASSED\n";
if ($failedTests === 0) {
    echo " >>> ALL AI SKILL ASSESSMENT TESTS PASSED 100%! <<<\n";
} else {
    echo " >>> {$failedTests} TESTS FAILED. REVIEW OUTPUT ABOVE. <<<\n";
}
echo "========================================================================\n";
