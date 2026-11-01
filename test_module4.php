<?php
/**
 * CareerAct — Module 4: Career Exploration Test Suite
 * 
 * Verifies domain exploration, keyword/skill search, domain filters,
 * career details rendering, required skills comparison, progression ladders,
 * related extracurricular activity recommendations, and goal updates.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/career_service.php';

$pdo = getDbConnection();
$careerService = new CareerService($pdo);

// Identify test student
$testStudent = $pdo->query("SELECT id, name, email FROM users WHERE email = 'priya.sharma@college.edu'")->fetch(PDO::FETCH_ASSOC);
if (!$testStudent) {
    $testStudent = $pdo->query("SELECT id, name, email FROM users WHERE role = 'student' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
if (!$testStudent) {
    die("No student account found in database for testing.\n");
}
$studentId = (int)$testStudent['id'];

// Ensure deterministic test credentials
$pdo->prepare("UPDATE users SET password_hash = :h WHERE id = :uid")
    ->execute([':h' => password_hash('Student@123', PASSWORD_DEFAULT), ':uid' => $studentId]);

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
echo " CAREERACT MODULE 4 — CAREER EXPLORATION VERIFICATION SUITE\n";
echo " Target Database: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n";
echo " Test Student: {$testStudent['name']} (ID: {$studentId}, {$testStudent['email']})\n";
echo "========================================================================\n\n";

// 1. ACCESS CONTROL: UNAUTHENTICATED ACCESS
runTest("1. Unauthenticated request to career_goals.php is blocked and redirected", function() {
    $ch = curl_init('http://localhost/Mini-Project/career_goals.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 302) {
        return "Expected HTTP 302 redirect for unauthenticated user, got HTTP {$httpCode}";
    }
    if (stripos($response, 'Location: login.php') === false) {
        return "Redirect location did not point to login.php";
    }
    return true;
});

// 2. RETRIEVE ALL DOMAINS / CAREER FIELDS
runTest("2. System retrieves all active career fields from database with counts", function() use ($careerService) {
    $fields = $careerService->getAllCareerFields();
    if (empty($fields)) {
        return "No career fields retrieved";
    }
    if (count($fields) < 10) {
        return "Expected at least 10 career fields, got " . count($fields);
    }
    $names = array_column($fields, 'field_name');
    if (!in_array('Computing, IT and Data', $names) || !in_array('Engineering and Manufacturing', $names)) {
        return "Expected core fields not found in career_fields list";
    }
    return true;
});

// 3. RETRIEVE ALL CAREERS WITHOUT FILTERS
runTest("3. All available career records are retrieved without filters", function() use ($careerService, $studentId) {
    $all = $careerService->searchAndFilterCareers([], $studentId);
    if (count($all) !== 34) {
        return "Expected exactly 34 careers from database, found " . count($all);
    }
    return true;
});

// 4. DOMAIN FILTERING
runTest("4. Filtering by domain field_id=1 returns only Computing, IT and Data careers", function() use ($careerService, $studentId) {
    $filtered = $careerService->searchAndFilterCareers(['field_id' => 1], $studentId);
    if (count($filtered) !== 4) {
        return "Expected 4 careers in Computing, IT and Data, found " . count($filtered);
    }
    foreach ($filtered as $cr) {
        if ($cr['field_name'] !== 'Computing, IT and Data') {
            return "Found career '{$cr['career_title']}' with mismatched domain '{$cr['field_name']}'";
        }
    }
    return true;
});

// 5. SEARCH BY CAREER TITLE
runTest("5. Search by career title matches 'Software developer'", function() use ($careerService, $studentId) {
    $results = $careerService->searchAndFilterCareers(['q' => 'Software developer'], $studentId);
    if (empty($results)) {
        return "No results found for 'Software developer'";
    }
    if ($results[0]['career_title'] !== 'Software developer') {
        return "Expected 'Software developer' as top result, got '{$results[0]['career_title']}'";
    }
    return true;
});

// 6. CASE-INSENSITIVE SEARCH
runTest("6. Search is case-insensitive ('DATA SCIENTIST' matches 'Data scientist')", function() use ($careerService, $studentId) {
    $results = $careerService->searchAndFilterCareers(['q' => 'DATA SCIENTIST'], $studentId);
    if (empty($results)) {
        return "No results found for uppercase 'DATA SCIENTIST'";
    }
    if (stripos($results[0]['career_title'], 'Data scientist') === false) {
        return "Top result does not match Data scientist";
    }
    return true;
});

// 7. SEARCH BY REQUIRED SKILL
runTest("7. Search by skill name matches careers requiring that skill", function() use ($careerService, $studentId) {
    $results = $careerService->searchAndFilterCareers(['q' => 'Programming fundamentals'], $studentId);
    if (empty($results)) {
        return "No careers found when searching by skill 'Programming fundamentals'";
    }
    $titles = array_column($results, 'career_title');
    if (!in_array('Data scientist', $titles) && !in_array('Software developer', $titles)) {
        return "Expected Data scientist or Software developer in skill search results";
    }
    return true;
});

// 8. COMBINED SEARCH AND DOMAIN FILTER
runTest("8. Search query combined with domain filter works correctly", function() use ($careerService, $studentId) {
    $results = $careerService->searchAndFilterCareers([
        'q'        => 'engineer',
        'field_id' => 2 // Engineering and Manufacturing
    ], $studentId);

    if (empty($results)) {
        return "No results found for 'engineer' in Engineering domain";
    }
    foreach ($results as $cr) {
        if ($cr['field_id'] !== 2) {
            return "Result '{$cr['career_title']}' is not in field_id 2";
        }
        if (stripos($cr['career_title'], 'engineer') === false) {
            return "Result '{$cr['career_title']}' does not match keyword 'engineer'";
        }
    }
    return true;
});

// 9. EMPTY SEARCH RESULT STATE
runTest("9. Search with non-existent query returns empty array gracefully", function() use ($careerService, $studentId) {
    $results = $careerService->searchAndFilterCareers(['q' => 'NonExistentXYZ999JobRole'], $studentId);
    if (!is_array($results) || !empty($results)) {
        return "Expected empty array for non-existent career query, got " . count($results) . " items";
    }
    return true;
});

// 10. CAREER DETAILS SPECIFICATION (DATA SCIENTIST)
runTest("10. Career Details retrieves full payload with skills, growth, and relations", function() use ($careerService, $studentId) {
    $details = $careerService->getCareerDetails(1, $studentId); // Data scientist
    if (!$details) {
        return "getCareerDetails returned null for career ID 1";
    }
    if ($details['title'] !== 'Data scientist') {
        return "Career title mismatch: " . $details['title'];
    }
    if (empty($details['field_name']) || empty($details['subfield_name'])) {
        return "Domain or subfield name missing in career details";
    }
    if (empty($details['skill_comparison'])) {
        return "Skill comparison payload missing";
    }
    if (!isset($details['growth_progression'])) {
        return "Growth progression payload missing";
    }
    if (!isset($details['related_careers'])) {
        return "Related careers payload missing";
    }
    return true;
});

// 11. REQUIRED SKILLS & READINESS COMPARISON
runTest("11. Skill comparison computes possessed competencies, gaps, and readiness percentage", function() use ($careerService, $studentId) {
    $details = $careerService->getCareerDetails(1, $studentId);
    $comp = $details['skill_comparison'];

    if (!isset($comp['readiness_pct']) || $comp['readiness_pct'] < 0 || $comp['readiness_pct'] > 100) {
        return "Invalid readiness percentage: " . ($comp['readiness_pct'] ?? 'null');
    }
    if (!isset($comp['possessed_skills']) || !isset($comp['gap_skills'])) {
        return "Missing possessed_skills or gap_skills in comparison";
    }
    if (($comp['possessed_count'] + $comp['gap_count']) !== $comp['total_required']) {
        return "Sum of possessed and gaps does not match total required skills count";
    }
    return true;
});

// 12. RELATED EXTRACURRICULAR ACTIVITIES
runTest("12. Related extracurricular activities are retrieved from database for the career", function() use ($careerService, $studentId) {
    $details = $careerService->getCareerDetails(1, $studentId);
    $activities = $details['extracurricular_activities'];

    if (empty($activities)) {
        return "No related activities returned for Data scientist";
    }
    foreach ($activities as $act) {
        if (empty($act['activity_title'])) {
            return "Activity record missing activity_title";
        }
        if (!isset($act['delivery_mode'])) {
            return "Activity record missing delivery_mode";
        }
    }
    return true;
});

// 13. SET PRIMARY CAREER GOAL TRANSACTION
runTest("13. Student can set a primary career goal and update student_career_goals safely", function() use ($careerService, $pdo, $studentId) {
    // Set Software developer (ID 4) as primary goal
    $success = $careerService->setPrimaryCareerGoal($studentId, 4);
    if (!$success) {
        return "setPrimaryCareerGoal returned false";
    }

    $stmt = $pdo->prepare("SELECT career_id, is_primary FROM student_career_goals WHERE user_id = :uid AND is_primary = 1");
    $stmt->execute([':uid' => $studentId]);
    $goal = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$goal || (int)$goal['career_id'] !== 4) {
        return "Primary goal in database was not updated to career ID 4";
    }

    // Verify getCareerDetails reflects is_primary_goal = true
    $details = $careerService->getCareerDetails(4, $studentId);
    if (!$details['is_primary_goal']) {
        return "is_primary_goal was not marked true for career ID 4";
    }

    // Reset back to Data scientist (ID 1)
    $careerService->setPrimaryCareerGoal($studentId, 1);
    return true;
});

// 14. AUTHENTICATED WEB PAGES LOAD SUCCESSFULLY (HTTP 200)
runTest("14. Authenticated browser session loads career_goals.php and career_details.php", function() use ($studentId, $pdo) {
    // Fetch student's email to simulate login
    $email = $pdo->query("SELECT email FROM users WHERE id = {$studentId}")->fetchColumn();
    $cookieFile = __DIR__ . '/test_m4_cookie.txt';
    if (file_exists($cookieFile)) unlink($cookieFile);

    // 1. Fetch CSRF token from login page
    $ch = curl_init('http://localhost/Mini-Project/login.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile
    ]);
    $loginHtml = curl_exec($ch);
    curl_close($ch);

    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $loginHtml, $matches);
    $csrfToken = $matches[1] ?? '';

    // 2. Perform student login
    $ch = curl_init('http://localhost/Mini-Project/login.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'email' => $email,
            'password' => 'Student@123',
            'csrf_token' => $csrfToken
        ]),
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    curl_exec($ch);
    curl_close($ch);

    // 3. Load career_goals.php
    $ch = curl_init('http://localhost/Mini-Project/career_goals.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $cookieFile
    ]);
    $goalsHtml = curl_exec($ch);
    $codeGoals = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($codeGoals !== 200) {
        if (file_exists($cookieFile)) unlink($cookieFile);
        return "career_goals.php returned HTTP {$codeGoals}";
    }
    if (stripos($goalsHtml, 'Career Exploration') === false) {
        if (file_exists($cookieFile)) unlink($cookieFile);
        return "career_goals.php page missing 'Career Exploration' heading";
    }

    // 4. Load career_details.php?id=1
    $ch = curl_init('http://localhost/Mini-Project/career_details.php?id=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $cookieFile
    ]);
    $detailsHtml = curl_exec($ch);
    $codeDetails = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (file_exists($cookieFile)) unlink($cookieFile);

    if ($codeDetails !== 200) {
        return "career_details.php?id=1 returned HTTP {$codeDetails}";
    }
    if (stripos($detailsHtml, 'Data scientist') === false) {
        return "career_details.php missing career title 'Data scientist'";
    }

    return true;
});

echo "\n========================================================================\n";
echo " MODULE 4 TEST RESULTS: {$passedTests} / {$totalTests} PASSED\n";
if ($failedTests === 0) {
    echo " >>> ALL MODULE 4 CAREER EXPLORATION TESTS PASSED 100%! <<<\n";
} else {
    echo " >>> {$failedTests} TESTS FAILED. PLEASE REVIEW LOGS. <<<\n";
}
echo "========================================================================\n";
