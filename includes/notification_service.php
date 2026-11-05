<?php
/**
 * CareerAct — Notification Service & Student Reminders
 * Central backend service for in-app notifications, deadline reminders,
 * notification preferences, and platform announcements.
 * 
 * Database: carrearact2
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Global reminder timing configuration (in days before deadline / event).
 * Configured centrally here rather than hardcoded in multiple files.
 */
function getReminderTimingConfig(): array
{
    return [
        'advance_notice_days' => 7, // 1 week prior reminder
        'urgent_notice_days'  => 3, // 3 days prior reminder
        'final_notice_days'   => 1, // 1 day prior / eve reminder
        'today_notice_days'   => 0, // Day of deadline reminder
    ];
}

/**
 * Returns student notification preferences.
 * Defaults all optional categories to enabled (1) if no custom row exists.
 */
function getStudentNotificationPreferences(PDO $db, int $userId): array
{
    $stmt = $db->prepare("
        SELECT 
            notify_deadlines,
            notify_status_changes,
            notify_assessments,
            notify_resume_portfolio,
            notify_announcements,
            updated_at
        FROM student_notification_preferences
        WHERE user_id = :user_id
    ");
    $stmt->execute([':user_id' => $userId]);
    $prefs = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($prefs) {
        return [
            'notify_deadlines'        => (bool)$prefs['notify_deadlines'],
            'notify_status_changes'   => (bool)$prefs['notify_status_changes'],
            'notify_assessments'      => (bool)$prefs['notify_assessments'],
            'notify_resume_portfolio' => (bool)$prefs['notify_resume_portfolio'],
            'notify_announcements'    => (bool)$prefs['notify_announcements'],
            'updated_at'              => $prefs['updated_at'],
        ];
    }

    // Default: all enabled
    return [
        'notify_deadlines'        => true,
        'notify_status_changes'   => true,
        'notify_assessments'      => true,
        'notify_resume_portfolio' => true,
        'notify_announcements'    => true,
        'updated_at'              => null,
    ];
}

/**
 * Saves student notification preferences.
 */
function saveStudentNotificationPreferences(PDO $db, int $userId, array $prefs): bool
{
    $deadlines       = !empty($prefs['notify_deadlines']) ? 1 : 0;
    $statusChanges   = !empty($prefs['notify_status_changes']) ? 1 : 0;
    $assessments     = !empty($prefs['notify_assessments']) ? 1 : 0;
    $resumePortfolio = !empty($prefs['notify_resume_portfolio']) ? 1 : 0;
    $announcements   = !empty($prefs['notify_announcements']) ? 1 : 0;

    $stmt = $db->prepare("
        INSERT INTO student_notification_preferences (
            user_id, notify_deadlines, notify_status_changes, notify_assessments,
            notify_resume_portfolio, notify_announcements, updated_at
        ) VALUES (
            :user_id, :deadlines, :status_changes, :assessments,
            :resume_portfolio, :announcements, NOW()
        )
        ON DUPLICATE KEY UPDATE
            notify_deadlines = VALUES(notify_deadlines),
            notify_status_changes = VALUES(notify_status_changes),
            notify_assessments = VALUES(notify_assessments),
            notify_resume_portfolio = VALUES(notify_resume_portfolio),
            notify_announcements = VALUES(notify_announcements),
            updated_at = NOW()
    ");

    return $stmt->execute([
        ':user_id'          => $userId,
        ':deadlines'        => $deadlines,
        ':status_changes'   => $statusChanges,
        ':assessments'      => $assessments,
        ':resume_portfolio' => $resumePortfolio,
        ':announcements'    => $announcements,
    ]);
}

/**
 * Validates and sanitizes internal destination links.
 * Prevents open redirect vulnerabilities.
 */
function sanitizeNotificationLink(?string $link): ?string
{
    if (empty($link)) {
        return null;
    }

    $link = trim($link);

    // Allow legitimate internal relative routes
    if (preg_match('/^[a-zA-Z0-9_\-\.\/]+\.php(\?[a-zA-Z0-9_\-=&%\.\/]*)?$/', $link)) {
        return $link;
    }

    // Allow verified secure external organizer URLs
    if (preg_match('/^https:\/\/[a-zA-Z0-9_\-\.]+\.[a-zA-Z]{2,}(\/.*)?$/', $link)) {
        return $link;
    }

    return null;
}

/**
 * Core In-App Notification Dispatcher.
 * 
 * Validates event, checks student preferences, enforces deduplication,
 * and records notification.
 */
function createNotification(
    PDO $db,
    int $userId,
    string $type,
    string $title,
    string $message,
    ?string $link = null,
    ?string $dedupeKey = null
): ?int {
    // 1. Verify user exists and is active
    $stmtUser = $db->prepare("SELECT id, role, account_status FROM users WHERE id = :id");
    $stmtUser->execute([':id' => $userId]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$user || $user['account_status'] === 'suspended') {
        return null;
    }

    // 2. Check student preferences based on notification type
    $prefs = getStudentNotificationPreferences($db, $userId);

    $isAllowed = true;
    switch ($type) {
        case 'deadline_reminder':
        case 'event_reminder':
            $isAllowed = $prefs['notify_deadlines'];
            break;
        case 'activity_status_change':
        case 'opportunity_update':
        case 'opportunity_archived':
            $isAllowed = $prefs['notify_status_changes'];
            break;
        case 'assessment_completed':
        case 'assessment_failed':
        case 'skill_gap_ready':
        case 'recommendations_ready':
            $isAllowed = $prefs['notify_assessments'];
            break;
        case 'resume_saved':
        case 'portfolio_updated':
        case 'portfolio_shared':
            $isAllowed = $prefs['notify_resume_portfolio'];
            break;
        case 'platform_announcement':
            $isAllowed = $prefs['notify_announcements'];
            break;
        case 'account_security':
        case 'system_notice':
        default:
            $isAllowed = true; // Essential notices bypass optional preferences
            break;
    }

    if (!$isAllowed) {
        return null; // Student opted out of this category
    }

    // 3. Check deduplication key (idempotency)
    if (!empty($dedupeKey)) {
        $stmtDup = $db->prepare("SELECT id FROM notifications WHERE dedupe_key = :key LIMIT 1");
        $stmtDup->execute([':key' => $dedupeKey]);
        $existingId = $stmtDup->fetchColumn();
        if ($existingId) {
            return null; // Already dispatched, avoid duplicate
        }
    }

    $sanitizedLink = sanitizeNotificationLink($link);
    $cleanTitle = mb_substr(trim($title), 0, 120);
    $cleanMessage = mb_substr(trim($message), 0, 300);

    $stmtIns = $db->prepare("
        INSERT INTO notifications (
            user_id, title, notification_type, message, link, dedupe_key,
            is_read, is_dismissed, created_at
        ) VALUES (
            :user_id, :title, :type, :message, :link, :dedupe_key,
            0, 0, NOW()
        )
    ");

    $stmtIns->execute([
        ':user_id'    => $userId,
        ':title'      => $cleanTitle,
        ':type'       => $type,
        ':message'    => $cleanMessage,
        ':link'       => $sanitizedLink,
        ':dedupe_key' => $dedupeKey ?: null,
    ]);

    return (int)$db->lastInsertId();
}

/**
 * Retrieves unread notification count for authenticated user.
 */
function getUnreadNotificationCount(PDO $db, int $userId): int
{
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM notifications 
        WHERE user_id = :user_id 
          AND is_read = 0 
          AND is_dismissed = 0
    ");
    $stmt->execute([':user_id' => $userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Retrieves paginated notifications for the student.
 */
function getStudentNotifications(
    PDO $db,
    int $userId,
    int $limit = 30,
    int $offset = 0,
    bool $unreadOnly = false,
    ?string $typeFilter = null
): array {
    $sql = "
        SELECT 
            id,
            user_id,
            title,
            notification_type,
            message,
            link,
            is_read,
            read_at,
            is_dismissed,
            created_at
        FROM notifications
        WHERE user_id = :user_id
          AND is_dismissed = 0
    ";

    $params = [':user_id' => $userId];

    if ($unreadOnly) {
        $sql .= " AND is_read = 0";
    }

    if (!empty($typeFilter)) {
        $sql .= " AND notification_type = :type";
        $params[':type'] = $typeFilter;
    }

    $sql .= " ORDER BY is_read ASC, created_at DESC, id DESC LIMIT :lim OFFSET :off";

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', max(1, min(100, $limit)), PDO::PARAM_INT);
    $stmt->bindValue(':off', max(0, $offset), PDO::PARAM_INT);

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Marks a single notification as read.
 * STRICT AUTHORIZATION: Enforces user ownership.
 */
function markNotificationAsRead(PDO $db, int $userId, int $notificationId): bool
{
    $stmt = $db->prepare("
        UPDATE notifications 
        SET is_read = 1, read_at = NOW() 
        WHERE id = :id AND user_id = :user_id
    ");
    $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    return $stmt->rowCount() > 0;
}

/**
 * Marks all notifications as read for the student.
 */
function markAllNotificationsAsRead(PDO $db, int $userId): int
{
    $stmt = $db->prepare("
        UPDATE notifications 
        SET is_read = 1, read_at = NOW() 
        WHERE user_id = :user_id AND is_read = 0 AND is_dismissed = 0
    ");
    $stmt->execute([':user_id' => $userId]);
    return $stmt->rowCount();
}

/**
 * Dismisses a notification (hides from inbox while preserving history).
 * STRICT AUTHORIZATION: Enforces user ownership.
 */
function dismissNotification(PDO $db, int $userId, int $notificationId): bool
{
    $stmt = $db->prepare("
        UPDATE notifications 
        SET is_dismissed = 1, dismissed_at = NOW() 
        WHERE id = :id AND user_id = :user_id
    ");
    $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    return $stmt->rowCount() > 0;
}

/**
 * Process Automatic Deadline Reminders.
 * 
 * Scans saved and enrolled opportunities with approaching verified registration deadlines.
 * Generates advance reminders based on central timing rules.
 * Enforces deduplication so repeated executions never spam or duplicate reminders.
 */
function processUpcomingDeadlineReminders(PDO $db): array
{
    $config = getReminderTimingConfig();
    $advDays    = $config['advance_notice_days']; // 7
    $urgentDays = $config['urgent_notice_days'];  // 3
    $finalDays  = $config['final_notice_days'];   // 1
    $todayDays  = $config['today_notice_days'];   // 0

    $results = [
        'evaluated_enrollments' => 0,
        'notifications_created' => 0,
        'notifications_skipped' => 0,
    ];

    // Query active student enrollments for published, verified opportunities with upcoming deadlines
    $sql = "
        SELECT 
            e.id AS enrollment_id,
            e.user_id,
            e.status AS enrollment_status,
            o.id AS opportunity_id,
            o.title AS opportunity_title,
            o.registration_deadline,
            o.start_date,
            o.venue,
            o.delivery_mode,
            ac.name AS category_name,
            DATEDIFF(o.registration_deadline, CURDATE()) AS days_left
        FROM enrollments e
        JOIN opportunities o ON e.opportunity_id = o.id
        JOIN activities a ON o.activity_id = a.id
        JOIN activity_categories ac ON a.category_id = ac.id
        WHERE e.status IN ('saved', 'registered', 'in_progress')
          AND o.status = 'published'
          AND o.verification_status IN ('verified', 'sample')
          AND o.registration_deadline IS NOT NULL
          AND o.registration_deadline >= CURDATE()
          AND DATEDIFF(o.registration_deadline, CURDATE()) <= :max_days
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':max_days', $advDays, PDO::PARAM_INT);
    $stmt->execute();
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results['evaluated_enrollments'] = count($candidates);

    foreach ($candidates as $cand) {
        $userId   = (int)$cand['user_id'];
        $oppId    = (int)$cand['opportunity_id'];
        $daysLeft = (int)$cand['days_left'];
        $title    = $cand['opportunity_title'];
        $deadline = date('M j, Y', strtotime($cand['registration_deadline']));

        $periodKey = null;
        $reminderTitle = '';
        $reminderMsg = '';

        if ($daysLeft === 0) {
            $periodKey = 'today';
            $reminderTitle = "Deadline Today: {$title}";
            $reminderMsg = "Today is the final day to register for '{$title}' ({$cand['category_name']}). Complete your registration before portals close.";
        } elseif ($daysLeft <= $finalDays) {
            $periodKey = '1d';
            $reminderTitle = "1 Day Left: {$title}";
            $reminderMsg = "Only 1 day left before registration closes for '{$title}' on {$deadline}.";
        } elseif ($daysLeft <= $urgentDays) {
            $periodKey = '3d';
            $reminderTitle = "3 Days Left: {$title}";
            $reminderMsg = "Registration deadline for '{$title}' is approaching on {$deadline}. Don't miss this opportunity.";
        } elseif ($daysLeft <= $advDays) {
            $periodKey = '7d';
            $reminderTitle = "Upcoming Deadline: {$title}";
            $reminderMsg = "Reminder: Registration for '{$title}' closes in {$daysLeft} days ({$deadline}).";
        }

        if ($periodKey) {
            $dedupeKey = "rem_deadline_{$periodKey}_opp{$oppId}_u{$userId}";
            $link = "activities.php?category_id=" . urlencode((string)$cand['category_name']) . "&opp_id={$oppId}";

            $notifId = createNotification(
                $db,
                $userId,
                'deadline_reminder',
                $reminderTitle,
                $reminderMsg,
                $link,
                $dedupeKey
            );

            if ($notifId) {
                $results['notifications_created']++;
            } else {
                $results['notifications_skipped']++;
            }
        }
    }

    return $results;
}

/**
 * Publishes a platform announcement from an admin and broadcasts in-app notices.
 */
function publishPlatformAnnouncement(
    PDO $db,
    int $adminId,
    string $title,
    string $message,
    ?string $link = null,
    string $audience = 'all_students'
): int {
    $stmt = $db->prepare("
        INSERT INTO platform_announcements (
            admin_id, title, message, link, target_audience, is_published, created_at
        ) VALUES (
            :admin_id, :title, :message, :link, :audience, 1, NOW()
        )
    ");
    $stmt->execute([
        ':admin_id' => $adminId,
        ':title'    => trim($title),
        ':message'  => trim($message),
        ':link'     => sanitizeNotificationLink($link),
        ':audience' => $audience,
    ]);
    $announcementId = (int)$db->lastInsertId();

    // Broadcast to eligible students
    $stmtStudents = $db->query("SELECT id FROM users WHERE role = 'student' AND account_status = 'active'");
    while ($studentId = $stmtStudents->fetchColumn()) {
        $dedupeKey = "announcement_{$announcementId}_u{$studentId}";
        createNotification(
            $db,
            (int)$studentId,
            'platform_announcement',
            "Announcement: " . mb_substr($title, 0, 100),
            mb_substr($message, 0, 280),
            $link,
            $dedupeKey
        );
    }

    return $announcementId;
}
