-- ============================================================================
-- CareerAct — Feature: AI Robot Skill Assessment & Question History Migration (v12)
-- Database: carrearact2
-- Safe schema updates for conversational AI interviewer and non-repeating questions
-- ============================================================================

-- 1. Add question_fingerprint, scenario_category, and difficulty_level to ai_assessment_questions
SET @dbname = DATABASE();
SET @tablename = "ai_assessment_questions";

-- Add question_fingerprint if missing
SET @colname = "question_fingerprint";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @colname
  ) > 0,
  "SELECT 1",
  "ALTER TABLE ai_assessment_questions ADD COLUMN question_fingerprint VARCHAR(64) NULL AFTER evaluation_criteria;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add scenario_category if missing
SET @colname = "scenario_category";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @colname
  ) > 0,
  "SELECT 1",
  "ALTER TABLE ai_assessment_questions ADD COLUMN scenario_category VARCHAR(100) NULL AFTER question_fingerprint;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add difficulty_level if missing
SET @colname = "difficulty_level";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @colname
  ) > 0,
  "SELECT 1",
  "ALTER TABLE ai_assessment_questions ADD COLUMN difficulty_level VARCHAR(50) DEFAULT 'intermediate' AFTER scenario_category;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 2. Add indexes for question history uniqueness lookups
CREATE INDEX IF NOT EXISTS idx_q_fingerprint ON ai_assessment_questions (question_fingerprint);
CREATE INDEX IF NOT EXISTS idx_q_assess_idx ON ai_assessment_questions (assessment_id, question_index);

