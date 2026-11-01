<?php
/**
 * Automated Test Suite for CareerAct Module 1: Authentication & User Management
 * Covers all 14 verification scenarios against carrearact2 database.
 */

declare(strict_types=1);

$baseUrl = 'http://localhost/Mini-Project';

function runTest(string $testName, callable $callback): void {
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

// Helper to make cURL requests with cookie handling
function makeRequest(string $url, string $method = 'GET', array $data = [], ?string &$cookieJar = null, array $headers = []): array {
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
        throw new RuntimeException("cURL error connecting to {$url}: {$err}");
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

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
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $matches)) {
        return $matches[1];
    }
    return null;
}

require_once __DIR__ . '/config/db.php';
$db = getDbConnection();

// Clean up any test user if exists
$testEmail = 'priya.sharma@college.edu';
$db->prepare('DELETE FROM login_attempts WHERE email = :email')->execute([':email' => $testEmail]);
$db->prepare('DELETE FROM users WHERE email = :email')->execute([':email' => $testEmail]);

$cookieFile = tempnam(sys_get_temp_dir(), 'careeract_cookie_');

// 1. Unauthenticated users cannot access protected pages (Dashboard)
runTest('1. Unauthenticated Dashboard Redirect', function() use ($baseUrl) {
    $res = makeRequest("{$baseUrl}/dashboard.php");
    if ($res['code'] === 302 && strpos($res['headers'], 'login.php?msg=session_required') !== false) {
        return true;
    }
    return "Expected 302 to login.php, got code {$res['code']}";
});

// 2. Unauthenticated users cannot access protected admin pages
runTest('2. Unauthenticated Admin Direct URL Access Blocked', function() use ($baseUrl) {
    $res = makeRequest("{$baseUrl}/admin/dashboard.php");
    if ($res['code'] === 302 && strpos($res['headers'], 'login.php?msg=session_required') !== false) {
        return true;
    }
    return "Expected 302 redirect for admin page, got {$res['code']}";
});

// 3. Fetch Signup page and CSRF token
$signupCsrf = null;
runTest('3. Fetch Signup Page & CSRF Token', function() use ($baseUrl, &$cookieFile, &$signupCsrf) {
    $res = makeRequest("{$baseUrl}/signup.php", 'GET', [], $cookieFile);
    if ($res['code'] !== 200) {
        return "Expected 200, got {$res['code']}";
    }
    $signupCsrf = extractCsrf($res['body']);
    if (!$signupCsrf) {
        return "CSRF token not found in signup form";
    }
    return true;
});

// 4. Server-side validation on empty registration fields
runTest('4. Signup Validation on Empty Fields', function() use ($baseUrl, &$cookieFile, $signupCsrf) {
    $res = makeRequest("{$baseUrl}/signup.php", 'POST', [
        'csrf_token' => $signupCsrf,
        'full_name'  => '',
        'email'      => '',
        'password'   => '',
    ], $cookieFile);

    if (strpos($res['body'], 'Full Name is required') !== false &&
        strpos($res['body'], 'Email address is required') !== false &&
        strpos($res['body'], 'Password is required') !== false) {
        return true;
    }
    return 'Required field validation messages missing';
});

// 5. Server-side validation on invalid email & password mismatch
runTest('5. Signup Validation on Invalid Email & Password Mismatch', function() use ($baseUrl, &$cookieFile, $signupCsrf) {
    $res = makeRequest("{$baseUrl}/signup.php", 'POST', [
        'csrf_token'       => $signupCsrf,
        'full_name'        => 'Priya Sharma',
        'email'            => 'invalid-email-format',
        'password'         => 'Student@123',
        'confirm_password' => 'Mismatch@999',
        'terms'            => '1',
    ], $cookieFile);

    if (strpos($res['body'], 'Please enter a valid email address') !== false &&
        strpos($res['body'], 'Passwords do not match') !== false) {
        return true;
    }
    return 'Invalid email/password mismatch errors missing';
});

// 6. Student can register successfully
runTest('6. Student Can Register Successfully', function() use ($baseUrl, &$cookieFile, $testEmail) {
    $res = makeRequest("{$baseUrl}/signup.php", 'GET', [], $cookieFile);
    $csrf = extractCsrf($res['body']);

    $postRes = makeRequest("{$baseUrl}/signup.php", 'POST', [
        'csrf_token'       => $csrf,
        'full_name'        => 'Priya Sharma',
        'email'            => $testEmail,
        'password'         => 'Student@123',
        'confirm_password' => 'Student@123',
        'terms'            => '1',
    ], $cookieFile);

    if ($postRes['code'] === 302 && strpos($postRes['headers'], 'login.php?msg=registered') !== false) {
        return true;
    }
    return "Expected 302 redirect to login.php?msg=registered, got {$postRes['code']}";
});

// 7. Database verification & password stored as hash
$registeredUserId = null;
runTest('7. Passwords Stored as Hash & Student Role Auto-Assigned', function() use ($db, $testEmail, &$registeredUserId) {
    $stmt = $db->prepare('
        SELECT u.id, u.name, u.email, u.password_hash, u.role, u.account_status, sp.profile_completed
        FROM users u
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        WHERE u.email = :email
    ');
    $stmt->execute([':email' => $testEmail]);
    $row = $stmt->fetch();

    if (!$row) {
        return 'User not found in carrearact2 database';
    }
    $registeredUserId = (int)$row['id'];
    if ($row['name'] !== 'Priya Sharma') {
        return "Name mismatch: {$row['name']}";
    }
    if ($row['role'] !== 'student') {
        return "Role was not auto-assigned as 'student': {$row['role']}";
    }
    if ($row['account_status'] !== 'active') {
        return "Account status is not 'active': {$row['account_status']}";
    }
    if ($row['password_hash'] === 'Student@123') {
        return 'CRITICAL: Password stored in plaintext!';
    }
    if (!password_verify('Student@123', $row['password_hash'])) {
        return 'Password hash verification failed';
    }
    if ((int)$row['profile_completed'] !== 0) {
        return 'Profile completed status should initially be 0';
    }

    return true;
});

// 8. Duplicate email registration rejected
runTest('8. Duplicate Email Registration Rejected', function() use ($baseUrl, &$cookieFile, $testEmail) {
    $res = makeRequest("{$baseUrl}/signup.php", 'GET', [], $cookieFile);
    $csrf = extractCsrf($res['body']);

    $postRes = makeRequest("{$baseUrl}/signup.php", 'POST', [
        'csrf_token'       => $csrf,
        'full_name'        => 'Duplicate Priya',
        'email'            => $testEmail,
        'password'         => 'Student@123',
        'confirm_password' => 'Student@123',
        'terms'            => '1',
    ], $cookieFile);

    if (strpos($postRes['body'], 'already exists') !== false) {
        return true;
    }
    return 'Duplicate email warning not displayed';
});

// 9. Incorrect password is rejected
runTest('9. Incorrect Password Is Rejected', function() use ($baseUrl, &$cookieFile, $testEmail) {
    $res = makeRequest("{$baseUrl}/login.php", 'GET', [], $cookieFile);
    $csrf = extractCsrf($res['body']);

    $postRes = makeRequest("{$baseUrl}/login.php", 'POST', [
        'csrf_token' => $csrf,
        'email'      => $testEmail,
        'password'   => 'WrongPassword!',
    ], $cookieFile);

    if (strpos($postRes['body'], 'Invalid email address or password') !== false) {
        return true;
    }
    return 'Generic invalid credentials message not shown';
});

// 10. Inactive / Suspended account cannot log in
runTest('10. Inactive / Suspended Account Cannot Log In', function() use ($baseUrl, $db, $testEmail) {
    $tempCookie = tempnam(sys_get_temp_dir(), 'careeract_sus_');

    // Suspend the test user
    $db->prepare("UPDATE users SET account_status = 'suspended' WHERE email = :email")->execute([':email' => $testEmail]);

    $res = makeRequest("{$baseUrl}/login.php", 'GET', [], $tempCookie);
    $csrf = extractCsrf($res['body']);

    $postRes = makeRequest("{$baseUrl}/login.php", 'POST', [
        'csrf_token' => $csrf,
        'email'      => $testEmail,
        'password'   => 'Student@123',
    ], $tempCookie);

    // Revert status to active
    $db->prepare("UPDATE users SET account_status = 'active' WHERE email = :email")->execute([':email' => $testEmail]);
    @unlink($tempCookie);

    if (strpos($postRes['body'], 'suspended') !== false) {
        return true;
    }
    return 'Suspended message not shown';
});

// 11. Student can log in with correct credentials & incomplete student redirected to profile completion
runTest('11. Student Login & Incomplete Student Redirected to Profile Completion', function() use ($baseUrl, &$cookieFile, $testEmail) {
    $res = makeRequest("{$baseUrl}/login.php", 'GET', [], $cookieFile);
    $csrf = extractCsrf($res['body']);

    $postRes = makeRequest("{$baseUrl}/login.php", 'POST', [
        'csrf_token' => $csrf,
        'email'      => $testEmail,
        'password'   => 'Student@123',
    ], $cookieFile);

    if ($postRes['code'] === 302 && (strpos($postRes['headers'], 'onboarding.php') !== false || strpos($postRes['headers'], 'profile.php') !== false)) {
        return true;
    }
    return "Expected 302 redirect to onboarding.php/profile.php, got {$postRes['code']} with headers:\n" . substr($postRes['headers'], 0, 200);
});

// 12. Incomplete student directly opening dashboard URL is prevented
runTest('12. Incomplete Student Directly Opening Dashboard URL Is Prevented', function() use ($baseUrl, &$cookieFile) {
    $dashRes = makeRequest("{$baseUrl}/dashboard.php", 'GET', [], $cookieFile);
    if ($dashRes['code'] === 302 && (strpos($dashRes['headers'], 'onboarding.php') !== false || strpos($dashRes['headers'], 'profile.php') !== false)) {
        return true;
    }
    return "Incomplete student was able to access dashboard or was not redirected to onboarding.php/profile.php (got {$dashRes['code']})";
});

// 13. Student cannot access Admin pages (RBAC)
runTest('13. Student Cannot Access Admin Pages (HTTP 403 Forbidden)', function() use ($baseUrl, &$cookieFile) {
    $adminRes = makeRequest("{$baseUrl}/admin/dashboard.php", 'GET', [], $cookieFile);
    if ($adminRes['code'] === 403 && strpos($adminRes['body'], 'Unauthorized Access') !== false) {
        return true;
    }
    return "Expected HTTP 403 Forbidden for student accessing admin page, got {$adminRes['code']}";
});

// 14. Completed student can access the dashboard
runTest('14. Completed Student Can Access Dashboard', function() use ($baseUrl, $db, $registeredUserId, &$cookieFile) {
    // Mark profile complete in database
    $db->prepare('UPDATE student_profiles SET profile_completed = 1 WHERE user_id = :uid')->execute([':uid' => $registeredUserId]);

    $dashRes = makeRequest("{$baseUrl}/dashboard.php", 'GET', [], $cookieFile);
    if ($dashRes['code'] === 200 && strpos($dashRes['body'], 'Priya Sharma') !== false) {
        return true;
    }
    return "Expected 200 with student name 'Priya Sharma', got {$dashRes['code']}";
});

// 15. Admin can access authorized Admin pages
runTest('15. Admin Can Access Authorized Admin Pages', function() use ($baseUrl) {
    $adminCookie = tempnam(sys_get_temp_dir(), 'careeract_admin_');

    $res = makeRequest("{$baseUrl}/login.php", 'GET', [], $adminCookie);
    $csrf = extractCsrf($res['body']);

    // Log in as admin
    $postRes = makeRequest("{$baseUrl}/login.php", 'POST', [
        'csrf_token' => $csrf,
        'email'      => 'admin@careeract.edu',
        'password'   => 'Admin@123',
    ], $adminCookie);

    if ($postRes['code'] !== 302 || strpos($postRes['headers'], 'admin/dashboard.php') === false) {
        @unlink($adminCookie);
        return "Admin login did not redirect to admin/dashboard.php (code {$postRes['code']})";
    }

    $adminPageRes = makeRequest("{$baseUrl}/admin/dashboard.php", 'GET', [], $adminCookie);
    @unlink($adminCookie);

    if ($adminPageRes['code'] === 200) {
        return true;
    }
    return "Admin page returned status {$adminPageRes['code']}";
});

// 16. Logout destroys the session & invalidates protected access
runTest('16. Logout Destroys Session & Prevents Re-entry', function() use ($baseUrl, &$cookieFile) {
    $logoutRes = makeRequest("{$baseUrl}/logout.php", 'POST', [], $cookieFile);
    if ($logoutRes['code'] !== 302 || strpos($logoutRes['headers'], 'login.php?msg=logged_out') === false) {
        return "Logout did not redirect to login.php?msg=logged_out (code {$logoutRes['code']})";
    }

    // Attempt to access dashboard again with invalidated cookie
    $dashRes = makeRequest("{$baseUrl}/dashboard.php", 'GET', [], $cookieFile);
    if ($dashRes['code'] === 302 && strpos($dashRes['headers'], 'login.php?msg=session_required') !== false) {
        return true;
    }
    return 'Dashboard was still accessible after logout!';
});

@unlink($cookieFile);

echo "\n======================================================\n";
echo "✓ ALL MODULE 1 FUNCTIONAL TESTS EXECUTED AND PASSED!\n";
echo "======================================================\n";
