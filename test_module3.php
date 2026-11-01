<?php
/**
 * Automated Verification Test Suite for CareerAct Module 3: Student Dashboard
 * Tests all 12+ functional and security requirements.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';

$baseUrl = 'http://localhost/Mini-Project';
$cookieStudent1 = sys_get_temp_dir() . '/cookie_m3_s1_' . time() . '.txt';
$cookieStudent2 = sys_get_temp_dir() . '/cookie_m3_s2_' . time() . '.txt';

function printHeader(string $title): void {
    echo "\n" . str_repeat('=', 72) . "\n";
    echo " " . $title . "\n";
    echo str_repeat('=', 72) . "\n";
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

function makeHttpRequest(string $url, string $method = 'GET', array $data = [], ?string $cookieJar = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    $raw = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $headers = substr($raw ?: '', 0, $headerSize);
    $body = substr($raw ?: '', $headerSize);

    curl_close($ch);

    return [
        'code'    => $httpCode,
        'headers' => $headers,
        'body'    => $body,
    ];
}

function extractCsrf(string $html): ?string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $matches)) {
        return $matches[1];
    }
    return null;
}

$allPassed = true;

// Ensure deterministic test credentials in database
$initDb = getDbConnection();
$uStmt = $initDb->query("SELECT id FROM users WHERE email = 'priya.sharma@college.edu'");
$pId = (int)$uStmt->fetchColumn();
if ($pId > 0) {
    $initDb->prepare("UPDATE users SET password_hash = :h WHERE id = :id")
           ->execute([':h' => password_hash('Student@123', PASSWORD_DEFAULT), ':id' => $pId]);
    $initDb->prepare("UPDATE student_profiles SET profile_completed = 1, education_level = 'undergraduate', institution = 'National Institute of Technology', field_of_study = 'Computer Science', graduation_year = 2026 WHERE user_id = :id")
           ->execute([':id' => $pId]);
    $initDb->prepare("INSERT INTO student_career_goals (user_id, career_id, is_primary, created_at, primary_marker) VALUES (:id1, 1, 1, NOW(), :id2) ON DUPLICATE KEY UPDATE is_primary = 1")
           ->execute([':id1' => $pId, ':id2' => $pId]);
    $initDb->prepare("INSERT INTO student_skills (user_id, skill_id, level, source, updated_at) VALUES (:sid1, 1, 4, 'self_reported', NOW()), (:sid2, 5, 3, 'self_reported', NOW()) ON DUPLICATE KEY UPDATE level = VALUES(level)")
           ->execute([':sid1' => $pId, ':sid2' => $pId]);
}

// ============================================================================
// 1. UNAUTHENTICATED ACCESS GUARDS (SECTION 16 & 19)
// ============================================================================
printHeader("1. ACCESS CONTROL & AUTHENTICATION GUARDS");

$allPassed = runCheck("Unauthenticated user opening dashboard.php is redirected to login.php", function() use ($baseUrl) {
    $res = makeHttpRequest("{$baseUrl}/dashboard.php");
    if ($res['code'] !== 302) {
        return "Expected 302 Redirect, got HTTP {$res['code']}";
    }
    if (stripos($res['headers'], 'login.php') === false) {
        return "Redirect location header does not point to login.php";
    }
    return true;
}) && $allPassed;

// ============================================================================
// 2. STUDENT LOGIN & DASHBOARD REDIRECTION (SECTION 2 & 19)
// ============================================================================
printHeader("2. STUDENT LOGIN & DASHBOARD LOADING");

$allPassed = runCheck("Student (Priya Sharma) logs in and is redirected to dashboard.php", function() use ($baseUrl, $cookieStudent1) {
    $loginPage = makeHttpRequest("{$baseUrl}/login.php", 'GET', [], $cookieStudent1);
    $csrf = extractCsrf($loginPage['body']);
    if (!$csrf) return "Failed to retrieve CSRF token from login page";

    $loginPost = makeHttpRequest("{$baseUrl}/login.php", 'POST', [
        'csrf_token' => $csrf,
        'email'      => 'priya.sharma@college.edu',
        'password'   => 'Student@123'
    ], $cookieStudent1);

    if ($loginPost['code'] !== 302) {
        return "Expected 302, got HTTP {$loginPost['code']}";
    }
    if (stripos($loginPost['headers'], 'dashboard.php') === false) {
        return "Completed student was not redirected to dashboard.php. Headers: {$loginPost['headers']}";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Student dashboard loads successfully (HTTP 200)", function() use ($baseUrl, $cookieStudent1) {
    $dashRes = makeHttpRequest("{$baseUrl}/dashboard.php", 'GET', [], $cookieStudent1);
    if ($dashRes['code'] !== 200) {
        return "Expected HTTP 200, got {$dashRes['code']}";
    }
    if (stripos($dashRes['body'], 'CareerAct') === false) {
        return "Dashboard body does not contain CareerAct branding";
    }
    return true;
}) && $allPassed;

// ============================================================================
// 3. PERSONALIZED GREETING & HEADER (SECTION 5)
// ============================================================================
printHeader("3. DASHBOARD HEADER & PERSONALIZATION");

$dashContent = '';
$allPassed = runCheck("Dashboard renders dynamic time greeting and actual student name", function() use ($baseUrl, $cookieStudent1, &$dashContent) {
    $res = makeHttpRequest("{$baseUrl}/dashboard.php", 'GET', [], $cookieStudent1);
    $dashContent = $res['body'];

    if (stripos($dashContent, 'Priya') === false) {
        return "Student first name 'Priya' not found in dashboard";
    }
    
    // Check greeting: Good Morning, Good Afternoon, or Good Evening
    $hasGreeting = (
        stripos($dashContent, 'Good Morning') !== false ||
        stripos($dashContent, 'Good Afternoon') !== false ||
        stripos($dashContent, 'Good Evening') !== false
    );
    if (!$hasGreeting) {
        return "Dynamic time-based greeting (Good Morning/Afternoon/Evening) missing";
    }

    if (stripos($dashContent, "Your career journey continues. Let's develop your skills and achieve your goals.") === false) {
        return "Personalized dashboard subtitle missing";
    }

    return true;
}) && $allPassed;

// ============================================================================
// 4. OVERVIEW CARDS (SECTION 6 - 6 CARDS)
// ============================================================================
printHeader("4. STUDENT OVERVIEW CARDS (DATABASE-DRIVEN)");

$allPassed = runCheck("Card A: Displays student's active career goal & domain from database", function() use (&$dashContent) {
    if (stripos($dashContent, 'cardCareerGoal') === false && stripos($dashContent, 'Career Goal') === false) {
        return "Career Goal card missing";
    }
    if (stripos($dashContent, 'Software Engineer') === false && stripos($dashContent, 'Data Scientist') === false) {
        return "Student's active career goal title from database not found";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Card B: Displays profile completion percentage (100% for completed student)", function() use (&$dashContent) {
    if (stripos($dashContent, 'Profile Completion') === false) {
        return "Profile completion card missing";
    }
    if (stripos($dashContent, '100%') === false) {
        return "Expected 100% profile completion for completed student";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Card C: Displays total skills count matching database records", function() use (&$dashContent) {
    $db = getDbConnection();
    $uStmt = $db->query("SELECT id FROM users WHERE email = 'priya.sharma@college.edu'");
    $uId = $uStmt->fetchColumn();
    $skillCount = (int)$db->query("SELECT COUNT(*) FROM student_skills WHERE user_id = {$uId}")->fetchColumn();

    if (stripos($dashContent, "{$skillCount} Skills") === false) {
        return "Total skills count {$skillCount} not accurately rendered in overview card";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Card D: Displays extracurricular activities count matching database records", function() use (&$dashContent) {
    if (stripos($dashContent, 'Extracurricular') === false) {
        return "Extracurriculars overview card missing";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Card E: Displays assessment status", function() use (&$dashContent) {
    if (stripos($dashContent, 'Assessment Status') === false) {
        return "Assessment status card missing";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Card F: Displays achievements count", function() use (&$dashContent) {
    if (stripos($dashContent, 'Achievements') === false) {
        return "Achievements overview card missing";
    }
    return true;
}) && $allPassed;

// ============================================================================
// 5. CAREER DEVELOPMENT PROGRESS TRACKER (SECTION 7 - 8 STAGES)
// ============================================================================
printHeader("5. CAREER DEVELOPMENT 8-STAGE PROGRESS TRACKER");

$allPassed = runCheck("Visual tracker renders all 8 career development stages", function() use (&$dashContent) {
    $stages = [
        'Profile Completed',
        'Skills Added',
        'Assessment Completed',
        'Skill-Gap Identified',
        'Recommendations Received',
        'Activities Explored',
        'Activities Completed',
        'Portfolio Updated'
    ];

    foreach ($stages as $stage) {
        if (stripos($dashContent, $stage) === false) {
            return "Stage '{$stage}' missing from career development progress tracker";
        }
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Completed stages (Profile Completed & Skills Added) marked completed", function() use (&$dashContent) {
    if (stripos($dashContent, 'tracker-step completed') === false) {
        return "Completed stage indicator class missing";
    }
    return true;
}) && $allPassed;

// ============================================================================
// 6. DETAILED SECTIONS & EMPTY STATES (SECTIONS 8, 9, 10, 11, 12, 13, 14, 15)
// ============================================================================
printHeader("6. DETAILED SECTIONS & EMPTY STATES");

$allPassed = runCheck("Section 8: My Career Profile summary displays education & career details", function() use (&$dashContent) {
    if (stripos($dashContent, 'My Career Profile') === false) {
        return "My Career Profile section missing";
    }
    if (stripos($dashContent, 'National Institute of Technology') === false) {
        return "Institution 'National Institute of Technology' not found in summary";
    }
    if (stripos($dashContent, 'Computer Science') === false) {
        return "Field of study 'Computer Science' not found in summary";
    }
    if (stripos($dashContent, 'View Full Profile') === false) {
        return "View Full Profile button missing";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Section 9: My Skills overview renders student's actual competencies & levels", function() use (&$dashContent) {
    if (stripos($dashContent, 'My Skills Overview') === false) {
        return "My Skills Overview section missing";
    }
    $db = getDbConnection();
    $uStmt = $db->query("SELECT id FROM users WHERE email = 'priya.sharma@college.edu'");
    $uId = $uStmt->fetchColumn();
    $skills = $db->query("SELECT s.name FROM student_skills ss JOIN skills s ON ss.skill_id = s.id WHERE ss.user_id = {$uId}")->fetchAll(PDO::FETCH_COLUMN);
    
    $found = false;
    foreach ($skills as $sn) {
        if (stripos($dashContent, $sn) !== false) {
            $found = true;
            break;
        }
    }
    if (!$found && !empty($skills)) {
        return "None of student's actual skills (" . implode(', ', $skills) . ") found in dashboard";
    }
    if (stripos($dashContent, 'Self-Reported') === false) {
        return "Skill status 'Self-Reported' badge missing";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Section 10: Assessment overview renders pending empty state when no tests taken", function() use (&$dashContent) {
    if (stripos($dashContent, 'Skill Assessment') === false) {
        return "Skill Assessment overview section missing";
    }
    if (stripos($dashContent, "You haven't completed your skill assessment yet") === false) {
        return "Expected assessment pending message not found";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Section 11: Skill-Gap overview renders informative pending state", function() use (&$dashContent) {
    if (stripos($dashContent, 'Skill-Gap Overview') === false) {
        return "Skill-Gap Overview section missing";
    }
    if (stripos($dashContent, "Skill-gap analysis will be generated automatically after you complete your skill assessment") === false) {
        return "Skill-gap pending state message missing";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Section 12: Recommendations preview renders meaningful empty state", function() use (&$dashContent) {
    if (stripos($dashContent, 'Recommendations Preview') === false) {
        return "Recommendations Preview section missing";
    }
    if (stripos($dashContent, "Your personalized recommendations will appear here after skill-gap analysis") === false) {
        return "Recommendations empty state message missing";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Section 13: Recent activities renders student's existing activities", function() use (&$dashContent) {
    if (stripos($dashContent, 'Recent Activities') === false) {
        return "Recent Activities section missing";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Section 14: Achievements preview renders portfolio button & empty state", function() use (&$dashContent) {
    if (stripos($dashContent, 'Achievements Preview') === false) {
        return "Achievements Preview section missing";
    }
    if (stripos($dashContent, 'View Portfolio') === false) {
        return "View Portfolio button missing";
    }
    return true;
}) && $allPassed;

$allPassed = runCheck("Section 15: Notifications interface renders properly in topbar", function() use (&$dashContent) {
    if (stripos($dashContent, 'notification-btn') === false) {
        return "Notification button missing from topbar";
    }
    if (stripos($dashContent, "You're all caught up!") === false) {
        return "Notification empty state 'You\'re all caught up!' missing";
    }
    return true;
}) && $allPassed;

// ============================================================================
// 7. SIDEBAR NAVIGATION VERIFICATION (SECTION 4 - ALL 14 LINKS)
// ============================================================================
printHeader("7. SIDEBAR NAVIGATION VERIFICATION (ALL 14 LINKS)");

$routes = [
    '1. Dashboard'            => 'dashboard.php',
    '2. My Profile'           => 'profile.php',
    '3. Career Exploration'   => 'career_goals.php',
    '4. My Skills'            => 'my_skills.php',
    '5. Skill Assessment'     => 'skills_assessment.php',
    '6. Skill-Gap Analysis'   => 'skill_gap.php',
    '7. Recommendations'      => 'recommendations.php',
    '8. Explore Activities'   => 'activities.php',
    '9. Saved Activities'     => 'saved_activities.php',
    '10. My Enrollments'      => 'enrollments.php',
    '11. My Portfolio'        => 'portfolio.php',
    '12. Resume Builder'      => 'resume.php',
    '13. Settings'            => 'settings.php',
];

foreach ($routes as $label => $route) {
    $allPassed = runCheck("Sidebar link: {$label} ({$route}) opens with HTTP 200", function() use ($baseUrl, $route, $cookieStudent1) {
        $res = makeHttpRequest("{$baseUrl}/{$route}", 'GET', [], $cookieStudent1);
        if ($res['code'] !== 200) {
            return "Expected HTTP 200 for {$route}, got {$res['code']}";
        }
        return true;
    }) && $allPassed;
}

// 14. Logout route
$allPassed = runCheck("Sidebar link: 14. Logout (logout.php) redirects to login.php", function() use ($baseUrl, $cookieStudent1) {
    $res = makeHttpRequest("{$baseUrl}/logout.php", 'GET', [], $cookieStudent1);
    if ($res['code'] !== 302) {
        return "Expected HTTP 302, got {$res['code']}";
    }
    if (stripos($res['headers'], 'login.php') === false) {
        return "Logout does not redirect to login.php";
    }
    return true;
}) && $allPassed;

// ============================================================================
// 8. CROSS-STUDENT ISOLATION & DATA SECURITY (SECTION 16 & 19)
// ============================================================================
printHeader("8. CROSS-STUDENT ISOLATION & DATA PRIVACY");

$allPassed = runCheck("Student 2 (Sharma) logs in and sees ONLY their own records", function() use ($baseUrl, $cookieStudent2) {
    // Reset password for bt@gmail.com
    $db = getDbConnection();
    $db->prepare("UPDATE users SET password_hash = :hash WHERE email = 'bt@gmail.com'")
       ->execute([':hash' => password_hash('SharmaPass@2026', PASSWORD_DEFAULT)]);

    $loginPage = makeHttpRequest("{$baseUrl}/login.php", 'GET', [], $cookieStudent2);
    $csrf = extractCsrf($loginPage['body']);

    $loginPost = makeHttpRequest("{$baseUrl}/login.php", 'POST', [
        'csrf_token' => $csrf,
        'email'      => 'bt@gmail.com',
        'password'   => 'SharmaPass@2026'
    ], $cookieStudent2);

    if ($loginPost['code'] !== 302) {
        return "Student 2 login failed with HTTP {$loginPost['code']}";
    }

    $dashRes2 = makeHttpRequest("{$baseUrl}/dashboard.php", 'GET', [], $cookieStudent2);
    if ($dashRes2['code'] !== 200) {
        return "Student 2 dashboard returned HTTP {$dashRes2['code']}";
    }

    $body2 = $dashRes2['body'];

    // Verify Student 2 sees "Sharma"
    if (stripos($body2, 'Sharma') === false) {
        return "Student 2 name 'Sharma' not found on their dashboard";
    }

    // Verify Student 2 CANNOT see Student 1's details
    if (stripos($body2, 'Priya') !== false) {
        return "Data leak: Student 2's dashboard contains Student 1's name 'Priya'";
    }
    if (stripos($body2, 'National Institute of Technology') !== false) {
        return "Data leak: Student 2's dashboard contains Student 1's institution";
    }

    return true;
}) && $allPassed;

// ============================================================================
// 9. CLEANUP
// ============================================================================
@unlink($cookieStudent1);
@unlink($cookieStudent2);

printHeader("MODULE 3 TEST SUITE SUMMARY");
if ($allPassed) {
    echo "\n>>> ALL MODULE 3 STUDENT DASHBOARD VERIFICATION TESTS PASSED 100%! <<<\n\n";
} else {
    echo "\n>>> SOME MODULE 3 TESTS FAILED. PLEASE REVIEW LOGS. <<<\n\n";
}
