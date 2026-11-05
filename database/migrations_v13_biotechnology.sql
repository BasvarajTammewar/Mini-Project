-- =========================================================================
-- CareerAct — Migration V13: Biotechnology Education and Career Domain
-- Database: carrearact2
-- Adds schema extensions for Biotechnology Education Pathways, Skill Details,
-- Career Specialisations, and Biosafety-compliant Activity Details.
-- =========================================================================

-- 1. Biotechnology Education Pathways Catalog
CREATE TABLE IF NOT EXISTS `biotechnology_education_pathways` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `degree_level` ENUM('undergraduate', 'postgraduate', 'doctorate') NOT NULL,
    `degree_name` VARCHAR(190) NOT NULL,
    `specialisation` VARCHAR(150) NOT NULL,
    `duration_years` VARCHAR(50) NOT NULL DEFAULT '3-4 years',
    `eligibility` TEXT NOT NULL,
    `core_subjects` TEXT NOT NULL,
    `lab_training` TEXT NOT NULL,
    `specialisation_areas` TEXT NOT NULL,
    `career_opportunities` TEXT NOT NULL,
    `higher_study_options` TEXT NOT NULL,
    `dissertation_or_proposal` TEXT NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_bio_degree_name` (`degree_name`),
    KEY `idx_bio_degree_level` (`degree_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Extended Biotechnology Skill Competency Details
CREATE TABLE IF NOT EXISTS `biotech_skill_details` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `skill_id` INT UNSIGNED NOT NULL,
    `specialisation` VARCHAR(100) NOT NULL,
    `proficiency_beginner` TEXT NOT NULL,
    `proficiency_intermediate` TEXT NOT NULL,
    `proficiency_advanced` TEXT NOT NULL,
    `practical_examples` TEXT NOT NULL,
    `relevant_degrees` VARCHAR(255) NOT NULL,
    `career_roles` TEXT NOT NULL,
    `extracurricular_activities` TEXT NOT NULL,
    `resume_evidence` TEXT NOT NULL,
    `improvement_activities` TEXT NOT NULL,
    UNIQUE KEY `uk_biotech_skill_id` (`skill_id`),
    CONSTRAINT `fk_bsd_skill` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Extended Biotechnology Career Role Details
CREATE TABLE IF NOT EXISTS `biotech_career_details` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `career_id` INT UNSIGNED NOT NULL,
    `specialisation` VARCHAR(100) NOT NULL,
    `min_education_level` ENUM('undergraduate','postgraduate','doctorate') NOT NULL DEFAULT 'undergraduate',
    `preferred_education_level` ENUM('undergraduate','postgraduate','doctorate') NOT NULL DEFAULT 'postgraduate',
    `role_orientation` VARCHAR(100) NOT NULL DEFAULT 'Laboratory-oriented',
    `practical_projects` TEXT NOT NULL,
    `career_progression` TEXT NOT NULL,
    `resume_evidence` TEXT NOT NULL,
    `higher_study_options` TEXT NOT NULL,
    UNIQUE KEY `uk_biotech_career_id` (`career_id`),
    CONSTRAINT `fk_bcd_career` FOREIGN KEY (`career_id`) REFERENCES `careers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Extended Biotechnology Activity Details (with Biosafety Standards)
CREATE TABLE IF NOT EXISTS `biotech_activity_details` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `activity_id` INT UNSIGNED NOT NULL,
    `specialisation` VARCHAR(100) NOT NULL,
    `suitable_degree_levels` VARCHAR(150) NOT NULL DEFAULT 'B.Sc., M.Sc., Ph.D.',
    `deliverables` TEXT NOT NULL,
    `resume_achievements` TEXT NOT NULL,
    `biosafety_guidelines` TEXT NOT NULL,
    UNIQUE KEY `uk_biotech_activity_id` (`activity_id`),
    CONSTRAINT `fk_bad_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
