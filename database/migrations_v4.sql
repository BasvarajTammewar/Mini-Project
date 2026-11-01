-- ============================================================================
-- CareerAct — Assessment & Roadmap Persistence Migration (v4)
-- ============================================================================

USE careeract;

CREATE TABLE IF NOT EXISTS student_assessment_records (
    record_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    career_id SMALLINT UNSIGNED NOT NULL,
    readiness_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    decision_path ENUM('path_a', 'path_b', 'path_c') NOT NULL DEFAULT 'path_b',
    path_title VARCHAR(100) NOT NULL,
    strengths_summary TEXT NOT NULL,
    improvements_summary TEXT NOT NULL,
    confidence_level ENUM('high', 'moderate', 'provisional') NOT NULL DEFAULT 'moderate',
    evidence_verified_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    self_reported_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_assessment_student (student_id),
    CONSTRAINT fk_assessment_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_assessment_career FOREIGN KEY (career_id) REFERENCES career_paths (career_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_roadmap_milestones (
    
    milestone_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    career_id SMALLINT UNSIGNED NOT NULL,
    phase_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
    phase_title VARCHAR(100) NOT NULL,
    competency_title VARCHAR(150) NOT NULL,
    reason_text VARCHAR(500) NOT NULL,
    suggested_action VARCHAR(500) NOT NULL,
    priority_level ENUM('critical', 'high', 'medium', 'advanced') NOT NULL DEFAULT 'high',
    target_period VARCHAR(100) NOT NULL,
    expected_outcome VARCHAR(500) NOT NULL,
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_roadmap_student (student_id),
    CONSTRAINT fk_roadmap_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_roadmap_career FOREIGN KEY (career_id) REFERENCES career_paths (career_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
