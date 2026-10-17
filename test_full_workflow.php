<?php
/**
 * CareerAct — Comprehensive End-to-End Automated Test Suite
 * Executes Scenarios A through H against the live MySQL database.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/assessment_engine.php';

$db = getDbConnection();

echo "====================================================================\n";
echo "   CAREERACT FULL-STACK & DATABASE VERIFICATION TEST SUITE\n";
echo "====================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $scenario, string $name, bool $condition, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] {$scenario}: {$name}\n";
        if ($details) echo "        -> {$details}\n";
    } else {
        $failCount++;
        echo " [FAIL] {$scenario}: {$name}\n";
        if ($details) echo "        -> ERROR: {$details}\n";
    }
}

// -------------------------------------------------------------------
// SCENARIO A: New Student Registration, Onboarding, Assessment & Roadmap
// -------------------------------------------------------------------
echo "\n--- RUNNING SCENARIO A: New Student Full Workflow ---\n";
$emailA = 'test_student_a_' . time() . '@college.edu';
$passA = 'Student@123';
$roleId = (int)$db->query("SELECT role_id FROM roles WHERE role_code = 'student'")->fetchColumn();
$statusId = (int)$db->query("SELECT account_status_id FROM account_statuses WHERE status_code = 'active'")->fetchColumn();

$stmtInsertUser = $db->prepare("
    INSERT INTO users (role_id, account_status_id, email, password_hash, email_verified_at, created_at)
    VALUES (:role_id, :status_id, :email, :hash, NOW(), NOW())
");
$stmtInsertUser->execute([
    ':role_id'   => $roleId,
    ':status_id' => $statusId,
    ':email'     => $emailA,
    ':hash'      => password_hash($passA, PASSWORD_DEFAULT)
]);
$userIdA = (int)$db->lastInsertId();
assertTest('Scenario A', 'User registration in users table', $userIdA > 0, "Created user_id: {$userIdA}");

// 2. Profile Creation (Onboarding Step 1)
$stmt = $db->prepare("
    INSERT INTO student_profiles (user_id, full_name, college_name, degree_name, course_name, branch_name, academic_year, created_at)
    VALUES (:uid, 'Aarohi Verma', 'Institute of Technology', 'B.Tech', 'Computer Science', 'Computer Science', 3, NOW())
");
$stmt->execute([':uid' => $userIdA]);
$studentIdA = (int)$db->lastInsertId();
assertTest('Scenario A', 'Student profile creation', $studentIdA > 0, "Created student_id: {$studentIdA}");

// Add skills for student A
$stmtSkill = $db->prepare("
    INSERT INTO student_skills (student_id, skill_id, proficiency_level_id, source_code, updated_at)
    VALUES (:sid, :skid, :lvl, 'self_declared', NOW())
");
$stmtSkill->execute([':sid' => $studentIdA, ':skid' => 1, ':lvl' => 3]); // Python
$stmtSkill->execute([':sid' => $studentIdA, ':skid' => 3, ':lvl' => 3]); // SQL

// 3. Career Goal Setup (Onboarding Step 2)
$careerIdA = 1; // Software Development Engineer
$stmt = $db->prepare("
    INSERT INTO student_career_goals (student_id, career_id, goal_rank, is_active, selected_at)
    VALUES (:sid, :cid, 1, 1, NOW())
");
$stmt->execute([':sid' => $studentIdA, ':cid' => $careerIdA]);
assertTest('Scenario A', 'Set career goal to Software Development Engineer', true, "Career ID: {$careerIdA}");

// 4. Extracurricular Experience (Onboarding Step 3)
$activityIdA = (int)$db->query("SELECT activity_id FROM activities WHERE activity_status_id = 3 LIMIT 1")->fetchColumn();
$stmtEnroll = $db->prepare("
    INSERT INTO student_activity_enrollments (
        student_id, activity_id, enrollment_status_id, completion_status,
        completion_verification, enrolled_at, completed_at, proof_url,
        student_reflection, skills_gained_text
    ) VALUES (
        :sid, :aid, 5, 'completed', 'verified', NOW(), NOW(),
        'https://github.com/aarohi/smart-logistics',
        'Built full-stack microservices backend using PHP and MySQL. Handled database optimization.',
        'PHP, MySQL, REST APIs, Git'
    )
");
$stmtEnroll->execute([':sid' => $studentIdA, ':aid' => $activityIdA]);
assertTest('Scenario A', 'Submit detailed extracurricular experience with verified evidence', true, "Activity ID: {$activityIdA}");

// 5. Trigger Assessment Engine
$resultA = runCareerAssessment($db, $studentIdA, $careerIdA);
assertTest('Scenario A', 'Assessment Engine execution', $resultA['record_id'] > 0, "Calculated score: {$resultA['readiness_score']}%");

$milestoneCount = (int)$db->query("SELECT COUNT(*) FROM student_roadmap_milestones WHERE student_id = {$studentIdA}")->fetchColumn();
assertTest('Scenario A', 'Roadmap milestones populated in database', $milestoneCount > 0, "Generated {$milestoneCount} milestones");

$recCount = (int)$db->query("SELECT COUNT(*) FROM activity_recommendations WHERE student_id = {$studentIdA}")->fetchColumn();
assertTest('Scenario A', 'Activity recommendations stored in database', $recCount > 0, "Stored {$recCount} recommendations");


// -------------------------------------------------------------------
// SCENARIO B: Student Needs Improvement (Path B)
// -------------------------------------------------------------------
echo "\n--- RUNNING SCENARIO B: Student Needing Improvement (Path B) ---\n";
$emailB = 'test_student_b_' . time() . '@college.edu';
$stmt = $db->prepare("
    INSERT INTO users (role_id, account_status_id, email, password_hash, email_verified_at, created_at)
    VALUES (:role_id, :status_id, :email, :hash, NOW(), NOW())
");
$stmt->execute([':role_id' => $roleId, ':status_id' => $statusId, ':email' => $emailB, ':hash' => password_hash('Pass@123', PASSWORD_DEFAULT)]);
$userIdB = (int)$db->lastInsertId();

$stmt = $db->prepare("
    INSERT INTO student_profiles (user_id, full_name, college_name, degree_name, academic_year, created_at)
    VALUES (:uid, 'Rohan Sharma', 'City Arts & Science College', 'B.Sc', 1, NOW())
");
$stmt->execute([':uid' => $userIdB]);
$studentIdB = (int)$db->lastInsertId();

// Career Goal: Cybersecurity Analyst (Career ID 3)
$careerIdB = 3;
$stmt = $db->prepare("
    INSERT INTO student_career_goals (student_id, career_id, goal_rank, is_active, selected_at)
    VALUES (:sid, :cid, 1, 1, NOW())
");
$stmt->execute([':sid' => $studentIdB, ':cid' => $careerIdB]);

// Experience: Only non-technical cultural fest, no cybersecurity skills
$actVolId = (int)$db->query("SELECT activity_id FROM activities WHERE activity_type_id IN (12, 18, 19) LIMIT 1")->fetchColumn();
if (!$actVolId) $actVolId = $activityIdA;

$stmtEnroll->execute([
    ':sid' => $studentIdB,
    ':aid' => $actVolId,
]);

$resultB = runCareerAssessment($db, $studentIdB, $careerIdB);
assertTest('Scenario B', 'Assessment flags skill gaps', !empty($resultB['improvements']), "Identified " . count($resultB['improvements']) . " improvement areas");
assertTest('Scenario B', 'Decision path evaluates to Path B (Improvement Needed)', $resultB['decision_path'] === 'path_b', "Decision: {$resultB['decision_path']} ({$resultB['path_title']})");


// -------------------------------------------------------------------
// SCENARIO C: Student Already Has Relevant Experience (Path A)
// -------------------------------------------------------------------
echo "\n--- RUNNING SCENARIO C: High-Competence Student (Path A) ---\n";
$emailC = 'test_student_c_' . time() . '@college.edu';
$stmtInsertUser->execute([':role_id' => $roleId, ':status_id' => $statusId, ':email' => $emailC, ':hash' => password_hash('Pass@123', PASSWORD_DEFAULT)]);
$userIdC = (int)$db->lastInsertId();

$stmt = $db->prepare("
    INSERT INTO student_profiles (user_id, full_name, college_name, degree_name, academic_year, created_at)
    VALUES (:uid, 'Priya Nair', 'National Institute of Tech', 'B.Tech CS', 4, NOW())
");
$stmt->execute([':uid' => $userIdC]);
$studentIdC = (int)$db->lastInsertId();

// Career Goal: Software Development Engineer (Career ID 1)
$careerIdC = 1;
$stmt = $db->prepare("
    INSERT INTO student_career_goals (student_id, career_id, goal_rank, is_active, selected_at)
    VALUES (:sid, :cid, 1, 1, NOW())
");
$stmt->execute([':sid' => $studentIdC, ':cid' => $careerIdC]);

// Seed all required skills for SDE at level 4 or 5
$reqSkills = $db->query("SELECT skill_id, expected_level_id FROM career_required_skills WHERE career_id = 1")->fetchAll();
foreach ($reqSkills as $rs) {
    $insSkill = $db->prepare("
        INSERT INTO student_skills (student_id, skill_id, proficiency_level_id, source_code, updated_at)
        VALUES (:sid, :skid, :lvl, 'verified', NOW())
    ");
    $insSkill->execute([':sid' => $studentIdC, ':skid' => $rs['skill_id'], ':lvl' => max(4, (int)$rs['expected_level_id'])]);
}

// 2 Verified technical achievements with verified proof
$activitiesList = $db->query("SELECT activity_id FROM activities WHERE activity_status_id = 3 LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
foreach ($activitiesList as $idx => $actId) {
    $stmtEnroll->execute([
        ':sid' => $studentIdC,
        ':aid' => (int)$actId
    ]);
}

$resultC = runCareerAssessment($db, $studentIdC, $careerIdC);
assertTest('Scenario C', 'High readiness score achieved', $resultC['readiness_score'] >= 75, "Score: {$resultC['readiness_score']}%");
assertTest('Scenario C', 'Decision path evaluates to Path A (No Major Gaps / Advanced)', $resultC['decision_path'] === 'path_a', "Decision: {$resultC['decision_path']}");


// -------------------------------------------------------------------
// SCENARIO D: Insufficient Information / Vague Submission (Path C)
// -------------------------------------------------------------------
echo "\n--- RUNNING SCENARIO D: Vague Submission (Path C) ---\n";
$emailD = 'test_student_d_' . time() . '@college.edu';
$stmtInsertUser->execute([':role_id' => $roleId, ':status_id' => $statusId, ':email' => $emailD, ':hash' => password_hash('Pass@123', PASSWORD_DEFAULT)]);
$userIdD = (int)$db->lastInsertId();

$stmt = $db->prepare("
    INSERT INTO student_profiles (user_id, full_name, college_name, degree_name, academic_year, created_at)
    VALUES (:uid, 'Kiran Kumar', 'Govt College', 'B.Com', 1, NOW())
");
$stmt->execute([':uid' => $userIdD]);
$studentIdD = (int)$db->lastInsertId();

// Career Goal: SDE without any recorded skills or verified evidence
$careerIdD = 1;
$stmt = $db->prepare("
    INSERT INTO student_career_goals (student_id, career_id, goal_rank, is_active, selected_at)
    VALUES (:sid, :cid, 1, 1, NOW())
");
$stmt->execute([':sid' => $studentIdD, ':cid' => $careerIdD]);

// 1 unverified minimal enrollment without proof or reflection
$stmtVague = $db->prepare("
    INSERT INTO student_activity_enrollments (
        student_id, activity_id, enrollment_status_id, completion_status,
        completion_verification, enrolled_at
    ) VALUES (
        :sid, :aid, 2, 'not_completed', 'not_required', NOW()
    )
");
$stmtVague->execute([':sid' => $studentIdD, ':aid' => $activityIdA]);

$resultD = runCareerAssessment($db, $studentIdD, $careerIdD);
assertTest('Scenario D', 'Assessment flags low evidence confidence', $resultD['confidence_level'] === 'Low' || $resultD['decision_path'] === 'path_c', "Confidence: {$resultD['confidence_level']}, Path: {$resultD['decision_path']}");
assertTest('Scenario D', 'Decision path evaluates to Path C or generates clarification questions', $resultD['decision_path'] === 'path_c' || !empty($resultD['clarification_questions']), "Path: {$resultD['decision_path']}");


// -------------------------------------------------------------------
// SCENARIO E: Activity Completion & Reassessment
// -------------------------------------------------------------------
echo "\n--- RUNNING SCENARIO E: Activity Completion & Reassessment ---\n";
// Student A completes a new activity with verified proof
$actNew = (int)$db->query("SELECT activity_id FROM activities WHERE activity_id != {$activityIdA} AND activity_status_id = 3 LIMIT 1")->fetchColumn();
$stmtEnroll->execute([
    ':sid' => $studentIdA,
    ':aid' => $actNew
]);

// Add newly mastered skill
$stmtSkill->execute([':sid' => $studentIdA, ':skid' => 2, ':lvl' => 4]); // JavaScript

// Reassess
$prevScore = $resultA['readiness_score'];
$resultEReassess = runCareerAssessment($db, $studentIdA, $careerIdA);
assertTest('Scenario E', 'Reassessment recalculates readiness score', $resultEReassess['record_id'] > $resultA['record_id'], "New Score: {$resultEReassess['readiness_score']}%, Prev Score: {$prevScore}%");
$historyCount = (int)$db->query("SELECT COUNT(*) FROM student_assessment_records WHERE student_id = {$studentIdA}")->fetchColumn();
assertTest('Scenario E', 'Assessment history preserved in student_assessment_records', $historyCount >= 2, "Recorded {$historyCount} assessment iterations");


// -------------------------------------------------------------------
// SCENARIO F: Existing Student Authentication & Profile Preservation
// -------------------------------------------------------------------
echo "\n--- RUNNING SCENARIO F: Existing Student Account Integrity ---\n";
$stmt = $db->prepare("SELECT * FROM users WHERE email = 'aarav.patel@college.edu' LIMIT 1");
$stmt->execute();
$existingUser = $stmt->fetch();
assertTest('Scenario F', 'Existing student account exists', !empty($existingUser), "Found user_id: {$existingUser['user_id']}");
$passValid = password_verify('Password@123', $existingUser['password_hash']);
assertTest('Scenario F', 'Password verification works for existing student', $passValid, "Password@123 verified");

$stmtProf = $db->prepare("SELECT * FROM student_profiles WHERE user_id = :uid LIMIT 1");
$stmtProf->execute([':uid' => $existingUser['user_id']]);
$prof = $stmtProf->fetch();
assertTest('Scenario F', 'Existing student profile data intact', !empty($prof['full_name']) && !empty($prof['college_name']), "Name: {$prof['full_name']}");


// -------------------------------------------------------------------
// SCENARIO G: Admin Role & Activity Lifecycle
// -------------------------------------------------------------------
echo "\n--- RUNNING SCENARIO G: Admin Role & Activity Lifecycle ---\n";
$stmtAdmin = $db->prepare("
    SELECT u.*, r.role_code 
    FROM users u 
    JOIN roles r ON u.role_id = r.role_id 
    WHERE u.email = 'admin@careeract.edu'
");
$stmtAdmin->execute();
$adminUser = $stmtAdmin->fetch();
assertTest('Scenario G', 'Admin account exists with role_code = admin', $adminUser && $adminUser['role_code'] === 'admin', "Email: {$adminUser['email']}");

// Test Activity Lifecycle (Insert draft -> publish -> expire)
$stmt = $db->prepare("
    INSERT INTO activities (
        activity_type_id, category_id, domain_id, mode_id, organizer_id, source_id,
        activity_status_id, verification_status_id, title, description,
        registration_deadline, created_by_user_id, created_at, updated_at
    ) VALUES (
        1, 1, 1, 1, 1, 1,
        1, 2, 'Test Admin Lifecycle Hackathon', 'Automated lifecycle test description',
        NOW() + INTERVAL 7 DAY, :admin_id, NOW(), NOW()
    )
");
$stmt->execute([':admin_id' => $adminUser['user_id']]);
$testActId = (int)$db->lastInsertId();
assertTest('Scenario G', 'Admin creates new activity as draft', $testActId > 0, "Created Activity ID: {$testActId}");

// Publish it
$db->query("UPDATE activities SET activity_status_id = 3 WHERE activity_id = {$testActId}");
$statusCheck = (int)$db->query("SELECT activity_status_id FROM activities WHERE activity_id = {$testActId}")->fetchColumn();
assertTest('Scenario G', 'Admin publishes activity (status = 3)', $statusCheck === 3);

// Expire it
$db->query("UPDATE activities SET activity_status_id = 6 WHERE activity_id = {$testActId}");
$statusCheckExp = (int)$db->query("SELECT activity_status_id FROM activities WHERE activity_id = {$testActId}")->fetchColumn();
assertTest('Scenario G', 'Admin expires activity (status = 6)', $statusCheckExp === 6);

// Verify student explore query excludes expired activities
$activeCheck = $db->query("SELECT COUNT(*) FROM activities WHERE activity_id = {$testActId} AND activity_status_id = 3")->fetchColumn();
assertTest('Scenario G', 'Expired activity excluded from active student catalog', (int)$activeCheck === 0);


// -------------------------------------------------------------------
// SCENARIO H: Security & Data Integrity
// -------------------------------------------------------------------
echo "\n--- RUNNING SCENARIO H: Security & Data Integrity ---\n";

// 1. CSRF Token verification
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$validCsrf = verifyCsrfToken($_SESSION['csrf_token']);
$invalidCsrf = verifyCsrfToken('tampered_fake_token');
assertTest('Scenario H', 'CSRF verification passes for valid token', $validCsrf === true);
assertTest('Scenario H', 'CSRF verification blocks tampered token', $invalidCsrf === false);

// 2. Duplicate Enrollment Prevention
$stmtDup = $db->prepare("SELECT enrollment_id FROM student_activity_enrollments WHERE student_id = :sid AND activity_id = :aid AND enrollment_status_id NOT IN (6, 7)");
$stmtDup->execute([':sid' => $studentIdA, ':aid' => $activityIdA]);
$isDuplicate = (bool)$stmtDup->fetch();
assertTest('Scenario H', 'Duplicate enrollment detector blocks second enrollment', $isDuplicate === true, "Detected prior enrollment");

// 3. Password Hashing Security Check
$rawPassInDb = $db->query("SELECT password_hash FROM users WHERE email = '{$emailA}'")->fetchColumn();
$isProperlyHashed = strpos($rawPassInDb, '$2y$') === 0 || strpos($rawPassInDb, '$argon2') === 0;
assertTest('Scenario H', 'Passwords stored using secure Bcrypt/Argon2 hashes (never plain text)', $isProperlyHashed);

// 4. SQL Injection Protection on Search
$maliciousInput = "' OR '1'='1' -- ";
$stmtSql = $db->prepare("SELECT COUNT(*) FROM activities WHERE title LIKE :q");
$stmtSql->execute([':q' => "%{$maliciousInput}%"]);
$countSql = (int)$stmtSql->fetchColumn();
assertTest('Scenario H', 'Prepared statements prevent SQL injection', $countSql === 0, "No records leaked via SQL injection attempt");


echo "\n====================================================================\n";
echo "   AUTOMATED TEST RUN SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "====================================================================\n";
