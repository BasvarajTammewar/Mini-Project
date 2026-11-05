-- ============================================================================
-- CAREERACT — MIGRATION V8: STUDENT PORTFOLIO & ACHIEVEMENT SHOWCASE
-- Database: carrearact2
-- Module 12: Professional Portfolio Showcase with Selective Privacy Controls
-- ============================================================================

CREATE TABLE IF NOT EXISTS `student_portfolios` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL UNIQUE,
    `share_token` VARCHAR(64) NOT NULL UNIQUE,
    `is_public` TINYINT(1) NOT NULL DEFAULT 0,
    `headline` VARCHAR(220) NULL,
    `bio` TEXT NULL,
    `display_email` TINYINT(1) NOT NULL DEFAULT 0,
    `display_phone` TINYINT(1) NOT NULL DEFAULT 0,
    `linkedin_url` VARCHAR(255) NULL,
    `github_url` VARCHAR(255) NULL,
    `website_url` VARCHAR(255) NULL,
    `theme_style` VARCHAR(50) NOT NULL DEFAULT 'modern_slate',
    `visible_sections_json` JSON NULL,
    `item_visibility_json` JSON NULL,
    `custom_descriptions_json` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_port_user` (`user_id`),
    INDEX `idx_port_share` (`share_token`),
    CONSTRAINT `fk_portfolio_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

