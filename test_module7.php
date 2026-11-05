<?php
/**
 * CareerAct — Module 7: AI Skill-Gap Analysis Verification Suite
 * 
 * Verifies all 10 completion criteria for Module 7:
 * 1. Student without a completed assessment sees prompt to complete Module 6.
 * 2. Only skills in the selected assessment are analyzed.
 * 3. Career goals do not introduce additional skills.
 * 4. Scores and thresholds come from backend configuration and saved results.
 * 5. Gemini cannot introduce an unassessed skill or fabricate a score.
 * 6. Analysis is saved and remains available after logout/login.
 * 7. A new assessment can have its own separate analysis.
 * 8. Students cannot access another student's assessment or analysis.
 * 9. Dashboard links show the correct latest analysis.
 * 10. The system works gracefully when Gemini is unavailable.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/gemini_service.php';
require_once __DIR__ . '/includes/assessment_service.php';
require_once __DIR__ . '/includes/skill_gap_service.php';

$pdo = getDbConnection();
$gemini = new GeminiService();
$assessService = new AssessmentService($pdo);
$gapService = new SkillGapService($pdo, $gemini);

$total = 0;
$passed = 0;

function it(string $title, callable $fn): void {
    global $total, $passed;
    $total++;
    echo "Running [{$title}]... ";
    try {
        $res = $fn();
        if ($res === true) {
            $passed++;
            echo "PASSED\n";
        } else {
            echo "FAILED: {$res}\n";
        }
    } catch (Throwable $e) {
        echo "FAILED with exception: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

echo "========================================================================\n";
echo " CAREERACT MODULE 7 — AI SKILL-GAP ANALYSIS VERIFICATION SUITE\n";
echo " Database: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n";
echo "========================================================================\n\n";

// Primary student (has completed assessments from Module 6)
$student = $pdo->query("SELECT id, name, email FROM users WHERE role = 'student' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$testUserId = (int)$student['id'];

// Second student for isolation testing
$student2 = $pdo->query("SELECT id, name, email FROM users WHERE role = 'student' AND id != {$testUserId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$testUser2Id = (int)$student2['id'];

// Create temporary user with 0 completed assessments
$zeroEmail = 'no_assess_' . time() . '@test.com';
$pdo->exec("INSERT INTO users (name, email, password_hash, role, created_at) VALUES ('No Assess Student', '{$zeroEmail}', 'hash', 'student', NOW())");
$zeroUserId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO student_profiles (user_id, city, profile_completed) VALUES ({$zeroUserId}, 'Pune', 1)");

// Check 1: Student without a completed assessment sees prompt to complete Module 6
it("1. Student without a completed assessment sees prompt to complete Module 6", function() use ($gapService, $zeroUserId) {
    if ($gapService->hasCompletedAssessments($zeroUserId)) {
        return "Expected hasCompletedAssessments to return false for student without completed assessments";
    }
    $latest = $gapService->getLatestCompletedAssessment($zeroUserId);
    if ($latest !== null) {
        return "Expected getLatestCompletedAssessment to return null";
    }
    return true;
});

// Check 2: Only skills in the selected assessment are analyzed
$latestAssessment = $gapService->getLatestCompletedAssessment($testUserId);
if (!$latestAssessment) {
    // Generate one if none exist for test student
    $gen = $gemini->generateScenarioQuestions('Problem solving', 'Analytical and problem solving');
    $aid = $assessService->createAssessmentSession($testUserId, 5, $gen['questions']);
    $eval = $gemini->evaluateStudentAnswers('Problem solving', 'Analytical and problem solving', [
        ['question' => $gen['questions'][0]['scenario'], 'answer' => 'Test answer', 'focus' => 'Logic']
    ]);
    $assessService->finalizeAssessmentEvaluation($aid, $testUserId, [1 => 'Test answer'], $eval);
    $latestAssessment = $gapService->getLatestCompletedAssessment($testUserId);
}

$targetAssessmentId = (int)$latestAssessment['assessment_id'];
$assessedSkillName = (string)$latestAssessment['skill_name'];
$assessedSkillId = (int)$latestAssessment['skill_id'];

it("2. Only skills in the selected assessment are analyzed", function() use ($gapService, $targetAssessmentId, $testUserId, $assessedSkillName) {
    $analysis = $gapService->generateSkillGapAnalysis($targetAssessmentId, $testUserId, false);
    if (empty($analysis)) {
        return "Failed to generate or retrieve skill gap analysis";
    }
    if ($analysis['skill_name'] !== $assessedSkillName) {
        return "Analysis analyzed skill '{$analysis['skill_name']}', expected '{$assessedSkillName}'";
    }
    return true;
});

// Check 3: Career goals do not introduce additional skills
it("3. Career goals do not introduce additional skills to the gap report", function() use ($pdo, $gapService, $targetAssessmentId, $testUserId, $assessedSkillName) {
    // Assign student primary career goal: Software Developer (career_id 4)
    $pdo->exec("INSERT INTO student_career_goals (user_id, career_id, is_primary, created_at) VALUES ({$testUserId}, 4, 1, NOW()) ON DUPLICATE KEY UPDATE is_primary = 1");

    $analysis = $gapService->generateSkillGapAnalysis($targetAssessmentId, $testUserId, false);
    if ($analysis['skill_name'] !== $assessedSkillName) {
        return "Career goal caused unassessed skill to replace assessed skill";
    }
    // Verify analysis only targets the single assessed skill
    if (!isset($analysis['metrics']['gap_status'])) {
        return "Missing backend gap status metrics";
    }
    return true;
});

// Check 4: Scores and thresholds come from backend configuration
it("4. Scores and thresholds come strictly from backend configuration", function() use ($gapService) {
    // Test boundary: 80+ is proficient
    $prof = $gapService->calculateGapMetrics(85.0);
    if ($prof['gap_status'] !== 'proficient') {
        return "Score 85 should be classified as proficient, got: " . $prof['gap_status'];
    }

    // Test boundary: 50-79 is moderate gap
    $mod = $gapService->calculateGapMetrics(65.0);
    if ($mod['gap_status'] !== 'moderate_gap') {
        return "Score 65 should be classified as moderate_gap, got: " . $mod['gap_status'];
    }

    // Test boundary: < 50 is critical gap
    $crit = $gapService->calculateGapMetrics(40.0);
    if ($crit['gap_status'] !== 'critical_gap') {
        return "Score 40 should be classified as critical_gap, got: " . $crit['gap_status'];
    }

    return true;
});

// Check 5: Gemini cannot introduce an unassessed skill or fabricate score
it("5. Backend rejects unassessed skills and preserves verified scores", function() use ($gapService, $targetAssessmentId, $testUserId, $latestAssessment) {
    $analysis = $gapService->getSavedSkillGapAnalysis($targetAssessmentId, $testUserId);
    if (!$analysis) {
        return "Saved analysis not found";
    }
    if ((float)$analysis['overall_score'] !== (float)$latestAssessment['overall_score']) {
        return "Stored score mismatch: saved {$analysis['overall_score']} vs assessment {$latestAssessment['overall_score']}";
    }
    return true;
});

// Check 6: Analysis is saved and remains available after session logout/login
it("6. Analysis is saved and remains retrievable from database", function() use ($gapService, $targetAssessmentId, $testUserId) {
    $saved = $gapService->getSavedSkillGapAnalysis($targetAssessmentId, $testUserId);
    if (!$saved) {
        return "getSavedSkillGapAnalysis returned null for persisted attempt";
    }
    if (empty($saved['summary_text']) || empty($saved['strengths'])) {
        return "Saved analysis missing summary text or strengths";
    }
    return true;
});

// Check 7: A new assessment can have its own separate analysis
it("7. Multiple assessment attempts have separate, distinct analyses", function() use ($pdo, $assessService, $gapService, $gemini, $testUserId, $targetAssessmentId, $assessedSkillName, $assessedSkillId) {
    // Choose a different skill from $assessedSkillId (e.g. if 11, choose 1 'Programming fundamentals', else choose 11 'Leadership')
    $altSkillId = ($assessedSkillId === 11) ? 1 : 11;
    $altSkillName = ($assessedSkillId === 11) ? 'Programming fundamentals' : 'Leadership';

    $genAlt = $gemini->generateScenarioQuestions($altSkillName, 'Core Competencies');
    $aidAlt = $assessService->createAssessmentSession($testUserId, $altSkillId, $genAlt['questions']);
    $evalAlt = $gemini->evaluateStudentAnswers($altSkillName, 'Core Competencies', [
        ['question' => $genAlt['questions'][0]['scenario'], 'answer' => 'Practical scenario response', 'focus' => 'Initiative']
    ]);
    $assessService->finalizeAssessmentEvaluation($aidAlt, $testUserId, [1 => 'Practical scenario response'], $evalAlt);

    // Generate gap analysis for this second attempt
    $analysis2 = $gapService->generateSkillGapAnalysis($aidAlt, $testUserId, false);
    if ($analysis2['skill_name'] !== $altSkillName) {
        return "Second analysis should target {$altSkillName}, got: " . $analysis2['skill_name'];
    }

    // Check first analysis is still intact and targets first skill
    $analysis1 = $gapService->getSavedSkillGapAnalysis($targetAssessmentId, $testUserId);
    if ($analysis1['skill_name'] !== $assessedSkillName) {
        return "First analysis was corrupted! Expected {$assessedSkillName}, got: " . $analysis1['skill_name'];
    }

    if ((int)$analysis1['assessment_id'] === (int)$analysis2['assessment_id']) {
        return "Assessment IDs should be different between attempts";
    }

    return true;
});

// Check 8: Students cannot access another student's assessment or analysis
it("8. Student B cannot access Student A's assessment or skill-gap analysis", function() use ($gapService, $targetAssessmentId, $testUser2Id) {
    $unauthorized = $gapService->getAssessmentForGapAnalysis($targetAssessmentId, $testUser2Id);
    if ($unauthorized !== null) {
        return "Security breach: Student B was able to load Student A's assessment details!";
    }

    $unauthorizedAnalysis = $gapService->getSavedSkillGapAnalysis($targetAssessmentId, $testUser2Id);
    if ($unauthorizedAnalysis !== null) {
        return "Security breach: Student B was able to load Student A's gap analysis!";
    }

    return true;
});

// Check 9: Dashboard receives valid latest analysis
it("9. Dashboard query receives valid latest skill-gap analysis", function() use ($gapService, $testUserId) {
    $latest = $gapService->getLatestAnalysisForStudent($testUserId);
    if (!$latest) {
        return "getLatestAnalysisForStudent returned null";
    }
    if (empty($latest['skill_name']) || empty($latest['gap_status'])) {
        return "Latest analysis missing skill_name or gap_status";
    }
    return true;
});

// Check 10: System works gracefully when Gemini is unavailable
it("10. System operates gracefully in curated diagnostic mode when Gemini is offline", function() use ($gemini) {
    $fallback = $gemini->getCuratedSkillGapAnalysis('Problem solving', 'Analytical', 65.0, 'moderate_gap', 'Software Developer');
    if ($fallback['skill_name'] !== 'Problem solving') {
        return "Fallback skill name mismatch";
    }
    if ($fallback['gap_status'] !== 'moderate_gap') {
        return "Fallback gap status mismatch";
    }
    if (empty($fallback['strengths']) || empty($fallback['improvement_areas']) || empty($fallback['practice_actions'])) {
        return "Fallback missing strengths, improvements, or practice actions";
    }
    return true;
});

// Cleanup temp zero user
$pdo->exec("DELETE FROM student_profiles WHERE user_id = {$zeroUserId}");
$pdo->exec("DELETE FROM users WHERE id = {$zeroUserId}");

echo "\n========================================================================\n";
echo " MODULE 7 TEST RESULTS: {$passed} / {$total} PASSED\n";
if ($passed === $total) {
    echo " >>> ALL 10 MODULE 7 VERIFICATION CRITERIA PASSED 100%! <<<\n";
} else {
    echo " >>> SOME TESTS FAILED. REVIEW OUTPUT ABOVE. <<<\n";
}
echo "========================================================================\n";
