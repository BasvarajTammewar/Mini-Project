-- CareerAct Migration v5: AI-Powered Real-World Skill Assessment Module
-- Target Databases: carrearact2, careeract

CREATE TABLE IF NOT EXISTS `ai_skill_assessments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `skill_id` INT UNSIGNED NOT NULL,
    `status` ENUM('in_progress', 'completed', 'failed') NOT NULL DEFAULT 'in_progress',
    `overall_score` DECIMAL(5,2) NULL,
    `demonstration_level` ENUM('Strongly Demonstrated', 'Partially Demonstrated', 'Not Yet Demonstrated') NULL,
    `ai_feedback` TEXT NULL,
    `strengths_json` TEXT NULL,
    `improvements_json` TEXT NULL,
    `recommendations_json` TEXT NULL,
    `rubric_summary_json` TEXT NULL,
    `attempt_number` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    INDEX `idx_ai_user_skill` (`user_id`, `skill_id`),
    INDEX `idx_ai_status` (`status`),
    CONSTRAINT `fk_ai_assess_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ai_assess_skill` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_assessment_questions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `assessment_id` INT UNSIGNED NOT NULL,
    `question_index` TINYINT UNSIGNED NOT NULL,
    `scenario_text` TEXT NOT NULL,
    `evaluation_criteria` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ai_q_assessment` (`assessment_id`),
    CONSTRAINT `fk_ai_q_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `ai_skill_assessments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_assessment_answers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `assessment_id` INT UNSIGNED NOT NULL,
    `question_id` INT UNSIGNED NOT NULL,
    `answer_text` TEXT NOT NULL,
    `score` DECIMAL(5,2) NULL,
    `feedback` TEXT NULL,
    `rubric_breakdown_json` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ai_a_assessment` (`assessment_id`),
    INDEX `idx_ai_a_question` (`question_id`),
    CONSTRAINT `fk_ai_a_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `ai_skill_assessments` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ai_a_question` FOREIGN KEY (`question_id`) REFERENCES `ai_assessment_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

