<?php
/**
 * Automated Test Suite for CareerAct Module 2: Student Profile & Onboarding
 * Verifies all 20+ requirements strictly against carrearact2 database.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';

$baseUrl = 'http://localhost/Mini-Project';
$cookieJarStudent = sys_get_temp_dir() . '/cookie_module2_student_' . time() . '.txt';

function printHeader(string $title): void {
    echo "\n" . str_repeat('=', 70) . "\n";
    echo " " . $title . "\n";
    echo str_repeat('=', 70) . "\n";
}

function runCheck(string $testName, callable $callback): bool {
    echo "Running [{$testName}]... ";
    try {
        $result = $callback();
        if ($result === true) {
            echo "PASSED\n";
            return true;
        } else {
            echo "FAILED: {$result}\n";
            return false;
        }
    } catch (Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
        return false;
    }
}

function makeHttpRequest(string $url, string $method = 'GET', array $data = [], ?string $cookieJar = null, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL error on {$url}: {$err}");
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    curl_close($ch);

    return [
        'code'    => $httpCode,
        'headers' => $headerStr,
        'body'    => $body,
    ];
}

function extractCsrf(string $html): ?string {
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/name=\'csrf_token\'\s+value=\'([a-f0-9]+)\'/i', $html, $m)) {
        return $m[1];
    }
    return null;
}

function getHeader(string $headerStr, string $headerName): ?string {
    $lines = explode("\r\n", $headerStr);
    foreach ($lines as $line) {
        if (stripos($line, $headerName . ':') === 0) {
            return trim(substr($line, strlen($headerName) + 1));
        }
    }
    return null;
}

$allPassed = true;

printHeader("1. STRICT DATABASE VERIFICATION");

$allPassed = runCheck("Verify Connected Database is 'careeract' or 'carrearact2'", function() {
    $db = getDbConnection();
    $stmt = $db->query('SELECT DATABASE()');
    $dbName = (string)$stmt->fetchColumn();
    if ($dbName !== 'careeract' && $dbName !== 'carrearact2') {
        return "Expected 'careeract' or 'carrearact2', got '{$dbName}'";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Verify All Required Existing Tables Exist in 'carrearact2'", function() {
    $db = getDbConnection();
    $requiredTables = [
        'users',
        'student_profiles',
        'student_education',
        'student_career_goals',
        'student_interest_fields',
        'student_skills',
        'student_existing_activities',
        'careers',
        'career_fields',
        'skills',
        'skill_levels',
        'activities',
        'countries',
        'cities'
    ];
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff($requiredTables, $tables);
    if (!empty($missing)) {
        return "Missing tables: " . implode(', ', $missing);
    }
    return true;
}) && $allPassed;

printHeader("2. ACCESS CONTROL & ROUTING GUARDS");

$allPassed = runCheck("Unauthenticated user cannot open onboarding.php", function() use ($baseUrl) {
    $res = makeHttpRequest("{$baseUrl}/onboarding.php");
    if ($res['code'] !== 302) {
        return "Expected HTTP 302, got {$res['code']}";
    }
    $loc = getHeader($res['headers'], 'Location');
    if (!$loc || strpos($loc, 'login.php') === false) {
        return "Expected redirect to login.php, got '{$loc}'";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Unauthenticated user cannot open profile.php", function() use ($baseUrl) {
    $res = makeHttpRequest("{$baseUrl}/profile.php");
    if ($res['code'] !== 302) {
        return "Expected HTTP 302, got {$res['code']}";
    }
    $loc = getHeader($res['headers'], 'Location');
    if (!$loc || strpos($loc, 'login.php') === false) {
        return "Expected redirect to login.php, got '{$loc}'";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Unauthenticated user cannot open dashboard.php", function() use ($baseUrl) {
    $res = makeHttpRequest("{$baseUrl}/dashboard.php");
    if ($res['code'] !== 302) {
        return "Expected HTTP 302, got {$res['code']}";
    }
    $loc = getHeader($res['headers'], 'Location');
    if (!$loc || strpos($loc, 'login.php') === false) {
        return "Expected redirect to login.php, got '{$loc}'";
    }
    return true;
}) && $allPassed;

printHeader("3. STUDENT AUTHENTICATION & ONBOARDING ENTRY");

// Dynamically fetch student user ID from database
$db = getDbConnection();
$studentUserId = (int)$db->query("SELECT id FROM users WHERE email = 'priya.sharma@college.edu'")->fetchColumn();
if (!$studentUserId) {
    die("Test student priya.sharma@college.edu not found in database.\n");
}
$db->prepare("UPDATE student_profiles SET profile_completed = 0 WHERE user_id = :uid")->execute([':uid' => $studentUserId]);

$allPassed = runCheck("Incomplete student logs in -> redirected to onboarding wizard", function() use ($baseUrl, $cookieJarStudent) {
    // 1. Get login page and CSRF
    $resLogin = makeHttpRequest("{$baseUrl}/login.php", 'GET', [], $cookieJarStudent);
    $csrf = extractCsrf($resLogin['body']);
    if (!$csrf) {
        return "Could not extract CSRF token from login page";
    }

    // 2. Perform login as student
    $postData = [
        'csrf_token' => $csrf,
        'email'      => 'priya.sharma@college.edu',
        'password'   => 'Student@123',
    ];
    $resAuth = makeHttpRequest("{$baseUrl}/login.php", 'POST', $postData, $cookieJarStudent);
    if ($resAuth['code'] !== 302) {
        return "Expected HTTP 302 redirect after login, got {$resAuth['code']}";
    }
    $loc = getHeader($resAuth['headers'], 'Location');
    if (!$loc || strpos($loc, 'onboarding.php') === false) {
        return "Expected redirect to onboarding.php for incomplete student, got '{$loc}'";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Incomplete student cannot access dashboard.php directly", function() use ($baseUrl, $cookieJarStudent) {
    $res = makeHttpRequest("{$baseUrl}/dashboard.php", 'GET', [], $cookieJarStudent);
    if ($res['code'] !== 302) {
        return "Expected HTTP 302 redirect from dashboard, got {$res['code']}";
    }
    $loc = getHeader($res['headers'], 'Location');
    if (!$loc || strpos($loc, 'onboarding.php') === false) {
        return "Expected redirect to onboarding.php, got '{$loc}'";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Logged-in student can open onboarding.php (HTTP 200)", function() use ($baseUrl, $cookieJarStudent) {
    $res = makeHttpRequest("{$baseUrl}/onboarding.php", 'GET', [], $cookieJarStudent);
    if ($res['code'] !== 200) {
        return "Expected HTTP 200, got {$res['code']}";
    }
    if (strpos($res['body'], 'Student Profile &amp; Onboarding') === false && strpos($res['body'], 'Student Profile') === false) {
        return "Onboarding page title not found in response body";
    }
    return true;
}) && $allPassed;

printHeader("4. VALIDATION & SECURITY CHECKS");

$allPassed = runCheck("Server-side validation rejects incomplete mandatory fields", function() use ($baseUrl, $cookieJarStudent) {
    $page = makeHttpRequest("{$baseUrl}/onboarding.php", 'GET', [], $cookieJarStudent);
    $csrf = extractCsrf($page['body']);
    if (!$csrf) {
        return "Could not extract CSRF token";
    }

    // Submit with empty institution & graduation year
    $badPost = [
        'csrf_token'     => $csrf,
        'submit_profile' => '1',
        'full_name'      => 'Priya Sharma',
        'institution'    => '', // Empty mandatory field
        'graduation_year'=> '', // Empty mandatory field
    ];
    $res = makeHttpRequest("{$baseUrl}/onboarding.php", 'POST', $badPost, $cookieJarStudent);
    // Should return HTTP 200 with error messages, NOT redirect
    if ($res['code'] !== 200) {
        return "Expected HTTP 200 with error display, got {$res['code']}";
    }
    if (strpos($res['body'], 'College / University name is required') === false) {
        return "Expected institution validation error message not displayed";
    }
    return true;
}) && $allPassed;

printHeader("5. COMPLETE PROFILE SUBMISSION & ATOMIC TRANSACTION");

$allPassed = runCheck("Submit complete profile across all 6 sections", function() use ($baseUrl, $cookieJarStudent, $db) {
    $page = makeHttpRequest("{$baseUrl}/onboarding.php", 'GET', [], $cookieJarStudent);
    $csrf = extractCsrf($page['body']);
    if (!$csrf) {
        return "Could not extract CSRF token";
    }

    // Pick real IDs from carrearact2
    $careerPrimary = (int)$db->query("SELECT id FROM careers WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
    $careerSecondary = (int)$db->query("SELECT id FROM careers WHERE is_active = 1 AND id != {$careerPrimary} ORDER BY id ASC LIMIT 1")->fetchColumn();
    $fieldIds = $db->query("SELECT id FROM career_fields WHERE is_active = 1 LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    $skillIds = $db->query("SELECT id FROM skills WHERE is_active = 1 LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    $countryId = (int)$db->query("SELECT id FROM countries WHERE iso2 = 'IN' LIMIT 1")->fetchColumn() ?: 1;

    $fullSubmission = [
        'csrf_token'            => $csrf,
        'submit_profile'        => '1',
        // Step 1: Personal
        'full_name'             => 'Priya Sharma',
        'country_id'            => (string)$countryId,
        'city'                  => 'Bengaluru',
        'experience_level'      => 'beginner',
        'preferred_mode'        => 'hybrid',
        'bio'                   => 'Pre-final year Computer Science student passionate about Cloud Computing and open-source development.',
        // Step 2: Education
        'education_level'       => 'undergraduate',
        'institution'           => 'National Institute of Technology',
        'field_of_study'        => 'Computer Science and Engineering',
        'qualification'         => 'B.Tech in Computer Science',
        'start_year'            => '2022',
        'graduation_year'       => '2026',
        // Step 3: Career Goals
        'primary_career_id'     => (string)$careerPrimary,
        'secondary_career_ids'  => [(string)$careerSecondary],
        'resume_summary'        => 'Aspiring Cloud Solutions Architect with hands-on containerization and RESTful API experience.',
        // Step 4: Interests
        'interest_fields'       => $fieldIds,
        // Step 5: Skills
        'skills'                => [
            (string)$skillIds[0] => '3',
            (string)$skillIds[1] => '4',
        ],
        // Step 6: Extracurricular Activities
        'activity_title'        => ['Smart India Hackathon 2024'],
        'activity_catalog_id'   => ['1'],
        'activity_role'         => ['Lead Backend Developer'],
        'activity_desc'         => ['Built an automated resource allocation microservice using Node.js and Docker.'],
        'activity_start'        => ['2024-01-15'],
        'activity_end'          => ['2024-03-20'],
    ];

    $res = makeHttpRequest("{$baseUrl}/onboarding.php", 'POST', $fullSubmission, $cookieJarStudent);
    if ($res['code'] !== 302) {
        return "Expected HTTP 302 redirect after submission, got {$res['code']}. Response snippet: " . substr(strip_tags($res['body']), 0, 300);
    }
    $loc = getHeader($res['headers'], 'Location');
    if (!$loc || strpos($loc, 'dashboard.php') === false) {
        return "Expected redirect to dashboard.php, got '{$loc}'";
    }
    return true;
}) && $allPassed;

printHeader("6. DATABASE PERSISTENCE VERIFICATION IN carrearact2");

$allPassed = runCheck("Verify student_profiles row updated with profile_completed = 1", function() use ($db, $studentUserId) {
    $stmt = $db->prepare("SELECT * FROM student_profiles WHERE user_id = :uid");
    $stmt->execute([':uid' => $studentUserId]);
    $p = $stmt->fetch();
    if (!$p) {
        return "No student_profiles record found for user {$studentUserId}";
    }
    if ((int)$p['profile_completed'] !== 1) {
        return "profile_completed is {$p['profile_completed']}, expected 1";
    }
    if ($p['institution'] !== 'National Institute of Technology') {
        return "institution mismatch: '{$p['institution']}'";
    }
    if ((int)$p['graduation_year'] !== 2026) {
        return "graduation_year mismatch: '{$p['graduation_year']}'";
    }
    if ($p['city'] !== 'Bengaluru') {
        return "city mismatch: '{$p['city']}'";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Verify student_education row saved", function() use ($db, $studentUserId) {
    $stmt = $db->prepare("SELECT * FROM student_education WHERE user_id = :uid");
    $stmt->execute([':uid' => $studentUserId]);
    $edu = $stmt->fetch();
    if (!$edu) {
        return "No student_education record found for user {$studentUserId}";
    }
    if ($edu['institution'] !== 'National Institute of Technology') {
        return "institution mismatch in student_education: '{$edu['institution']}'";
    }
    if ((int)$edu['end_year'] !== 2026) {
        return "end_year mismatch in student_education: '{$edu['end_year']}'";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Verify student_career_goals rows saved (1 primary, 1 secondary)", function() use ($db, $studentUserId) {
    $stmt = $db->prepare("SELECT * FROM student_career_goals WHERE user_id = :uid ORDER BY is_primary DESC");
    $stmt->execute([':uid' => $studentUserId]);
    $goals = $stmt->fetchAll();
    if (count($goals) !== 2) {
        return "Expected 2 career goals, found " . count($goals);
    }
    if ((int)$goals[0]['is_primary'] !== 1) {
        return "Expected first goal to be primary (is_primary = 1)";
    }
    if ((int)$goals[1]['is_primary'] !== 0) {
        return "Expected second goal to be secondary (is_primary = 0)";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Verify student_interest_fields rows saved", function() use ($db, $studentUserId) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM student_interest_fields WHERE user_id = :uid");
    $stmt->execute([':uid' => $studentUserId]);
    $count = (int)$stmt->fetchColumn();
    if ($count !== 2) {
        return "Expected 2 interest fields, found {$count}";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Verify student_skills rows saved with self_reported source", function() use ($db, $studentUserId) {
    $stmt = $db->prepare("SELECT * FROM student_skills WHERE user_id = :uid");
    $stmt->execute([':uid' => $studentUserId]);
    $skills = $stmt->fetchAll();
    if (count($skills) !== 2) {
        return "Expected 2 skills, found " . count($skills);
    }
    foreach ($skills as $sk) {
        if ($sk['source'] !== 'self_reported') {
            return "Skill source must be 'self_reported', got '{$sk['source']}'";
        }
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Verify student_existing_activities row saved", function() use ($db, $studentUserId) {
    $stmt = $db->prepare("SELECT * FROM student_existing_activities WHERE user_id = :uid");
    $stmt->execute([':uid' => $studentUserId]);
    $act = $stmt->fetch();
    if (!$act) {
        return "No student_existing_activities record found for user {$studentUserId}";
    }
    if ($act['title'] !== 'Smart India Hackathon 2024') {
        return "Activity title mismatch: '{$act['title']}'";
    }
    return true;
}) && $allPassed;

printHeader("7. POST-ONBOARDING ACCESS & PROFILE EDITING");

$allPassed = runCheck("Completed student can access dashboard.php (HTTP 200)", function() use ($baseUrl, $cookieJarStudent) {
    $res = makeHttpRequest("{$baseUrl}/dashboard.php", 'GET', [], $cookieJarStudent);
    if ($res['code'] !== 200) {
        return "Expected HTTP 200, got {$res['code']}";
    }
    if (strpos($res['body'], 'CareerAct') === false) {
        return "Dashboard body does not contain CareerAct branding";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Completed student can view profile.php with all saved data (HTTP 200)", function() use ($baseUrl, $cookieJarStudent) {
    $res = makeHttpRequest("{$baseUrl}/profile.php", 'GET', [], $cookieJarStudent);
    if ($res['code'] !== 200) {
        return "Expected HTTP 200, got {$res['code']}";
    }
    if (strpos($res['body'], 'National Institute of Technology') === false) {
        return "Profile page does not display saved institution";
    }
    if (strpos($res['body'], 'Smart India Hackathon 2024') === false) {
        return "Profile page does not display saved extracurricular activity";
    }
    if (strpos($res['body'], 'Profile Complete') === false) {
        return "Profile page does not show Profile Complete badge";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Profile edit mode opens with pre-populated values (HTTP 200)", function() use ($baseUrl, $cookieJarStudent) {
    $res = makeHttpRequest("{$baseUrl}/onboarding.php?mode=edit", 'GET', [], $cookieJarStudent);
    if ($res['code'] !== 200) {
        return "Expected HTTP 200, got {$res['code']}";
    }
    if (strpos($res['body'], 'National Institute of Technology') === false) {
        return "Edit mode does not pre-populate institution";
    }
    if (strpos($res['body'], 'Smart India Hackathon 2024') === false) {
        return "Edit mode does not pre-populate existing activity";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Updating profile does NOT create duplicate records", function() use ($baseUrl, $cookieJarStudent, $db, $studentUserId) {
    $page = makeHttpRequest("{$baseUrl}/onboarding.php?mode=edit", 'GET', [], $cookieJarStudent);
    $csrf = extractCsrf($page['body']);
    if (!$csrf) {
        return "Could not extract CSRF token";
    }

    $careerPrimary = (int)$db->query("SELECT id FROM careers WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
    $fieldIds = $db->query("SELECT id FROM career_fields WHERE is_active = 1 LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    $skillIds = $db->query("SELECT id FROM skills WHERE is_active = 1 LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);

    // Update with slightly modified city and bio
    $updatePost = [
        'csrf_token'            => $csrf,
        'submit_profile'        => '1',
        'full_name'             => 'Priya Sharma',
        'country_id'            => '1',
        'city'                  => 'Bengaluru City',
        'experience_level'      => 'intermediate',
        'preferred_mode'        => 'online',
        'bio'                   => 'Updated bio: Pre-final year student with verified skills.',
        'education_level'       => 'undergraduate',
        'institution'           => 'National Institute of Technology',
        'field_of_study'        => 'Computer Science and Engineering',
        'qualification'         => 'B.Tech in CSE',
        'start_year'            => '2022',
        'graduation_year'       => '2026',
        'primary_career_id'     => (string)$careerPrimary,
        'resume_summary'        => 'Updated resume summary.',
        'interest_fields'       => $fieldIds,
        'skills'                => [
            (string)$skillIds[0] => '4',
        ],
        'activity_title'        => ['Smart India Hackathon 2024 (Updated)'],
        'activity_catalog_id'   => ['1'],
        'activity_role'         => ['Lead Full-Stack Developer'],
        'activity_desc'         => ['Updated description.'],
        'activity_start'        => ['2024-01-15'],
        'activity_end'          => ['2024-03-20'],
    ];

    $res = makeHttpRequest("{$baseUrl}/onboarding.php", 'POST', $updatePost, $cookieJarStudent);
    if ($res['code'] !== 302) {
        return "Expected HTTP 302 redirect after update, got {$res['code']}";
    }

    // Verify row counts in database: should NOT have duplicate education, primary goals, or profiles
    $profCount = (int)$db->query("SELECT COUNT(*) FROM student_profiles WHERE user_id = {$studentUserId}")->fetchColumn();
    $eduCount = (int)$db->query("SELECT COUNT(*) FROM student_education WHERE user_id = {$studentUserId}")->fetchColumn();
    $primaryGoalCount = (int)$db->query("SELECT COUNT(*) FROM student_career_goals WHERE user_id = {$studentUserId} AND is_primary = 1")->fetchColumn();

    if ($profCount !== 1) {
        return "Duplicate student_profiles created: count = {$profCount}";
    }
    if ($eduCount !== 1) {
        return "Duplicate student_education created: count = {$eduCount}";
    }
    if ($primaryGoalCount !== 1) {
        return "Duplicate primary goals created: count = {$primaryGoalCount}";
    }
    return true;
}) && $allPassed;

printHeader("8. RE-LOGIN WORKFLOW VERIFICATION");

$allPassed = runCheck("Subsequent login routes completed student directly to dashboard.php", function() use ($baseUrl) {
    $newCookieJar = sys_get_temp_dir() . '/cookie_relogin_' . time() . '.txt';
    $resLogin = makeHttpRequest("{$baseUrl}/login.php", 'GET', [], $newCookieJar);
    $csrf = extractCsrf($resLogin['body']);

    $postData = [
        'csrf_token' => $csrf,
        'email'      => 'priya.sharma@college.edu',
        'password'   => 'Student@123',
    ];
    $resAuth = makeHttpRequest("{$baseUrl}/login.php", 'POST', $postData, $newCookieJar);
    if ($resAuth['code'] !== 302) {
        return "Expected HTTP 302, got {$resAuth['code']}";
    }
    $loc = getHeader($resAuth['headers'], 'Location');
    if (!$loc || strpos($loc, 'dashboard.php') === false) {
        return "Expected direct redirect to dashboard.php for completed student, got '{$loc}'";
    }
    if (file_exists($newCookieJar)) {
        @unlink($newCookieJar);
    }
    return true;
}) && $allPassed;

// Clean up temporary cookie jar
if (file_exists($cookieJarStudent)) {
    @unlink($cookieJarStudent);
}

printHeader("TEST SUITE SUMMARY");

if ($allPassed) {
    echo "\n>>> ALL MODULE 2 TESTS PASSED PERFECTLY AGAINST 'carrearact2' <<<\n\n";
    exit(0);
} else {
    echo "\n>>> SOME TESTS FAILED <<<\n\n";
    exit(1);
}
