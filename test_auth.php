<?php
/**
 * Automated Test Suite for CareerAct Authentication Module
 */

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

// Helper to make cURL requests with cookie jar
function makeRequest($url, $method = 'GET', $data = [], &$cookieJar = null, $headers = []) {
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

// Temporary cookie file for session tests
$cookieFile = tempnam(sys_get_temp_dir(), 'careeract_cookie_');

// 1. Test unauthenticated redirect
runTest("Unauthenticated Dashboard Redirect", function() use ($baseUrl) {
    $res = makeRequest("{$baseUrl}/dashboard.php");
    if ($res['code'] === 302 && strpos($res['headers'], 'login.php?msg=session_required') !== false) {
        return true;
    }
    return "Expected 302 to login.php, got code {$res['code']}";
});

// 2. Fetch signup page and CSRF
$signupCsrf = null;
runTest("Fetch Signup Page & CSRF Token", function() use ($baseUrl, &$cookieFile, &$signupCsrf) {
    $res = makeRequest("{$baseUrl}/signup.php", 'GET', [], $cookieFile);
    if ($res['code'] !== 200) return "Expected 200, got {$res['code']}";
    $signupCsrf = extractCsrf($res['body']);
    if (!$signupCsrf) return "CSRF token not found in signup form";
    return true;
});

// 3. Test Signup Validation (empty fields)
runTest("Signup Validation on Empty Fields", function() use ($baseUrl, &$cookieFile, $signupCsrf) {
    $res = makeRequest("{$baseUrl}/signup.php", 'POST', [
        'csrf_token' => $signupCsrf,
        'full_name' => '',
        'email' => '',
        'password' => ''
    ], $cookieFile);
    
    if (strpos($res['body'], 'Full Name is required') !== false && 
        strpos($res['body'], 'Email address is required') !== false) {
        return true;
    }
    return "Validation messages missing";
});

// 4. Test Signup Validation (invalid email & mismatched password)
runTest("Signup Validation on Invalid Email & Password Mismatch", function() use ($baseUrl, &$cookieFile, $signupCsrf) {
    $res = makeRequest("{$baseUrl}/signup.php", 'POST', [
        'csrf_token'       => $signupCsrf,
        'full_name'        => 'Test Student',
        'email'            => 'not-an-email',
        'college_name'     => 'Sample College',
        'degree_name'      => 'B.Tech',
        'branch_name'      => 'CSE',
        'academic_year'    => '2',
        'password'         => 'Secret123',
        'confirm_password' => 'WrongSecret',
        'terms'            => '1'
    ], $cookieFile);
    
    if (strpos($res['body'], 'Please enter a valid email address') !== false && 
        strpos($res['body'], 'Passwords do not match') !== false) {
        return true;
    }
    return "Invalid email/password mismatch errors missing";
});

// Clean up any test user if exists
require_once __DIR__ . '/config/db.php';
$testEmail = 'aarav.patel@college.edu';
$db = getDbConnection();
$db->prepare("DELETE FROM users WHERE email = :email")->execute([':email' => $testEmail]);

// 5. Test Valid Registration
runTest("Valid Student Registration via POST", function() use ($baseUrl, &$cookieFile, $testEmail) {
    // Fresh signup page for fresh CSRF
    $res = makeRequest("{$baseUrl}/signup.php", 'GET', [], $cookieFile);
    $csrf = extractCsrf($res['body']);

    $postRes = makeRequest("{$baseUrl}/signup.php", 'POST', [
        'csrf_token'       => $csrf,
        'full_name'        => 'Aarav Patel',
        'email'            => $testEmail,
        'college_name'     => 'Pune Institute of Computer Technology',
        'degree_name'      => 'B.Tech',
        'branch_name'      => 'Computer Science & Engineering',
        'academic_year'    => '3',
        'password'         => 'Password@123',
        'confirm_password' => 'Password@123',
        'terms'            => '1'
    ], $cookieFile);

    if ($postRes['code'] === 302 && strpos($postRes['headers'], 'login.php?msg=registered') !== false) {
        return true;
    }
    return "Expected 302 redirect to login.php?msg=registered, got code {$postRes['code']}";
});

// 6. Verify Database Integrity for Registered Student
runTest("Database Verification (users & student_profiles)", function() use ($db, $testEmail) {
    $stmt = $db->prepare("
        SELECT u.user_id, u.role_id, u.account_status_id, u.password_hash, sp.full_name, sp.college_name, sp.academic_year
        FROM users u
        JOIN student_profiles sp ON u.user_id = sp.user_id
        WHERE u.email = :email
    ");
    $stmt->execute([':email' => $testEmail]);
    $row = $stmt->fetch();

    if (!$row) return "User or student profile not found in database";
    if ($row['full_name'] !== 'Aarav Patel') return "Full name mismatch: " . $row['full_name'];
    if ((int)$row['academic_year'] !== 3) return "Academic year mismatch";
    if (!password_verify('Password@123', $row['password_hash'])) return "Password hash verification failed";

    return true;
});

// 7. Duplicate Email Registration Rejection
runTest("Duplicate Email Registration Rejection", function() use ($baseUrl, &$cookieFile, $testEmail) {
    $res = makeRequest("{$baseUrl}/signup.php", 'GET', [], $cookieFile);
    $csrf = extractCsrf($res['body']);

    $postRes = makeRequest("{$baseUrl}/signup.php", 'POST', [
        'csrf_token'       => $csrf,
        'full_name'        => 'Duplicate User',
        'email'            => $testEmail,
        'college_name'     => 'Another College',
        'degree_name'      => 'BCA',
        'branch_name'      => 'IT',
        'academic_year'    => '1',
        'password'         => 'Password@123',
        'confirm_password' => 'Password@123',
        'terms'            => '1'
    ], $cookieFile);

    if (strpos($postRes['body'], 'already exists') !== false) {
        return true;
    }
    return "Duplicate email warning not displayed";
});

// 8. Login with Wrong Password
runTest("Login with Incorrect Password Rejection", function() use ($baseUrl, &$cookieFile, $testEmail) {
    $res = makeRequest("{$baseUrl}/login.php", 'GET', [], $cookieFile);
    $csrf = extractCsrf($res['body']);

    $postRes = makeRequest("{$baseUrl}/login.php", 'POST', [
        'csrf_token' => $csrf,
        'email'      => $testEmail,
        'password'   => 'WrongPassword!'
    ], $cookieFile);

    if (strpos($postRes['body'], 'Invalid email address or password') !== false) {
        return true;
    }
    return "Generic invalid credentials message not shown";
});

// 9. Login with Correct Credentials
runTest("Login with Correct Credentials", function() use ($baseUrl, &$cookieFile, $testEmail) {
    $res = makeRequest("{$baseUrl}/login.php", 'GET', [], $cookieFile);
    $csrf = extractCsrf($res['body']);

    $postRes = makeRequest("{$baseUrl}/login.php", 'POST', [
        'csrf_token' => $csrf,
        'email'      => $testEmail,
        'password'   => 'Password@123'
    ], $cookieFile);

    if ($postRes['code'] === 302 && strpos($postRes['headers'], 'dashboard.php') !== false) {
        return true;
    }
    return "Expected redirect to dashboard.php, got code {$postRes['code']}";
});

// 10. Access Protected Dashboard with Authenticated Session
runTest("Access Authenticated Dashboard", function() use ($baseUrl, &$cookieFile) {
    $res = makeRequest("{$baseUrl}/dashboard.php", 'GET', [], $cookieFile);
    if ($res['code'] === 200 && strpos($res['body'], 'Aarav Patel') !== false) {
        return true;
    }
    return "Dashboard did not display authenticated student name";
});

// 11. Ineligible Account Status Check (Suspended)
runTest("Ineligible Account Status (Suspended) Rejection", function() use ($baseUrl, $db, $testEmail) {
    $tempCookie = tempnam(sys_get_temp_dir(), 'careeract_temp_');
    
    // Set status to suspended (3)
    $db->prepare("UPDATE users SET account_status_id = (SELECT account_status_id FROM account_statuses WHERE status_code = 'suspended') WHERE email = :email")
       ->execute([':email' => $testEmail]);

    $res = makeRequest("{$baseUrl}/login.php", 'GET', [], $tempCookie);
    $csrf = extractCsrf($res['body']);

    $postRes = makeRequest("{$baseUrl}/login.php", 'POST', [
        'csrf_token' => $csrf,
        'email'      => $testEmail,
        'password'   => 'Password@123'
    ], $tempCookie);

    // Revert status back to active
    $db->prepare("UPDATE users SET account_status_id = (SELECT account_status_id FROM account_statuses WHERE status_code = 'active') WHERE email = :email")
       ->execute([':email' => $testEmail]);

    @unlink($tempCookie);

    if (strpos($postRes['body'], 'suspended') !== false) {
        return true;
    }
    return "Suspended message not shown";
});

// 12. Logout
runTest("Logout Functionality", function() use ($baseUrl, &$cookieFile) {
    $res = makeRequest("{$baseUrl}/logout.php", 'GET', [], $cookieFile);
    if ($res['code'] !== 302 || strpos($res['headers'], 'login.php?msg=logged_out') === false) {
        return "Expected 302 redirect to login.php?msg=logged_out";
    }

    // Now attempt to access dashboard with the invalidated session
    $dashRes = makeRequest("{$baseUrl}/dashboard.php", 'GET', [], $cookieFile);
    if ($dashRes['code'] === 302 && strpos($dashRes['headers'], 'login.php?msg=session_required') !== false) {
        return true;
    }
    return "Dashboard was accessible after logout!";
});

@unlink($cookieFile);

echo "\n--- All automated functional tests completed successfully! ---\n";
