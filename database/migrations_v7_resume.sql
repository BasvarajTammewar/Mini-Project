-- ============================================================================
-- CAREERACT — MIGRATION V7: AI RESUME BUILDER & PORTFOLIO
-- Database: carrearact2
-- Module 11: Professional Editable Resume Builder with Multi-Version Support
-- ============================================================================

CREATE TABLE IF NOT EXISTS `student_resumes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `career_id` INT UNSIGNED NULL,
    `target_role` VARCHAR(190) NOT NULL,
    `title` VARCHAR(190) NOT NULL,
    `template_style` VARCHAR(50) NOT NULL DEFAULT 'modern_ats',
    `summary` TEXT NULL,
    `contact_info_json` JSON NULL,
    `education_data` JSON NULL,
    `skills_data` JSON NULL,
    `experience_data` JSON NULL,
    `projects_data` JSON NULL,
    `certificates_data` JSON NULL,
    `custom_sections_json` JSON NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_resume_user` (`user_id`),
    INDEX `idx_resume_career` (`career_id`),
    CONSTRAINT `fk_resume_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_resume_career` FOREIGN KEY (`career_id`) REFERENCES `careers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

