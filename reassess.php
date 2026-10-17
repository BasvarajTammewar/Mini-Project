<?php
/**
 * CareerAct — Student Career Reassessment Module
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/assessment_engine.php';

$userId = (int)$_SESSION['user_id'];
$db = getDbConnection();

// Fetch student profile
$stmtStudent = $db->prepare("SELECT student_id, full_name FROM student_profiles WHERE user_id = :user_id LIMIT 1");
$stmtStudent->execute([':user_id' => $userId]);
$student = $stmtStudent->fetch();
$studentId = $student ? (int)$student['student_id'] : 0;

// Fetch active career goal
$stmtGoal = $db->prepare("SELECT career_id FROM student_career_goals WHERE student_id = :student_id AND is_active = 1 LIMIT 1");
$stmtGoal->execute([':student_id' => $studentId]);
$careerId = (int)$stmtGoal->fetchColumn();

if ($careerId <= 0) {
    header('Location: career_goals.php');
    exit;
}

// Fetch previous assessment score
$stmtPrev = $db->prepare("
    SELECT readiness_score, created_at 
    FROM student_assessment_records 
    WHERE student_id = :student_id 
    ORDER BY created_at DESC LIMIT 1
");
$stmtPrev->execute([':student_id' => $studentId]);
$prevAssessment = $stmtPrev->fetch();
$prevScore = $prevAssessment ? (int)$prevAssessment['readiness_score'] : 0;

// Run fresh assessment through engine
$newResults = runCareerAssessment($db, $studentId, $careerId);
$newScore = (int)$newResults['readiness_score'];
$scoreDelta = $newScore - $prevScore;

// Redirect to assessment results with delta notice
header('Location: assessment_results.php?record_id=' . $newResults['record_id'] . '&delta=' . $scoreDelta);
exit;
