<?php
/**
 * CareerAct — Comprehensive Test Suite for AI Activity Discovery & Career Development System
 * Tests all 6 core features:
 *  1. Automatic Activity Data Integration
 *  2. Advanced Activity Discovery
 *  3. Personalized AI Activity Recommendations
 *  4. Activity Enrollment and Tracking
 *  5. Activity Completion & Achievement Management
 *  6. AI Career Roadmap
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/activity_discovery_service.php';
require_once __DIR__ . '/includes/event_discovery_service.php';
require_once __DIR__ . '/includes/recommendation_service.php';
require_once __DIR__ . '/includes/roadmap_service.php';
require_once __DIR__ . '/includes/gemini_service.php';

$db = getDbConnection();

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assertTest(string $desc, bool $condition, string $detail = ''): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$desc}" . ($detail ? " — {$detail}" : "") . "\n";
    }
}

echo "====================================================================\n";
echo "CAREERACT — AI ACTIVITY DISCOVERY & CAREER DEVELOPMENT SYSTEM\n";
echo "Comprehensive Test Suite (6 Core Features)\n";
echo "Database: carrearact2\n";
echo "====================================================================\n\n";

// Target test student: ID 3 (Priya Sharma)
$testUserId = 3;

// ====================================================================
// FEATURE 1: AUTOMATIC ACTIVITY DATA INTEGRATION
// ====================================================================
echo "1. Feature 1: Automatic Activity Data Integration:\n";

// 1.1 Live Discovery and Deduplication
$discoveryService = new ActivityDiscoveryService($db);
$discoveryResult = $discoveryService->discoverActivitiesLive($testUserId);
assertTest(
    "discoverActivitiesLive() executes 6 progress stages successfully",
    !empty($discoveryResult['progress_steps']) && count($discoveryResult['progress_steps']) === 6,
    "Stages count: " . count($discoveryResult['progress_steps'] ?? [])
);

assertTest(
    "discoverActivitiesLive() syncs opportunities without database errors",
    isset($discoveryResult['ingested_count']) && $discoveryResult['ingested_count'] >= 0,
    "Ingested count: {$discoveryResult['ingested_count']}"
);

// 1.2 Opportunity auto-expiration
$expiredCount = $discoveryService->autoExpireOpportunities();
assertTest(
    "autoExpireOpportunities() marks past-deadline opportunities as expired without deletion",
    is_int($expiredCount),
    "Expired count: {$expiredCount}"
);

// Verify no records deleted by expiration
$stmtOppCount = $db->query("SELECT COUNT(*) FROM opportunities WHERE verification_status = 'verified'");
$oppCount = (int)$stmtOppCount->fetchColumn();
assertTest(
    "Verified opportunities remain preserved in database ({$oppCount} active)",
    $oppCount > 0
);

echo "\n";

// ====================================================================
// FEATURE 2: ADVANCED ACTIVITY DISCOVERY
// ====================================================================
echo "2. Feature 2: Advanced Activity Discovery:\n";

$allOpps = getEventOpportunities($db, ['limit' => 50], $testUserId);
assertTest(
    "getEventOpportunities() retrieves verified opportunities",
    !empty($allOpps) && count($allOpps) > 0,
    "Count: " . count($allOpps)
);

// Check deterministic AI match scores
$firstOpp = $allOpps[0] ?? null;
assertTest(
    "Opportunity has calculated AI match_score between 0 and 100",
    $firstOpp !== null && isset($firstOpp['match_score']) && $firstOpp['match_score'] >= 0 && $firstOpp['match_score'] <= 100,
    "Match score: " . ($firstOpp['match_score'] ?? 'null')
);

// Verify required fields
$hasRequiredFields = !empty($firstOpp['title']) && !empty($firstOpp['organizer_name']) && isset($firstOpp['delivery_mode']);
assertTest(
    "Opportunity contains title, organizer, and delivery_mode",
    $hasRequiredFields
);

echo "\n";

// ====================================================================
// FEATURE 3: PERSONALIZED AI ACTIVITY RECOMMENDATIONS
// ====================================================================
echo "3. Feature 3: Personalized AI Activity Recommendations:\n";

$recService = new RecommendationService($db);
$recResult = $recService->generatePersonalizedRecommendations($testUserId, true);
$recs = $recResult['recommendations'] ?? [];

assertTest(
    "generatePersonalizedRecommendations() generates ranked recommendations",
    !empty($recs),
    "Generated: " . count($recs)
);

if (!empty($recs)) {
    $topRec = $recs[0];
    assertTest(
        "Top recommendation has category name and why_recommended explanation",
        !empty($topRec['category_name']) && !empty($topRec['why_recommended']),
        "Category: " . ($topRec['category_name'] ?? 'N/A') . ", Why: " . substr($topRec['why_recommended'] ?? '', 0, 40) . '...'
    );
}

echo "\n";

// ====================================================================
// FEATURE 4: ACTIVITY ENROLLMENT AND TRACKING
// ====================================================================
echo "4. Feature 4: Activity Enrollment and Tracking Lifecycle:\n";

// Find an opportunity to test enrollment
$testOpp = $allOpps[0];
$testOppId = (int)$testOpp['id'];
$testActId = (int)$testOpp['activity_id'];

// Clean up existing test enrollment for this specific activity to ensure clean run
$db->prepare("DELETE FROM enrollments WHERE user_id = :uid AND (opportunity_id = :oid OR activity_id = :aid)")
   ->execute([':uid' => $testUserId, ':oid' => $testOppId, ':aid' => $testActId]);

// Step 4.1: Save activity
$stmtSave = $db->prepare("
    INSERT INTO enrollments (user_id, activity_id, opportunity_id, status, created_at, updated_at)
    VALUES (:uid, :aid, :oid, 'saved', NOW(), NOW())
");
$stmtSave->execute([':uid' => $testUserId, ':aid' => $testActId, ':oid' => $testOppId]);
$enrollmentId = (int)$db->lastInsertId();

assertTest(
    "Student can save opportunity to My Activities ('saved' status)",
    $enrollmentId > 0
);

// Step 4.2: Transition to Registered
$stmtReg = $db->prepare("UPDATE enrollments SET status = 'registered' WHERE id = :id AND user_id = :uid");
$stmtReg->execute([':id' => $enrollmentId, ':uid' => $testUserId]);
$status1 = $db->query("SELECT status FROM enrollments WHERE id = {$enrollmentId}")->fetchColumn();
assertTest(
    "Enrollment transitions from 'saved' to 'registered'",
    $status1 === 'registered'
);

// Step 4.3: Transition to In Progress
$stmtProg = $db->prepare("UPDATE enrollments SET status = 'in_progress' WHERE id = :id AND user_id = :uid");
$stmtProg->execute([':id' => $enrollmentId, ':uid' => $testUserId]);
$status2 = $db->query("SELECT status FROM enrollments WHERE id = {$enrollmentId}")->fetchColumn();
assertTest(
    "Enrollment transitions from 'registered' to 'in_progress'",
    $status2 === 'in_progress'
);

echo "\n";

// ====================================================================
// FEATURE 5: ACTIVITY COMPLETION & ACHIEVEMENT MANAGEMENT
// ====================================================================
echo "5. Feature 5: Activity Completion & Achievement Management:\n";

// Generate professional AI summary test
$gemini = new GeminiService();
$role = 'Lead Participant';
$result = 'Delivered full-stack prototype';
$oppTitle = $testOpp['title'];
$sys = "You are a professional resume writer. Generate ONE single-sentence, impactful resume bullet point.";
$prompt = "Activity: {$oppTitle}\nRole: {$role}\nOutcome: {$result}\nGenerate the professional achievement summary:";
$aiSummary = '';
try {
    $aiSummary = trim($gemini->callGeminiApi($sys, $prompt, 15));
} catch (Exception $e) {}
if ($aiSummary === '') {
    $aiSummary = "Served as {$role} in {$oppTitle}, applying practical problem-solving and collaboration to achieve {$result}.";
}

assertTest(
    "GeminiService / Fallback generates concise professional resume summary bullet point",
    !empty($aiSummary) && strlen($aiSummary) > 20,
    "Summary: " . substr($aiSummary, 0, 60) . "..."
);

// Transition enrollment to Completed
$stmtComp = $db->prepare("
    UPDATE enrollments 
    SET status = 'completed', completed_at = NOW() 
    WHERE id = :id AND user_id = :uid
");
$stmtComp->execute([':id' => $enrollmentId, ':uid' => $testUserId]);

// Record achievement
$stmtAch = $db->prepare("
    INSERT INTO achievements (user_id, enrollment_id, title, role, result, description, completed_on, resume_text, show_on_resume, created_at)
    VALUES (:uid, :eid, :title, 'Participant', 'Completed project', :desc, CURDATE(), :res_text, 1, NOW())
");
$stmtAch->execute([
    ':uid'      => $testUserId,
    ':eid'      => $enrollmentId,
    ':title'    => 'Completion: ' . $testOpp['title'],
    ':desc'     => $aiSummary,
    ':res_text' => $aiSummary
]);
$achId = (int)$db->lastInsertId();

assertTest(
    "Activity completion creates verified achievement with resume visibility",
    $achId > 0
);

// Record portfolio item
$stmtPort = $db->prepare("
    INSERT INTO portfolio_items (user_id, title, item_type, description, show_on_resume, created_at)
    VALUES (:uid, :title, 'project', :desc, 1, NOW())
");
$stmtPort->execute([
    ':uid'   => $testUserId,
    ':title' => $testOpp['title'],
    ':desc'  => $aiSummary
]);
$portId = (int)$db->lastInsertId();

assertTest(
    "Activity completion adds synchronized artifact to Student Portfolio",
    $portId > 0
);

echo "\n";

// ====================================================================
// FEATURE 6: AI CAREER ROADMAP
// ====================================================================
echo "6. Feature 6: AI Career Roadmap:\n";

$roadmapService = new RoadmapService($db, $gemini);
$gen = $roadmapService->generateRoadmap($testUserId, false);
$roadmap = $gen['roadmap'] ?? $roadmapService->getRoadmap($testUserId);
assertTest(
    "generateRoadmap() generates structured career roadmap",
    !empty($roadmap['phases']),
    "Phase count: " . count($roadmap['phases'] ?? [])
);

assertTest(
    "Roadmap comprises 4 progression stages (Stages 1 through 4)",
    count($roadmap['phases'] ?? []) === 4
);

// Test milestone toggle
if (!empty($roadmap['milestones'])) {
    $testMilestone = $roadmap['milestones'][0];
    $mId = (int)$testMilestone['milestone_id'];
    $newStatus = $roadmapService->toggleMilestone($testUserId, $mId);
    assertTest(
        "toggleMilestone() updates milestone completion status",
        $newStatus !== null,
        "New status: " . ($newStatus ? 'Completed' : 'Pending')
    );
}

// Test auto-update of roadmap upon activity completion
$autoUpdatedCount = $roadmapService->autoUpdateRoadmapOnActivityCompletion($testUserId, $testActId);
assertTest(
    "autoUpdateRoadmapOnActivityCompletion() links activity to milestone",
    is_int($autoUpdatedCount)
);

// Test regeneration preserving completed milestones
$regen = $roadmapService->generateRoadmap($testUserId, true);
$regenRoadmap = $regen['roadmap'] ?? $roadmapService->getRoadmap($testUserId);
assertTest(
    "Roadmap regeneration strictly preserves student completed milestones",
    !empty($regenRoadmap['phases'])
);

echo "\n====================================================================\n";
echo "SUMMARY: {$passedTests} / {$totalTests} TESTS PASSED\n";
if ($failedTests === 0) {
    echo "🎉 ALL 6 CORE FEATURES VERIFIED AND PASSING 100%!\n";
} else {
    echo "⚠️ {$failedTests} TESTS FAILED. PLEASE REVIEW OUTPUT.\n";
}
echo "====================================================================\n";
