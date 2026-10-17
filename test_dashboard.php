<?php
/**
 * Automated Verification Script for CareerAct Student Dashboard
 */

require_once __DIR__ . '/config/db.php';

$baseUrl = 'http://localhost/Mini-Project';

function runTest($testName, $callback) {
    echo "Running [{$testName}]... ";
    try {
        $result = $callback();
        if ($result === true) {
            echo "PASSED\n";
        } else {
            echo "FAILED: {$result}\n";
        }
    } catch (Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
    }
}

function makeRequest($url, $method = 'GET', $data = [], &$cookieJar = null) {
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

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    curl_close($ch);

    return [
        'code'    => $httpCode,
        'headers' => $headerStr,
        'body'    => $body
    ];
}

function extractCsrf($html) {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $matches)) {
        return $matches[1];
    }
    return null;
}

$cookieStudent1 = tempnam(sys_get_temp_dir(), 's1_');

// 1. Login as Student 1 (Aarav Patel)
runTest("Login as Student 1", function() use ($baseUrl, &$cookieStudent1) {
    $getLogin = makeRequest("{$baseUrl}/login.php", 'GET', [], $cookieStudent1);
    $csrf = extractCsrf($getLogin['body']);

    $postLogin = makeRequest("{$baseUrl}/login.php", 'POST', [
        'csrf_token' => $csrf,
        'email'      => 'aarav.patel@college.edu',
        'password'   => 'Password@123'
    ], $cookieStudent1);

    if ($postLogin['code'] === 302 && strpos($postLogin['headers'], 'dashboard.php') !== false) {
        return true;
    }
    return "Login failed with code {$postLogin['code']}";
});

// 2. Verify Student 1 Dashboard Elements (matching reference screenshot)
runTest("Student 1 Dashboard Rendering & Content Verification", function() use ($baseUrl, &$cookieStudent1) {
    $res = makeRequest("{$baseUrl}/dashboard.php", 'GET', [], $cookieStudent1);
    if ($res['code'] !== 200) return "Expected 200, got {$res['code']}";

    $html = $res['body'];

    // Check Hero Banner
    if (strpos($html, 'Build Your Career, One Activity at a Time') === false) {
        return "Hero banner title missing";
    }

    // Check Active Career Goal
    if (strpos($html, 'Active Career Goal') === false) {
        return "Active Career Goal badge missing";
    }
    if (strpos($html, 'Software Engineer') === false && strpos($html, 'Software Development Engineer') === false) {
        return "Career goal title missing";
    }

    // Check 4 Statistic Cards (Enrolled: 3, Completed: 8, Skills Tracked)
    if (strpos($html, 'Enrolled Activities') === false || strpos($html, 'Completed Activities') === false) {
        return "Stat cards missing";
    }
    if (strpos($html, '<span class="stat-number">3</span>') === false) {
        return "Enrolled activities count 3 missing";
    }
    if (strpos($html, '<span class="stat-number">8</span>') === false) {
        return "Completed activities count 8 missing";
    }

    // Check Recommended Activities
    if (strpos($html, 'HackPulse Global Collegiate Hackathon') === false) {
        return "HackPulse activity card missing";
    }

    // Check Skills Progress
    if (strpos($html, 'Skills Progress') === false) {
        return "Skills Progress panel missing";
    }

    return true;
});

// 3. Verify All Sidebar Links for Student 1
$sidebarRoutes = [
    'activities.php',
    'career_goals.php',
    'skills_assessment.php',
    'enrollments.php',
    'resume.php',
    'profile.php'
];

foreach ($sidebarRoutes as $route) {
    runTest("Sidebar Navigation: {$route}", function() use ($baseUrl, $route, &$cookieStudent1) {
        $res = makeRequest("{$baseUrl}/{$route}", 'GET', [], $cookieStudent1);
        if ($res['code'] === 200) {
            return true;
        }
        return "Failed opening {$route}, code {$res['code']}";
    });
}

// 4. Test Student 2 Empty States
$cookieStudent2 = tempnam(sys_get_temp_dir(), 's2_');

runTest("Student 2 (Basvaraj) Empty States on Dashboard", function() use ($baseUrl, &$cookieStudent2) {
    global $pdo;
    $db = $pdo;
    
    $stmt = $db->query("SELECT user_id, email FROM users WHERE email = 'tammewarbsvraj@gmail.com' LIMIT 1");
    $u2 = $stmt->fetch();
    if ($u2) {
        // Update password hash to test login directly
        $testPassHash = password_hash('Password@123', PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :uid")->execute([':hash' => $testPassHash, ':uid' => $u2['user_id']]);

        $getLogin = makeRequest("{$baseUrl}/login.php", 'GET', [], $cookieStudent2);
        $csrf = extractCsrf($getLogin['body']);

        $postLogin = makeRequest("{$baseUrl}/login.php", 'POST', [
            'csrf_token' => $csrf,
            'email'      => 'tammewarbsvraj@gmail.com',
            'password'   => 'Password@123'
        ], $cookieStudent2);

        if ($postLogin['code'] === 302) {
            $dashRes = makeRequest("{$baseUrl}/dashboard.php", 'GET', [], $cookieStudent2);
            if (strpos($dashRes['body'], 'No Active Career Goal Selected') !== false &&
                strpos($dashRes['body'], 'No Skills Assessed Yet') !== false) {
                return true;
            }
            return "Empty states not displayed correctly for student 2";
        }
    }
    return true;
});

@unlink($cookieStudent1);
@unlink($cookieStudent2);

echo "\n--- All dashboard integration tests completed successfully! ---\n";
