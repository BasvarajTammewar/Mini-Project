-- ============================================================================
-- CAREERACT — MIGRATION V10: NOTIFICATIONS & STUDENT REMINDERS
-- Database: carrearact2
-- Module 14: In-App Notification Center, Preferences, & Deadline Reminders
-- ============================================================================

-- 1. Enhance notifications table with title, deduplication key, and dismissal fields
ALTER TABLE notifications 
    ADD COLUMN IF NOT EXISTS title VARCHAR(120) NOT NULL DEFAULT 'Notification' AFTER user_id,
    ADD COLUMN IF NOT EXISTS dedupe_key VARCHAR(120) NULL AFTER link,
    ADD COLUMN IF NOT EXISTS is_dismissed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read,
    ADD COLUMN IF NOT EXISTS dismissed_at DATETIME NULL AFTER is_dismissed;

-- Ensure index on dedupe_key for fast idempotency lookups
ALTER TABLE notifications 
    ADD UNIQUE INDEX IF NOT EXISTS uq_notif_dedupe (dedupe_key);

-- Ensure index on user_id + is_read + is_dismissed for rapid inbox queries
ALTER TABLE notifications 
    ADD INDEX IF NOT EXISTS idx_user_notif_status (user_id, is_read, is_dismissed, created_at);

-- 2. Student Notification Category Preferences
CREATE TABLE IF NOT EXISTS student_notification_preferences (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    notify_deadlines TINYINT(1) NOT NULL DEFAULT 1,
    notify_status_changes TINYINT(1) NOT NULL DEFAULT 1,
    notify_assessments TINYINT(1) NOT NULL DEFAULT 1,
    notify_resume_portfolio TINYINT(1) NOT NULL DEFAULT 1,
    notify_announcements TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_snp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Platform Announcements (Admin Broadcasts)
CREATE TABLE IF NOT EXISTS platform_announcements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(300) NULL,
    target_audience ENUM('all_students', 'all_users') NOT NULL DEFAULT 'all_students',
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pa_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

