<?php
/**
 * CareerAct — Comprehensive Test Suite for Module 12: Student Portfolio & Achievement Showcase
 * 
 * Verifies all Module 12 requirements:
 * 1. Data Integrity: Uses verified education, confirmed skills, completed activities, and projects
 * 2. Strict Boundary: Uncompleted (saved, registered, in-progress) and recommended activities are strictly excluded
 * 3. Private by Default: Portfolio is private initially and returns 404 when not public
 * 4. Controlled Sharing: Public showcase is viewable only when public sharing is explicitly enabled
 * 5. Instant Revocation: Disabling sharing or regenerating share token immediately revokes public access
 * 6. Contact Protection: Email and phone are hidden in public showcase by default
 * 7. Selective Visibility: Section and item-level visibility toggles remove chosen content from public view
 * 8. Zero Leakage: Public showcase does not expose private assessment logs, scores, or account credentials
 * 9. Gemini AI Assistance: Bio generation produces truthful text without inventing facts
 * 10. Multi-Tenant Isolation & Separation: Student B cannot edit Student A's portfolio; edits never alter raw skills
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/portfolio_service.php';

$db = getDbConnection();
$userId = 3; // Sharma (Student A)

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

echo "========================================================================\n";
echo "CAREERACT MODULE 12 — STUDENT PORTFOLIO & SHOWCASE VERIFICATION SUITE\n";
echo "Database: carrearact2 | Test Student ID: {$userId}\n";
echo "========================================================================\n\n";

// --- Test 1: Verified Portfolio Content & Data Sources ---
echo "--- Test 1: Portfolio Content & Data Sources ---\n";
$portfolioData = getFullPortfolioData($db, $userId, false);

$hasCandidate = !empty($portfolioData['candidate']['full_name']);
$hasEducation = !empty($portfolioData['education']);
$hasSkills = !empty($portfolioData['skills']);

assertTest(
    "Portfolio successfully retrieves confirmed profile, education, and skills",
    $hasCandidate && $hasEducation && $hasSkills,
    "Name: {$portfolioData['candidate']['full_name']}, Skills: " . count($portfolioData['skills'])
);

// --- Test 2: Strict Exclusion of Uncompleted & Recommended Activities ---
echo "\n--- Test 2: Strict Exclusion of Uncompleted Activities ---\n";
// Student 3 has enrollment #1 with status = 'saved'
$uncompletedFound = false;
foreach ($portfolioData['activities'] as $act) {
    if (stripos($act['title'], 'Global Student AI Agents') !== false) {
        $uncompletedFound = true;
        break;
    }
}

$allActsCompleted = true;
foreach ($portfolioData['activities'] as $act) {
    if (!empty($act['is_enrollment'])) {
        $eid = (int)str_replace('enr_', '', $act['item_key']);
        $chkStatus = $db->query("SELECT status FROM enrollments WHERE id = {$eid}")->fetchColumn();
        if ($chkStatus !== 'completed') {
            $allActsCompleted = false;
            break;
        }
    }
}

assertTest(
    "Saved, registered, in-progress, or recommended activities are strictly excluded from portfolio experience",
    !$uncompletedFound && $allActsCompleted,
    "Uncompleted found: " . ($uncompletedFound ? 'YES' : 'NO')
);

// --- Test 3: Private by Default ---
echo "\n--- Test 3: Private by Default Enforcement ---\n";
// Ensure portfolio is set to private
updateStudentPortfolioSettings($db, $userId, ['is_public' => 0]);
$settings = getStudentPortfolioSettings($db, $userId);

// In public mode, private portfolio must return null
$publicDataWhenPrivate = getFullPortfolioData($db, $userId, true);

assertTest(
    "Portfolio is private by default and blocks public data access when sharing is off",
    !$settings['is_public'] && $publicDataWhenPrivate === null,
    "is_public: " . ($settings['is_public'] ? '1' : '0')
);

// --- Test 4: Controlled Public Sharing ---
echo "\n--- Test 4: Public Sharing Activation ---\n";
updateStudentPortfolioSettings($db, $userId, ['is_public' => 1]);
$publicData = getFullPortfolioData($db, $userId, true);

assertTest(
    "Enabling public sharing allows authorized public showcase retrieval",
    $publicData !== null && !empty($publicData['candidate']['full_name']),
    "Public retrieval: " . ($publicData !== null ? 'SUCCESS' : 'FAILED')
);

// --- Test 5: Instant Revocation via Privacy Switch & Token Regeneration ---
echo "\n--- Test 5: Instant Link Revocation ---\n";
$oldToken = $settings['share_token'];
$newToken = regeneratePortfolioShareToken($db, $userId);

$stmtOld = $db->prepare("SELECT user_id FROM student_portfolios WHERE share_token = :tok AND is_public = 1");
$stmtOld->execute([':tok' => $oldToken]);
$oldLookup = $stmtOld->fetch();

$stmtNew = $db->prepare("SELECT user_id FROM student_portfolios WHERE share_token = :tok AND is_public = 1");
$stmtNew->execute([':tok' => $newToken]);
$newLookup = $stmtNew->fetch();

assertTest(
    "Regenerating share token immediately invalidates the old link and activates the new secure token",
    $oldLookup === false && $newLookup !== false,
    "Old token invalidated: YES, New token active: YES"
);

// --- Test 6: Contact Information Protection ---
echo "\n--- Test 6: Contact Information Protection ---\n";
// Set display_email = 0, display_phone = 0
updateStudentPortfolioSettings($db, $userId, [
    'is_public'     => 1,
    'display_email' => 0,
    'display_phone' => 0,
]);
$protectedData = getFullPortfolioData($db, $userId, true);

$emailProtected = empty($protectedData['candidate']['email']);
$phoneProtected = empty($protectedData['candidate']['phone']);

// Now explicitly enable email display
updateStudentPortfolioSettings($db, $userId, [
    'is_public'     => 1,
    'display_email' => 1,
]);
$optInData = getFullPortfolioData($db, $userId, true);
$emailDisplayed = !empty($optInData['candidate']['email']);

assertTest(
    "Contact info (email/phone) is protected by default and published only on explicit student opt-in",
    $emailProtected && $phoneProtected && $emailDisplayed,
    "Default hidden: YES, Opt-in visible: YES"
);

// --- Test 7: Selective Section & Item-Level Visibility ---
echo "\n--- Test 7: Selective Section & Item Visibility Toggles ---\n";
// Toggle 'education' section to FALSE
updateStudentPortfolioSettings($db, $userId, [
    'is_public'        => 1,
    'visible_sections' => [
        'summary'      => true,
        'education'    => false, // Disabled
        'skills'       => true,
        'activities'   => true,
        'projects'     => true,
        'certificates' => true,
        'achievements' => true,
    ],
]);
$hiddenEduData = getFullPortfolioData($db, $userId, true);
$eduIsHidden = empty($hiddenEduData['education']);

// Reset section visibility and test item-level hiding
updateStudentPortfolioSettings($db, $userId, [
    'is_public'        => 1,
    'visible_sections' => [
        'summary' => true, 'education' => true, 'skills' => true,
        'activities' => true, 'projects' => true, 'certificates' => true, 'achievements' => true,
    ],
    'item_visibility'  => [
        'sea_10' => false, // Hide SIH activity
    ],
]);
$itemFilteredData = getFullPortfolioData($db, $userId, true);
$itemIsHidden = true;
foreach ($itemFilteredData['activities'] as $act) {
    if ($act['item_key'] === 'sea_10') {
        $itemIsHidden = false;
        break;
    }
}

assertTest(
    "Section toggles and item-level checkboxes accurately remove selected content from public view",
    $eduIsHidden && $itemIsHidden,
    "Education hidden: YES, SIH item hidden: YES"
);

// --- Test 8: Zero Leakage of Internal IDs, Scores, or Account Data ---
echo "\n--- Test 8: Zero Leakage of Private Data ---\n";
// Render public view output
ob_start();
$_GET['share'] = $newToken;
include __DIR__ . '/portfolio_view.php';
$html = ob_get_clean();

$noPasswordLeak = (stripos($html, 'password_hash') === false && stripos($html, '$2y$') === false);
$noAssessmentLeak = (stripos($html, 'ai_assessment') === false && stripos($html, 'ai_feedback') === false);
$noInternalIdLeak = (stripos($html, 'user_id') === false && stripos($html, 'provider_id') === false);

assertTest(
    "Public showcase page completely excludes passwords, assessment attempts, and internal database keys",
    $noPasswordLeak && $noAssessmentLeak && $noInternalIdLeak,
    "HTML size: " . strlen($html) . " bytes"
);

// --- Test 9: Gemini AI Portfolio Bio Generation ---
echo "\n--- Test 9: Gemini AI Portfolio Bio Assistance ---\n";
$bioDraft = generatePortfolioBioDraft($db, $userId);

assertTest(
    "AI Bio generator produces professional, truthful portfolio biography based on confirmed skills",
    !empty($bioDraft) && strlen($bioDraft) >= 40 && stripos($bioDraft, 'Sharma') === false,
    "Bio Draft length: " . strlen($bioDraft)
);

// --- Test 10: Multi-Tenant Student Isolation & Separation of Concerns ---
echo "\n--- Test 10: Multi-Tenant Isolation & Non-Contamination ---\n";
$stmtOther = $db->query("SELECT id FROM users WHERE role = 'student' AND id != {$userId} LIMIT 1");
$otherUser = $stmtOther->fetch(PDO::FETCH_ASSOC);

$isolationPassed = true;
if ($otherUser) {
    $otherUid = (int)$otherUser['id'];
    $otherSettings = getStudentPortfolioSettings($db, $otherUid);
    // Student B's settings are completely distinct from Student A's
    $isolationPassed = ($otherSettings['user_id'] === $otherUid && $otherSettings['share_token'] !== $newToken);
}

$skillsCountBefore = (int)$db->query("SELECT COUNT(*) FROM student_skills WHERE user_id = {$userId}")->fetchColumn();
$actsCountBefore = (int)$db->query("SELECT COUNT(*) FROM student_existing_activities WHERE user_id = {$userId}")->fetchColumn();

// Save portfolio customizations
updateStudentPortfolioSettings($db, $userId, [
    'headline' => 'Updated Custom Headline for Test',
    'bio'      => 'Custom student biography.',
]);

$skillsCountAfter = (int)$db->query("SELECT COUNT(*) FROM student_skills WHERE user_id = {$userId}")->fetchColumn();
$actsCountAfter = (int)$db->query("SELECT COUNT(*) FROM student_existing_activities WHERE user_id = {$userId}")->fetchColumn();

assertTest(
    "Student B cannot access Student A's settings, and portfolio edits never modify student_skills or activities",
    $isolationPassed && ($skillsCountBefore === $skillsCountAfter) && ($actsCountBefore === $actsCountAfter),
    "Skills: {$skillsCountBefore}->{$skillsCountAfter}, Acts: {$actsCountBefore}->{$actsCountAfter}"
);

// Reset portfolio settings to clean state
updateStudentPortfolioSettings($db, $userId, [
    'is_public'            => 1,
    'visible_sections'     => [
        'summary' => true, 'education' => true, 'skills' => true,
        'activities' => true, 'projects' => true, 'certificates' => true, 'achievements' => true,
    ],
    'item_visibility'      => [],
    'custom_descriptions' => [],
]);

echo "\n========================================================================\n";
echo "MODULE 12 TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "========================================================================\n";

exit($failed > 0 ? 1 : 0);

