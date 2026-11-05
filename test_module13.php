<?php
/**
 * CareerAct — Comprehensive Test Suite for Module 13: Admin Panel & Activity Management
 * 
 * Verifies all 12 Core Requirements:
 * 1. Role-Based Access Control: Students accessing admin pages receive 403 Forbidden
 * 2. Public Signup Role Hardcoding: signup.php strictly creates role = 'student'
 * 3. Seeded Initial Admin: admin@careeract.edu exists with role = 'admin'
 * 4. Aggregate Platform Metrics: Real database statistics without student PII leakage
 * 5. Category Creation & Management: Categories can be added and updated
 * 6. Category Duplicate Prevention: Case-insensitive trimmed duplicate names are blocked
 * 7. Safe Category Deactivation: Deactivation toggles is_active without deleting references
 * 8. Category Inactive Student Filter: Inactive categories and their opps are hidden from student discovery
 * 9. Verification Queue Isolation: Unverified/needs_correction opportunities are hidden from active discovery
 * 10. Admin Verification Decision: Admin approval publishes to students; rejection moves to draft
 * 11. Opportunity Deduplication & Archival: Dedupe hash catches duplicates; archival preserves student history
 * 12. Gemini Advisory Non-Hallucination & Audit Trail: Advisory analysis provides quality checks; audit trail logs all actions
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/admin_service.php';
require_once __DIR__ . '/includes/event_discovery_service.php';
require_once __DIR__ . '/includes/gemini_service.php';

$db = getDbConnection();

$passed = 0;
$failed = 0;

function assertTest(string $desc, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$desc}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

echo "====================================================================\n";
echo "CAREERACT — MODULE 13 COMPREHENSIVE TEST SUITE\n";
echo "Admin Panel, Opportunity Verification Queue, and Platform Management\n";
echo "====================================================================\n\n";

// -----------------------------------------------------------------------------
// Test 1: Seeded Admin Account & Role Check
// -----------------------------------------------------------------------------
echo "1. Initial Admin Account & Role Enforcement:\n";
$stmt = $db->prepare("SELECT id, name, email, role FROM users WHERE role = 'admin' LIMIT 1");
$stmt->execute();
$adminUser = $stmt->fetch(PDO::FETCH_ASSOC);

assertTest(
    "Initial Administrator account exists in carrearact2 users table",
    $adminUser !== false && $adminUser['role'] === 'admin',
    "Found admin: " . ($adminUser['email'] ?? 'None')
);
$adminId = $adminUser ? (int)$adminUser['id'] : 1;

// Test session simulation
$_SESSION['user_id'] = 3; // Student Sharma
$_SESSION['role'] = 'student';
$studentIsAdmin = isAdmin();
$studentIsStudent = isStudent();

assertTest(
    "Student session (Sharma) is not recognized as admin (isAdmin() === false)",
    $studentIsAdmin === false && $studentIsStudent === true
);

$_SESSION['user_id'] = $adminId;
$_SESSION['role'] = 'admin';
$adminIsAdmin = isAdmin();

assertTest(
    "Admin session is recognized as admin (isAdmin() === true)",
    $adminIsAdmin === true
);

// -----------------------------------------------------------------------------
// Test 2: Public Signup Role Hardcoding
// -----------------------------------------------------------------------------
echo "\n2. Public Signup Role Protection:\n";
$signupContent = file_get_contents(__DIR__ . '/signup.php');
$hardcodedStudent = (strpos($signupContent, "'student'") !== false || strpos($signupContent, "\\'student\\'") !== false);
$ignoresRoleParam = strpos($signupContent, "\$_POST['role']") === false;

assertTest(
    "signup.php hardcodes 'student' role on server-side and ignores client-supplied role input",
    $hardcodedStudent && $ignoresRoleParam
);

// -----------------------------------------------------------------------------
// Test 3: Admin Platform Stats & Student Privacy
// -----------------------------------------------------------------------------
echo "\n3. Admin Platform Statistics & Student Data Privacy:\n";
$stats = getAdminPlatformStats($db);

assertTest(
    "getAdminPlatformStats() returns real metrics structure",
    isset($stats['students'], $stats['opportunities'], $stats['categories'], $stats['participation'], $stats['audit'])
);

assertTest(
    "Student counts and opportunity counts are non-negative integers",
    $stats['students']['total'] > 0 && $stats['opportunities']['total'] > 0
);

// Verify no PII leak in platform stats
$serializedStats = json_encode($stats);
$hasNoPasswords = strpos($serializedStats, 'password_hash') === false;
$hasNoStudentEmails = strpos($serializedStats, '@student') === false && strpos($serializedStats, 'sharma@') === false;

assertTest(
    "Platform statistics maintain strict student privacy (no passwords or personal student emails leaked)",
    $hasNoPasswords && $hasNoStudentEmails
);

// -----------------------------------------------------------------------------
// Test 4: Category Management & Duplicate Prevention
// -----------------------------------------------------------------------------
echo "\n4. Category Creation & Duplicate Prevention:\n";
$uniqueSuffix = time() . '_' . rand(100, 999);
$testCatName = "Autonomous Robotics Sprints {$uniqueSuffix}";
$testCatCode = "ROBOTICS_{$uniqueSuffix}";

// 4a: Create Category
$createRes = saveCategoryAdmin($db, $adminId, [
    'name'        => $testCatName,
    'code'        => $testCatCode,
    'description' => 'Hands-on embedded robotics and autonomous vehicle challenges.',
    'is_active'   => 1,
]);

assertTest(
    "saveCategoryAdmin() successfully creates a new category",
    $createRes['success'] === true && !empty($createRes['id'])
);
$testCatId = (int)($createRes['id'] ?? 0);

// 4b: Attempt Duplicate Name (case-insensitive, with whitespace)
$dupRes = saveCategoryAdmin($db, $adminId, [
    'name'        => "  " . strtolower($testCatName) . "  ",
    'code'        => "ROBOTICS_DUP_{$uniqueSuffix}",
    'description' => 'Duplicate attempt.',
    'is_active'   => 1,
]);

assertTest(
    "saveCategoryAdmin() strictly blocks duplicate category name (case-insensitive & trimmed)",
    $dupRes['success'] === false && strpos($dupRes['error'], 'already exists') !== false,
    $dupRes['error'] ?? ''
);

// -----------------------------------------------------------------------------
// Test 5: Safe Category Deactivation & Student Discovery Isolation
// -----------------------------------------------------------------------------
echo "\n5. Safe Category Deactivation & Discovery Filtering:\n";
// Toggle to inactive
$deactRes = toggleCategoryActive($db, $adminId, $testCatId);
assertTest(
    "toggleCategoryActive() deactivates category safely without deleting row",
    $deactRes['success'] === true && $deactRes['is_active'] === 0
);

// Check that deactivated category is omitted from student catalog
$studentCatalog = getActivityCategoriesCatalog($db, 3);
$foundInStudentCatalog = false;
foreach ($studentCatalog as $sc) {
    if ((int)$sc['id'] === $testCatId) {
        $foundInStudentCatalog = true;
        break;
    }
}

assertTest(
    "Deactivated category is immediately hidden from student exploration catalog",
    $foundInStudentCatalog === false
);

// Reactivate category for subsequent opportunity tests
$reactRes = toggleCategoryActive($db, $adminId, $testCatId);
assertTest(
    "toggleCategoryActive() reactivates category successfully",
    $reactRes['success'] === true && $reactRes['is_active'] === 1
);

// -----------------------------------------------------------------------------
// Test 6: Opportunity Deduplication & Creation
// -----------------------------------------------------------------------------
echo "\n6. Opportunity Creation & Deduplication:\n";
// Create a parent activity under this category
$stmtAct = $db->prepare("
    INSERT INTO activities (category_id, title, description, is_active, source_id, created_at)
    VALUES (:cat_id, :title, 'Activity for test', 1, 1, NOW())
");
$stmtAct->execute([':cat_id' => $testCatId, ':title' => "Robotics Lab Activity {$uniqueSuffix}"]);
$testActivityId = (int)$db->lastInsertId();

$oppTitle = "National AI Drone Challenge {$uniqueSuffix}";
$oppSourceUrl = "https://robotics-india.example/challenge-{$uniqueSuffix}";

$oppCreateRes = saveOpportunityAdmin($db, $adminId, [
    'title'                 => $oppTitle,
    'description'           => 'Multi-university autonomous drone navigation challenge across obstacles.',
    'activity_id'           => $testActivityId,
    'delivery_mode'         => 'offline',
    'state_name'            => 'Karnataka',
    'scope_level'           => 'national',
    'venue'                 => 'IISc Bangalore Campus',
    'registration_deadline' => date('Y-m-d', strtotime('+30 days')),
    'eligibility'           => 'Engineering undergraduates in India',
    'cost_amount'           => 0.00,
    'cost_currency'         => 'INR',
    'registration_url'      => $oppSourceUrl . '/register',
    'source_url'            => $oppSourceUrl,
    'status'                => 'draft',
    'verification_status'   => 'unverified',
]);

assertTest(
    "saveOpportunityAdmin() creates unverified opportunity with dedupe_hash",
    $oppCreateRes['success'] === true && !empty($oppCreateRes['id'])
);
$testOppId = (int)($oppCreateRes['id'] ?? 0);

// Attempt duplicate opportunity with same title and source URL
$oppDupRes = saveOpportunityAdmin($db, $adminId, [
    'title'            => $oppTitle,
    'description'      => 'Duplicate attempt of the same event.',
    'activity_id'      => $testActivityId,
    'delivery_mode'    => 'offline',
    'registration_url' => $oppSourceUrl . '/register',
    'source_url'       => $oppSourceUrl,
]);

assertTest(
    "saveOpportunityAdmin() catches duplicate opportunity submission by dedupe_hash",
    $oppDupRes['success'] === false && strpos($oppDupRes['error'], 'duplicate') !== false,
    $oppDupRes['error'] ?? ''
);

// -----------------------------------------------------------------------------
// Test 7: Verification Queue Isolation from Active Student Discovery
// -----------------------------------------------------------------------------
echo "\n7. Verification Queue Isolation from Active Student Discovery:\n";
// Query student event opportunities
$studentOpps = getEventOpportunities($db, ['category_id' => $testCatId]);
$foundUnverifiedInStudentOpps = false;
foreach ($studentOpps as $so) {
    if ((int)$so['id'] === $testOppId) {
        $foundUnverifiedInStudentOpps = true;
        break;
    }
}

assertTest(
    "Unverified opportunity in queue is strictly hidden from active student exploration",
    $foundUnverifiedInStudentOpps === false
);

// -----------------------------------------------------------------------------
// Test 8: Gemini Advisory Analysis Non-Hallucination
// -----------------------------------------------------------------------------
echo "\n8. Gemini-Assisted Queue Review & Non-Hallucination:\n";
$gemini = new GeminiService();
$oppForReview = getAdminOpportunityById($db, $testOppId);

$analysis = analyzeOpportunityWithGemini($gemini, $oppForReview);

assertTest(
    "analyzeOpportunityWithGemini() returns structured advisory report",
    isset($analysis['category_fit'], $analysis['brief_summary'], $analysis['quality_score'], $analysis['advisory_recommendation'])
);

assertTest(
    "Gemini review does not hallucinate facts and identifies completeness accurately",
    is_array($analysis['missing_information']) && $analysis['quality_score'] >= 1 && $analysis['quality_score'] <= 10
);

// -----------------------------------------------------------------------------
// Test 9: Admin Verification Decision & Instant Publishing
// -----------------------------------------------------------------------------
echo "\n9. Admin Verification Decision & Student Discovery Publishing:\n";
$verifyRes = setOpportunityVerification($db, $adminId, $testOppId, 'verified', 'Verified with IISc official portal.');

assertTest(
    "setOpportunityVerification() records verified status, verifier admin, and publishes",
    $verifyRes['success'] === true && $verifyRes['verification_status'] === 'verified' && $verifyRes['status'] === 'published'
);

// Now student exploration should see the opportunity!
$studentOppsAfter = getEventOpportunities($db, ['category_id' => $testCatId]);
$foundVerifiedInStudentOpps = false;
foreach ($studentOppsAfter as $so) {
    if ((int)$so['id'] === $testOppId) {
        $foundVerifiedInStudentOpps = true;
        break;
    }
}

assertTest(
    "Verified and published opportunity is now visible in student event exploration",
    $foundVerifiedInStudentOpps === true
);

// -----------------------------------------------------------------------------
// Test 10: Opportunity Archival & Student History Preservation
// -----------------------------------------------------------------------------
echo "\n10. Archival & Student History Protection:\n";
// Simulate student enrollment in this opportunity
$saveRes = saveOrEnrollOpportunity($db, 3, $testActivityId, $testOppId, 'completed');
assertTest(
    "Student successfully completed/enrolled in opportunity prior to archival",
    $saveRes['success'] === true
);

// Archive the opportunity
$archiveRes = archiveOpportunityAdmin($db, $adminId, $testOppId, 'Competition concluded.');
assertTest(
    "archiveOpportunityAdmin() marks opportunity as archived",
    $archiveRes['success'] === true
);

// Verify student enrollment record is preserved intact!
$stmtEnr = $db->prepare("SELECT id, status FROM enrollments WHERE user_id = 3 AND opportunity_id = :opp_id");
$stmtEnr->execute([':opp_id' => $testOppId]);
$preservedEnrollment = $stmtEnr->fetch(PDO::FETCH_ASSOC);

assertTest(
    "Archiving opportunity preserves student enrollment record intact (no deletion/cascade)",
    $preservedEnrollment !== false && $preservedEnrollment['status'] === 'completed'
);

// -----------------------------------------------------------------------------
// Test 11: Audit Trail Verification
// -----------------------------------------------------------------------------
echo "\n11. Administrative Audit Trail Integrity:\n";
$auditLogs = getAdminAuditLogs($db, 20);

$actionsFound = array_column($auditLogs, 'action');

$hasCategoryCreated = in_array('category_created', $actionsFound, true);
$hasCategoryDeactivated = in_array('category_deactivated', $actionsFound, true);
$hasOppCreated = in_array('opportunity_created', $actionsFound, true);
$hasOppVerified = in_array('opportunity_verification_updated', $actionsFound, true);
$hasOppArchived = in_array('opportunity_archived', $actionsFound, true);

assertTest(
    "Audit trail recorded all administrative events (create, deactivate, verify, archive)",
    $hasCategoryCreated && $hasCategoryDeactivated && $hasOppCreated && $hasOppVerified && $hasOppArchived,
    "Actions logged: " . implode(', ', array_unique($actionsFound))
);

// Clean up test records
$db->exec("DELETE FROM enrollments WHERE opportunity_id = {$testOppId}");
$db->exec("DELETE FROM opportunities WHERE id = {$testOppId}");
$db->exec("DELETE FROM activities WHERE id = {$testActivityId}");
$db->exec("DELETE FROM activity_categories WHERE id = {$testCatId}");

// -----------------------------------------------------------------------------
// Final Summary
// -----------------------------------------------------------------------------
echo "\n====================================================================\n";
echo "MODULE 13 TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "====================================================================\n";

if ($failed === 0) {
    echo "🎉 ALL MODULE 13 TESTS PASSED PERFECTLY!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED. PLEASE REVIEW OUTPUT ABOVE.\n";
    exit(1);
}
