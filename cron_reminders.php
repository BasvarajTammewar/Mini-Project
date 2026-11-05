<?php
/**
 * CareerAct — Automated Deadline Reminders Cron / Scheduled Task
 * 
 * Safe, idempotent CLI script for Windows Task Scheduler or periodic execution.
 * Synchronizes expired opportunities and generates in-app deadline reminders.
 * 
 * Usage:
 *   CLI: C:\xampp\php\php.exe c:\xampp\htdocs\Mini-Project\cron_reminders.php
 */

declare(strict_types=1);

// CLI execution preferred; allow web access only with secure token or local development
if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '::1') {
    $providedKey = $_GET['cron_key'] ?? '';
    $expectedKey = 'careeract_scheduled_reminders_key';
    if (!hash_equals($expectedKey, $providedKey)) {
        http_response_code(403);
        die("403 Forbidden: Direct web access disallowed without valid cron key.\n");
    }
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/event_discovery_service.php';
require_once __DIR__ . '/includes/notification_service.php';

$startTime = microtime(true);
$timestamp = date('Y-m-d H:i:s');

echo "====================================================================\n";
echo "CAREERACT SCHEDULED TASK: DEADLINE REMINDERS & STATUS SYNC\n";
echo "Timestamp: {$timestamp}\n";
echo "====================================================================\n";

try {
    $db = getDbConnection();

    // 1. Expire past opportunities first
    echo "1. Checking and expiring past opportunities...\n";
    $expiredCount = autoExpirePastOpportunities($db);
    echo "   -> Expired {$expiredCount} past opportunities.\n";

    // 2. Process upcoming deadline reminders
    echo "2. Processing upcoming opportunity deadline reminders...\n";
    $stats = processUpcomingDeadlineReminders($db);
    echo "   -> Evaluated {$stats['evaluated_enrollments']} active candidate enrollments.\n";
    echo "   -> Created {$stats['notifications_created']} new in-app reminders.\n";
    echo "   -> Skipped {$stats['notifications_skipped']} (already reminded or opted-out).\n";

    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
    echo "\nTask completed successfully in {$elapsed} ms.\n";
    echo "====================================================================\n";

} catch (Throwable $e) {
    echo "\nERROR: Scheduled task failed: " . $e->getMessage() . "\n";
    error_log("CareerAct Scheduled Reminders Error: " . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        exit(1);
    }
}

