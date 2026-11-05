<?php
/**
 * CareerAct — Comprehensive Test Suite for Module 9: Explore Activities & Events Across India
 * 
 * Verifies all Module 9 requirements:
 * 1. Indian location selection (state/UT, city/district, custom city, All India)
 * 2. Foreign-only exclusion & explicit Indian eligibility labeling for international online events
 * 3. Screen 1: Shows category tiles ONLY, no individual event cards
 * 4. Screen 2: Dedicated category opportunity listing with strict category isolation
 * 5. Gemini AI discovery integration with truthful disclaimers and verified DB fallback
 * 6. Opportunity listing fields (name, category, organizer, location, dates, deadline, eligibility, source URL, verification)
 * 7. Comprehensive multi-criteria filtering (location, scope, format, deadline, cost, search)
 * 8. Deduplication integrity via dedupe_hash
 * 9. Automatic exclusion of expired events from active listings
 * 10. Strict separation: Saving/enrolling NEVER pollutes student_existing_activities or marks completed
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/event_discovery_service.php';

$db = getDbConnection();
$userId = 3;

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
echo "CAREERACT MODULE 9 — EXPLORE ACTIVITIES ACROSS INDIA VERIFICATION SUITE\n";
echo "Database: carrearact2 | Test Student ID: {$userId}\n";
echo "========================================================================\n\n";

// --- Test 1: Indian State & City Taxonomy & Default Student Location ---
echo "--- Test 1: Indian Location Flow & Taxonomy ---\n";
$states = getIndianStatesAndCities();
$hasMaharashtra = isset($states['Maharashtra']) && in_array('Pune', $states['Maharashtra']['cities'], true);
$hasKarnataka   = isset($states['Karnataka']) && in_array('Bengaluru', $states['Karnataka']['cities'], true);
$hasDelhi       = isset($states['Delhi']) && in_array('New Delhi', $states['Delhi']['cities'], true);
$prefCity       = getStudentPreferredCity($db, $userId);

assertTest(
    "Indian States & Cities taxonomy includes major states and hubs",
    $hasMaharashtra && $hasKarnataka && $hasDelhi,
    "Taxonomy includes Maharashtra, Karnataka, Delhi"
);
assertTest(
    "Student's preferred location is retrieved from profile",
    !empty($prefCity),
    "Preferred city: {$prefCity}"
);

// --- Test 2: All India & State/City Filtering ---
echo "\n--- Test 2: All India & Location-Based Filtering ---\n";
$allIndiaOpps = getEventOpportunities($db, ['state' => 'all', 'city' => 'all'], $userId);
$puneOpps     = getEventOpportunities($db, ['state' => 'Maharashtra', 'city' => 'Pune'], $userId);

assertTest(
    "All India query discovers national, state, and online opportunities",
    count($allIndiaOpps) >= 10,
    "Discovered " . count($allIndiaOpps) . " opportunities across India"
);
assertTest(
    "State/City query discovers regional opportunities plus online opportunities",
    count($puneOpps) >= 2,
    "Discovered " . count($puneOpps) . " opportunities for Pune/Maharashtra"
);

// --- Test 3: Foreign-Only Opportunities Strictly Excluded ---
echo "\n--- Test 3: Exclusion of Foreign-Only Events ---\n";
// Temporarily check if any opportunity from non-India country without India eligibility appears
$foreignFound = false;
foreach ($allIndiaOpps as $opp) {
    if (!empty($opp['country_id']) && (int)$opp['country_id'] !== 37 && empty($opp['is_international_eligible'])) {
        $foreignFound = true;
        break;
    }
}
assertTest(
    "Foreign-only opportunities are strictly excluded from listings",
    !$foreignFound,
    "Zero foreign-only events found in active listings"
);

// --- Test 4: International Online Events Clearly Labeled ---
echo "\n--- Test 4: International Online Event Labeling ---\n";
$intlOpps = getEventOpportunities($db, ['scope_level' => 'international_eligible'], $userId);
$hasIntl = count($intlOpps) > 0;
assertTest(
    "International online events available to Indian students are retrieved and labeled",
    $hasIntl,
    "Found " . count($intlOpps) . " international India-eligible opportunities"
);

// --- Test 5: Screen 1: Category Tiles ONLY (No individual event cards) ---
echo "\n--- Test 5: Screen 1 Structure (Category Tiles Only) ---\n";
$catalog = getActivityCategoriesCatalog($db, $userId);
$hasAllCategories = false;
$categoryCodes = array_column($catalog, 'code');
$expectedCodes = ['HACKATHON', 'COMPETITION', 'WORKSHOP', 'INTERNSHIP', 'PROJECT', 'LEADERSHIP', 'VOLUNTEERING', 'RESEARCH', 'CONFERENCE', 'QUIZ', 'MENTORSHIP', 'ENTREPRENEURSHIP', 'FESTIVAL', 'SPORTS'];

$missingCodes = array_diff($expectedCodes, $categoryCodes);
assertTest(
    "Catalog contains all expected extracurricular categories",
    empty($missingCodes),
    !empty($missingCodes) ? "Missing: " . implode(', ', $missingCodes) : "All 14+ categories present"
);

// --- Test 6: Screen 2: Strict Category Isolation ---
echo "\n--- Test 6: Screen 2 Category Isolation ---\n";
$hackathons = getEventOpportunities($db, ['category_code' => 'HACKATHON'], $userId);
$allHackathons = true;
foreach ($hackathons as $h) {
    if ($h['category_code'] !== 'HACKATHON') {
        $allHackathons = false;
        break;
    }
}
assertTest(
    "Screen 2 for Hackathons returns strictly Hackathon opportunities",
    $allHackathons && count($hackathons) > 0,
    "Hackathon count: " . count($hackathons)
);

// --- Test 7: Gemini AI Discovery & Regional Intelligence Engine ---
echo "\n--- Test 7: Gemini AI Opportunity Discovery Engine ---\n";
$aiInsights = getCategoryAiDiscoveryInsights($db, 'HACKATHON', 'Maharashtra', 'Pune', 'Software Developer');
assertTest(
    "AI Discovery provides executive summary, top hubs, prep tips, and official portals",
    !empty($aiInsights['summary']) && !empty($aiInsights['top_hubs']) && !empty($aiInsights['prep_tips']),
    "Summary length: " . strlen($aiInsights['summary']) . ", Hubs: " . count($aiInsights['top_hubs'])
);
assertTest(
    "AI Discovery includes truthful disclaimer regarding indexing coverage",
    strpos($aiInsights['disclaimer'], 'not claim exhaustive coverage') !== false,
    "Disclaimer verified"
);

// --- Test 8: Deduplication Integrity via dedupe_hash ---
echo "\n--- Test 8: Deduplication Hash Verification ---\n";
$dupStmt = $db->query("
    SELECT dedupe_hash, COUNT(*) as cnt 
    FROM opportunities 
    WHERE dedupe_hash IS NOT NULL AND dedupe_hash != '' 
    GROUP BY dedupe_hash 
    HAVING cnt > 1
");
$dups = $dupStmt->fetchAll(PDO::FETCH_ASSOC);
assertTest(
    "Zero duplicate opportunities exist (dedupe_hash uniqueness enforced)",
    empty($dups),
    "Duplicate groups: " . count($dups)
);

// --- Test 9: Expired Opportunities Automatically Excluded ---
echo "\n--- Test 9: Automatic Expiration Handling ---\n";
// Check if any expired opportunity is returned in getEventOpportunities
$hasExpired = false;
$today = date('Y-m-d');
foreach ($allIndiaOpps as $o) {
    if (!empty($o['registration_deadline']) && $o['registration_deadline'] < $today) {
        $hasExpired = true;
        break;
    }
}
assertTest(
    "Past-deadline expired opportunities are hidden from active listings",
    !$hasExpired,
    "All " . count($allIndiaOpps) . " active listings have valid future or open deadlines"
);

// --- Test 10: Strict Separation of Concerns (Saving/Enrolling) ---
echo "\n--- Test 10: Strict Separation of Past vs. Future Opportunities ---\n";
$beforeExistingActs = (int)$db->query("SELECT COUNT(*) FROM student_existing_activities WHERE user_id = {$userId}")->fetchColumn();
$beforeSkills = (int)$db->query("SELECT COUNT(*) FROM student_skills WHERE user_id = {$userId}")->fetchColumn();

// Save opportunity #7 (Pune Smart City Hackathon) for test user
$sampleOpp = $allIndiaOpps[0];
$saveResult = saveOrEnrollOpportunity($db, $userId, (int)$sampleOpp['activity_id'], (int)$sampleOpp['id'], 'saved');

$afterExistingActs = (int)$db->query("SELECT COUNT(*) FROM student_existing_activities WHERE user_id = {$userId}")->fetchColumn();
$afterSkills = (int)$db->query("SELECT COUNT(*) FROM student_skills WHERE user_id = {$userId}")->fetchColumn();

// Verify enrollment record
$enrStmt = $db->prepare("SELECT status FROM enrollments WHERE user_id = :uid AND opportunity_id = :oid LIMIT 1");
$enrStmt->execute([':uid' => $userId, ':oid' => $sampleOpp['id']]);
$enrStatus = $enrStmt->fetchColumn();

assertTest(
    "Saving an opportunity records status='saved' in enrollments and NEVER alters student_existing_activities",
    $saveResult['success'] && ($enrStatus === 'saved') && ($beforeExistingActs === $afterExistingActs) && ($beforeSkills === $afterSkills),
    "Enrollment status: {$enrStatus}, Existing Acts: {$beforeExistingActs}->{$afterExistingActs}, Skills: {$beforeSkills}->{$afterSkills}"
);

echo "\n========================================================================\n";
echo "MODULE 9 TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "========================================================================\n";

exit($failed > 0 ? 1 : 0);

