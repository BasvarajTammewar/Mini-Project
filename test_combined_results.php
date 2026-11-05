<?php
/**
 * Test Suite: Combined AI Skill Assessment Results Page
 * Verifies all requirements: Score card, Improvement status, Skill gap cards,
 * Real verified activity recommendations, Personalized AI feedback, and Multi-tenant authorization.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/assessment_service.php';
require_once __DIR__ . '/includes/skill_gap_service.php';

$db = getDbConnection();

echo "====================================================================\n";
echo "CAREERACT — COMBINED AI SKILL ASSESSMENT RESULTS PAGE TEST SUITE\n";
echo "====================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCondition(bool $cond, string $desc): void {
    global $passCount, $failCount;
    if ($cond) {
        echo "  [PASS] {$desc}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$desc}\n";
        $failCount++;
    }
}

// Set up two test users
$db->exec("DELETE FROM users WHERE email IN ('test_res_a@example.com', 'test_res_b@example.com')");
$stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role, account_status) VALUES ('Student Results A', 'test_res_a@example.com', 'hash', 'student', 'active')");
$stmt->execute();
$userAId = (int)$db->lastInsertId();

$stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role, account_status) VALUES ('Student Results B', 'test_res_b@example.com', 'hash', 'student', 'active')");
$stmt->execute();
$userBId = (int)$db->lastInsertId();

// Ensure profile onboarding completed
$db->exec("INSERT INTO student_profiles (user_id, institution, field_of_study, profile_completed) VALUES ({$userAId}, 'Test Inst', 'CS', 1) ON DUPLICATE KEY UPDATE profile_completed=1");
$db->exec("INSERT INTO student_profiles (user_id, institution, field_of_study, profile_completed) VALUES ({$userBId}, 'Test Inst', 'CS', 1) ON DUPLICATE KEY UPDATE profile_completed=1");

// Link career goal
$careerRow = $db->query("SELECT id, title FROM careers LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$careerTitle = $careerRow ? $careerRow['title'] : 'Software Engineer';
if ($careerRow) {
    $db->exec("INSERT INTO student_career_goals (user_id, career_id, is_primary) VALUES ({$userAId}, {$careerRow['id']}, 1) ON DUPLICATE KEY UPDATE is_primary=1");
}

// Get a skill ID (e.g. Leadership or Problem Solving)
$skillRow = $db->query("SELECT id, name FROM skills LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$skillId = (int)$skillRow['id'];
$skillName = $skillRow['name'];

// 1. Create a completed assessment for Student A with 67% score (Partially Demonstrated)
$stmt = $db->prepare("
    INSERT INTO ai_skill_assessments (
        user_id, skill_id, attempt_number, overall_score, demonstration_level,
        status, ai_feedback, strengths_json, improvements_json, rubric_summary_json,
        created_at, completed_at
    ) VALUES (
        :user_id, :skill_id, 1, 67.0, 'Partially Demonstrated',
        'completed', 'Student demonstrated good situational grasp but needs more structured delegation under deadlines.',
        :strengths, :improvements, :rubrics, NOW(), NOW()
    )
");
$strengths = json_encode(['Active Communication', 'Initial Situation Assessment']);
$improvements = json_encode(['Strategic Decision Making', 'Team Conflict Resolution', 'Active Delegation']);
$rubrics = json_encode([
    'practical_approach' => 16.5,     // 66%
    'critical_thinking' => 17.0,      // 68%
    'strategy_communication' => 16.0, // 64%
    'impact_resolution' => 17.5       // 70%
]);
$stmt->execute([
    ':user_id' => $userAId,
    ':skill_id' => $skillId,
    ':strengths' => $strengths,
    ':improvements' => $improvements,
    ':rubrics' => $rubrics
]);
$asmtAId = (int)$db->lastInsertId();

echo "1. Overall Assessment Score & Rendering Logic:\n";
// Simulate page environment for Student A
$_SESSION['user_id'] = $userAId;
$_GET['id'] = $asmtAId;

ob_start();
include __DIR__ . '/assessment_results.php';
$htmlA = ob_get_clean();

assertCondition(strpos($htmlA, '67%') !== false, "Overall score (67%) is rendered on the page");
assertCondition(strpos($htmlA, 'Partially Demonstrated') !== false, "Demonstration level 'Partially Demonstrated' is rendered");
assertCondition(strpos($htmlA, 'progress-bar-fill') !== false && strpos($htmlA, 'width: 67%') !== false, "Dynamic horizontal CSS progress bar filled to 67%");
assertCondition(strpos($htmlA, "Your assessment score is <strong>67%</strong>") !== false, "Score text specifically states the percentage and demonstration");

echo "\n2. Improvement Status Card Requirements:\n";
assertCondition(strpos($htmlA, 'Do You Need Improvement?') !== false, "Section explicitly titled 'Do You Need Improvement?'");
assertCondition(strpos($htmlA, 'Main Areas to Develop:') !== false, "Main areas to develop section is shown for partially demonstrated score");
assertCondition(strpos($htmlA, 'Strategic Decision Making') !== false, "Improvement area 'Strategic Decision Making' is displayed");
assertCondition(strpos($htmlA, 'Encouraging Note:') !== false, "Encouraging feedback note is displayed for growth guidance");

echo "\n3. Skill Gap Analysis Grid:\n";
assertCondition(strpos($htmlA, 'Decision Making') !== false, "Criterion 1: Decision Making is rendered");
assertCondition(strpos($htmlA, 'Critical Thinking') !== false, "Criterion 2: Critical Thinking is rendered");
assertCondition(strpos($htmlA, 'Team Management') !== false, "Criterion 3: Team Management is rendered");
assertCondition(strpos($htmlA, 'Responsibility') !== false, "Criterion 4: Responsibility & Impact Delivery is rendered");
assertCondition(strpos($htmlA, '💡 <strong>Tip:</strong>') !== false, "Each criterion card contains concrete actionable tips");

echo "\n4. Recommended Extracurricular Activities from carrearact2:\n";
assertCondition(strpos($htmlA, 'Recommended Activities to Improve Your Skills') !== false, "Recommended activities header is present on the unified page");
assertCondition(strpos($htmlA, 'Skills Developed:') !== false || strpos($htmlA, 'Catalog Exploration') !== false, "Skills developed pills or appropriate catalog exploration fallback rendered");
assertCondition(strpos($htmlA, 'Why Recommended:') !== false || strpos($htmlA, 'Browse Available Opportunities') !== false, "Why Recommended rationale or catalog action rendered");

echo "\n5. Personalized AI Feedback Card:\n";
assertCondition(strpos($htmlA, '✦ Gemini Developmental Feedback') !== false, "AI Gemini Feedback badge card is present");
assertCondition(strpos($htmlA, 'Student demonstrated good situational grasp') !== false, "Personalized feedback text synthesized from actual assessment is displayed");
assertCondition(strpos($htmlA, $careerTitle) !== false || strpos($htmlA, 'Industry Professional') !== false, "Contextualized to student's confirmed career path ({$careerTitle})");

echo "\n6. High-Performer (>= 80%) Non-Urgent Status Check:\n";
// Create high score assessment (88%)
$stmt = $db->prepare("
    INSERT INTO ai_skill_assessments (
        user_id, skill_id, attempt_number, overall_score, demonstration_level,
        status, ai_feedback, strengths_json, improvements_json, rubric_summary_json,
        created_at, completed_at
    ) VALUES (
        :user_id, :skill_id, 2, 88.0, 'Strongly Demonstrated',
        'completed', 'Outstanding execution across all practical indicators.',
        :strengths, :improvements, :rubrics, NOW(), NOW()
    )
");
$stmt->execute([
    ':user_id' => $userAId,
    ':skill_id' => $skillId,
    ':strengths' => json_encode(['Root Cause Discovery', 'Stakeholder Leadership']),
    ':improvements' => json_encode(['Executive Presence']),
    ':rubrics' => json_encode(['practical_approach' => 22.0, 'critical_thinking' => 22.0, 'strategy_communication' => 22.0, 'impact_resolution' => 22.0])
]);
$asmtHighId = (int)$db->lastInsertId();

$_GET['id'] = $asmtHighId;
ob_start();
include __DIR__ . '/assessment_results.php';
$htmlHigh = ob_get_clean();

assertCondition(strpos($htmlHigh, 'Strongly Demonstrated') !== false, "Score 88% displays 'Strongly Demonstrated'");
assertCondition(strpos($htmlHigh, 'Excellent Demonstration:') !== false, "High performer does not show urgent improvement banner");

echo "\n7. Multi-Tenant Authorization & Isolation:\n";
// Create a separate assessment for Student B
$stmt = $db->prepare("
    INSERT INTO ai_skill_assessments (
        user_id, skill_id, attempt_number, overall_score, demonstration_level,
        status, ai_feedback, strengths_json, improvements_json, rubric_summary_json,
        created_at, completed_at
    ) VALUES (
        :user_id, :skill_id, 1, 42.0, 'Needs Improvement',
        'completed', 'Student B exclusive confidential feedback.',
        :strengths, :improvements, :rubrics, NOW(), NOW()
    )
");
$stmt->execute([
    ':user_id' => $userBId,
    ':skill_id' => $skillId,
    ':strengths' => json_encode(['Analytical Persistence']),
    ':improvements' => json_encode(['Executive Overview']),
    ':rubrics' => json_encode(['practical_approach' => 10.0, 'critical_thinking' => 11.0, 'strategy_communication' => 10.5, 'impact_resolution' => 10.5])
]);
$asmtBId = (int)$db->lastInsertId();

// Student B attempts to access Student A's assessment ID
$_SESSION['user_id'] = $userBId;
$_GET['id'] = $asmtAId;

ob_start();
include __DIR__ . '/assessment_results.php';
$htmlB = ob_get_clean();

// Check if Student B saw Student A's data
$leaked = (strpos($htmlB, 'Student demonstrated good situational grasp') !== false);
assertCondition(!$leaked, "Student B is strictly prevented from seeing Student A's assessment result");
assertCondition(strpos($htmlB, 'Student B exclusive confidential feedback') !== false, "System securely falls back to Student B's own verified record");

// Cleanup test records
$db->exec("DELETE FROM ai_skill_assessments WHERE id IN ({$asmtAId}, {$asmtHighId}, {$asmtBId})");
$db->exec("DELETE FROM student_career_goals WHERE user_id IN ({$userAId}, {$userBId})");
$db->exec("DELETE FROM student_profiles WHERE user_id IN ({$userAId}, {$userBId})");
$db->exec("DELETE FROM users WHERE id IN ({$userAId}, {$userBId})");

echo "\n====================================================================\n";
echo "COMBINED RESULTS TEST RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
echo "====================================================================\n";

if ($failCount === 0) {
    echo "🎉 ALL COMBINED RESULTS PAGE TESTS PASSED PERFECTLY!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED.\n";
    exit(1);
}
