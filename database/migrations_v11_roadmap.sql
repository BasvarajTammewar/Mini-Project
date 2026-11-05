-- ============================================================================
-- CareerAct — Feature 6: AI Career Roadmap & Activity Tracking Migration (v11)
-- Database: carrearact2
-- Compatible with users(id) and careers(id)
-- ============================================================================

CREATE TABLE IF NOT EXISTS student_roadmap_milestones (
    milestone_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    career_id INT UNSIGNED NOT NULL,
    phase_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
    phase_title VARCHAR(150) NOT NULL,
    competency_title VARCHAR(190) NOT NULL,
    reason_text VARCHAR(600) NOT NULL,
    suggested_action VARCHAR(600) NOT NULL,
    recommended_activity_id INT UNSIGNED NULL,
    priority_level ENUM('critical', 'high', 'medium', 'advanced') NOT NULL DEFAULT 'high',
    target_period VARCHAR(100) NOT NULL,
    expected_outcome VARCHAR(600) NOT NULL,
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_roadmap_user (user_id),
    KEY idx_roadmap_career (career_id),
    KEY idx_roadmap_activity (recommended_activity_id),
    CONSTRAINT fk_roadmap_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_roadmap_career FOREIGN KEY (career_id) REFERENCES careers (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add index on enrollments to fast query and prevent duplicates if not exists
CREATE INDEX IF NOT EXISTS idx_enrollments_user_opp ON enrollments (user_id, opportunity_id, status);
CREATE INDEX IF NOT EXISTS idx_enrollments_user_act ON enrollments (user_id, activity_id, status);

