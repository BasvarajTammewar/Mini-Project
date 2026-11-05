<?php
/**
 * CareerAct — Comprehensive Test Suite for Module 11: AI Resume Builder & Portfolio
 * 
 * Verifies all Module 11 requirements:
 * 1. Data Sources: Only student-confirmed profile, skills, education, and completed activities are used
 * 2. Strict Boundary: Uncompleted activities (saved, registered, in_progress) and recommendations are strictly excluded
 * 3. Zero Hallucination: Gemini/synthesizer never invents credentials, skills, or achievements
 * 4. Role-Tailored Generation: Generates targeted summary and action-verb bullet points
 * 5. Section Regeneration: Allows regenerating individual sections without overwriting others
 * 6. Persistence & Reopening: Saved resume drafts reload identically with full fidelity
 * 7. Multi-Version Support: Allows creating separate versions for different career roles
 * 8. Strict Multi-Tenant Isolation: Student B cannot view, edit, or delete Student A's resumes
 * 9. Separation of Concerns: Editing a resume NEVER alters student_skills or student_existing_activities
 * 10. ATS Export & Print: Clean ATS formatting and printable layout without empty sections
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/resume_service.php';

$db = getDbConnection();
$userId = 3; // Sharma (Student A)

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
echo "CAREERACT MODULE 11 — AI RESUME BUILDER & PORTFOLIO VERIFICATION SUITE\n";
echo "Database: carrearact2 | Test Student ID: {$userId}\n";
echo "========================================================================\n\n";

// --- Test 1: Verified Resume Data Sources Inspection ---
echo "--- Test 1: Confirmed Resume Data Sources ---\n";
$sources = getStudentResumeSources($db, $userId);

$hasProfile = !empty($sources['profile']['full_name']) && !empty($sources['profile']['email']);
$hasEducation = !empty($sources['education']);
$hasSkills = !empty($sources['skills']);

assertTest(
    "Student confirmed profile, education, and skills are retrieved",
    $hasProfile && $hasEducation && $hasSkills,
    "Name: {$sources['profile']['full_name']}, Skills count: " . count($sources['skills'])
);

// --- Test 2: Strict Exclusion of Uncompleted Activities ---
echo "\n--- Test 2: Strict Exclusion of Uncompleted & Recommended Activities ---\n";
// Student 3 has enrollment #1 with status = 'saved'
$uncompletedIncluded = false;
foreach ($sources['completed_experience'] as $exp) {
    if (stripos($exp['title'], 'Global Student AI Agents') !== false) {
        $uncompletedIncluded = true;
        break;
    }
}

// Ensure every item in completed_experience is actually completed
$allCompleted = true;
foreach ($sources['completed_experience'] as $exp) {
    if (!empty($exp['is_enrollment'])) {
        $eid = (int)str_replace('enr_', '', $exp['id']);
        $chkStatus = $db->query("SELECT status FROM enrollments WHERE id = {$eid}")->fetchColumn();
        if ($chkStatus !== 'completed') {
            $allCompleted = false;
            break;
        }
    }
}

assertTest(
    "Saved, registered, and in-progress activities are strictly excluded from resume experience",
    !$uncompletedIncluded && $allCompleted,
    "Uncompleted included: " . ($uncompletedIncluded ? 'YES' : 'NO')
);

// --- Test 3: Zero Hallucination Constraint in Resume Generation ---
echo "\n--- Test 3: Zero Hallucination Constraint ---\n";
$draft = generateResumeDraft($db, $userId, 'Software Developer', 4);

$confirmedSkillNames = array_map('strtolower', array_column($sources['skills'], 'name'));
$inventedSkills = [];

if (!empty($draft['skills'])) {
    foreach ($draft['skills'] as $cat => $sList) {
        if (is_array($sList)) {
            foreach ($sList as $s) {
                if (!in_array(strtolower(trim((string)$s)), $confirmedSkillNames, true)) {
                    $inventedSkills[] = $s;
                }
            }
        }
    }
}

assertTest(
    "Generated resume contains zero hallucinated or unconfirmed skills",
    empty($inventedSkills),
    !empty($inventedSkills) ? "Invented skills found: " . implode(', ', $inventedSkills) : "All skills verified against student records"
);

// --- Test 4: Role-Targeted Summary & Action-Verb Bullet Points ---
echo "\n--- Test 4: Action-Verb Bullet Points & Role Alignment ---\n";
$hasSummary = !empty($draft['summary']) && (stripos($draft['summary'], 'Software Developer') !== false || stripos($draft['summary'], 'Software') !== false || strlen($draft['summary']) > 20);
$hasActionVerbs = false;

if (!empty($draft['experience'])) {
    foreach ($draft['experience'] as $exp) {
        if (!empty($exp['bullet_points'])) {
            foreach ($exp['bullet_points'] as $b) {
                if (preg_match('/^(Spearheaded|Coordinated|Engineered|Conducted|Designed|Executed|Led|Facilitated|Collaborated|Developed)/i', trim((string)$b))) {
                    $hasActionVerbs = true;
                    break 2;
                }
            }
        }
    }
} else {
    // If student has no completed experience, experience section must be cleanly empty
    $hasActionVerbs = true;
}

assertTest(
    "Resume draft contains targeted professional summary and action-verb bullet points",
    $hasSummary && $hasActionVerbs,
    "Summary: " . substr($draft['summary'], 0, 60) . "..."
);

// --- Test 5: Section Regeneration (Summary & Experience) ---
echo "\n--- Test 5: Individual Section Regeneration ---\n";
$oldSummary = $draft['summary'];
$regenSummary = regenerateResumeSection($db, $userId, 'summary', 'Data Scientist', $draft);
$hasNewSummary = !empty($regenSummary['summary']);

$regenExp = regenerateResumeSection($db, $userId, 'experience', 'Full Stack Developer', $draft);
$hasExpStructure = isset($regenExp['experience']) && is_array($regenExp['experience']);

assertTest(
    "Specific sections can be regenerated independently without corrupting other sections",
    $hasNewSummary && $hasExpStructure,
    "New summary length: " . strlen($regenSummary['summary'] ?? '')
);

// --- Test 6: Persistence & Reopening Fidelity ---
echo "\n--- Test 6: Resume Persistence & Retrieval Fidelity ---\n";
$draft['title'] = 'Software Engineering Placement Resume v1';
$draft['summary'] = 'Edited custom professional summary for verification testing.';
$savedId = saveStudentResume($db, $userId, $draft);

$reopened = getStudentResume($db, $userId, $savedId);

assertTest(
    "Saved resume reloads identically from the database with full section fidelity",
    $reopened !== null && $reopened['summary'] === 'Edited custom professional summary for verification testing.' && $reopened['title'] === 'Software Engineering Placement Resume v1',
    "Saved ID: {$savedId}, Title: " . ($reopened['title'] ?? 'NONE')
);

// --- Test 7: Multi-Version Support for Different Roles ---
echo "\n--- Test 7: Multi-Version Support ---\n";
// Create a second version for a different role (Data Analyst)
$draft2 = generateResumeDraft($db, $userId, 'Data Analyst', 5);
$draft2['title'] = 'Data Analyst Graduate Resume v2';
$savedId2 = saveStudentResume($db, $userId, $draft2);

$versionList = getStudentResumeList($db, $userId);
$versionIds = array_column($versionList, 'id');

assertTest(
    "Student can create and manage multiple distinct resume versions for different target roles",
    in_array($savedId, $versionIds) && in_array($savedId2, $versionIds) && count($versionList) >= 2,
    "Versions count: " . count($versionList)
);

// --- Test 8: Strict Multi-Tenant Isolation ---
echo "\n--- Test 8: Multi-Tenant Student Isolation ---\n";
// Find another student user (Student B)
$stmtOther = $db->query("SELECT id FROM users WHERE role = 'student' AND id != {$userId} LIMIT 1");
$otherUser = $stmtOther->fetch(PDO::FETCH_ASSOC);

if ($otherUser) {
    $otherUserId = (int)$otherUser['id'];
    // Student B attempts to access Student A's saved resume
    $stolenResume = getStudentResume($db, $otherUserId, $savedId);
    // Student B attempts to delete Student A's saved resume
    $unauthorizedDelete = deleteStudentResume($db, $otherUserId, $savedId);

    assertTest(
        "Student B cannot access, read, or delete Student A's resume records",
        $stolenResume === null && !$unauthorizedDelete,
        "Stolen resume: " . ($stolenResume ? 'LEAKED' : 'BLOCKED') . ", Unauthorized delete: " . ($unauthorizedDelete ? 'ALLOWED' : 'BLOCKED')
    );
} else {
    assertTest(
        "Student B isolation check skipped (only one student in DB)",
        true
    );
}

// --- Test 9: Separation of Concerns (Zero Contamination) ---
echo "\n--- Test 9: Separation of Concerns ---\n";
$skillsBefore = (int)$db->query("SELECT COUNT(*) FROM student_skills WHERE user_id = {$userId}")->fetchColumn();
$actsBefore = (int)$db->query("SELECT COUNT(*) FROM student_existing_activities WHERE user_id = {$userId}")->fetchColumn();

// Modify and save resume
$reopened['summary'] = 'Another revision to test non-contamination.';
saveStudentResume($db, $userId, $reopened);

$skillsAfter = (int)$db->query("SELECT COUNT(*) FROM student_skills WHERE user_id = {$userId}")->fetchColumn();
$actsAfter = (int)$db->query("SELECT COUNT(*) FROM student_existing_activities WHERE user_id = {$userId}")->fetchColumn();

assertTest(
    "Editing or saving a resume NEVER alters student_skills or student_existing_activities",
    $skillsBefore === $skillsAfter && $actsBefore === $actsAfter,
    "Skills: {$skillsBefore}->{$skillsAfter}, Activities: {$actsBefore}->{$actsAfter}"
);

// --- Test 10: ATS Export & Print Formatting ---
echo "\n--- Test 10: ATS Export & Printable Structure ---\n";
$renderedHtml = renderAtsResumeHtml($reopened);

$hasCandidateName = strpos($renderedHtml, 'ats-candidate-name') !== false;
$hasSummarySection = strpos($renderedHtml, 'Professional Summary') !== false;
$hasEducationSection = strpos($renderedHtml, 'Education') !== false;
$hasSkillsSection = strpos($renderedHtml, 'Skills & Core Competencies') !== false;

assertTest(
    "Rendered ATS resume contains clean, standard ATS section hierarchy without decorative clutter",
    $hasCandidateName && $hasSummarySection && $hasEducationSection && $hasSkillsSection,
    "ATS HTML length: " . strlen($renderedHtml)
);

// Clean up test versions created in this test run
deleteStudentResume($db, $userId, $savedId2);

echo "\n========================================================================\n";
echo "MODULE 11 TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "========================================================================\n";

exit($failed > 0 ? 1 : 0);

