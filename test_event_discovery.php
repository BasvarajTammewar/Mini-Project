<?php
/**
 * CareerAct — Verification Suite: Event Discovery & Career-Targeted Extracurricular Activities
 * Tests career goal matching, city filtering (Pune, Mumbai, Bengaluru, Delhi, Hyderabad, Online),
 * real-world opportunity retrieval, automatic expiration, saving/enrollment, and zero-error loading.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/event_discovery_service.php';

$db = getDbConnection();

$totalTests = 0;
$passedTests = 0;

function runTest(string $title, callable $testFn): void {
    global $totalTests, $passedTests;
    $totalTests++;
    echo "Running [$title]... ";
    try {
        $result = $testFn();
        if ($result === true) {
            $passedTests++;
            echo "PASSED\n";
        } else {
            echo "FAILED: $result\n";
        }
    } catch (Throwable $e) {
        echo "FAILED with exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

echo "========================================================================\n";
echo " CAREERACT — EVENT DISCOVERY & EXTRACURRICULAR ACTIVITY TEST SUITE\n";
echo " Target Database: carrearact2\n";
echo "========================================================================\n\n";

// Setup Test User: Priya Sharma (or active student)
$stmt = $db->query("SELECT id, name, email FROM users WHERE email = 'priya.sharma@college.edu' OR role = 'student' LIMIT 1");
$studentUser = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$studentUser) {
    die("Error: No student user found for testing.\n");
}
$testUserId = (int)$studentUser['id'];

// Reset password hash to Student@123 for test determinism
$db->prepare("UPDATE users SET password_hash = :hash WHERE id = :uid")
   ->execute([':hash' => password_hash('Student@123', PASSWORD_DEFAULT), ':uid' => $testUserId]);

// Ensure completed profile and preferred city = 'Pune'
$stmtProf = $db->prepare("
    INSERT INTO student_profiles (user_id, education_level, city, experience_level, preferred_mode, profile_completed, updated_at)
    VALUES (:uid, 'undergraduate', 'Pune', 'beginner', 'any', 1, NOW())
    ON DUPLICATE KEY UPDATE profile_completed = 1, city = 'Pune', updated_at = NOW()
");
$stmtProf->execute([':uid' => $testUserId]);

// Ensure Career Goal: Software Developer (ID 4)
$stmtDel = $db->prepare("DELETE FROM student_career_goals WHERE user_id = :uid");
$stmtDel->execute([':uid' => $testUserId]);

$stmtGoal = $db->prepare("
    INSERT INTO student_career_goals (user_id, career_id, is_primary, created_at)
    VALUES (:uid, 4, 1, NOW())
");
$stmtGoal->execute([':uid' => $testUserId]);

// Test 1: Unauthenticated request
runTest("1. Unauthenticated request to activities.php redirects to login.php", function() {
    $ch = curl_init('http://localhost/Mini-Project/activities.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    if ($httpCode === 302 && stripos($redirectUrl, 'login.php') !== false) {
        return true;
    }
    return "Expected HTTP 302 redirect to login.php, got HTTP $httpCode, redirect: $redirectUrl";
});

// Test 2: Authenticated request renders without SQL / column errors
runTest("2. Authenticated student loads activities.php with HTTP 200 (no student_id error)", function() use ($testUserId, $studentUser) {
    // Simulate web session
    $cookieFile = __DIR__ . '/scratch/test_activities_cookie.txt';
    if (file_exists($cookieFile)) unlink($cookieFile);

    // Login via POST to establish clean session
    $ch = curl_init('http://localhost/Mini-Project/login.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $loginPage = curl_exec($ch);

    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $loginPage, $m);
    $csrf = $m[1] ?? '';

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'csrf_token' => $csrf,
        'email'      => $studentUser['email'],
        'password'   => 'Student@123'
    ]));
    curl_exec($ch);

    // Request activities.php
    curl_setopt($ch, CURLOPT_POST, false);
    curl_setopt($ch, CURLOPT_URL, 'http://localhost/Mini-Project/activities.php');
    $dashContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return "Expected HTTP 200, got $httpCode";
    }

    if (stripos($dashContent, 'Fatal error') !== false || stripos($dashContent, 'PDOException') !== false) {
        return "Page contains PHP Fatal error / PDOException!";
    }

    if (stripos($dashContent, 'Target Career Goal') === false && stripos($dashContent, 'Software developer') === false) {
        return "Target career goal banner missing from page!";
    }

    return true;
});

// Test 3: Recommended Activities for Software Developer
runTest("3. System recommends career-targeted activities for Software Developer", function() use ($db, $testUserId) {
    $activities = getRecommendedActivities($db, 4, $testUserId);
    if (empty($activities)) {
        return "No activities returned for Software Developer (Career ID 4)";
    }
    
    $titles = array_column($activities, 'title');
    $hasHackathon = false;
    $hasSoftwareProject = false;
    foreach ($titles as $t) {
        if (stripos($t, 'hackathon') !== false) $hasHackathon = true;
        if (stripos($t, 'software') !== false || stripos($t, 'code') !== false) $hasSoftwareProject = true;
    }

    if (!$hasHackathon) {
        return "Expected hackathon activity in recommendations, found: " . implode('; ', $titles);
    }
    if (!$hasSoftwareProject) {
        return "Expected software project activity in recommendations, found: " . implode('; ', $titles);
    }

    return true;
});

// Test 4: City Filtering for Pune
runTest("4. Preferred City filter: Selecting 'Pune' returns Pune opportunities and Online events", function() use ($db, $testUserId) {
    $opps = getEventOpportunities($db, ['city' => 'Pune'], $testUserId);
    if (empty($opps)) {
        return "No opportunities returned for Pune!";
    }

    foreach ($opps as $o) {
        $cityName = strtolower($o['city_name']);
        $mode = strtolower($o['delivery_mode']);
        if (stripos($cityName, 'pune') === false && $mode !== 'online') {
            return "Opportunity {$o['title']} is neither in Pune nor online! City: {$o['city_name']}, Mode: $mode";
        }
    }
    return true;
});

// Test 5: City Filtering across all major hubs
runTest("5. City Filtering across Mumbai, Bengaluru, Delhi, and Hyderabad", function() use ($db, $testUserId) {
    $hubs = ['Mumbai', 'Bengaluru', 'New Delhi', 'Hyderabad'];
    foreach ($hubs as $hub) {
        $opps = getEventOpportunities($db, ['city' => $hub], $testUserId);
        if (empty($opps)) {
            return "No opportunities returned when filtering for hub: $hub";
        }
        $hasLocal = false;
        foreach ($opps as $o) {
            if (stripos($o['city_name'], $hub) !== false || (stripos($hub, 'Delhi') !== false && stripos($o['city_name'], 'Delhi') !== false)) {
                $hasLocal = true;
                break;
            }
        }
        if (!$hasLocal) {
            return "Did not find local events for $hub in returned results";
        }
    }
    return true;
});

// Test 6: Automatic event expiration logic
runTest("6. Automatic event expiration marks past deadline events as 'expired'", function() use ($db, $testUserId) {
    // 1. Check expired event from migration
    $stmtExp = $db->query("SELECT status, registration_deadline FROM opportunities WHERE external_id = 'EXPIRED-HACK-2025'");
    $exp = $stmtExp->fetch(PDO::FETCH_ASSOC);
    if (!$exp || $exp['status'] !== 'expired') {
        return "Expected EXPIRED-HACK-2025 to have status 'expired', got " . ($exp['status'] ?? 'none');
    }

    // 2. Verify getEventOpportunities excludes expired events by default
    $activeOpps = getEventOpportunities($db, [], $testUserId);
    foreach ($activeOpps as $ao) {
        if ($ao['status'] === 'expired') {
            return "Active opportunities list contains expired event {$ao['title']}";
        }
        if (!empty($ao['registration_deadline']) && strtotime($ao['registration_deadline']) < strtotime('today')) {
            return "Active opportunities list contains event with past deadline {$ao['registration_deadline']}";
        }
    }

    return true;
});

// Test 7: Search and Format Filtering
runTest("7. Search keyword and delivery mode filtering", function() use ($db, $testUserId) {
    // Search 'Hackathon'
    $hackOpps = getEventOpportunities($db, ['search' => 'Hackathon'], $testUserId);
    if (empty($hackOpps)) {
        return "Search for 'Hackathon' returned 0 results";
    }

    // Delivery mode 'online'
    $onlineOpps = getEventOpportunities($db, ['delivery_mode' => 'online'], $testUserId);
    if (empty($onlineOpps)) {
        return "Filter for 'online' returned 0 results";
    }
    foreach ($onlineOpps as $oo) {
        if ($oo['delivery_mode'] !== 'online') {
            return "Filter for delivery_mode 'online' returned item with mode {$oo['delivery_mode']}";
        }
    }

    return true;
});

// Test 8: Save and Enroll Opportunity
runTest("8. Student can save and register for an opportunity in enrollments table", function() use ($db, $testUserId) {
    // Get a live opportunity
    $stmtOpp = $db->query("SELECT id, activity_id FROM opportunities WHERE status = 'published' LIMIT 1");
    $opp = $stmtOpp->fetch(PDO::FETCH_ASSOC);
    if (!$opp) return "No published opportunity found";

    $oppId = (int)$opp['id'];
    $actId = (int)$opp['activity_id'];

    // 1. Save opportunity
    $resSave = saveOrEnrollOpportunity($db, $testUserId, $actId, $oppId, 'saved');
    if (!$resSave['success']) return "Failed to save opportunity";

    $stmtCheck = $db->prepare("SELECT id, status FROM enrollments WHERE user_id = :uid AND opportunity_id = :oid");
    $stmtCheck->execute([':uid' => $testUserId, ':oid' => $oppId]);
    $savedRecord = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if (!$savedRecord || $savedRecord['status'] !== 'saved') {
        return "Expected enrollment record with status 'saved', got " . ($savedRecord['status'] ?? 'null');
    }

    // 2. Update to registered
    $resReg = saveOrEnrollOpportunity($db, $testUserId, $actId, $oppId, 'registered');
    if (!$resReg['success']) return "Failed to update opportunity to registered";

    $stmtCheck->execute([':uid' => $testUserId, ':oid' => $oppId]);
    $regRecord = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if (!$regRecord || $regRecord['status'] !== 'registered') {
        return "Expected enrollment record with status 'registered', got " . ($regRecord['status'] ?? 'null');
    }

    return true;
});

// Test 9: Saved Activities & Enrollments Pages load successfully
runTest("9. Saved Activities (saved_activities.php) and Enrollments (enrollments.php) load with HTTP 200", function() use ($studentUser) {
    $cookieFile = __DIR__ . '/scratch/test_activities_cookie.txt';

    $ch = curl_init('http://localhost/Mini-Project/saved_activities.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $savedContent = curl_exec($ch);
    $httpSaved = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_setopt($ch, CURLOPT_URL, 'http://localhost/Mini-Project/enrollments.php');
    $enrContent = curl_exec($ch);
    $httpEnr = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpSaved !== 200) {
        return "saved_activities.php returned HTTP $httpSaved";
    }
    if (stripos($savedContent, 'Fatal error') !== false || stripos($savedContent, 'PDOException') !== false) {
        return "saved_activities.php has PHP fatal error!";
    }

    if ($httpEnr !== 200) {
        return "enrollments.php returned HTTP $httpEnr";
    }
    if (stripos($enrContent, 'Fatal error') !== false || stripos($enrContent, 'PDOException') !== false) {
        return "enrollments.php has PHP fatal error!";
    }

    return true;
});

// Test 10: Official Registration Links Integrity
runTest("10. Verified opportunities have valid official registration URLs", function() use ($db) {
    $stmt = $db->query("
        SELECT id, title, registration_url, source_url 
        FROM opportunities 
        WHERE status = 'published' AND is_sample = 0
    ");
    $opps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($opps)) {
        return "No published opportunities found";
    }

    foreach ($opps as $o) {
        $url = $o['registration_url'] ?: $o['source_url'];
        if (empty($url) || (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://'))) {
            return "Opportunity {$o['title']} has invalid or missing official URL: " . ($url ?: 'empty');
        }
    }
    return true;
});

echo "\n========================================================================\n";
echo " EVENT DISCOVERY TEST RESULTS: $passedTests / $totalTests PASSED\n";
if ($passedTests === $totalTests) {
    echo " >>> ALL EVENT DISCOVERY & ACTIVITY TESTS PASSED 100%! <<<\n";
} else {
    echo " >>> SOME TESTS FAILED <<<\n";
}
echo "========================================================================\n";
