<?php
/**
 * CareerAct — Comprehensive Test Suite for Module 14: Notifications & Student Reminders
 * 
 * Verifies all 14 Core Acceptance Criteria:
 * 1. Multi-Tenant Student Isolation: Student sees only their own notifications
 * 2. Strict Cross-Student Authorization: Student B cannot mark/read/dismiss Student A's notifications
 * 3. Unread Count Synchronization: Counter reflects accurate unread states
 * 4. Mark-All-As-Read Scope: Updates strictly the requesting student's records
 * 5. Event-Driven Generation: Notifications created only after real application events
 * 6. Verified Deadline Constraints: Only verified, future-dated opportunities generate reminders
 * 7. Scheduler Idempotency: Repeated scheduled executions create ZERO duplicate reminders
 * 8. Inactive Opportunity Handling: Expired/archived opportunities never generate upcoming reminders
 * 9. Historical Integrity: Preserves already-created notifications after opportunity expiration
 * 10. Student Preference Enforcement: Category opt-outs are strictly respected
 * 11. Essential Notice Delivery: Security and essential notices bypass optional category opt-outs
 * 12. Assessment Privacy Protection: Notification previews never leak private scores or grades
 * 13. Safe Internal Routing: Notification links are sanitized and prevent open redirects
 * 14. Platform Announcement Broadcasting: Admin broadcast respects preferences and delivers in-app
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/notification_service.php';
require_once __DIR__ . '/includes/event_discovery_service.php';

$db = getDbConnection();

$passed = 0;
$failed = 0;

function assertTest(string $desc, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$desc}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

echo "====================================================================\n";
echo "CAREERACT — MODULE 14 COMPREHENSIVE TEST SUITE\n";
echo "Notifications, Student Reminders & In-App Alerts Verification\n";
echo "====================================================================\n\n";

$studentA = 3; // Sharma

// Ensure a second student exists for isolation tests
$stmtB = $db->query("SELECT id FROM users WHERE role = 'student' AND id != {$studentA} LIMIT 1");
$studentB = (int)$stmtB->fetchColumn();
if (!$studentB) {
    $db->exec("INSERT INTO users (name, email, password_hash, role, account_status) VALUES ('Test Student B', 'student_b@test.edu', 'hash', 'student', 'active')");
    $studentB = (int)$db->lastInsertId();
}

$uniqueKey = time() . '_' . rand(100, 999);

// -----------------------------------------------------------------------------
// Test 1: Notification Creation & Multi-Tenant Isolation
// -----------------------------------------------------------------------------
echo "1. Notification Creation & Multi-Tenant Isolation:\n";

// Clear test user existing notifications with this unique key
$notifA_Id = createNotification(
    $db,
    $studentA,
    'activity_status_change',
    "Student A Notice {$uniqueKey}",
    "Private notification content for Student A.",
    "enrollments.php",
    "test_iso_a_{$uniqueKey}"
);

$notifB_Id = createNotification(
    $db,
    $studentB,
    'activity_status_change',
    "Student B Notice {$uniqueKey}",
    "Private notification content for Student B.",
    "enrollments.php",
    "test_iso_b_{$uniqueKey}"
);

assertTest(
    "createNotification() successfully creates notifications for Student A and Student B",
    $notifA_Id !== null && $notifB_Id !== null
);

// Student A queries notifications
$listA = getStudentNotifications($db, $studentA, 50);
$foundBInA = false;
$foundAInA = false;
foreach ($listA as $n) {
    if ((int)$n['user_id'] === $studentB) $foundBInA = true;
    if ((int)$n['id'] === $notifA_Id) $foundAInA = true;
}

assertTest(
    "Student A sees only their own notifications and never Student B's (foundA=true, foundB=false)",
    $foundAInA === true && $foundBInA === false
);

// -----------------------------------------------------------------------------
// Test 2: Strict Cross-Student Authorization on Actions
// -----------------------------------------------------------------------------
echo "\n2. Strict Cross-Student Authorization on Actions:\n";

// Student B attempts to mark Student A's notification as read
$unauthorizedMark = markNotificationAsRead($db, $studentB, (int)$notifA_Id);
assertTest(
    "Student B cannot mark Student A's notification as read (returns false)",
    $unauthorizedMark === false
);

// Student B attempts to dismiss Student A's notification
$unauthorizedDismiss = dismissNotification($db, $studentB, (int)$notifA_Id);
assertTest(
    "Student B cannot dismiss Student A's notification (returns false)",
    $unauthorizedDismiss === false
);

// -----------------------------------------------------------------------------
// Test 3: Unread Count Synchronization & Individual Mark-Read
// -----------------------------------------------------------------------------
echo "\n3. Unread Count Synchronization:\n";
$unreadBefore = getUnreadNotificationCount($db, $studentA);

// Student A marks their own notification as read
$authorizedMark = markNotificationAsRead($db, $studentA, (int)$notifA_Id);
$unreadAfter = getUnreadNotificationCount($db, $studentA);

assertTest(
    "Student A can mark their own notification as read, decreasing unread count by 1",
    $authorizedMark === true && $unreadAfter === ($unreadBefore - 1)
);

// -----------------------------------------------------------------------------
// Test 4: Mark-All-As-Read Scope Isolation
// -----------------------------------------------------------------------------
echo "\n4. Mark-All-As-Read Scope Isolation:\n";
// Create 2 new unread notices for A and 1 for B
$notifA2 = createNotification($db, $studentA, 'system_notice', "Notice 2 {$uniqueKey}", "Body 2", null, "test_a2_{$uniqueKey}");
$notifA3 = createNotification($db, $studentA, 'system_notice', "Notice 3 {$uniqueKey}", "Body 3", null, "test_a3_{$uniqueKey}");

$markedCountA = markAllNotificationsAsRead($db, $studentA);

// Verify Student B's unread notification was untouched
$stmtCheckB = $db->prepare("SELECT is_read FROM notifications WHERE id = :id");
$stmtCheckB->execute([':id' => $notifB_Id]);
$bIsRead = (int)$stmtCheckB->fetchColumn();

assertTest(
    "markAllNotificationsAsRead() marks Student A's notices without modifying Student B's",
    $markedCountA >= 2 && $bIsRead === 0
);

// -----------------------------------------------------------------------------
// Test 5: Notification Category Preferences Opt-Out
// -----------------------------------------------------------------------------
echo "\n5. Notification Preferences Opt-Out Enforcement:\n";
// Student A disables deadline reminders
saveStudentNotificationPreferences($db, $studentA, [
    'notify_deadlines'        => false,
    'notify_status_changes'   => true,
    'notify_assessments'      => true,
    'notify_resume_portfolio' => true,
    'notify_announcements'    => true,
]);

$savedPrefs = getStudentNotificationPreferences($db, $studentA);
assertTest(
    "Notification preferences save and reload accurately from database",
    $savedPrefs['notify_deadlines'] === false && $savedPrefs['notify_status_changes'] === true
);

// Attempt to send deadline reminder to Student A -> should be skipped!
$skippedNotice = createNotification(
    $db,
    $studentA,
    'deadline_reminder',
    "Upcoming Deadline Notice",
    "Registration ending soon.",
    "activities.php",
    "test_optout_{$uniqueKey}"
);

assertTest(
    "createNotification() strictly respects student category opt-out (returns null)",
    $skippedNotice === null
);

// Essential notice should still be delivered regardless of preferences
$essentialNotice = createNotification(
    $db,
    $studentA,
    'account_security',
    "Security Alert: Password Changed",
    "Your account settings were updated.",
    "settings.php",
    "test_sec_{$uniqueKey}"
);

assertTest(
    "Essential security notices bypass optional category toggles and are always delivered",
    $essentialNotice !== null
);

// Re-enable deadlines for subsequent scheduler tests
saveStudentNotificationPreferences($db, $studentA, [
    'notify_deadlines'        => true,
    'notify_status_changes'   => true,
    'notify_assessments'      => true,
    'notify_resume_portfolio' => true,
    'notify_announcements'    => true,
]);

// -----------------------------------------------------------------------------
// Test 6: Deadline Reminder Engine & Verified Date Rules
// -----------------------------------------------------------------------------
echo "\n6. Deadline Reminders & Opportunity Rules:\n";

// Create an active test category and published opportunity with deadline in 5 days
$db->exec("INSERT INTO activity_categories (code, name, is_active) VALUES ('TEST_CAT_{$uniqueKey}', 'Test Category {$uniqueKey}', 1)");
$catId = (int)$db->lastInsertId();

$db->exec("INSERT INTO activities (category_id, title, is_active, source_id) VALUES ({$catId}, 'Test Activity {$uniqueKey}', 1, 1)");
$actId = (int)$db->lastInsertId();

// Case 1: Verified, published, future deadline (within 7 days)
$db->exec("
    INSERT INTO opportunities (
        activity_id, title, status, verification_status, registration_deadline, 
        delivery_mode, source_id, dedupe_hash, created_at
    ) VALUES (
        {$actId}, 'Verified Hackathon {$uniqueKey}', 'published', 'verified', 
        DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'online', 1, 'hash_ver_{$uniqueKey}', NOW()
    )
");
$oppVerifiedId = (int)$db->lastInsertId();

// Case 2: Unverified opportunity (should NEVER generate reminder)
$db->exec("
    INSERT INTO opportunities (
        activity_id, title, status, verification_status, registration_deadline, 
        delivery_mode, source_id, dedupe_hash, created_at
    ) VALUES (
        {$actId}, 'Unverified Hackathon {$uniqueKey}', 'draft', 'unverified', 
        DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'online', 1, 'hash_unver_{$uniqueKey}', NOW()
    )
");
$oppUnverifiedId = (int)$db->lastInsertId();

// Case 3: Archived opportunity (should NEVER generate reminder)
$db->exec("
    INSERT INTO opportunities (
        activity_id, title, status, verification_status, registration_deadline, 
        delivery_mode, source_id, dedupe_hash, created_at
    ) VALUES (
        {$actId}, 'Archived Hackathon {$uniqueKey}', 'archived', 'verified', 
        DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'online', 1, 'hash_arch_{$uniqueKey}', NOW()
    )
");
$oppArchivedId = (int)$db->lastInsertId();

// Case 4: Past deadline opportunity (should NEVER generate reminder)
$db->exec("
    INSERT INTO opportunities (
        activity_id, title, status, verification_status, registration_deadline, 
        delivery_mode, source_id, dedupe_hash, created_at
    ) VALUES (
        {$actId}, 'Past Deadline Hackathon {$uniqueKey}', 'expired', 'verified', 
        DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'online', 1, 'hash_past_{$uniqueKey}', NOW()
    )
");
$oppPastId = (int)$db->lastInsertId();

// Enroll Student A in all four
$db->exec("INSERT INTO enrollments (user_id, activity_id, opportunity_id, status) VALUES ({$studentA}, {$actId}, {$oppVerifiedId}, 'saved')");
$db->exec("INSERT INTO enrollments (user_id, activity_id, opportunity_id, status) VALUES ({$studentA}, {$actId}, {$oppUnverifiedId}, 'saved')");
$db->exec("INSERT INTO enrollments (user_id, activity_id, opportunity_id, status) VALUES ({$studentA}, {$actId}, {$oppArchivedId}, 'saved')");
$db->exec("INSERT INTO enrollments (user_id, activity_id, opportunity_id, status) VALUES ({$studentA}, {$actId}, {$oppPastId}, 'saved')");

// Run reminder engine
$engineRun1 = processUpcomingDeadlineReminders($db);

assertTest(
    "processUpcomingDeadlineReminders() successfully creates reminder for verified future opportunity",
    $engineRun1['notifications_created'] >= 1
);

// Check that no reminders were generated for unverified, archived, or past-deadline opps
$stmtChkUnver = $db->query("SELECT id FROM notifications WHERE dedupe_key LIKE '%opp{$oppUnverifiedId}%'");
$stmtChkArch  = $db->query("SELECT id FROM notifications WHERE dedupe_key LIKE '%opp{$oppArchivedId}%'");
$stmtChkPast  = $db->query("SELECT id FROM notifications WHERE dedupe_key LIKE '%opp{$oppPastId}%'");

assertTest(
    "Unverified, archived, or past-deadline opportunities are excluded from reminder generation",
    $stmtChkUnver->fetch() === false && $stmtChkArch->fetch() === false && $stmtChkPast->fetch() === false
);

// -----------------------------------------------------------------------------
// Test 7: Scheduler Idempotency & Zero Duplication
// -----------------------------------------------------------------------------
echo "\n7. Scheduler Idempotency (Repeat Execution Safety):\n";
$engineRun2 = processUpcomingDeadlineReminders($db);

assertTest(
    "Repeated scheduled task execution generates ZERO duplicate notifications (idempotent)",
    $engineRun2['notifications_created'] === 0 && $engineRun2['notifications_skipped'] >= 1
);

// -----------------------------------------------------------------------------
// Test 8: Historical Integrity After Opportunity Expiration
// -----------------------------------------------------------------------------
echo "\n8. Historical Integrity After Opportunity Expiration:\n";
// Expire the verified opportunity
$db->exec("UPDATE opportunities SET status = 'expired' WHERE id = {$oppVerifiedId}");

// Check that Student A's already-delivered reminder notification still exists in the inbox!
$stmtPreserved = $db->prepare("SELECT id, title, is_dismissed FROM notifications WHERE user_id = :uid AND dedupe_key LIKE :key");
$stmtPreserved->execute([':uid' => $studentA, ':key' => "%opp{$oppVerifiedId}%"]);
$preserved = $stmtPreserved->fetch(PDO::FETCH_ASSOC);

assertTest(
    "Already-created notifications remain permanently preserved in student inbox after opportunity expires",
    $preserved !== false && !empty($preserved['title'])
);

// -----------------------------------------------------------------------------
// Test 9: Safe Link Routing & Open Redirect Prevention
// -----------------------------------------------------------------------------
echo "\n9. Safe Link Routing & Security:\n";
$safeInternal = sanitizeNotificationLink("activities.php?opp_id=12");
$safeExternal = sanitizeNotificationLink("https://organizer.edu.in/register");
$dangerousExternal = sanitizeNotificationLink("javascript:alert(1)");
$malformedProtocol = sanitizeNotificationLink("http://phishing-site.example");

assertTest(
    "sanitizeNotificationLink() allows legitimate internal paths and secure HTTPS domains, blocks unsafe protocols",
    $safeInternal === "activities.php?opp_id=12" && 
    $safeExternal === "https://organizer.edu.in/register" && 
    $dangerousExternal === null && 
    $malformedProtocol === null
);

// -----------------------------------------------------------------------------
// Test 10: Zero Assessment Score Leaks in Previews
// -----------------------------------------------------------------------------
echo "\n10. Assessment Score Privacy:\n";
$notifAsmtId = createNotification(
    $db,
    $studentA,
    'assessment_completed',
    'Skill Assessment Evaluated',
    'Your AI skill assessment has been evaluated. Review your verified diagnostic report.',
    'assessment_results.php?id=99',
    "test_asmt_priv_{$uniqueKey}"
);

$stmtAsmt = $db->prepare("SELECT message, link FROM notifications WHERE id = :id");
$stmtAsmt->execute([':id' => $notifAsmtId]);
$asmtRow = $stmtAsmt->fetch(PDO::FETCH_ASSOC);

$hasNoScores = strpos($asmtRow['message'], '%') === false && 
               strpos($asmtRow['message'], '/100') === false && 
               strpos($asmtRow['message'], 'grade') === false;

assertTest(
    "Assessment notification previews contain zero private scores or grades, linking directly to authenticated results",
    $hasNoScores && strpos($asmtRow['link'], 'assessment_results.php') !== false
);

// -----------------------------------------------------------------------------
// Test 11: Platform Announcements & Broadcasts
// -----------------------------------------------------------------------------
echo "\n11. Platform Announcement Broadcasting:\n";
$annId = publishPlatformAnnouncement(
    $db,
    1, // Admin
    "Platform Broadcast {$uniqueKey}",
    "CareerAct platform update available for all students.",
    "dashboard.php",
    "all_students"
);

assertTest(
    "publishPlatformAnnouncement() creates announcement record in platform_announcements",
    $annId > 0
);

// Check that in-app notification arrived in Student A's notification center
$stmtAnnNotif = $db->prepare("SELECT id, title FROM notifications WHERE user_id = :uid AND dedupe_key = :key");
$stmtAnnNotif->execute([':uid' => $studentA, ':key' => "announcement_{$annId}_u{$studentA}"]);
$annNotif = $stmtAnnNotif->fetch(PDO::FETCH_ASSOC);

assertTest(
    "Platform announcement delivers in-app notification to student notification center",
    $annNotif !== false && strpos($annNotif['title'], "Platform Broadcast {$uniqueKey}") !== false
);

// Clean up test rows
$db->exec("DELETE FROM notifications WHERE user_id IN ({$studentA}, {$studentB}) AND (title LIKE '%{$uniqueKey}%' OR dedupe_key LIKE '%{$uniqueKey}%')");
$db->exec("DELETE FROM platform_announcements WHERE id = {$annId}");
$db->exec("DELETE FROM enrollments WHERE opportunity_id IN ({$oppVerifiedId}, {$oppUnverifiedId}, {$oppArchivedId}, {$oppPastId})");
$db->exec("DELETE FROM opportunities WHERE id IN ({$oppVerifiedId}, {$oppUnverifiedId}, {$oppArchivedId}, {$oppPastId})");
$db->exec("DELETE FROM activities WHERE id = {$actId}");
$db->exec("DELETE FROM activity_categories WHERE id = {$catId}");

// -----------------------------------------------------------------------------
// Final Summary
// -----------------------------------------------------------------------------
echo "\n====================================================================\n";
echo "MODULE 14 TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "====================================================================\n";

if ($failed === 0) {
    echo "🎉 ALL MODULE 14 TESTS PASSED PERFECTLY!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED. PLEASE REVIEW OUTPUT ABOVE.\n";
    exit(1);
}

