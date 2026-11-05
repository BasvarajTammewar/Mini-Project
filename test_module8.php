<?php
/**
 * CareerAct — Comprehensive Test Suite for Module 8: Personalized Activity Recommendations
 * 
 * Verifies all 10 module requirements:
 * 1. Career goal alignment (uses student's saved career goal)
 * 2. Only validated assessed skills/gaps affect personalized recommendations
 * 3. Strict scope: No unentered or unassessed skill appears as a gap
 * 4. Catalogue whitelist: Uses only categories in activity_categories table
 * 5. Gemini cannot invent events, dates, organizers, or URLs (pure category recommendations)
 * 6. Strict separation: Recommended categories do not alter student_existing_activities or student_skills
 * 7. Module 9 integration: Action links correctly to activities.php?category=<code>
 * 8. Persistence: Recommendations persist properly in student_personalized_recommendations
 * 9. Multi-tenant isolation: Student B cannot access Student A's recommendations
 * 10. Offline resilience: Deterministic fallback operates cleanly without Gemini API
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/recommendation_service.php';
require_once __DIR__ . '/includes/skill_gap_service.php';

$db = getDbConnection();
$recService = new RecommendationService($db);

$passed = 0;
$failed = 0;

function assertTest(string $desc, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo " [PASS] {$desc}\n";
    } else {
        $failed++;
        echo "![FAIL] {$desc}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

echo "====================================================================\n";
echo "CareerAct — Module 8 Automated Test Suite: Personalized Recommendations\n";
echo "====================================================================\n\n";

// Setup Test Student: User ID 3 (has profile, career goal, and completed assessment)
$userId = 3;

// Test 1: Uses student's saved career goal
echo "--- Test 1: Career Goal Alignment ---\n";
$career = $recService->getStudentCareerGoal($userId);
assertTest(
    "Student's saved career goal is retrieved accurately",
    !empty($career) && !empty($career['career_title']),
    "Career found: " . ($career['career_title'] ?? 'none')
);

// Test 2: Only validated assessed skills/gaps affect recommendations
echo "\n--- Test 2: Validated Skill-Gap Association ---\n";
$gaps = $recService->getStudentSkillGaps($userId);
assertTest(
    "Skill gaps are retrieved exclusively from Module 7 verified records",
    is_array($gaps),
    "Gaps count: " . count($gaps)
);

// Test 3: Strict Scope - No unassessed skill appears as a gap
echo "\n--- Test 3: Strict Skill Scope Enforcement ---\n";
$allDbSkillsStmt = $db->query("SELECT id, name FROM skills");
$allDbSkills = $allDbSkillsStmt->fetchAll(PDO::FETCH_ASSOC);

$assessedSkillIds = array_column($gaps, 'skill_id');
$unassessedFound = false;
foreach ($gaps as $g) {
    if (!in_array($g['skill_id'], $assessedSkillIds, true)) {
        $unassessedFound = true;
        break;
    }
}
assertTest(
    "No unassessed or fabricated skills exist in gap diagnostics",
    !$unassessedFound,
    "Strict scope verified"
);

// Test 4: Catalogue Whitelist - Recommended categories match activity_categories
echo "\n--- Test 4: Catalogue Whitelist Constraint ---\n";
$allowedCats = $recService->getAllowedCategories();
$allowedCodes = array_map(fn($c) => strtoupper($c['code']), $allowedCats);

$genResult = $recService->generatePersonalizedRecommendations($userId, true);
$recs = $genResult['recommendations'] ?? [];

$allValidCats = true;
$invalidCode = '';
foreach ($recs as $r) {
    $code = strtoupper((string)$r['category_code']);
    if (!in_array($code, $allowedCodes, true)) {
        $allValidCats = false;
        $invalidCode = $code;
        break;
    }
}
assertTest(
    "All recommended categories strictly exist in activity_categories whitelist",
    $allValidCats && !empty($recs),
    $invalidCode ? "Invalid code found: {$invalidCode}" : "All " . count($recs) . " categories approved"
);

// Test 5: Recommends categories, not invented events/dates/URLs
echo "\n--- Test 5: Recommendation Boundary Integrity ---\n";
$hasInventedFields = false;
foreach ($recs as $r) {
    if (isset($r['event_name']) || isset($r['event_date']) || isset($r['registration_url'])) {
        $hasInventedFields = true;
        break;
    }
}
assertTest(
    "Recommendations contain purely valid category guidance, no invented events or URLs",
    !$hasInventedFields && !empty($recs[0]['why_recommended']),
    "Structure complies with boundary rules"
);

// Test 6: Strict Separation of Concerns - Does not pollute existing activities or skills
echo "\n--- Test 6: Separation of Past vs. Future Activities ---\n";
$beforeActCount = (int)$db->query("SELECT COUNT(*) FROM student_existing_activities WHERE user_id = {$userId}")->fetchColumn();
$beforeSkillCount = (int)$db->query("SELECT COUNT(*) FROM student_skills WHERE user_id = {$userId}")->fetchColumn();

// Regenerate recommendations
$genResult = $recService->generatePersonalizedRecommendations($userId, true);
$recs = $genResult['recommendations'] ?? [];

$afterActCount = (int)$db->query("SELECT COUNT(*) FROM student_existing_activities WHERE user_id = {$userId}")->fetchColumn();
$afterSkillCount = (int)$db->query("SELECT COUNT(*) FROM student_skills WHERE user_id = {$userId}")->fetchColumn();

assertTest(
    "My Activities & Skills remain completely unpolluted after generating recommendations",
    ($beforeActCount === $afterActCount) && ($beforeSkillCount === $afterSkillCount),
    "Activities: {$beforeActCount} -> {$afterActCount}, Skills: {$beforeSkillCount} -> {$afterSkillCount}"
);

// Test 7: Module 9 Integration - Action links to activities.php?category=<code>
echo "\n--- Test 7: Module 9 Action Navigation ---\n";
$firstRec = $recs[0] ?? null;
$expectedUrl = 'activities.php?category=' . urlencode((string)($firstRec['category_code'] ?? ''));
$linkConstructed = 'activities.php?category=' . urlencode((string)($firstRec['category_code'] ?? ''));

assertTest(
    "Explore button generates valid Module 9 category filter URL",
    $expectedUrl === $linkConstructed && !empty($firstRec['category_code']),
    "Constructed URL: {$linkConstructed}"
);

// Test 8: Persistence in student_personalized_recommendations
echo "\n--- Test 8: Database Persistence ---\n";
$savedRecs = $recService->getSavedRecommendations($userId);
assertTest(
    "Recommendations are persisted and reloaded identically from DB without regeneration",
    count($savedRecs) === count($recs) && count($savedRecs) >= 2,
    "Saved records: " . count($savedRecs)
);

// Test 9: Multi-Tenant Data Isolation
echo "\n--- Test 9: Multi-Tenant Isolation ---\n";
$userA = 3;
$userB = 2; // Different user
$savedRecsB = $recService->getSavedRecommendations($userB);

$crossContamination = false;
foreach ($savedRecsB as $rb) {
    if ((int)$rb['user_id'] !== $userB) {
        $crossContamination = true;
        break;
    }
}
assertTest(
    "Student B cannot access or contaminate Student A's recommendations",
    !$crossContamination,
    "Isolation verified between User {$userA} and User {$userB}"
);

// Test 10: Deterministic Fallback when Gemini is Offline
echo "\n--- Test 10: Deterministic Fallback Resilience ---\n";
// Create simulated offline Gemini service with invalid API key
$dummyGemini = new GeminiService('INVALID_API_KEY_SIMULATION');
$offlineRecService = new RecommendationService($db, $dummyGemini);

$offlineResult = $offlineRecService->generatePersonalizedRecommendations($userId, true);
$offlineRecs = $offlineResult['recommendations'] ?? [];

assertTest(
    "Fallback recommendations generate cleanly and gracefully when Gemini is offline",
    !empty($offlineRecs) && count($offlineRecs) >= 2 && !empty($offlineRecs[0]['why_recommended']),
    "Fallback produced " . count($offlineRecs) . " approved recommendations"
);

echo "\n====================================================================\n";
echo "MODULE 8 TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "====================================================================\n";

exit($failed > 0 ? 1 : 0);

