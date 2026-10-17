-- ============================================================================
-- CareerAct: Extracurricular Activity to Career Recommendation System
-- File: careeract_v2.sql
-- Target: MySQL 8.0+ (also imports on MariaDB 10.4+ used by XAMPP)
-- Import: phpMyAdmin > Import > choose this file > Go
--
-- ----------------------------------------------------------------------------
-- IMPORTANT: RE-RUN AND UPGRADE BEHAVIOUR
-- ----------------------------------------------------------------------------
-- * CREATE TABLE IF NOT EXISTS does NOT upgrade an older schema. If a table
--   with the same name already exists with different columns, it is left
--   unchanged. This script is intended for a fresh database. For an existing
--   database created from careeract.sql v1, drop nothing automatically:
--   export your data first and migrate manually.
-- * Seed data uses INSERT IGNORE only for lookup tables with deterministic
--   unique natural keys. Re-running therefore does not duplicate reference rows.
-- * Demo activities are inserted with a deterministic external_source_id and
--   the same unique key, so re-running does not duplicate them.
-- * The only DROP statements are "DROP TRIGGER IF EXISTS" for the four triggers
--   in Section 16, so their definitions can be recreated on re-import. They
--   do not touch any table or row data. No table is dropped and no data deleted.
--
-- ----------------------------------------------------------------------------
-- SECURITY AND PERMISSIONS
-- ----------------------------------------------------------------------------
-- * No admin account is created. Create the first admin through the
--   application's registration flow, then set role_code = 'admin' manually.
-- * No plaintext passwords are stored. Use PHP password_hash() (bcrypt or
--   argon2id) and store the result in users.password_hash VARCHAR(255).
-- * Email verification and password-reset tokens are stored as SHA-256 hashes
--   (CHAR(64)), never as raw tokens.
-- * No API keys or credentials are stored. Source credentials must live
--   outside the database, in PHP configuration or environment variables.
-- * Grant the PHP application database user only the privileges it needs
--   (SELECT, INSERT, UPDATE, DELETE on careeract.*). Do not use root in
--   production code. XAMPP's default root account has no password; change it.
--
-- ----------------------------------------------------------------------------
-- TIME CONVENTION
-- ----------------------------------------------------------------------------
-- All DATETIME/TIMESTAMP values are stored in UTC. The PHP layer converts to
-- the user's local timezone (Asia/Kolkata for Indian users) for display.
-- Registration deadlines are DATETIME in UTC. Activity dates are DATE in the
-- activity's local calendar (they describe the event day, not an instant).
-- ============================================================================

CREATE DATABASE IF NOT EXISTS careeract
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE careeract;

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- SECTION 1: LOOKUP / REFERENCE TABLES (no dependencies)
-- ============================================================================

-- Account roles. Only Student and Admin are used. No organizer/provider role.
CREATE TABLE IF NOT EXISTS roles (
  role_id      TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_code    VARCHAR(30)      NOT NULL,
  role_name    VARCHAR(60)      NOT NULL,
  description  VARCHAR(255)     NULL,
  created_at   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id),
  UNIQUE KEY uq_roles_code (role_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Account lifecycle status (kept as a lookup so new states need no schema change).
CREATE TABLE IF NOT EXISTS account_statuses (
  account_status_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  status_code       VARCHAR(30)      NOT NULL,
  status_name       VARCHAR(60)      NOT NULL,
  PRIMARY KEY (account_status_id),
  UNIQUE KEY uq_account_status_code (status_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cities (
  city_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  city_name   VARCHAR(100) NOT NULL,
  state_name  VARCHAR(100) NOT NULL,
  country     VARCHAR(100) NOT NULL DEFAULT 'India',
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (city_id),
  UNIQUE KEY uq_city_name_state_country (city_name, state_name, country)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proficiency_levels (
  proficiency_level_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  level_code           VARCHAR(20)      NOT NULL,
  level_name           VARCHAR(50)      NOT NULL,
  level_value          TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (proficiency_level_id),
  UNIQUE KEY uq_proficiency_code (level_code),
  UNIQUE KEY uq_proficiency_value (level_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity delivery modes.
CREATE TABLE IF NOT EXISTS activity_modes (
  mode_id    TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mode_code  VARCHAR(20)      NOT NULL,
  mode_name  VARCHAR(50)      NOT NULL,
  PRIMARY KEY (mode_id),
  UNIQUE KEY uq_activity_mode_code (mode_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity types (what kind of extracurricular it is).
CREATE TABLE IF NOT EXISTS activity_types (
  activity_type_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_code        VARCHAR(40)      NOT NULL,
  type_name        VARCHAR(100)     NOT NULL,
  is_technical     TINYINT(1)       NOT NULL DEFAULT 0,
  description      VARCHAR(255)     NULL,
  PRIMARY KEY (activity_type_id),
  UNIQUE KEY uq_activity_type_code (type_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity categories: broader grouping used for filtering and tagging.
CREATE TABLE IF NOT EXISTS activity_categories (
  category_id   SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_code VARCHAR(40)       NOT NULL,
  category_name VARCHAR(100)      NOT NULL,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (category_id),
  UNIQUE KEY uq_activity_category_code (category_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Free-form tags admins can attach to activities (many-to-many via junction table).
CREATE TABLE IF NOT EXISTS activity_tags (
  tag_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tag_slug  VARCHAR(80)  NOT NULL,
  tag_name  VARCHAR(80)  NOT NULL,
  PRIMARY KEY (tag_id),
  UNIQUE KEY uq_activity_tag_slug (tag_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Publication status of an activity listing.
CREATE TABLE IF NOT EXISTS activity_statuses (
  activity_status_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  status_code        VARCHAR(30)      NOT NULL,
  status_name        VARCHAR(60)      NOT NULL,
  is_listable        TINYINT(1)       NOT NULL DEFAULT 0,
  PRIMARY KEY (activity_status_id),
  UNIQUE KEY uq_activity_status_code (status_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification state. Being present in the table does NOT imply verification.
CREATE TABLE IF NOT EXISTS verification_statuses (
  verification_status_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  status_code            VARCHAR(30)      NOT NULL,
  status_name            VARCHAR(60)      NOT NULL,
  PRIMARY KEY (verification_status_id),
  UNIQUE KEY uq_verification_status_code (status_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enrollment lifecycle statuses.
CREATE TABLE IF NOT EXISTS enrollment_statuses (
  enrollment_status_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  status_code          VARCHAR(30)      NOT NULL,
  status_name          VARCHAR(60)      NOT NULL,
  is_active            TINYINT(1)       NOT NULL DEFAULT 0,
  is_terminal          TINYINT(1)       NOT NULL DEFAULT 0,
  sort_order           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (enrollment_status_id),
  UNIQUE KEY uq_enrollment_status_code (status_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification types.
CREATE TABLE IF NOT EXISTS notification_types (
  notification_type_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_code            VARCHAR(40)      NOT NULL,
  type_name            VARCHAR(100)     NOT NULL,
  PRIMARY KEY (notification_type_id),
  UNIQUE KEY uq_notification_type_code (type_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification delivery channels.
CREATE TABLE IF NOT EXISTS notification_channels (
  channel_id   TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel_code VARCHAR(20)      NOT NULL,
  channel_name VARCHAR(50)      NOT NULL,
  PRIMARY KEY (channel_id),
  UNIQUE KEY uq_notification_channel_code (channel_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Skill categories and domains.
CREATE TABLE IF NOT EXISTS skill_categories (
  skill_category_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_code     VARCHAR(40)      NOT NULL,
  category_name     VARCHAR(100)     NOT NULL,
  is_technical      TINYINT(1)       NOT NULL DEFAULT 0,
  sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (skill_category_id),
  UNIQUE KEY uq_skill_category_code (category_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS domains (
  domain_id    TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  domain_code  VARCHAR(40)      NOT NULL,
  domain_name  VARCHAR(100)     NOT NULL,
  description  VARCHAR(255)     NULL,
  is_active    TINYINT(1)       NOT NULL DEFAULT 1,
  PRIMARY KEY (domain_id),
  UNIQUE KEY uq_domain_code (domain_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Career categories (grouping above career paths, e.g. "Technology").
CREATE TABLE IF NOT EXISTS career_categories (
  career_category_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_code      VARCHAR(40)      NOT NULL,
  category_name      VARCHAR(100)     NOT NULL,
  PRIMARY KEY (career_category_id),
  UNIQUE KEY uq_career_category_code (category_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assessment question types.
CREATE TABLE IF NOT EXISTS question_types (
  question_type_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_code        VARCHAR(30)      NOT NULL,
  type_name        VARCHAR(60)      NOT NULL,
  PRIMARY KEY (question_type_id),
  UNIQUE KEY uq_question_type_code (type_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Learning resource types.
CREATE TABLE IF NOT EXISTS resource_types (
  resource_type_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_code        VARCHAR(30)      NOT NULL,
  type_name        VARCHAR(60)      NOT NULL,
  PRIMARY KEY (resource_type_id),
  UNIQUE KEY uq_resource_type_code (type_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recommendation categories used by rule-based logic.
CREATE TABLE IF NOT EXISTS recommendation_reason_types (
  reason_type_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reason_code    VARCHAR(40)      NOT NULL,
  reason_name    VARCHAR(100)     NOT NULL,
  PRIMARY KEY (reason_type_id),
  UNIQUE KEY uq_reason_type_code (reason_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Declared source types for authorized activity sources.
CREATE TABLE IF NOT EXISTS source_types (
  source_type_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_code      VARCHAR(40)      NOT NULL,
  type_name      VARCHAR(100)     NOT NULL,
  PRIMARY KEY (source_type_id),
  UNIQUE KEY uq_source_type_code (type_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 2: USERS, AUTHENTICATION AND ACCOUNT SECURITY
-- ============================================================================

CREATE TABLE IF NOT EXISTS users (
  user_id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  role_id            TINYINT UNSIGNED NOT NULL,
  account_status_id  TINYINT UNSIGNED NOT NULL,
  email              VARCHAR(255)     NOT NULL,
  password_hash      VARCHAR(255)     NOT NULL,
  email_verified_at  DATETIME         NULL,
  last_login_at      DATETIME         NULL,
  password_changed_at DATETIME        NULL,
  failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until       DATETIME         NULL,
  created_at         TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role_id),
  KEY idx_users_account_status (account_status_id),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (role_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_users_account_status FOREIGN KEY (account_status_id) REFERENCES account_statuses (account_status_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email verification tokens: store SHA-256 hash of the token, never the token.
CREATE TABLE IF NOT EXISTS email_verification_tokens (
  verification_token_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id               INT UNSIGNED NOT NULL,
  token_hash            CHAR(64)     NOT NULL,
  expires_at            DATETIME     NOT NULL,
  used_at               DATETIME     NULL,
  revoked_at            DATETIME     NULL,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (verification_token_id),
  UNIQUE KEY uq_verification_token_hash (token_hash),
  KEY idx_verification_user (user_id),
  KEY idx_verification_expiry (expires_at),
  CONSTRAINT fk_verification_user FOREIGN KEY (user_id) REFERENCES users (user_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password reset tokens: store SHA-256 hash only.
CREATE TABLE IF NOT EXISTS password_reset_tokens (
  reset_token_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  token_hash     CHAR(64)     NOT NULL,
  expires_at     DATETIME     NOT NULL,
  used_at        DATETIME     NULL,
  revoked_at     DATETIME     NULL,
  requested_ip   VARCHAR(45)  NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (reset_token_id),
  UNIQUE KEY uq_reset_token_hash (token_hash),
  KEY idx_reset_user (user_id),
  KEY idx_reset_expiry (expires_at),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users (user_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login / session tracking. session_token_hash stores a hash, never a raw session id.
CREATE TABLE IF NOT EXISTS user_sessions (
  session_id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            INT UNSIGNED    NOT NULL,
  session_token_hash CHAR(64)        NOT NULL,
  ip_address         VARCHAR(45)     NULL,
  user_agent         VARCHAR(255)    NULL,
  created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at       DATETIME        NULL,
  expires_at         DATETIME        NOT NULL,
  revoked_at         DATETIME        NULL,
  PRIMARY KEY (session_id),
  UNIQUE KEY uq_session_token_hash (session_token_hash),
  KEY idx_session_user (user_id, expires_at),
  CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users (user_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login attempt log for brute-force detection. user_id may be NULL for unknown emails.
CREATE TABLE IF NOT EXISTS login_attempts (
  attempt_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED    NULL,
  email_tried  VARCHAR(255)    NULL,
  ip_address   VARCHAR(45)     NULL,
  was_success  TINYINT(1)      NOT NULL DEFAULT 0,
  attempted_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (attempt_id),
  KEY idx_login_attempt_user (user_id, attempted_at),
  KEY idx_login_attempt_ip (ip_address, attempted_at),
  CONSTRAINT fk_login_attempt_user FOREIGN KEY (user_id) REFERENCES users (user_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user preferences (UI and locale).
CREATE TABLE IF NOT EXISTS user_preferences (
  user_id          INT UNSIGNED NOT NULL,
  timezone         VARCHAR(64)  NOT NULL DEFAULT 'Asia/Kolkata',
  preferred_language VARCHAR(10) NOT NULL DEFAULT 'en',
  theme            VARCHAR(20)  NOT NULL DEFAULT 'system',
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_pref_user FOREIGN KEY (user_id) REFERENCES users (user_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 3: STUDENT PROFILES
-- ============================================================================

CREATE TABLE IF NOT EXISTS student_profiles (
  student_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id               INT UNSIGNED NOT NULL,
  full_name             VARCHAR(150) NOT NULL,
  phone                 VARCHAR(20)  NULL,
  college_name          VARCHAR(200) NOT NULL,
  degree_name           VARCHAR(100) NOT NULL,
  course_name           VARCHAR(150) NULL,
  branch_name           VARCHAR(150) NULL,
  academic_year         TINYINT UNSIGNED NULL,
  graduation_year       SMALLINT UNSIGNED NULL,
  city_id               INT UNSIGNED NULL,
  preferred_mode_code   VARCHAR(20)  NULL,
  biography             VARCHAR(500) NULL,
  linkedin_url         VARCHAR(500) NULL,
  portfolio_url        VARCHAR(500) NULL,
  profile_visibility   ENUM('private','college_only','public') NOT NULL DEFAULT 'private',
  profile_completed_at DATETIME     NULL,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id),
  UNIQUE KEY uq_student_user (user_id),
  KEY idx_student_city (city_id),
  KEY idx_student_college (college_name),
  CONSTRAINT chk_student_academic_year CHECK (academic_year IS NULL OR academic_year BETWEEN 1 AND 10),
  CONSTRAINT chk_student_grad_year CHECK (graduation_year IS NULL OR graduation_year BETWEEN 1950 AND 2100),
  CONSTRAINT fk_student_user FOREIGN KEY (user_id) REFERENCES users (user_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_student_city FOREIGN KEY (city_id) REFERENCES cities (city_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preferred activity cities (many-to-many; a student may prefer several cities).
CREATE TABLE IF NOT EXISTS student_preferred_cities (
  student_id INT UNSIGNED NOT NULL,
  city_id    INT UNSIGNED NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id, city_id),
  KEY idx_spc_city (city_id),
  CONSTRAINT fk_spc_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_spc_city FOREIGN KEY (city_id) REFERENCES cities (city_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification preferences per channel and notification type.
CREATE TABLE IF NOT EXISTS user_notification_preferences (
  user_id              INT UNSIGNED     NOT NULL,
  notification_type_id TINYINT UNSIGNED NOT NULL,
  channel_id           TINYINT UNSIGNED NOT NULL,
  is_enabled           TINYINT(1)       NOT NULL DEFAULT 1,
  updated_at           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, notification_type_id, channel_id),
  CONSTRAINT fk_unp_user FOREIGN KEY (user_id) REFERENCES users (user_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_unp_type FOREIGN KEY (notification_type_id) REFERENCES notification_types (notification_type_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_unp_channel FOREIGN KEY (channel_id) REFERENCES notification_channels (channel_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 4: CAREERS, DOMAINS AND CAREER GOALS
-- ============================================================================

CREATE TABLE IF NOT EXISTS career_paths (
  career_id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  career_category_id TINYINT UNSIGNED  NOT NULL,
  domain_id          TINYINT UNSIGNED  NOT NULL,
  career_code        VARCHAR(50)       NOT NULL,
  career_title       VARCHAR(150)      NOT NULL,
  description        TEXT              NULL,
  is_active          TINYINT(1)        NOT NULL DEFAULT 1,
  created_at         TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (career_id),
  UNIQUE KEY uq_career_code (career_code),
  KEY idx_career_category (career_category_id),
  KEY idx_career_domain (domain_id),
  CONSTRAINT fk_career_category FOREIGN KEY (career_category_id) REFERENCES career_categories (career_category_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_career_domain FOREIGN KEY (domain_id) REFERENCES domains (domain_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Specific roles within a career path (e.g. Backend Engineer under Software Engineer).
CREATE TABLE IF NOT EXISTS career_roles (
  career_role_id INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  career_id      SMALLINT UNSIGNED NOT NULL,
  role_title     VARCHAR(150)      NOT NULL,
  role_summary   VARCHAR(500)      NULL,
  sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (career_role_id),
  UNIQUE KEY uq_career_role_title (career_id, role_title),
  CONSTRAINT fk_career_role_career FOREIGN KEY (career_id) REFERENCES career_paths (career_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student's career-goal selections (current, ranked).
CREATE TABLE IF NOT EXISTS student_career_goals (
  goal_id      INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  student_id   INT UNSIGNED      NOT NULL,
  career_id    SMALLINT UNSIGNED NOT NULL,
  goal_rank    TINYINT UNSIGNED  NOT NULL DEFAULT 1,
  is_active    TINYINT(1)        NOT NULL DEFAULT 1,
  selected_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deselected_at DATETIME         NULL,
  created_at   TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (goal_id),
  KEY idx_goal_student_active (student_id, is_active),
  KEY idx_goal_career (career_id),
  CONSTRAINT fk_goal_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_goal_career FOREIGN KEY (career_id) REFERENCES career_paths (career_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Career-goal history: append-only record of goal changes.
CREATE TABLE IF NOT EXISTS career_goal_history (
  goal_history_id INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  student_id      INT UNSIGNED      NOT NULL,
  career_id       SMALLINT UNSIGNED NOT NULL,
  action_code     ENUM('selected','deselected','ranked_changed') NOT NULL,
  goal_rank       TINYINT UNSIGNED  NULL,
  changed_at      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (goal_history_id),
  KEY idx_goal_hist_student (student_id, changed_at),
  CONSTRAINT fk_goal_hist_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_goal_hist_career FOREIGN KEY (career_id) REFERENCES career_paths (career_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student interests and preferred activity domains.
CREATE TABLE IF NOT EXISTS student_preferred_domains (
  student_id INT UNSIGNED     NOT NULL,
  domain_id  TINYINT UNSIGNED NOT NULL,
  created_at TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id, domain_id),
  KEY idx_spd_domain (domain_id),
  CONSTRAINT fk_spd_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_spd_domain FOREIGN KEY (domain_id) REFERENCES domains (domain_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 5: SKILLS, INTERESTS AND STUDENT SKILL RECORDS
-- ============================================================================

CREATE TABLE IF NOT EXISTS skills (
  skill_id          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  skill_category_id TINYINT UNSIGNED  NOT NULL,
  skill_name        VARCHAR(120)      NOT NULL,
  skill_slug        VARCHAR(120)      NOT NULL,
  description       VARCHAR(500)      NULL,
  is_active         TINYINT(1)        NOT NULL DEFAULT 1,
  created_at        TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (skill_id),
  UNIQUE KEY uq_skill_slug (skill_slug),
  KEY idx_skill_category (skill_category_id),
  CONSTRAINT fk_skill_category FOREIGN KEY (skill_category_id) REFERENCES skill_categories (skill_category_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Many-to-many: which domains a skill is relevant to.
CREATE TABLE IF NOT EXISTS skill_domains (
  skill_id  INT UNSIGNED     NOT NULL,
  domain_id TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (skill_id, domain_id),
  KEY idx_skill_domains_domain (domain_id),
  CONSTRAINT fk_skill_domains_skill FOREIGN KEY (skill_id) REFERENCES skills (skill_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_skill_domains_domain FOREIGN KEY (domain_id) REFERENCES domains (domain_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student's current self-declared skill proficiency. One row per student per skill.
CREATE TABLE IF NOT EXISTS student_skills (
  student_skill_id     INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  student_id           INT UNSIGNED     NOT NULL,
  skill_id             INT UNSIGNED     NOT NULL,
  proficiency_level_id TINYINT UNSIGNED NOT NULL,
  source_code          ENUM('self_declared','assessed','activity_completed','certificate','admin_verified') NOT NULL DEFAULT 'self_declared',
  created_at           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (student_skill_id),
  UNIQUE KEY uq_student_skill (student_id, skill_id),
  KEY idx_student_skill_skill (skill_id),
  KEY idx_student_skill_level (proficiency_level_id),
  CONSTRAINT fk_student_skill_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_student_skill_skill FOREIGN KEY (skill_id) REFERENCES skills (skill_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_student_skill_level FOREIGN KEY (proficiency_level_id) REFERENCES proficiency_levels (proficiency_level_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student interest tags (independent of domains).
CREATE TABLE IF NOT EXISTS interests (
  interest_id   INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  interest_slug VARCHAR(120)     NOT NULL,
  interest_name VARCHAR(120)     NOT NULL,
  domain_id     TINYINT UNSIGNED NULL,
  PRIMARY KEY (interest_id),
  UNIQUE KEY uq_interest_slug (interest_slug),
  KEY idx_interest_domain (domain_id),
  CONSTRAINT fk_interest_domain FOREIGN KEY (domain_id) REFERENCES domains (domain_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_interests (
  student_id  INT UNSIGNED NOT NULL,
  interest_id INT UNSIGNED NOT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id, interest_id),
  KEY idx_student_interest_interest (interest_id),
  CONSTRAINT fk_student_interest_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_student_interest_interest FOREIGN KEY (interest_id) REFERENCES interests (interest_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Evidence supporting a skill claim (certificate, project link, activity, assessment).
CREATE TABLE IF NOT EXISTS skill_evidence (
  evidence_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_skill_id INT UNSIGNED    NOT NULL,
  evidence_type    ENUM('project','certificate','activity','assessment','reference','other') NOT NULL,
  evidence_url     VARCHAR(500)    NULL,
  description      VARCHAR(500)    NULL,
  recorded_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (evidence_id),
  KEY idx_evidence_student_skill (student_skill_id),
  CONSTRAINT fk_evidence_student_skill FOREIGN KEY (student_skill_id) REFERENCES student_skills (student_skill_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Career-required skills and expected proficiency.
CREATE TABLE IF NOT EXISTS career_required_skills (
  career_id            SMALLINT UNSIGNED NOT NULL,
  skill_id             INT UNSIGNED      NOT NULL,
  expected_level_id    TINYINT UNSIGNED  NOT NULL,
  importance_weight    DECIMAL(4,2)      NOT NULL DEFAULT 1.00,
  created_at           TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (career_id, skill_id),
  KEY idx_crs_skill (skill_id),
  KEY idx_crs_level (expected_level_id),
  CONSTRAINT chk_crs_weight CHECK (importance_weight >= 0 AND importance_weight <= 10),
  CONSTRAINT fk_crs_career FOREIGN KEY (career_id) REFERENCES career_paths (career_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_crs_skill FOREIGN KEY (skill_id) REFERENCES skills (skill_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_crs_level FOREIGN KEY (expected_level_id) REFERENCES proficiency_levels (proficiency_level_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Learning resources linked to careers or skills (courses, books, links).
CREATE TABLE IF NOT EXISTS learning_resources (
  resource_id      INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  resource_type_id TINYINT UNSIGNED  NOT NULL,
  title            VARCHAR(200)      NOT NULL,
  provider_name    VARCHAR(150)      NULL,
  resource_url     VARCHAR(500)      NULL,
  description      VARCHAR(500)      NULL,
  is_free          TINYINT(1)        NOT NULL DEFAULT 1,
  is_active        TINYINT(1)        NOT NULL DEFAULT 1,
  created_at       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (resource_id),
  KEY idx_resource_type (resource_type_id),
  CONSTRAINT fk_resource_type FOREIGN KEY (resource_type_id) REFERENCES resource_types (resource_type_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS skill_learning_resources (
  skill_id    INT UNSIGNED NOT NULL,
  resource_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (skill_id, resource_id),
  KEY idx_slr_resource (resource_id),
  CONSTRAINT fk_slr_skill FOREIGN KEY (skill_id) REFERENCES skills (skill_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_slr_resource FOREIGN KEY (resource_id) REFERENCES learning_resources (resource_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 6: SKILL ASSESSMENTS AND SKILL GAP ANALYSIS
-- ============================================================================

-- Assessment definitions (what a test measures).
CREATE TABLE IF NOT EXISTS assessments (
  assessment_id    INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  skill_id         INT UNSIGNED      NOT NULL,
  assessment_code  VARCHAR(60)       NOT NULL,
  title            VARCHAR(200)      NOT NULL,
  description      VARCHAR(500)      NULL,
  max_score        DECIMAL(6,2)      NOT NULL DEFAULT 100.00,
  pass_score       DECIMAL(6,2)      NULL,
  is_active        TINYINT(1)        NOT NULL DEFAULT 1,
  created_at       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (assessment_id),
  UNIQUE KEY uq_assessment_code (assessment_code),
  KEY idx_assessment_skill (skill_id),
  CONSTRAINT chk_assessment_max CHECK (max_score > 0),
  CONSTRAINT fk_assessment_skill FOREIGN KEY (skill_id) REFERENCES skills (skill_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_questions (
  question_id      INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  assessment_id    INT UNSIGNED      NOT NULL,
  question_type_id TINYINT UNSIGNED  NOT NULL,
  question_text    TEXT              NOT NULL,
  points           DECIMAL(6,2)      NOT NULL DEFAULT 1.00,
  sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (question_id),
  UNIQUE KEY uq_question_order (assessment_id, sort_order),
  KEY idx_question_type (question_type_id),
  CONSTRAINT chk_question_points CHECK (points >= 0),
  CONSTRAINT fk_question_assessment FOREIGN KEY (assessment_id) REFERENCES assessments (assessment_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_question_type FOREIGN KEY (question_type_id) REFERENCES question_types (question_type_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Options for multiple-choice questions. is_correct is a boolean flag, not an answer text.
CREATE TABLE IF NOT EXISTS question_options (
  option_id     INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  question_id   INT UNSIGNED      NOT NULL,
  option_text   VARCHAR(500)      NOT NULL,
  is_correct    TINYINT(1)        NOT NULL DEFAULT 0,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (option_id),
  UNIQUE KEY uq_option_order (question_id, sort_order),
  CONSTRAINT fk_option_question FOREIGN KEY (question_id) REFERENCES assessment_questions (question_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assessment attempts.
CREATE TABLE IF NOT EXISTS assessment_attempts (
  attempt_id      BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  student_id      INT UNSIGNED      NOT NULL,
  assessment_id   INT UNSIGNED      NOT NULL,
  attempt_number  TINYINT UNSIGNED  NOT NULL DEFAULT 1,
  attempt_status  ENUM('in_progress','completed','abandoned') NOT NULL DEFAULT 'in_progress',
  score           DECIMAL(6,2)      NULL,
  max_score       DECIMAL(6,2)      NOT NULL,
  identified_level_id TINYINT UNSIGNED NULL,
  started_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at    DATETIME          NULL,
  created_at      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (attempt_id),
  UNIQUE KEY uq_attempt_number (student_id, assessment_id, attempt_number),
  KEY idx_attempt_assessment (assessment_id),
  KEY idx_attempt_status (attempt_status),
  CONSTRAINT chk_attempt_score CHECK (score IS NULL OR (score >= 0 AND score <= max_score)),
  CONSTRAINT fk_attempt_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_attempt_assessment FOREIGN KEY (assessment_id) REFERENCES assessments (assessment_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_attempt_level FOREIGN KEY (identified_level_id) REFERENCES proficiency_levels (proficiency_level_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Individual student answers.
CREATE TABLE IF NOT EXISTS assessment_answers (
  answer_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id     BIGINT UNSIGNED NOT NULL,
  question_id    INT UNSIGNED    NOT NULL,
  option_id      INT UNSIGNED    NULL,
  answer_text    TEXT            NULL,
  points_awarded DECIMAL(6,2)    NULL,
  answered_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (answer_id),
  UNIQUE KEY uq_answer_attempt_question (attempt_id, question_id),
  KEY idx_answer_question (question_id),
  KEY idx_answer_option (option_id),
  CONSTRAINT fk_answer_attempt FOREIGN KEY (attempt_id) REFERENCES assessment_attempts (attempt_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_answer_question FOREIGN KEY (question_id) REFERENCES assessment_questions (question_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_answer_option FOREIGN KEY (option_id) REFERENCES question_options (option_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Skill gap results per student per career per skill. Calculated by PHP; stored here.
CREATE TABLE IF NOT EXISTS skill_gap_results (
  gap_id               BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  student_id           INT UNSIGNED      NOT NULL,
  career_id            SMALLINT UNSIGNED NOT NULL,
  skill_id             INT UNSIGNED      NOT NULL,
  current_level_value  TINYINT UNSIGNED  NULL,
  required_level_value TINYINT UNSIGNED  NOT NULL,
  gap_score            TINYINT           NOT NULL DEFAULT 0,
  suggested_action     VARCHAR(500)      NULL,
  calculated_at        TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (gap_id),
  UNIQUE KEY uq_gap_student_career_skill (student_id, career_id, skill_id),
  KEY idx_gap_career (career_id),
  KEY idx_gap_skill (skill_id),
  CONSTRAINT fk_gap_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_gap_career FOREIGN KEY (career_id) REFERENCES career_paths (career_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_gap_skill FOREIGN KEY (skill_id) REFERENCES skills (skill_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 7: ACTIVITY SOURCES, ORGANIZERS AND ACTIVITIES
-- ============================================================================

-- Authorized activity sources. Credentials are NOT stored here; only a reference name.
CREATE TABLE IF NOT EXISTS activity_sources (
  source_id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_type_id       TINYINT UNSIGNED  NOT NULL,
  source_name          VARCHAR(150)      NOT NULL,
  source_website       VARCHAR(500)      NULL,
  credential_reference VARCHAR(100)      NULL COMMENT 'Name of an env/config key holding the secret. Never the secret itself.',
  is_enabled           TINYINT(1)        NOT NULL DEFAULT 0,
  sync_interval_hours  SMALLINT UNSIGNED NULL,
  next_sync_due_at     DATETIME          NULL,
  sync_status          ENUM('never_synced','idle','running','success','failed','disabled') NOT NULL DEFAULT 'never_synced',
  last_attempted_sync_at DATETIME        NULL,
  last_successful_sync_at DATETIME       NULL,
  last_error_message   VARCHAR(500)      NULL,
  retry_count          TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  created_at           TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (source_id),
  UNIQUE KEY uq_activity_source_name (source_name),
  KEY idx_source_enabled_due (is_enabled, next_sync_due_at),
  CONSTRAINT fk_source_type FOREIGN KEY (source_type_id) REFERENCES source_types (source_type_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Organizers are data, not login accounts.
CREATE TABLE IF NOT EXISTS organizers (
  organizer_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organizer_name VARCHAR(200) NOT NULL,
  website_url    VARCHAR(500) NULL,
  contact_email  VARCHAR(255) NULL,
  is_sample      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (organizer_id),
  UNIQUE KEY uq_organizer_name (organizer_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Core activity listings.
CREATE TABLE IF NOT EXISTS activities (
  activity_id           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  activity_type_id      TINYINT UNSIGNED  NOT NULL,
  category_id           SMALLINT UNSIGNED NULL,
  domain_id             TINYINT UNSIGNED  NULL,
  mode_id               TINYINT UNSIGNED  NOT NULL,
  organizer_id          INT UNSIGNED      NULL,
  source_id             SMALLINT UNSIGNED NULL,
  activity_status_id    TINYINT UNSIGNED  NOT NULL,
  verification_status_id TINYINT UNSIGNED NOT NULL,
  title                 VARCHAR(255)      NOT NULL,
  description           TEXT              NULL,
  city_id               INT UNSIGNED      NULL,
  venue_details         VARCHAR(255)      NULL,
  online_meeting_info   VARCHAR(500)      NULL,
  country               VARCHAR(100)      NOT NULL DEFAULT 'India',
  start_date            DATE              NULL,
  end_date              DATE              NULL,
  registration_opens_at DATETIME          NULL,
  registration_deadline DATETIME          NULL,
  eligibility_text      VARCHAR(500)      NULL,
  min_academic_year     TINYINT UNSIGNED  NULL,
  participation_fee     DECIMAL(10,2)     NULL,
  fee_currency          CHAR(3)           NOT NULL DEFAULT 'INR',
  prize_or_benefits     VARCHAR(500)      NULL,
  registration_url      VARCHAR(500)      NULL,
  official_source_url   VARCHAR(500)      NULL,
  external_activity_id  VARCHAR(150)      NULL,
  is_sample             TINYINT(1)        NOT NULL DEFAULT 0,
  cancellation_reason   VARCHAR(500)      NULL,
  cancelled_at          DATETIME          NULL,
  last_verified_at      DATETIME          NULL,
  verified_by_user_id   INT UNSIGNED      NULL,
  created_by_user_id    INT UNSIGNED      NULL,
  created_at            TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (activity_id),
  UNIQUE KEY uq_activity_source_external (source_id, external_activity_id),
  KEY idx_activity_type (activity_type_id),
  KEY idx_activity_category (category_id),
  KEY idx_activity_domain (domain_id),
  KEY idx_activity_mode (mode_id),
  KEY idx_activity_city (city_id),
  KEY idx_activity_status (activity_status_id),
  KEY idx_activity_verification (verification_status_id),
  KEY idx_activity_deadline (registration_deadline),
  KEY idx_activity_start (start_date),
  KEY idx_activity_fee (participation_fee),
  KEY idx_activity_organizer (organizer_id),
  KEY idx_activity_status_deadline (activity_status_id, registration_deadline),
  CONSTRAINT chk_activity_dates CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date),
  CONSTRAINT chk_activity_reg_window CHECK (registration_opens_at IS NULL OR registration_deadline IS NULL OR registration_opens_at <= registration_deadline),
  CONSTRAINT chk_activity_fee CHECK (participation_fee IS NULL OR participation_fee >= 0),
  CONSTRAINT chk_activity_min_year CHECK (min_academic_year IS NULL OR min_academic_year BETWEEN 1 AND 10),
  CONSTRAINT fk_activity_type FOREIGN KEY (activity_type_id) REFERENCES activity_types (activity_type_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_activity_category FOREIGN KEY (category_id) REFERENCES activity_categories (category_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_activity_domain FOREIGN KEY (domain_id) REFERENCES domains (domain_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_activity_mode FOREIGN KEY (mode_id) REFERENCES activity_modes (mode_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_activity_organizer FOREIGN KEY (organizer_id) REFERENCES organizers (organizer_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_activity_source FOREIGN KEY (source_id) REFERENCES activity_sources (source_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_activity_status FOREIGN KEY (activity_status_id) REFERENCES activity_statuses (activity_status_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_activity_verification FOREIGN KEY (verification_status_id) REFERENCES verification_statuses (verification_status_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_activity_city FOREIGN KEY (city_id) REFERENCES cities (city_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_activity_verified_by FOREIGN KEY (verified_by_user_id) REFERENCES users (user_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_activity_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (user_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity-to-category tagging is handled by activities.category_id (one primary category).
-- Additional tags are many-to-many.
CREATE TABLE IF NOT EXISTS activity_tag_map (
  activity_id INT UNSIGNED NOT NULL,
  tag_id      INT UNSIGNED NOT NULL,
  PRIMARY KEY (activity_id, tag_id),
  KEY idx_atm_tag (tag_id),
  CONSTRAINT fk_atm_activity FOREIGN KEY (activity_id) REFERENCES activities (activity_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_atm_tag FOREIGN KEY (tag_id) REFERENCES activity_tags (tag_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Skills required by an activity.
CREATE TABLE IF NOT EXISTS activity_skills (
  activity_id          INT UNSIGNED     NOT NULL,
  skill_id             INT UNSIGNED     NOT NULL,
  proficiency_level_id TINYINT UNSIGNED NULL,
  is_required          TINYINT(1)       NOT NULL DEFAULT 1,
  PRIMARY KEY (activity_id, skill_id),
  KEY idx_activity_skill_skill (skill_id),
  CONSTRAINT fk_as_activity FOREIGN KEY (activity_id) REFERENCES activities (activity_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_as_skill FOREIGN KEY (skill_id) REFERENCES skills (skill_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_as_level FOREIGN KEY (proficiency_level_id) REFERENCES proficiency_levels (proficiency_level_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Career-to-activity-type mappings used by recommendation rules.
CREATE TABLE IF NOT EXISTS career_activity_type_map (
  career_id        SMALLINT UNSIGNED NOT NULL,
  activity_type_id TINYINT UNSIGNED  NOT NULL,
  relevance_weight DECIMAL(4,2)      NOT NULL DEFAULT 1.00,
  PRIMARY KEY (career_id, activity_type_id),
  KEY idx_cat_type (activity_type_id),
  CONSTRAINT chk_cat_weight CHECK (relevance_weight >= 0 AND relevance_weight <= 10),
  CONSTRAINT fk_cat_career FOREIGN KEY (career_id) REFERENCES career_paths (career_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_cat_type FOREIGN KEY (activity_type_id) REFERENCES activity_types (activity_type_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Career-to-activity direct mapping (specific activity suggested for a career).
CREATE TABLE IF NOT EXISTS career_activity_map (
  career_id   SMALLINT UNSIGNED NOT NULL,
  activity_id INT UNSIGNED      NOT NULL,
  note        VARCHAR(255)      NULL,
  PRIMARY KEY (career_id, activity_id),
  KEY idx_cam_activity (activity_id),
  CONSTRAINT fk_cam_career FOREIGN KEY (career_id) REFERENCES career_paths (career_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_cam_activity FOREIGN KEY (activity_id) REFERENCES activities (activity_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Explicit eligibility rules (structured, so PHP can filter reliably).
CREATE TABLE IF NOT EXISTS activity_eligibility (
  eligibility_id    INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  activity_id       INT UNSIGNED     NOT NULL,
  rule_type         ENUM('course','branch','academic_year','degree','open_to_all','other') NOT NULL,
  rule_value        VARCHAR(150)     NOT NULL,
  PRIMARY KEY (eligibility_id),
  UNIQUE KEY uq_eligibility_rule (activity_id, rule_type, rule_value),
  CONSTRAINT fk_elig_activity FOREIGN KEY (activity_id) REFERENCES activities (activity_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prerequisite activities (activity B requires activity A first).
CREATE TABLE IF NOT EXISTS activity_prerequisites (
  activity_id             INT UNSIGNED NOT NULL,
  prerequisite_activity_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (activity_id, prerequisite_activity_id),
  KEY idx_prereq_required (prerequisite_activity_id),
  -- Self-prerequisite rows are rejected by PHP. A CHECK on these FK columns is
  -- not used because MySQL 8 forbids CHECK on columns with ON UPDATE CASCADE.
  CONSTRAINT fk_prereq_activity FOREIGN KEY (activity_id) REFERENCES activities (activity_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_prereq_required FOREIGN KEY (prerequisite_activity_id) REFERENCES activities (activity_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registration links for an activity (an activity may have several).
CREATE TABLE IF NOT EXISTS activity_registration_links (
  link_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  activity_id INT UNSIGNED NOT NULL,
  link_label  VARCHAR(100) NOT NULL DEFAULT 'Register',
  link_url    VARCHAR(500) NOT NULL,
  is_primary  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (link_id),
  UNIQUE KEY uq_reg_link (activity_id, link_url),
  CONSTRAINT fk_reg_link_activity FOREIGN KEY (activity_id) REFERENCES activities (activity_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity status history (append-only audit of publication changes).
CREATE TABLE IF NOT EXISTS activity_status_history (
  status_history_id BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  activity_id       INT UNSIGNED     NOT NULL,
  from_status_id    TINYINT UNSIGNED NULL,
  to_status_id      TINYINT UNSIGNED NOT NULL,
  changed_by_user_id INT UNSIGNED    NULL,
  reason            VARCHAR(500)     NULL,
  changed_at        TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (status_history_id),
  KEY idx_ash_activity (activity_id, changed_at),
  CONSTRAINT fk_ash_activity FOREIGN KEY (activity_id) REFERENCES activities (activity_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ash_from FOREIGN KEY (from_status_id) REFERENCES activity_statuses (activity_status_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ash_to FOREIGN KEY (to_status_id) REFERENCES activity_statuses (activity_status_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ash_user FOREIGN KEY (changed_by_user_id) REFERENCES users (user_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity-level deadline history (for deadline extensions).
CREATE TABLE IF NOT EXISTS activity_deadline_history (
  deadline_history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  activity_id         INT UNSIGNED    NOT NULL,
  old_deadline        DATETIME        NULL,
  new_deadline        DATETIME        NULL,
  changed_by_user_id  INT UNSIGNED    NULL,
  changed_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (deadline_history_id),
  KEY idx_adh_activity (activity_id, changed_at),
  CONSTRAINT fk_adh_activity FOREIGN KEY (activity_id) REFERENCES activities (activity_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_adh_user FOREIGN KEY (changed_by_user_id) REFERENCES users (user_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Source-specific identifiers for an activity (mapping external IDs to local rows).
CREATE TABLE IF NOT EXISTS activity_source_identifiers (
  identifier_id       INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  source_id           SMALLINT UNSIGNED NOT NULL,
  activity_id         INT UNSIGNED      NOT NULL,
  external_id         VARCHAR(150)      NOT NULL,
  content_hash        CHAR(64)          NULL,
  first_seen_at       DATETIME          NULL,
  last_seen_at        DATETIME          NULL,
  created_at          TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (identifier_id),
  UNIQUE KEY uq_source_external (source_id, external_id),
  KEY idx_sai_activity (activity_id),
  CONSTRAINT fk_sai_source FOREIGN KEY (source_id) REFERENCES activity_sources (source_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_sai_activity FOREIGN KEY (activity_id) REFERENCES activities (activity_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Duplicate-detection candidates flagged for admin review (never auto-merged).
CREATE TABLE IF NOT EXISTS activity_duplicate_candidates (
  candidate_id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  activity_id         INT UNSIGNED    NOT NULL,
  possible_duplicate_id INT UNSIGNED  NOT NULL,
  similarity_score    DECIMAL(5,2)    NOT NULL DEFAULT 0.00,
  review_status      ENUM('pending','confirmed_duplicate','not_duplicate') NOT NULL DEFAULT 'pending',
  detected_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by_user_id INT UNSIGNED    NULL,
  reviewed_at         DATETIME        NULL,
  PRIMARY KEY (candidate_id),
  UNIQUE KEY uq_dup_pair (activity_id, possible_duplicate_id),
  KEY idx_dup_status (review_status),
  -- activity_id <> possible_duplicate_id is enforced by PHP (see note on prerequisites).
  CONSTRAINT chk_dup_score CHECK (similarity_score BETWEEN 0 AND 100),
  CONSTRAINT fk_dup_activity FOREIGN KEY (activity_id) REFERENCES activities (activity_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_dup_other FOREIGN KEY (possible_duplicate_id) REFERENCES activities (activity_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_dup_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users (user_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sync runs (one row per sync attempt).
CREATE TABLE IF NOT EXISTS activity_sync_runs (
  sync_run_id      BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  source_id        SMALLINT UNSIGNED NOT NULL,
  started_at       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at      DATETIME          NULL,
  run_status       ENUM('running','success','partial','failed') NOT NULL DEFAULT 'running',
  attempt_number   TINYINT UNSIGNED  NOT NULL DEFAULT 1,
  records_received INT UNSIGNED      NOT NULL DEFAULT 0,
  records_inserted INT UNSIGNED      NOT NULL DEFAULT 0,
  records_updated  INT UNSIGNED      NOT NULL DEFAULT 0,
  records_failed   INT UNSIGNED      NOT NULL DEFAULT 0,
  PRIMARY KEY (sync_run_id),
  KEY idx_sync_run_source (source_id, started_at),
  CONSTRAINT fk_sync_run_source FOREIGN KEY (source_id) REFERENCES activity_sources (source_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-record import errors belonging to a sync run.
CREATE TABLE IF NOT EXISTS activity_import_errors (
  import_error_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sync_run_id     BIGINT UNSIGNED NOT NULL,
  external_id     VARCHAR(150)    NULL,
  error_message   VARCHAR(1000)   NOT NULL,
  created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (import_error_id),
  KEY idx_import_error_run (sync_run_id),
  CONSTRAINT fk_import_error_run FOREIGN KEY (sync_run_id) REFERENCES activity_sync_runs (sync_run_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin moderation history for activities (approval, rejection, reports).
CREATE TABLE IF NOT EXISTS activity_moderation_actions (
  moderation_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  activity_id        INT UNSIGNED    NOT NULL,
  admin_user_id      INT UNSIGNED    NULL,
  action_code        ENUM('approved','rejected','verified','unverified','cancelled','reported','report_resolved') NOT NULL,
  notes              VARCHAR(1000)   NULL,
  created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (moderation_id),
  KEY idx_mod_activity (activity_id, created_at),
  KEY idx_mod_admin (admin_user_id),
  CONSTRAINT fk_mod_activity FOREIGN KEY (activity_id) REFERENCES activities (activity_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_mod_admin FOREIGN KEY (admin_user_id) REFERENCES users (user_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User reports against activities (e.g. expired, fake, or wrong information).
CREATE TABLE IF NOT EXISTS activity_reports (
  report_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  activity_id         INT UNSIGNED    NOT NULL,
  reporter_user_id    INT UNSIGNED    NULL,
  report_reason       VARCHAR(500)    NOT NULL,
  report_status       ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at         DATETIME        NULL,
  resolved_by_user_id INT UNSIGNED    NULL,
  PRIMARY KEY (report_id),
  KEY idx_report_activity_status (activity_id, report_status),
  CONSTRAINT fk_report_activity FOREIGN KEY (activity_id) REFERENCES activities (activity_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_report_reporter FOREIGN KEY (reporter_user_id) REFERENCES users (user_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_report_resolver FOREIGN KEY (resolved_by_user_id) REFERENCES users (user_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 8: RECOMMENDATIONS
-- ============================================================================

-- Rule-based recommendation rules, managed by admins. PHP evaluates these.
CREATE TABLE IF NOT EXISTS recommendation_rules (
  rule_id          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  rule_code        VARCHAR(60)       NOT NULL,
  rule_name        VARCHAR(150)      NOT NULL,
  reason_type_id   TINYINT UNSIGNED  NOT NULL,
  weight           DECIMAL(5,2)      NOT NULL DEFAULT 1.00,
  is_active        TINYINT(1)        NOT NULL DEFAULT 1,
  description      VARCHAR(500)      NULL,
  created_at       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rule_id),
  UNIQUE KEY uq_rule_code (rule_code),
  KEY idx_rule_reason (reason_type_id),
  CONSTRAINT chk_rule_weight CHECK (weight >= 0 AND weight <= 100),
  CONSTRAINT fk_rule_reason FOREIGN KEY (reason_type_id) REFERENCES recommendation_reason_types (reason_type_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A generation run for a student (timestamps for history).
CREATE TABLE IF NOT EXISTS recommendation_runs (
  run_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id   INT UNSIGNED    NOT NULL,
  generated_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  trigger_code ENUM('manual','profile_update','goal_change','assessment','scheduled') NOT NULL DEFAULT 'manual',
  PRIMARY KEY (run_id),
  KEY idx_reco_run_student (student_id, generated_at),
  CONSTRAINT fk_reco_run_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity recommendation results per run. Historical rows are kept.
CREATE TABLE IF NOT EXISTS activity_recommendations (
  recommendation_id BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  run_id            BIGINT UNSIGNED   NOT NULL,
  student_id        INT UNSIGNED      NOT NULL,
  activity_id       INT UNSIGNED      NOT NULL,
  career_id         SMALLINT UNSIGNED NULL,
  relevance_score   DECIMAL(5,2)      NOT NULL DEFAULT 0.00,
  is_dismissed      TINYINT(1)        NOT NULL DEFAULT 0,
  dismissed_at      DATETIME          NULL,
  PRIMARY KEY (recommendation_id),
  UNIQUE KEY uq_reco_run_activity_career (run_id, activity_id, career_id),
  KEY idx_reco_student_activity (student_id, activity_id),
  KEY idx_reco_activity (activity_id),
  CONSTRAINT chk_reco_score CHECK (relevance_score BETWEEN 0 AND 100),
  CONSTRAINT fk_reco_run FOREIGN KEY (run_id) REFERENCES recommendation_runs (run_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_reco_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_reco_activity FOREIGN KEY (activity_id) REFERENCES activities (activity_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_reco_career FOREIGN KEY (career_id) REFERENCES career_paths (career_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Career recommendation results per run.
CREATE TABLE IF NOT EXISTS career_recommendations (
  career_recommendation_id BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  run_id                   BIGINT UNSIGNED   NOT NULL,
  student_id               INT UNSIGNED      NOT NULL,
  career_id                SMALLINT UNSIGNED NOT NULL,
  relevance_score          DECIMAL(5,2)      NOT NULL DEFAULT 0.00,
  PRIMARY KEY (career_recommendation_id),
  UNIQUE KEY uq_career_reco_run (run_id, career_id),
  KEY idx_career_reco_student (student_id, relevance_score),
  CONSTRAINT chk_career_reco_score CHECK (relevance_score BETWEEN 0 AND 100),
  CONSTRAINT fk_career_reco_run FOREIGN KEY (run_id) REFERENCES recommendation_runs (run_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_career_reco_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_career_reco_career FOREIGN KEY (career_id) REFERENCES career_paths (career_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reasons explaining a recommendation (activity or career).
CREATE TABLE IF NOT EXISTS recommendation_reasons (
  reason_id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recommendation_id BIGINT UNSIGNED NULL,
  career_recommendation_id BIGINT UNSIGNED NULL,
  rule_id           INT UNSIGNED    NULL,
  reason_text       VARCHAR(500)    NOT NULL,
  created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (reason_id),
  KEY idx_reason_reco (recommendation_id),
  KEY idx_reason_career_reco (career_recommendation_id),
  KEY idx_reason_rule (rule_id),
  -- Exactly one of recommendation_id / career_recommendation_id must be set.
  -- Enforced by PHP; not a CHECK, because MySQL 8 forbids CHECK on FK columns with ON UPDATE CASCADE.
  CONSTRAINT fk_reason_reco FOREIGN KEY (recommendation_id) REFERENCES activity_recommendations (recommendation_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_reason_career_reco FOREIGN KEY (career_recommendation_id) REFERENCES career_recommendations (career_recommendation_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_reason_rule FOREIGN KEY (rule_id) REFERENCES recommendation_rules (rule_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Skill matches: which student skills match a recommended activity or career.
CREATE TABLE IF NOT EXISTS recommendation_skill_matches (
  match_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recommendation_id BIGINT UNSIGNED NOT NULL,
  skill_id          INT UNSIGNED    NOT NULL,
  match_strength    DECIMAL(5,2)    NOT NULL DEFAULT 0.00,
  PRIMARY KEY (match_id),
  UNIQUE KEY uq_reco_skill (recommendation_id, skill_id),
  KEY idx_rsm_skill (skill_id),
  CONSTRAINT fk_rsm_reco FOREIGN KEY (recommendation_id) REFERENCES activity_recommendations (recommendation_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_rsm_skill FOREIGN KEY (skill_id) REFERENCES skills (skill_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Interest matches for a recommendation.
CREATE TABLE IF NOT EXISTS recommendation_interest_matches (
  match_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recommendation_id BIGINT UNSIGNED NOT NULL,
  interest_id       INT UNSIGNED    NOT NULL,
  PRIMARY KEY (match_id),
  UNIQUE KEY uq_reco_interest (recommendation_id, interest_id),
  KEY idx_rim_interest (interest_id),
  CONSTRAINT fk_rim_reco FOREIGN KEY (recommendation_id) REFERENCES activity_recommendations (recommendation_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_rim_interest FOREIGN KEY (interest_id) REFERENCES interests (interest_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student feedback on recommendations (helpful, not relevant, etc.).
CREATE TABLE IF NOT EXISTS recommendation_feedback (
  feedback_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recommendation_id BIGINT UNSIGNED NOT NULL,
  student_id        INT UNSIGNED    NOT NULL,
  feedback_code     ENUM('helpful','not_relevant','already_done','too_far','other') NOT NULL,
  comment_text      VARCHAR(500)    NULL,
  created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (feedback_id),
  KEY idx_feedback_reco (recommendation_id),
  KEY idx_feedback_student (student_id),
  CONSTRAINT fk_feedback_reco FOREIGN KEY (recommendation_id) REFERENCES activity_recommendations (recommendation_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_feedback_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 9: ENROLLMENTS, COMPLETION AND ACTIVITY TRACKING
-- ============================================================================

CREATE TABLE IF NOT EXISTS student_activity_enrollments (
  enrollment_id        BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  student_id           INT UNSIGNED      NOT NULL,
  activity_id          INT UNSIGNED      NOT NULL,
  enrollment_status_id TINYINT UNSIGNED  NOT NULL,
  -- active_key is 1 while the enrollment is open (non-terminal status such as
  -- interested, enrolled, in_progress, verification_pending) and NULL once it is
  -- terminal (completed, withdrawn, rejected, cancelled). Triggers in Section 16
  -- set it automatically. The unique key on (student_id, activity_id, active_key)
  -- therefore blocks a second open enrollment for the same student and activity,
  -- while allowing a new enrollment after the previous one has closed.
  active_key           TINYINT UNSIGNED  NULL,
  interested_at        DATETIME          NULL,
  enrolled_at          DATETIME          NULL,
  registration_reference VARCHAR(150)    NULL,
  completion_status    ENUM('not_completed','in_progress','completed','partially_completed') NOT NULL DEFAULT 'not_completed',
  completed_at         DATETIME          NULL,
  proof_url            VARCHAR(500)      NULL,
  certificate_reference VARCHAR(150)     NULL,
  student_reflection   VARCHAR(1000)     NULL,
  skills_gained_text   VARCHAR(500)      NULL,
  completion_verification ENUM('not_required','pending','verified','rejected') NOT NULL DEFAULT 'not_required',
  cancellation_reason  VARCHAR(500)      NULL,
  withdrawn_at         DATETIME          NULL,
  created_at           TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (enrollment_id),
  UNIQUE KEY uq_enrollment_active (student_id, activity_id, active_key),
  KEY idx_enrollment_activity (activity_id),
  KEY idx_enrollment_status (enrollment_status_id),
  KEY idx_enrollment_student_completion (student_id, completion_status),
  CONSTRAINT chk_enrollment_active_key CHECK (active_key IS NULL OR active_key = 1),
  CONSTRAINT fk_enrollment_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_enrollment_activity FOREIGN KEY (activity_id) REFERENCES activities (activity_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_enrollment_status FOREIGN KEY (enrollment_status_id) REFERENCES enrollment_statuses (enrollment_status_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enrollment status history (append-only).
CREATE TABLE IF NOT EXISTS enrollment_status_history (
  enrollment_history_id BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  enrollment_id         BIGINT UNSIGNED   NOT NULL,
  from_status_id        TINYINT UNSIGNED  NULL,
  to_status_id          TINYINT UNSIGNED  NOT NULL,
  changed_by_user_id    INT UNSIGNED      NULL,
  reason                VARCHAR(500)      NULL,
  changed_at            TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (enrollment_history_id),
  KEY idx_esh_enrollment (enrollment_id, changed_at),
  CONSTRAINT fk_esh_enrollment FOREIGN KEY (enrollment_id) REFERENCES student_activity_enrollments (enrollment_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_esh_from FOREIGN KEY (from_status_id) REFERENCES enrollment_statuses (enrollment_status_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_esh_to FOREIGN KEY (to_status_id) REFERENCES enrollment_statuses (enrollment_status_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_esh_user FOREIGN KEY (changed_by_user_id) REFERENCES users (user_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin verification history for completion evidence and certificates.
CREATE TABLE IF NOT EXISTS completion_verification_history (
  verification_history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  enrollment_id           BIGINT UNSIGNED NOT NULL,
  action_code             ENUM('submitted','verified','rejected','requested_more_info') NOT NULL,
  admin_user_id           INT UNSIGNED    NULL,
  notes                   VARCHAR(1000)   NULL,
  created_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (verification_history_id),
  KEY idx_cvh_enrollment (enrollment_id, created_at),
  CONSTRAINT fk_cvh_enrollment FOREIGN KEY (enrollment_id) REFERENCES student_activity_enrollments (enrollment_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_cvh_admin FOREIGN KEY (admin_user_id) REFERENCES users (user_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Skills gained from a completed enrollment (junction to skills).
CREATE TABLE IF NOT EXISTS enrollment_skills (
  enrollment_id BIGINT UNSIGNED NOT NULL,
  skill_id      INT UNSIGNED    NOT NULL,
  PRIMARY KEY (enrollment_id, skill_id),
  KEY idx_es_skill (skill_id),
  CONSTRAINT fk_es_enrollment FOREIGN KEY (enrollment_id) REFERENCES student_activity_enrollments (enrollment_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_es_skill FOREIGN KEY (skill_id) REFERENCES skills (skill_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 10: CERTIFICATES, ACHIEVEMENTS AND ISSUERS
-- ============================================================================

CREATE TABLE IF NOT EXISTS certificate_issuers (
  issuer_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  issuer_name  VARCHAR(200) NOT NULL,
  website_url  VARCHAR(500) NULL,
  is_sample    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (issuer_id),
  UNIQUE KEY uq_issuer_name (issuer_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS certificates (
  certificate_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id           INT UNSIGNED    NOT NULL,
  issuer_id            INT UNSIGNED    NULL,
  enrollment_id        BIGINT UNSIGNED NULL,
  certificate_title    VARCHAR(200)    NOT NULL,
  certificate_number   VARCHAR(100)    NULL,
  certificate_url      VARCHAR(500)    NULL,
  storage_reference    VARCHAR(255)    NULL,
  issued_on            DATE            NULL,
  expires_on           DATE            NULL,
  verification_status  ENUM('unverified','pending','verified','rejected') NOT NULL DEFAULT 'unverified',
  verification_evidence VARCHAR(500)   NULL,
  verified_by_user_id  INT UNSIGNED    NULL,
  verified_at          DATETIME        NULL,
  created_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (certificate_id),
  UNIQUE KEY uq_certificate_issuer_number (issuer_id, certificate_number),
  KEY idx_certificate_student (student_id),
  KEY idx_certificate_enrollment (enrollment_id),
  KEY idx_certificate_status (verification_status),
  CONSTRAINT chk_certificate_dates CHECK (expires_on IS NULL OR issued_on IS NULL OR expires_on >= issued_on),
  CONSTRAINT fk_certificate_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_certificate_issuer FOREIGN KEY (issuer_id) REFERENCES certificate_issuers (issuer_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_certificate_enrollment FOREIGN KEY (enrollment_id) REFERENCES student_activity_enrollments (enrollment_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_certificate_verifier FOREIGN KEY (verified_by_user_id) REFERENCES users (user_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Achievements. Linked to an enrollment when they come from an activity.
CREATE TABLE IF NOT EXISTS achievements (
  achievement_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id           INT UNSIGNED    NOT NULL,
  enrollment_id        BIGINT UNSIGNED NULL,
  title                VARCHAR(200)    NOT NULL,
  achievement_type     ENUM('award','prize','recognition','position','publication','other') NOT NULL DEFAULT 'other',
  issuer_name          VARCHAR(200)    NULL,
  achieved_on          DATE            NULL,
  description          VARCHAR(500)    NULL,
  verification_status  ENUM('unverified','pending','verified','rejected') NOT NULL DEFAULT 'unverified',
  verified_by_user_id  INT UNSIGNED    NULL,
  verified_at          DATETIME        NULL,
  created_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (achievement_id),
  KEY idx_achievement_student (student_id),
  KEY idx_achievement_enrollment (enrollment_id),
  KEY idx_achievement_status (verification_status),
  CONSTRAINT fk_achievement_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_achievement_enrollment FOREIGN KEY (enrollment_id) REFERENCES student_activity_enrollments (enrollment_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_achievement_verifier FOREIGN KEY (verified_by_user_id) REFERENCES users (user_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin review history for certificates and achievements.
CREATE TABLE IF NOT EXISTS credential_review_history (
  review_id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  certificate_id   BIGINT UNSIGNED NULL,
  achievement_id   BIGINT UNSIGNED NULL,
  action_code      ENUM('submitted','verified','rejected','requested_more_info') NOT NULL,
  admin_user_id    INT UNSIGNED    NULL,
  notes            VARCHAR(1000)   NULL,
  created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (review_id),
  KEY idx_crh_cert (certificate_id),
  KEY idx_crh_achv (achievement_id),
  -- Exactly one of certificate_id / achievement_id must be set. Enforced by PHP.
  CONSTRAINT fk_crh_cert FOREIGN KEY (certificate_id) REFERENCES certificates (certificate_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_crh_achv FOREIGN KEY (achievement_id) REFERENCES achievements (achievement_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_crh_admin FOREIGN KEY (admin_user_id) REFERENCES users (user_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 11: RESUME AND PORTFOLIO
-- ============================================================================

CREATE TABLE IF NOT EXISTS resume_templates (
  template_id   TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_code VARCHAR(40)      NOT NULL,
  template_name VARCHAR(100)     NOT NULL,
  description   VARCHAR(255)     NULL,
  PRIMARY KEY (template_id),
  UNIQUE KEY uq_resume_template_code (template_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resume_profiles (
  resume_profile_id INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  student_id        INT UNSIGNED     NOT NULL,
  template_id       TINYINT UNSIGNED NULL,
  headline          VARCHAR(200)     NULL,
  summary           TEXT             NULL,
  created_at        TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (resume_profile_id),
  UNIQUE KEY uq_resume_profile_student (student_id),
  CONSTRAINT fk_resume_profile_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_resume_profile_template FOREIGN KEY (template_id) REFERENCES resume_templates (template_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ordered, toggleable resume sections.
CREATE TABLE IF NOT EXISTS resume_sections (
  resume_section_id INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  resume_profile_id INT UNSIGNED     NOT NULL,
  section_type      ENUM('education','experience','projects','skills','achievements','activities','certificates','awards','publications','volunteering') NOT NULL,
  sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_visible        TINYINT(1)       NOT NULL DEFAULT 1,
  PRIMARY KEY (resume_section_id),
  UNIQUE KEY uq_resume_section_type (resume_profile_id, section_type),
  CONSTRAINT fk_resume_section_profile FOREIGN KEY (resume_profile_id) REFERENCES resume_profiles (resume_profile_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS education_records (
  education_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id       INT UNSIGNED NOT NULL,
  institution_name VARCHAR(200) NOT NULL,
  degree_name      VARCHAR(150) NOT NULL,
  field_of_study   VARCHAR(150) NULL,
  start_date       DATE         NULL,
  end_date         DATE         NULL,
  grade_text       VARCHAR(50)  NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (education_id),
  KEY idx_education_student (student_id),
  CONSTRAINT chk_education_dates CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date),
  CONSTRAINT fk_education_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_experiences (
  experience_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id     INT UNSIGNED NOT NULL,
  employer_name  VARCHAR(200) NOT NULL,
  job_title      VARCHAR(150) NOT NULL,
  employment_type ENUM('internship','part_time','full_time','freelance','volunteer_role','other') NOT NULL DEFAULT 'other',
  start_date     DATE         NULL,
  end_date       DATE         NULL,
  is_current     TINYINT(1)   NOT NULL DEFAULT 0,
  description    VARCHAR(1000) NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (experience_id),
  KEY idx_work_student (student_id),
  CONSTRAINT chk_work_dates CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date),
  CONSTRAINT fk_work_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_projects (
  project_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id    INT UNSIGNED NOT NULL,
  project_title VARCHAR(200) NOT NULL,
  description   TEXT         NULL,
  project_url   VARCHAR(500) NULL,
  start_date    DATE         NULL,
  end_date      DATE         NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id),
  KEY idx_project_student (student_id),
  CONSTRAINT chk_project_dates CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date),
  CONSTRAINT fk_project_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Volunteer experience (separate from paid work for clear resume sections).
CREATE TABLE IF NOT EXISTS volunteer_experiences (
  volunteer_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id       INT UNSIGNED NOT NULL,
  organization_name VARCHAR(200) NOT NULL,
  role_title       VARCHAR(150) NULL,
  start_date       DATE         NULL,
  end_date         DATE         NULL,
  description      VARCHAR(1000) NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (volunteer_id),
  KEY idx_volunteer_student (student_id),
  CONSTRAINT chk_volunteer_dates CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date),
  CONSTRAINT fk_volunteer_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Publications (papers, articles, posters).
CREATE TABLE IF NOT EXISTS publications (
  publication_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id       INT UNSIGNED NOT NULL,
  title            VARCHAR(255) NOT NULL,
  venue_name       VARCHAR(200) NULL,
  published_on     DATE         NULL,
  publication_url  VARCHAR(500) NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (publication_id),
  KEY idx_publication_student (student_id),
  CONSTRAINT fk_publication_student FOREIGN KEY (student_id) REFERENCES student_profiles (student_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resume export history (metadata only; the file itself is produced by PHP).
CREATE TABLE IF NOT EXISTS resume_exports (
  export_id      BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  resume_profile_id INT UNSIGNED  NOT NULL,
  export_format  ENUM('pdf','docx','html') NOT NULL,
  file_reference VARCHAR(255)     NULL,
  exported_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (export_id),
  KEY idx_export_profile (resume_profile_id, exported_at),
  CONSTRAINT fk_export_profile FOREIGN KEY (resume_profile_id) REFERENCES resume_profiles (resume_profile_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resume-to-activity linkage: lets a completed activity appear in a resume once,
-- without re-entering its details.
CREATE TABLE IF NOT EXISTS resume_activity_links (
  resume_profile_id INT UNSIGNED    NOT NULL,
  enrollment_id     BIGINT UNSIGNED NOT NULL,
  is_visible        TINYINT(1)      NOT NULL DEFAULT 1,
  sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (resume_profile_id, enrollment_id),
  KEY idx_ral_enrollment (enrollment_id),
  CONSTRAINT fk_ral_profile FOREIGN KEY (resume_profile_id) REFERENCES resume_profiles (resume_profile_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ral_enrollment FOREIGN KEY (enrollment_id) REFERENCES student_activity_enrollments (enrollment_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 12: NOTIFICATIONS
-- ============================================================================

CREATE TABLE IF NOT EXISTS notifications (
  notification_id      BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id              INT UNSIGNED     NOT NULL,
  notification_type_id TINYINT UNSIGNED NOT NULL,
  activity_id          INT UNSIGNED     NULL,
  enrollment_id        BIGINT UNSIGNED  NULL,
  title                VARCHAR(200)     NOT NULL,
  message              VARCHAR(1000)    NULL,
  is_read              TINYINT(1)       NOT NULL DEFAULT 0,
  read_at              DATETIME         NULL,
  created_at           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (notification_id),
  KEY idx_notif_user_read (user_id, is_read, created_at),
  KEY idx_notif_type (notification_type_id),
  KEY idx_notif_activity (activity_id),
  KEY idx_notif_enrollment (enrollment_id),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (user_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_notif_type FOREIGN KEY (notification_type_id) REFERENCES notification_types (notification_type_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_notif_activity FOREIGN KEY (activity_id) REFERENCES activities (activity_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_notif_enrollment FOREIGN KEY (enrollment_id) REFERENCES student_activity_enrollments (enrollment_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delivery attempts per channel. Email is delivered by PHP, not by SQL.
CREATE TABLE IF NOT EXISTS notification_deliveries (
  delivery_id     BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  notification_id BIGINT UNSIGNED  NOT NULL,
  channel_id      TINYINT UNSIGNED NOT NULL,
  delivery_status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  attempt_count   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error      VARCHAR(500)     NULL,
  delivered_at    DATETIME         NULL,
  created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (delivery_id),
  UNIQUE KEY uq_delivery_notification_channel (notification_id, channel_id),
  KEY idx_delivery_status (delivery_status),
  CONSTRAINT fk_delivery_notification FOREIGN KEY (notification_id) REFERENCES notifications (notification_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_delivery_channel FOREIGN KEY (channel_id) REFERENCES notification_channels (channel_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 13: ADMIN AUDIT LOG
-- ============================================================================

-- Append-only audit trail. Never update or delete rows here from application code.
CREATE TABLE IF NOT EXISTS admin_audit_logs (
  audit_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id INT UNSIGNED    NULL,
  action_code   VARCHAR(60)     NOT NULL,
  target_table  VARCHAR(64)     NULL,
  target_id     VARCHAR(64)     NULL,
  details_json  JSON            NULL,
  ip_address    VARCHAR(45)     NULL,
  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (audit_id),
  KEY idx_audit_actor (actor_user_id, created_at),
  KEY idx_audit_action (action_code, created_at),
  KEY idx_audit_target (target_table, target_id),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users (user_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 14: SEED DATA (LOOKUPS AND DEMO CONTENT)
-- Lookup rows use INSERT IGNORE against unique natural keys (*_code, *_slug,
-- *_name), so re-running adds nothing. Demo activities use the unique key
-- (source_id, external_activity_id), also idempotent.
-- No real admin account is created. No passwords or secrets are inserted.
-- ============================================================================

INSERT IGNORE INTO roles (role_code, role_name, description) VALUES
  ('student', 'Student', 'Registered student: manages profile, skills, goals and enrollments'),
  ('admin',   'Administrator', 'Moderates activities, verifies evidence, manages reference data');

INSERT IGNORE INTO account_statuses (status_code, status_name) VALUES
  ('pending_verification', 'Pending Email Verification'),
  ('active',               'Active'),
  ('suspended',            'Suspended'),
  ('locked',               'Temporarily Locked'),
  ('deleted',              'Deleted');

INSERT IGNORE INTO cities (city_name, state_name, country) VALUES
  ('Pune',      'Maharashtra', 'India'),
  ('Mumbai',    'Maharashtra', 'India'),
  ('Bengaluru', 'Karnataka',   'India'),
  ('Hyderabad', 'Telangana',   'India'),
  ('Delhi',     'Delhi',       'India'),
  ('Chennai',   'Tamil Nadu',  'India'),
  ('Kolkata',   'West Bengal', 'India'),
  ('Ahmedabad', 'Gujarat',     'India');

INSERT IGNORE INTO proficiency_levels (level_code, level_name, level_value) VALUES
  ('beginner',     'Beginner',     1),
  ('elementary',   'Elementary',   2),
  ('intermediate', 'Intermediate', 3),
  ('advanced',     'Advanced',     4),
  ('expert',       'Expert',       5);

INSERT IGNORE INTO activity_modes (mode_code, mode_name) VALUES
  ('online',  'Online'),
  ('offline', 'Offline'),
  ('hybrid',  'Hybrid');

INSERT IGNORE INTO activity_types (type_code, type_name, is_technical, description) VALUES
  ('hackathon',                'Hackathon',                       1, 'Time-boxed build competition'),
  ('coding_competition',       'Coding Competition',              1, 'Algorithmic or programming contest'),
  ('technical_competition',    'Technical Competition',           1, 'Engineering or technical contest'),
  ('non_technical_competition','Non-Technical Competition',       0, 'Quiz, debate, case or general contest'),
  ('workshop',                 'Workshop',                        0, 'Hands-on practical session'),
  ('seminar',                  'Seminar',                         0, 'Talk or lecture-style session'),
  ('webinar',                  'Webinar',                         0, 'Online talk or session'),
  ('course',                   'Course',                          0, 'Structured course, online or offline'),
  ('certification',            'Certification',                   0, 'Credential awarded after assessment'),
  ('internship',               'Internship',                      0, 'Structured work placement'),
  ('research_opportunity',     'Research Opportunity',            1, 'Research project or lab participation'),
  ('volunteering',             'Volunteering',                    0, 'Community or social service'),
  ('leadership',               'Leadership Activity',             0, 'Leadership role or programme'),
  ('entrepreneurship_event',   'Entrepreneurship Event',          0, 'Startup pitch, incubation or founder event'),
  ('conference',               'Conference',                      0, 'Multi-session industry or academic event'),
  ('training_program',         'Training Program',                0, 'Structured training programme'),
  ('project',                  'Project',                         1, 'Guided or self-directed project'),
  ('community_social_impact',  'Community / Social Impact',       0, 'Community or social-impact initiative'),
  ('other',                    'Other Extracurricular',           0, 'Any other extracurricular activity');

INSERT IGNORE INTO activity_categories (category_code, category_name, sort_order) VALUES
  ('technical',     'Technical',            1),
  ('non_technical', 'Non-Technical',        2),
  ('learning',      'Learning and Training', 3),
  ('career',        'Career and Placement', 4),
  ('social',        'Social and Community', 5),
  ('leadership',    'Leadership',           6),
  ('research',      'Research',             7),
  ('other',         'Other',                8);

INSERT IGNORE INTO activity_tags (tag_slug, tag_name) VALUES
  ('beginner-friendly', 'Beginner Friendly'),
  ('team-based',        'Team Based'),
  ('free',              'Free'),
  ('certificate',       'Certificate Provided'),
  ('scholarship',       'Scholarship Available'),
  ('prize',             'Prize Money');

INSERT IGNORE INTO activity_statuses (status_code, status_name, is_listable) VALUES
  ('draft',          'Draft',                    0),
  ('pending_review', 'Pending Admin Review',     0),
  ('published',      'Published',                1),
  ('rejected',       'Rejected',                 0),
  ('cancelled',      'Cancelled',                0),
  ('expired',        'Expired',                  0),
  ('archived',       'Archived',                 0);

INSERT IGNORE INTO verification_statuses (status_code, status_name) VALUES
  ('unverified',      'Unverified'),
  ('sample_data',     'Sample Data (Not Real)'),
  ('admin_verified',  'Verified by Admin'),
  ('source_verified', 'Verified from Authorized Source'),
  ('rejected',        'Rejected');

INSERT IGNORE INTO enrollment_statuses (status_code, status_name, is_active, is_terminal, sort_order) VALUES
  ('interested',           'Interested',           0, 0, 1),
  ('enrolled',             'Enrolled',             1, 0, 2),
  ('in_progress',          'In Progress',          1, 0, 3),
  ('verification_pending', 'Verification Pending', 1, 0, 4),
  ('completed',            'Completed',            0, 1, 5),
  ('withdrawn',            'Withdrawn',            0, 1, 6),
  ('rejected',             'Rejected',             0, 1, 7),
  ('cancelled',            'Cancelled',            0, 1, 8);

INSERT IGNORE INTO notification_types (type_code, type_name) VALUES
  ('enrollment_confirmation', 'Enrollment Confirmation'),
  ('deadline_reminder',       'Deadline Reminder'),
  ('activity_status_update',  'Activity Status Update'),
  ('completion_confirmation', 'Completion Confirmation'),
  ('recommendation_update',   'Recommendation Update'),
  ('verification_result',     'Verification Result');

INSERT IGNORE INTO notification_channels (channel_code, channel_name) VALUES
  ('in_app', 'In-App'),
  ('email',  'Email');

INSERT IGNORE INTO skill_categories (category_code, category_name, is_technical, sort_order) VALUES
  ('technical',     'Technical',     1, 1),
  ('design',        'Design',        1, 2),
  ('analytical',    'Analytical',    0, 3),
  ('business',      'Business',      0, 4),
  ('communication', 'Communication', 0, 5),
  ('leadership',    'Leadership',    0, 6),
  ('research',      'Research',      0, 7),
  ('sustainability','Sustainability',0, 8);

INSERT IGNORE INTO domains (domain_code, domain_name, description) VALUES
  ('it_software',      'IT and Software',           'Software development and computing'),
  ('data_analytics',   'Data and Analytics',        'Data science, analytics and AI/ML'),
  ('security',         'Cybersecurity',             'Information and network security'),
  ('design',           'Design and Creative',       'UI/UX, graphic and product design'),
  ('engineering',      'Engineering',               'Mechanical, electronics and civil engineering'),
  ('business',         'Business and Management',   'Business analysis, product and management'),
  ('entrepreneurship', 'Entrepreneurship',          'Founding and growing ventures'),
  ('science_research', 'Science and Research',      'Academic and applied research'),
  ('marketing',        'Marketing',                 'Marketing and communications'),
  ('finance',          'Finance',                   'Finance, accounting and investment'),
  ('hr',               'Human Resources',           'People operations and talent'),
  ('social_impact',    'Social and Community Impact','Social, nonprofit and community work'),
  ('sustainability',   'Environment and Sustainability','Environmental and sustainability work'),
  ('healthcare_nonclinical','Healthcare (Non-Clinical)','Healthcare administration, policy and health tech'),
  ('other',            'Other',                     'Other fields');

INSERT IGNORE INTO career_categories (category_code, category_name) VALUES
  ('technology',    'Technology'),
  ('engineering',   'Engineering'),
  ('design',        'Design and Creative'),
  ('business',      'Business and Management'),
  ('science',       'Science and Research'),
  ('social',        'Social and Environmental'),
  ('healthcare',    'Healthcare (Non-Clinical)');

INSERT IGNORE INTO skills (skill_category_id, skill_name, skill_slug, description) VALUES
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'),     'Programming (General)',       'programming-general',     'General programming and problem solving'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'),     'Python',                       'python',                  'Python programming'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'),     'Data Structures and Algorithms','dsa',                    'Algorithmic problem solving'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'),     'SQL and Databases',            'sql-databases',           'Relational database design and querying'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'),     'Machine Learning',             'machine-learning',        'Supervised and unsupervised ML'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'),     'Network Security',             'network-security',        'Network security fundamentals'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'),     'Ethical Hacking',              'ethical-hacking',         'Authorised penetration testing'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'),     'Hardware and Electronics',     'hardware-electronics',    'Circuits and embedded basics'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'),     'CAD and Mechanical Design',    'cad-mechanical-design',   'Computer-aided mechanical design'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'),     'Structural Analysis',          'structural-analysis',     'Civil and structural engineering analysis'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='design'),        'UI Design',                    'ui-design',               'Interface layout and visual hierarchy'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='design'),        'UX Research',                  'ux-research',             'User research and usability testing'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='design'),        'Graphic Design',               'graphic-design',          'Visual communication and branding'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='analytical'),    'Statistics',                   'statistics',              'Descriptive and inferential statistics'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='analytical'),    'Data Visualization',           'data-visualization',      'Charts and dashboards'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='analytical'),    'Critical Thinking',            'critical-thinking',       'Structured reasoning and evaluation'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'),      'Financial Analysis',           'financial-analysis',      'Financial statements and budgeting'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'),      'Market Research',              'market-research',         'Customer and market analysis'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'),      'Product Management',           'product-management',      'Product planning and roadmapping'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'),      'Marketing Strategy',           'marketing-strategy',      'Campaign planning and brand positioning'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'),      'Human Resource Management',    'hr-management',           'Recruitment, engagement and HR processes'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='communication'), 'Public Speaking',              'public-speaking',         'Presenting ideas to an audience'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='communication'), 'Technical Writing',            'technical-writing',       'Clear documentation and reports'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='communication'), 'Teamwork',                     'teamwork',                'Collaborating effectively in teams'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='leadership'),    'Team Leadership',              'team-leadership',         'Coordinating and motivating teams'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='leadership'),    'Project Management',           'project-management',      'Planning, scheduling and delivery'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='research'),      'Research Methodology',         'research-methodology',    'Designing and conducting studies'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'),      'Entrepreneurship',             'entrepreneurship',        'Venture building and business model design'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='sustainability'),'Sustainability Practices',    'sustainability-practices','Environmental impact and sustainable operations');

INSERT IGNORE INTO question_types (type_code, type_name) VALUES
  ('single_choice',   'Single Choice'),
  ('multiple_choice', 'Multiple Choice'),
  ('true_false',      'True / False'),
  ('short_answer',    'Short Answer');

INSERT IGNORE INTO resource_types (type_code, type_name) VALUES
  ('course',   'Course'),
  ('book',     'Book'),
  ('article',  'Article or Guide'),
  ('video',    'Video'),
  ('practice', 'Practice Platform');

INSERT IGNORE INTO recommendation_reason_types (reason_code, reason_name) VALUES
  ('career_goal_match',  'Matches Career Goal'),
  ('skill_gap_fill',     'Fills a Skill Gap'),
  ('interest_match',     'Matches Interest'),
  ('location_match',     'Near Preferred City'),
  ('mode_match',         'Matches Preferred Mode'),
  ('academic_fit',       'Fits Academic Stage'),
  ('deadline_urgency',   'Registration Closing Soon');

INSERT IGNORE INTO source_types (type_code, type_name) VALUES
  ('manual_admin',     'Manual Admin Entry'),
  ('official_api',     'Official API (authorized)'),
  ('authorized_feed',  'Authorized RSS or Dataset'),
  ('partner_submission','Partner Submission'),
  ('sample_data',      'Sample Data (Demo Only)');

INSERT IGNORE INTO certificate_issuers (issuer_name, website_url, is_sample) VALUES
  ('SAMPLE ISSUER (Demo Only)', NULL, 1);

INSERT IGNORE INTO resume_templates (template_code, template_name, description) VALUES
  ('classic',  'Classic',  'Single-column, conservative layout'),
  ('modern',   'Modern',   'Two-column layout with accent colours');

INSERT IGNORE INTO organizers (organizer_name, website_url, contact_email, is_sample) VALUES
  ('SAMPLE ORGANIZER A (Demo)', NULL, NULL, 1),
  ('SAMPLE ORGANIZER B (Demo)', NULL, NULL, 1);

-- Source records. Credentials are referenced by name only; no secrets are stored.
INSERT IGNORE INTO activity_sources (source_type_id, source_name, source_website, credential_reference, is_enabled, sync_status)
SELECT st.source_type_id, 'SAMPLE DATA (Demo Only)', NULL, NULL, 0, 'disabled'
FROM source_types st WHERE st.type_code = 'sample_data';

INSERT IGNORE INTO activity_sources (source_type_id, source_name, source_website, credential_reference, is_enabled, sync_status)
SELECT st.source_type_id, 'Manual Admin Entry', NULL, NULL, 1, 'idle'
FROM source_types st WHERE st.type_code = 'manual_admin';

-- Starter career paths. Admins can add more without schema changes.
INSERT IGNORE INTO career_paths (career_category_id, domain_id, career_code, career_title, description)
SELECT cc.career_category_id, d.domain_id, x.career_code, x.career_title, x.description
FROM (
  SELECT 'technology'  AS cat, 'it_software'       AS dom, 'software_engineer'        AS career_code, 'Software Engineer'                AS career_title, 'Designs, builds and maintains software systems' AS description
  UNION ALL SELECT 'technology', 'data_analytics',   'data_scientist',         'Data Scientist',                 'Derives insights and predictive models from data'
  UNION ALL SELECT 'technology', 'data_analytics',   'ai_ml_engineer',         'AI/ML Engineer',                 'Builds and deploys machine learning systems'
  UNION ALL SELECT 'technology', 'security',         'cybersecurity_analyst',  'Cybersecurity Analyst',          'Monitors, detects and responds to security threats'
  UNION ALL SELECT 'design',     'design',           'ui_ux_designer',         'UI/UX Designer',                 'Designs usable and coherent digital experiences'
  UNION ALL SELECT 'business',   'business',         'business_analyst',       'Business Analyst',               'Bridges business needs and technical solutions'
  UNION ALL SELECT 'business',   'business',         'product_manager',        'Product Manager',                'Defines product vision, priorities and delivery'
  UNION ALL SELECT 'business',   'entrepreneurship', 'entrepreneur',           'Entrepreneur',                   'Founds and grows new ventures'
  UNION ALL SELECT 'engineering','engineering',      'mechanical_engineer',    'Mechanical Engineer',            'Designs and analyses mechanical systems'
  UNION ALL SELECT 'engineering','engineering',      'electronics_engineer',   'Electronics Engineer',           'Designs circuits, embedded systems and devices'
  UNION ALL SELECT 'engineering','engineering',      'civil_engineer',         'Civil Engineer',                 'Plans, designs and supervises infrastructure'
  UNION ALL SELECT 'science',    'science_research', 'research_scientist',     'Research Scientist',             'Conducts systematic research and publishes findings'
  UNION ALL SELECT 'business',   'marketing',        'marketing_specialist',   'Marketing Specialist',           'Plans and runs campaigns to reach audiences'
  UNION ALL SELECT 'business',   'finance',          'finance_professional',   'Finance Professional',           'Manages financial analysis, planning and reporting'
  UNION ALL SELECT 'business',   'hr',               'hr_professional',        'Human Resources Professional',   'Manages talent acquisition, engagement and people operations'
  UNION ALL SELECT 'social',     'social_impact',    'social_impact_professional','Social Impact Professional',  'Works on community and nonprofit initiatives'
  UNION ALL SELECT 'social',     'sustainability',   'sustainability_professional','Environmental/Sustainability Professional','Works on environmental and sustainability programmes'
  UNION ALL SELECT 'healthcare', 'healthcare_nonclinical','healthcare_program_manager','Healthcare Programme Coordinator (Non-Clinical)','Coordinates non-clinical healthcare operations and programmes'
) x
JOIN career_categories cc ON cc.category_code = x.cat
JOIN domains d ON d.domain_code = x.dom;

-- Career-to-skill mappings.
INSERT IGNORE INTO career_required_skills (career_id, skill_id, expected_level_id, importance_weight)
SELECT c.career_id, s.skill_id, pl.proficiency_level_id, w.weight
FROM (
  SELECT 'software_engineer' AS career_code, 'programming-general' AS skill_slug, 'advanced' AS level_code, 9.00 AS weight
  UNION ALL SELECT 'software_engineer', 'dsa',             'advanced',     9.00
  UNION ALL SELECT 'software_engineer', 'python',          'intermediate', 7.00
  UNION ALL SELECT 'software_engineer', 'sql-databases',   'intermediate', 6.00
  UNION ALL SELECT 'data_scientist',    'python',          'advanced',     9.00
  UNION ALL SELECT 'data_scientist',    'statistics',      'advanced',     9.00
  UNION ALL SELECT 'data_scientist',    'machine-learning','advanced',     8.00
  UNION ALL SELECT 'data_scientist',    'data-visualization','intermediate',6.00
  UNION ALL SELECT 'data_scientist',    'sql-databases',   'intermediate', 6.00
  UNION ALL SELECT 'ai_ml_engineer',    'python',          'advanced',     9.00
  UNION ALL SELECT 'ai_ml_engineer',    'machine-learning','advanced',     9.00
  UNION ALL SELECT 'ai_ml_engineer',    'dsa',             'intermediate', 7.00
  UNION ALL SELECT 'ai_ml_engineer',    'statistics',      'intermediate', 6.00
  UNION ALL SELECT 'cybersecurity_analyst','network-security','advanced',  9.00
  UNION ALL SELECT 'cybersecurity_analyst','ethical-hacking','intermediate',7.00
  UNION ALL SELECT 'cybersecurity_analyst','programming-general','intermediate',5.00
  UNION ALL SELECT 'ui_ux_designer',    'ui-design',       'advanced',     9.00
  UNION ALL SELECT 'ui_ux_designer',    'ux-research',     'advanced',     8.00
  UNION ALL SELECT 'ui_ux_designer',    'graphic-design',  'intermediate', 6.00
  UNION ALL SELECT 'business_analyst',  'critical-thinking','advanced',    8.00
  UNION ALL SELECT 'business_analyst',  'market-research', 'intermediate', 7.00
  UNION ALL SELECT 'business_analyst',  'technical-writing','intermediate',7.00
  UNION ALL SELECT 'business_analyst',  'sql-databases',   'intermediate', 5.00
  UNION ALL SELECT 'product_manager',   'product-management','advanced',    9.00
  UNION ALL SELECT 'product_manager',   'market-research', 'intermediate', 7.00
  UNION ALL SELECT 'product_manager',   'public-speaking', 'intermediate', 6.00
  UNION ALL SELECT 'entrepreneur',      'entrepreneurship','advanced',     9.00
  UNION ALL SELECT 'entrepreneur',      'public-speaking', 'advanced',     8.00
  UNION ALL SELECT 'entrepreneur',      'financial-analysis','intermediate',7.00
  UNION ALL SELECT 'entrepreneur',      'market-research', 'intermediate', 7.00
  UNION ALL SELECT 'mechanical_engineer','cad-mechanical-design','advanced',9.00
  UNION ALL SELECT 'mechanical_engineer','critical-thinking','advanced',    7.00
  UNION ALL SELECT 'mechanical_engineer','project-management','intermediate',6.00
  UNION ALL SELECT 'electronics_engineer','hardware-electronics','advanced', 9.00
  UNION ALL SELECT 'electronics_engineer','programming-general','intermediate',6.00
  UNION ALL SELECT 'civil_engineer',    'structural-analysis','advanced',   9.00
  UNION ALL SELECT 'civil_engineer',    'project-management','intermediate',7.00
  UNION ALL SELECT 'civil_engineer',    'critical-thinking','advanced',     6.00
  UNION ALL SELECT 'research_scientist','research-methodology','advanced',  9.00
  UNION ALL SELECT 'research_scientist','statistics',      'advanced',     8.00
  UNION ALL SELECT 'research_scientist','technical-writing','intermediate',7.00
  UNION ALL SELECT 'marketing_specialist','marketing-strategy','advanced', 9.00
  UNION ALL SELECT 'marketing_specialist','market-research','intermediate',7.00
  UNION ALL SELECT 'marketing_specialist','public-speaking','intermediate', 6.00
  UNION ALL SELECT 'finance_professional','financial-analysis','advanced', 9.00
  UNION ALL SELECT 'finance_professional','statistics',    'intermediate', 7.00
  UNION ALL SELECT 'finance_professional','technical-writing','intermediate',6.00
  UNION ALL SELECT 'hr_professional',   'hr-management',   'advanced',     9.00
  UNION ALL SELECT 'hr_professional',   'teamwork',        'advanced',     7.00
  UNION ALL SELECT 'hr_professional',   'public-speaking', 'intermediate', 6.00
  UNION ALL SELECT 'social_impact_professional','public-speaking','advanced',8.00
  UNION ALL SELECT 'social_impact_professional','project-management','intermediate',7.00
  UNION ALL SELECT 'social_impact_professional','teamwork',  'advanced',     7.00
  UNION ALL SELECT 'sustainability_professional','sustainability-practices','advanced',9.00
  UNION ALL SELECT 'sustainability_professional','research-methodology','intermediate',6.00
  UNION ALL SELECT 'sustainability_professional','public-speaking','intermediate',6.00
  UNION ALL SELECT 'healthcare_program_manager','project-management','advanced',8.00
  UNION ALL SELECT 'healthcare_program_manager','teamwork',  'advanced',     7.00
  UNION ALL SELECT 'healthcare_program_manager','technical-writing','intermediate',6.00
) w
JOIN career_paths c ON c.career_code = w.career_code
JOIN skills s ON s.skill_slug = w.skill_slug
JOIN proficiency_levels pl ON pl.level_code = w.level_code;

-- Career-to-activity-type mappings.
INSERT IGNORE INTO career_activity_type_map (career_id, activity_type_id, relevance_weight)
SELECT c.career_id, t.activity_type_id, w.weight
FROM (
  SELECT 'software_engineer' AS career_code, 'hackathon' AS type_code, 9.00 AS weight
  UNION ALL SELECT 'software_engineer', 'coding_competition', 9.00
  UNION ALL SELECT 'software_engineer', 'internship',        8.00
  UNION ALL SELECT 'software_engineer', 'training_program',  7.00
  UNION ALL SELECT 'software_engineer', 'workshop',          6.00
  UNION ALL SELECT 'data_scientist',    'course',            9.00
  UNION ALL SELECT 'data_scientist',    'hackathon',         8.00
  UNION ALL SELECT 'data_scientist',    'research_opportunity',8.00
  UNION ALL SELECT 'data_scientist',    'coding_competition', 7.00
  UNION ALL SELECT 'ai_ml_engineer',    'course',            9.00
  UNION ALL SELECT 'ai_ml_engineer',    'hackathon',         8.00
  UNION ALL SELECT 'ai_ml_engineer',    'research_opportunity',7.00
  UNION ALL SELECT 'cybersecurity_analyst','certification',  9.00
  UNION ALL SELECT 'cybersecurity_analyst','hackathon',      8.00
  UNION ALL SELECT 'cybersecurity_analyst','training_program',7.00
  UNION ALL SELECT 'ui_ux_designer',    'workshop',          8.00
  UNION ALL SELECT 'ui_ux_designer',    'hackathon',         6.00
  UNION ALL SELECT 'ui_ux_designer',    'internship',        7.00
  UNION ALL SELECT 'business_analyst',  'non_technical_competition',8.00
  UNION ALL SELECT 'business_analyst',  'internship',        8.00
  UNION ALL SELECT 'business_analyst',  'certification',     7.00
  UNION ALL SELECT 'product_manager',   'hackathon',         7.00
  UNION ALL SELECT 'product_manager',   'non_technical_competition',8.00
  UNION ALL SELECT 'product_manager',   'workshop',          6.00
  UNION ALL SELECT 'entrepreneur',      'entrepreneurship_event',9.00
  UNION ALL SELECT 'entrepreneur',      'conference',        6.00
  UNION ALL SELECT 'entrepreneur',      'non_technical_competition',7.00
  UNION ALL SELECT 'mechanical_engineer','technical_competition',9.00
  UNION ALL SELECT 'mechanical_engineer','workshop',         7.00
  UNION ALL SELECT 'mechanical_engineer','internship',       8.00
  UNION ALL SELECT 'electronics_engineer','technical_competition',9.00
  UNION ALL SELECT 'electronics_engineer','project',         8.00
  UNION ALL SELECT 'electronics_engineer','workshop',        7.00
  UNION ALL SELECT 'civil_engineer',    'technical_competition',8.00
  UNION ALL SELECT 'civil_engineer',    'internship',        8.00
  UNION ALL SELECT 'civil_engineer',    'workshop',          6.00
  UNION ALL SELECT 'research_scientist','research_opportunity',9.00
  UNION ALL SELECT 'research_scientist','conference',        8.00
  UNION ALL SELECT 'research_scientist','seminar',           6.00
  UNION ALL SELECT 'marketing_specialist','non_technical_competition',7.00
  UNION ALL SELECT 'marketing_specialist','webinar',         6.00
  UNION ALL SELECT 'finance_professional','certification',   8.00
  UNION ALL SELECT 'finance_professional','course',          7.00
  UNION ALL SELECT 'hr_professional',   'leadership',        8.00
  UNION ALL SELECT 'hr_professional',   'webinar',           6.00
  UNION ALL SELECT 'social_impact_professional','volunteering',9.00
  UNION ALL SELECT 'social_impact_professional','community_social_impact',8.00
  UNION ALL SELECT 'sustainability_professional','community_social_impact',8.00
  UNION ALL SELECT 'sustainability_professional','volunteering',7.00
  UNION ALL SELECT 'healthcare_program_manager','volunteering',6.00
  UNION ALL SELECT 'healthcare_program_manager','course',    7.00
) w
JOIN career_paths c ON c.career_code = w.career_code
JOIN activity_types t ON t.type_code = w.type_code;

-- Starter recommendation rules for PHP rule evaluation (no external AI).
INSERT IGNORE INTO recommendation_rules (rule_code, rule_name, reason_type_id, weight, description)
SELECT r.rule_code, r.rule_name, rt.reason_type_id, r.weight, r.description
FROM (
  SELECT 'goal_type_match'   AS rule_code, 'Activity type matches career goal' AS rule_name, 'career_goal_match' AS reason_code, 30.00 AS weight, 'Score activities whose type is mapped to a selected career' AS description
  UNION ALL SELECT 'skill_gap_fill',   'Activity teaches a missing skill',      'skill_gap_fill',     25.00, 'Score activities requiring skills the student lacks for the goal'
  UNION ALL SELECT 'interest_match',   'Activity domain matches an interest',   'interest_match',     15.00, 'Score activities in a domain the student prefers'
  UNION ALL SELECT 'city_match',       'Activity is in a preferred city',       'location_match',     10.00, 'Score offline/hybrid activities in a preferred city; online activities score here as well'
  UNION ALL SELECT 'mode_match',       'Activity mode matches preference',      'mode_match',         5.00,  'Score activities whose mode equals the preferred learning mode'
  UNION ALL SELECT 'academic_fit',     'Eligibility fits academic year',        'academic_fit',       10.00, 'Score activities whose minimum academic year is at or below the student year'
  UNION ALL SELECT 'deadline_urgency', 'Registration closes within 14 days',    'deadline_urgency',   5.00,  'Boost activities whose registration deadline is close but still open'
) r
JOIN recommendation_reason_types rt ON rt.reason_code = r.reason_code;

-- ----------------------------------------------------------------------------
-- Demo activities. Every row is labelled [SAMPLE], has no registration or
-- official URL, and uses fixed dates in the past/future relative to the
-- project's own demo data. They are not real events. The deterministic
-- (source_id, external_activity_id) key makes re-import idempotent.
-- Deadlines are placeholders for layout testing and are NOT live deadlines.
-- ----------------------------------------------------------------------------

INSERT IGNORE INTO activities
  (activity_type_id, category_id, domain_id, mode_id, organizer_id, source_id,
   activity_status_id, verification_status_id, title, description, city_id, country,
   venue_details, online_meeting_info, start_date, end_date,
   registration_opens_at, registration_deadline, eligibility_text, min_academic_year,
   participation_fee, fee_currency, prize_or_benefits, external_activity_id, is_sample)
SELECT
  (SELECT activity_type_id FROM activity_types WHERE type_code='hackathon'),
  (SELECT category_id FROM activity_categories WHERE category_code='technical'),
  (SELECT domain_id FROM domains WHERE domain_code='it_software'),
  (SELECT mode_id FROM activity_modes WHERE mode_code='offline'),
  (SELECT organizer_id FROM organizers WHERE organizer_name='SAMPLE ORGANIZER A (Demo)'),
  (SELECT source_id FROM activity_sources WHERE source_name='SAMPLE DATA (Demo Only)'),
  (SELECT activity_status_id FROM activity_statuses WHERE status_code='draft'),
  (SELECT verification_status_id FROM verification_statuses WHERE status_code='sample_data'),
  '[SAMPLE] City Build Hackathon', 'Sample 24-hour hackathon for demonstrating listing and filtering.',
  (SELECT city_id FROM cities WHERE city_name='Pune'), 'India',
  'Sample venue (placeholder)', NULL, '2027-03-05', '2027-03-06',
  '2027-01-10 00:00:00', '2027-02-20 23:59:00', 'Open to undergraduate students', 1,
  0.00, 'INR', 'Sample certificate of participation', 'sample-hack-pune-001', 1
UNION ALL
SELECT
  (SELECT activity_type_id FROM activity_types WHERE type_code='workshop'),
  (SELECT category_id FROM activity_categories WHERE category_code='learning'),
  (SELECT domain_id FROM domains WHERE domain_code='data_analytics'),
  (SELECT mode_id FROM activity_modes WHERE mode_code='hybrid'),
  (SELECT organizer_id FROM organizers WHERE organizer_name='SAMPLE ORGANIZER B (Demo)'),
  (SELECT source_id FROM activity_sources WHERE source_name='SAMPLE DATA (Demo Only)'),
  (SELECT activity_status_id FROM activity_statuses WHERE status_code='draft'),
  (SELECT verification_status_id FROM verification_statuses WHERE status_code='sample_data'),
  '[SAMPLE] Python for Data Analysis Workshop', 'Sample hands-on workshop on Python-based data analysis basics.',
  (SELECT city_id FROM cities WHERE city_name='Mumbai'), 'India',
  'Sample venue (placeholder)', 'Sample online meeting placeholder', '2027-04-12', '2027-04-12',
  '2027-02-01 00:00:00', '2027-03-31 23:59:00', 'Open to all students', 1,
  150.00, 'INR', 'Sample workshop kit', 'sample-ws-mumbai-001', 1;

-- Activity-skill mappings for demo activities.
INSERT IGNORE INTO activity_skills (activity_id, skill_id, proficiency_level_id, is_required)
SELECT a.activity_id, s.skill_id, NULL, 1
FROM activities a
JOIN skills s ON s.skill_slug = 'programming-general'
WHERE a.external_activity_id = 'sample-hack-pune-001' AND a.is_sample = 1;

INSERT IGNORE INTO activity_skills (activity_id, skill_id, proficiency_level_id, is_required)
SELECT a.activity_id, s.skill_id, NULL, 1
FROM activities a
JOIN skills s ON s.skill_slug = 'python'
WHERE a.external_activity_id = 'sample-ws-mumbai-001' AND a.is_sample = 1;

-- ----------------------------------------------------------------------------
-- Career-to-activity-type seed for "course" (defined above) is preserved.
-- ----------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- SECTION 15: VIEWS
-- Date filtering uses NOW() at query time, so no scheduled job is required.
-- Views rely only on columns that exist in this file.
-- ============================================================================

-- Published, not cancelled, registration still open (deadline not passed).
CREATE OR REPLACE VIEW v_available_activities AS
SELECT
  a.activity_id,
  a.title,
  at.type_name            AS activity_type,
  d.domain_name,
  am.mode_name            AS delivery_mode,
  c.city_name,
  c.state_name,
  a.country,
  a.start_date,
  a.end_date,
  a.registration_opens_at,
  a.registration_deadline,
  a.participation_fee,
  a.fee_currency,
  a.eligibility_text,
  a.registration_url,
  vs.status_code          AS verification_status
FROM activities a
JOIN activity_statuses ast ON ast.activity_status_id = a.activity_status_id
JOIN activity_types at     ON at.activity_type_id   = a.activity_type_id
JOIN activity_modes am     ON am.mode_id            = a.mode_id
JOIN verification_statuses vs ON vs.verification_status_id = a.verification_status_id
LEFT JOIN domains d        ON d.domain_id           = a.domain_id
LEFT JOIN cities c         ON c.city_id            = a.city_id
WHERE ast.status_code = 'published'
  AND (a.registration_opens_at IS NULL OR a.registration_opens_at <= NOW())
  AND a.registration_deadline IS NOT NULL
  AND a.registration_deadline >= NOW();

-- Published activities whose event starts in the future (may still be closed for registration).
CREATE OR REPLACE VIEW v_upcoming_activities AS
SELECT
  a.activity_id,
  a.title,
  at.type_name       AS activity_type,
  am.mode_name       AS delivery_mode,
  c.city_name,
  a.start_date,
  a.end_date,
  a.registration_deadline
FROM activities a
JOIN activity_statuses ast ON ast.activity_status_id = a.activity_status_id
JOIN activity_types at     ON at.activity_type_id   = a.activity_type_id
JOIN activity_modes am     ON am.mode_id            = a.mode_id
LEFT JOIN cities c         ON c.city_id            = a.city_id
WHERE ast.status_code = 'published'
  AND a.start_date IS NOT NULL
  AND a.start_date >= CURDATE();

-- Published activities whose registration window is open right now.
CREATE OR REPLACE VIEW v_open_registration_activities AS
SELECT
  a.activity_id,
  a.title,
  a.registration_opens_at,
  a.registration_deadline,
  a.registration_url,
  at.type_name AS activity_type
FROM activities a
JOIN activity_statuses ast ON ast.activity_status_id = a.activity_status_id
JOIN activity_types at     ON at.activity_type_id   = a.activity_type_id
WHERE ast.status_code = 'published'
  AND (a.registration_opens_at IS NULL OR a.registration_opens_at <= NOW())
  AND a.registration_deadline IS NOT NULL
  AND a.registration_deadline >= NOW();

-- Activities past their registration deadline or end date, not cancelled.
-- Application logic should still show these only in history views.
CREATE OR REPLACE VIEW v_expired_activities AS
SELECT
  a.activity_id,
  a.title,
  a.registration_deadline,
  a.end_date,
  ast.status_code AS activity_status
FROM activities a
JOIN activity_statuses ast ON ast.activity_status_id = a.activity_status_id
WHERE ast.status_code <> 'cancelled'
  AND (
        (a.registration_deadline IS NOT NULL AND a.registration_deadline < NOW())
     OR (a.end_date IS NOT NULL AND a.end_date < CURDATE())
  );

-- Completed enrollments for resume and reports.
CREATE OR REPLACE VIEW v_student_completed_activities AS
SELECT
  sp.student_id,
  sp.full_name,
  e.enrollment_id,
  a.activity_id,
  a.title          AS activity_title,
  at.type_name     AS activity_type,
  d.domain_name,
  a.start_date,
  a.end_date,
  e.completed_at,
  e.proof_url,
  e.certificate_reference,
  e.skills_gained_text,
  e.completion_verification
FROM student_activity_enrollments e
JOIN enrollment_statuses es ON es.enrollment_status_id = e.enrollment_status_id
JOIN student_profiles sp    ON sp.student_id           = e.student_id
JOIN activities a           ON a.activity_id           = e.activity_id
JOIN activity_types at      ON at.activity_type_id     = a.activity_type_id
LEFT JOIN domains d         ON d.domain_id             = a.domain_id
WHERE es.status_code = 'completed';

-- Verified achievements and certificates for resume generation.
CREATE OR REPLACE VIEW v_resume_ready_achievements AS
SELECT
  ach.student_id,
  'achievement'        AS source_kind,
  ach.achievement_id   AS source_id,
  ach.title,
  ach.issuer_name      AS issuer,
  ach.achieved_on      AS event_date,
  ach.enrollment_id
FROM achievements ach
WHERE ach.verification_status = 'verified'
UNION ALL
SELECT
  cert.student_id,
  'certificate'        AS source_kind,
  cert.certificate_id  AS source_id,
  cert.certificate_title AS title,
  ci.issuer_name       AS issuer,
  cert.issued_on       AS event_date,
  cert.enrollment_id
FROM certificates cert
LEFT JOIN certificate_issuers ci ON ci.issuer_id = cert.issuer_id
WHERE cert.verification_status = 'verified';

-- Enrollment counts per activity, for admin dashboards.
CREATE OR REPLACE VIEW v_activity_enrollment_stats AS
SELECT
  a.activity_id,
  a.title,
  SUM(CASE WHEN es.status_code = 'interested'  THEN 1 ELSE 0 END) AS interested_count,
  SUM(CASE WHEN es.is_active = 1               THEN 1 ELSE 0 END) AS active_count,
  SUM(CASE WHEN es.status_code = 'completed'   THEN 1 ELSE 0 END) AS completed_count
FROM activities a
LEFT JOIN student_activity_enrollments e ON e.activity_id = a.activity_id
LEFT JOIN enrollment_statuses es         ON es.enrollment_status_id = e.enrollment_status_id
GROUP BY a.activity_id, a.title;

-- Student skill profile: current proficiency per skill.
CREATE OR REPLACE VIEW v_student_skill_profiles AS
SELECT
  ss.student_id,
  s.skill_id,
  s.skill_name,
  sc.category_name,
  pl.level_name,
  pl.level_value,
  ss.source_code
FROM student_skills ss
JOIN skills s             ON s.skill_id = ss.skill_id
JOIN skill_categories sc  ON sc.skill_category_id = s.skill_category_id
JOIN proficiency_levels pl ON pl.proficiency_level_id = ss.proficiency_level_id;

-- Career skill gaps as stored by PHP. Shows the stored result, not a live calculation.
CREATE OR REPLACE VIEW v_career_skill_gaps AS
SELECT
  g.student_id,
  cp.career_title,
  s.skill_name,
  g.current_level_value,
  g.required_level_value,
  g.gap_score,
  g.suggested_action,
  g.calculated_at
FROM skill_gap_results g
JOIN career_paths cp ON cp.career_id = g.career_id
JOIN skills s        ON s.skill_id   = g.skill_id;

-- Dashboard summary per source: sync health.
CREATE OR REPLACE VIEW v_source_sync_status AS
SELECT
  src.source_id,
  src.source_name,
  st.type_name        AS source_type,
  src.is_enabled,
  src.sync_status,
  src.last_attempted_sync_at,
  src.last_successful_sync_at,
  src.next_sync_due_at,
  src.retry_count,
  src.last_error_message
FROM activity_sources src
JOIN source_types st ON st.source_type_id = src.source_type_id;

-- ============================================================================
-- SECTION 16: TRIGGERS
-- Four triggers, each with one clear purpose and no recursion:
--   two write status-history rows (activities, enrollments), and
--   two keep enrollments.active_key correct so duplicate open enrollments are blocked.
-- Both use the standard delimiter handling for phpMyAdmin imports.
-- ============================================================================

DELIMITER $$

-- Keeps activity_status_history populated whenever an activity's status changes.
-- Writes only to the history table, so it cannot re-fire on itself.
DROP TRIGGER IF EXISTS trg_activities_status_history $$
CREATE TRIGGER trg_activities_status_history
AFTER UPDATE ON activities
FOR EACH ROW
BEGIN
  IF NOT (OLD.activity_status_id <=> NEW.activity_status_id) THEN
    INSERT INTO activity_status_history (activity_id, from_status_id, to_status_id, reason)
    VALUES (NEW.activity_id, OLD.activity_status_id, NEW.activity_status_id, 'automatic: status changed');
  END IF;
END $$

-- Keeps enrollment_status_history populated whenever an enrollment's status changes.
DROP TRIGGER IF EXISTS trg_enrollments_status_history $$
CREATE TRIGGER trg_enrollments_status_history
AFTER UPDATE ON student_activity_enrollments
FOR EACH ROW
BEGIN
  IF NOT (OLD.enrollment_status_id <=> NEW.enrollment_status_id) THEN
    INSERT INTO enrollment_status_history (enrollment_id, from_status_id, to_status_id, reason)
    VALUES (NEW.enrollment_id, OLD.enrollment_status_id, NEW.enrollment_status_id, 'automatic: status changed');
  END IF;
END $$

-- Sets active_key on insert: 1 while the status is non-terminal, else NULL.
-- Reads enrollment_statuses (a different table) and writes only NEW.active_key.
DROP TRIGGER IF EXISTS trg_enrollments_active_key_insert $$
CREATE TRIGGER trg_enrollments_active_key_insert
BEFORE INSERT ON student_activity_enrollments
FOR EACH ROW
BEGIN
  DECLARE v_is_terminal TINYINT DEFAULT 0;
  SELECT es.is_terminal INTO v_is_terminal
  FROM enrollment_statuses es
  WHERE es.enrollment_status_id = NEW.enrollment_status_id;
  SET NEW.active_key = IF(v_is_terminal = 0, 1, NULL);
END $$

-- Keeps active_key correct when a status changes (e.g. interested -> enrolled -> completed).
DROP TRIGGER IF EXISTS trg_enrollments_active_key_update $$
CREATE TRIGGER trg_enrollments_active_key_update
BEFORE UPDATE ON student_activity_enrollments
FOR EACH ROW
BEGIN
  DECLARE v_is_terminal TINYINT DEFAULT 0;
  IF NOT (OLD.enrollment_status_id <=> NEW.enrollment_status_id) THEN
    SELECT es.is_terminal INTO v_is_terminal
    FROM enrollment_statuses es
    WHERE es.enrollment_status_id = NEW.enrollment_status_id;
    SET NEW.active_key = IF(v_is_terminal = 0, 1, NULL);
  END IF;
END $$

DELIMITER ;

-- ============================================================================
-- END OF careeract_v2.sql
-- ============================================================================


-- ============================================================================
-- CAREERACT FULL-CATALOG EXTENSION v3
-- Additive, re-runnable catalog expansion. Existing tables and rows are preserved.
-- INSERT IGNORE + natural keys prevent duplicate reference rows on re-import.
-- Directory records below are evergreen links, NOT claims of dated live events.
-- Live event listings require an authorized API/feed importer in the PHP layer.
-- ============================================================================

INSERT IGNORE INTO cities (city_name, state_name, country) VALUES
  ('Nagpur', 'Maharashtra', 'India'),
  ('Nashik', 'Maharashtra', 'India'),
  ('Chhatrapati Sambhajinagar', 'Maharashtra', 'India'),
  ('Kolhapur', 'Maharashtra', 'India'),
  ('Solapur', 'Maharashtra', 'India'),
  ('Thane', 'Maharashtra', 'India'),
  ('Navi Mumbai', 'Maharashtra', 'India'),
  ('Nanded', 'Maharashtra', 'India'),
  ('Amravati', 'Maharashtra', 'India'),
  ('Satara', 'Maharashtra', 'India'),
  ('Panaji', 'Goa', 'India'),
  ('Indore', 'Madhya Pradesh', 'India'),
  ('Bhopal', 'Madhya Pradesh', 'India'),
  ('Jaipur', 'Rajasthan', 'India'),
  ('Lucknow', 'Uttar Pradesh', 'India'),
  ('Kanpur', 'Uttar Pradesh', 'India'),
  ('Noida', 'Uttar Pradesh', 'India'),
  ('Gurugram', 'Haryana', 'India'),
  ('Chandigarh', 'Chandigarh', 'India'),
  ('Kochi', 'Kerala', 'India'),
  ('Thiruvananthapuram', 'Kerala', 'India'),
  ('Coimbatore', 'Tamil Nadu', 'India'),
  ('Mysuru', 'Karnataka', 'India'),
  ('Mangaluru', 'Karnataka', 'India'),
  ('Visakhapatnam', 'Andhra Pradesh', 'India'),
  ('Vijayawada', 'Andhra Pradesh', 'India'),
  ('Bhubaneswar', 'Odisha', 'India'),
  ('Guwahati', 'Assam', 'India'),
  ('Patna', 'Bihar', 'India'),
  ('Ranchi', 'Jharkhand', 'India'),
  ('Dehradun', 'Uttarakhand', 'India'),
  ('Srinagar', 'Jammu and Kashmir', 'India'),
  ('Remote / Online', 'Online', 'India');

-- Expanded skill catalogue (natural slug key keeps this idempotent).
INSERT IGNORE INTO skills (skill_category_id, skill_name, skill_slug, description) VALUES
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Java', 'java', 'Object-oriented programming with Java'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'JavaScript', 'javascript', 'Programming for web and application development'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'TypeScript', 'typescript', 'Typed JavaScript development'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'HTML', 'html', 'Semantic web page structure'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'CSS', 'css', 'Web styling and responsive layouts'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'React', 'react', 'Component-based user interfaces'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Node.js', 'nodejs', 'Server-side JavaScript'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'PHP', 'php', 'Server-side web programming'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'MySQL', 'mysql', 'Relational database development with MySQL'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Git and GitHub', 'git-github', 'Version control and collaborative development'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'API Design', 'api-design', 'Designing and consuming REST APIs'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Linux', 'linux', 'Linux command line and system basics'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Cloud Computing', 'cloud-computing', 'Cloud concepts and deployment'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Docker', 'docker', 'Containerization fundamentals'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Software Testing and QA', 'testing-qa', 'Test planning and quality assurance'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Cybersecurity Fundamentals', 'cybersecurity-fundamentals', 'Security principles and risk awareness'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Cryptography Basics', 'cryptography', 'Encryption and hashing concepts'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Computer Networking', 'networking', 'Network protocols and troubleshooting'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Data Visualization', 'data-visualization', 'Communicating insights through charts'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Statistics', 'statistics', 'Descriptive and inferential statistics'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Excel and Spreadsheets', 'excel', 'Spreadsheet analysis and formulas'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Pandas', 'pandas', 'Data manipulation with Python'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'NumPy', 'numpy', 'Numerical computing in Python'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Deep Learning', 'deep-learning', 'Neural networks and deep learning methods'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Natural Language Processing', 'nlp', 'Processing and analyzing language data'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Computer Vision', 'computer-vision', 'Analysis of image and video data'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Prompt Engineering', 'prompt-engineering', 'Structured prompting and AI workflow design'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Generative AI Literacy', 'generative-ai', 'Responsible use of generative AI tools'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Internet of Things', 'iot', 'Connected devices and sensor systems'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Embedded Systems', 'embedded-systems', 'Firmware and microcontroller fundamentals'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Arduino', 'arduino', 'Prototyping with microcontrollers'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Robotics', 'robotics', 'Robot design, control and automation'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'CAD', 'cad', 'Computer-aided design and drafting'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'SolidWorks', 'solidworks', '3D mechanical design'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'AutoCAD', 'autocad', 'Technical drafting and design'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Electronics and Circuits', 'electronics-circuits', 'Circuit analysis and electronic components'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Renewable Energy', 'renewable-energy', 'Solar, wind and clean energy concepts'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'GIS and Mapping', 'gis', 'Geospatial data and mapping'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Research Data Analysis', 'research-data-analysis', 'Preparing and analyzing research data'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='design'), 'UI/UX Design', 'ui-ux-design', 'User research, flows, wireframes and usability'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='design'), 'Figma', 'figma', 'Collaborative interface design and prototyping'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='design'), 'Graphic Design', 'graphic-design', 'Visual communication and layout'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='design'), 'Video Editing', 'video-editing', 'Editing and producing video content'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='design'), 'Photography', 'photography', 'Image capture and visual storytelling'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='design'), 'Motion Graphics', 'motion-graphics', 'Animated visual communication'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='analytical'), 'Critical Thinking', 'critical-thinking', 'Evaluating claims and evidence'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='analytical'), 'Problem Solving', 'problem-solving', 'Breaking complex problems into solvable steps'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='analytical'), 'Logical Reasoning', 'logical-reasoning', 'Structured reasoning and inference'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='analytical'), 'Business Analysis', 'business-analysis', 'Requirements and process analysis'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='analytical'), 'Financial Analysis', 'financial-analysis', 'Financial statements, ratios and decisions'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='analytical'), 'Market Research', 'market-research', 'Customer and market analysis'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='analytical'), 'Data Storytelling', 'data-storytelling', 'Explaining data insights to stakeholders'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'), 'Entrepreneurial Thinking', 'entrepreneurial-thinking', 'Identifying problems and testing ventures'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'), 'Sales', 'sales', 'Customer discovery and sales process'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'), 'Digital Marketing', 'digital-marketing', 'Digital campaign strategy and execution'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'), 'SEO', 'seo', 'Search engine optimization fundamentals'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'), 'Content Strategy', 'content-strategy', 'Planning audience-focused content'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'), 'Accounting Fundamentals', 'accounting', 'Bookkeeping and financial reporting'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'), 'Risk Management', 'risk-management', 'Identifying and mitigating organizational risk'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'), 'Product Strategy', 'product-strategy', 'Product vision, prioritization and roadmaps'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'), 'Requirements Gathering', 'requirements-gathering', 'Eliciting and documenting stakeholder needs'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='communication'), 'Communication', 'communication', 'Clear written and verbal communication'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='communication'), 'Public Speaking', 'public-speaking', 'Presenting ideas to an audience'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='communication'), 'Technical Writing', 'technical-writing', 'Writing documentation and reports'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='communication'), 'Storytelling', 'storytelling', 'Structuring clear and engaging narratives'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='communication'), 'Negotiation', 'negotiation', 'Finding workable agreements'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='communication'), 'Active Listening', 'active-listening', 'Understanding and responding to others'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='leadership'), 'Teamwork', 'teamwork', 'Collaborating toward shared outcomes'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='leadership'), 'Leadership', 'leadership', 'Motivating and guiding a team'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='leadership'), 'Project Management', 'project-management', 'Planning and delivering projects'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='leadership'), 'Time Management', 'time-management', 'Prioritizing tasks and deadlines'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='leadership'), 'Conflict Resolution', 'conflict-resolution', 'Managing disagreements constructively'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='leadership'), 'Event Management', 'event-management', 'Planning and delivering events'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='leadership'), 'Mentoring', 'mentoring', 'Supporting others through guidance'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='research'), 'Research Methodology', 'research-methodology', 'Research design, methods and ethics'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='research'), 'Academic Writing', 'academic-writing', 'Writing structured scholarly work'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='research'), 'Literature Review', 'literature-review', 'Finding and synthesizing prior research'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='research'), 'Scientific Communication', 'scientific-communication', 'Explaining research to specialist and general audiences'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='sustainability'), 'Sustainability Literacy', 'sustainability', 'Environmental and social sustainability concepts'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='sustainability'), 'Climate Literacy', 'climate-literacy', 'Climate science and climate action basics'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='sustainability'), 'Community Outreach', 'community-outreach', 'Engaging communities in initiatives'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'), 'Human Resource Management', 'hr-management', 'Recruitment, engagement and people operations'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'), 'Customer Service', 'customer-service', 'Responding to customer needs and issues'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'), 'Teaching and Facilitation', 'teaching-facilitation', 'Helping groups learn and participate'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Digital Accessibility', 'accessibility', 'Designing inclusive digital experiences'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Agile and Scrum', 'agile-scrum', 'Iterative delivery and team rituals'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Data Ethics and Privacy', 'data-ethics', 'Responsible data use and privacy basics'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Blockchain Fundamentals', 'blockchain', 'Distributed ledger concepts'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Mobile App Development', 'mobile-development', 'Building mobile applications'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Game Development', 'game-development', 'Interactive software and game logic'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'DevOps Fundamentals', 'devops', 'Automation and reliable software delivery'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'MLOps', 'machine-learning-ops', 'Deploying and monitoring machine learning systems'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Power BI', 'power-bi', 'Business intelligence reports and dashboards'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Tableau', 'tableau', 'Visual analytics and dashboards'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'R Programming', 'r-programming', 'Statistical computing with R'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'MATLAB', 'matlab', 'Numerical analysis and engineering computing'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Finite Element Analysis', 'finite-element-analysis', 'Simulation and structural analysis'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Supply Chain Fundamentals', 'supply-chain', 'Operations and logistics concepts'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'), 'Operations Management', 'operations-management', 'Process improvement and operational planning'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='business'), 'Public Policy Literacy', 'public-policy', 'Policy analysis and public programmes'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='sustainability'), 'Waste Management', 'waste-management', 'Waste reduction and circular economy practices'),
  ((SELECT skill_category_id FROM skill_categories WHERE category_code='technical'), 'Health Informatics', 'health-informatics', 'Digital information in healthcare systems');

INSERT IGNORE INTO skill_domains (skill_id, domain_id)
SELECT s.skill_id, d.domain_id FROM (
  SELECT 'java' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'javascript' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'javascript' AS skill_slug, 'design' AS domain_code
  UNION ALL SELECT 'typescript' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'html' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'html' AS skill_slug, 'design' AS domain_code
  UNION ALL SELECT 'css' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'css' AS skill_slug, 'design' AS domain_code
  UNION ALL SELECT 'react' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'react' AS skill_slug, 'design' AS domain_code
  UNION ALL SELECT 'nodejs' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'php' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'mysql' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'mysql' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'git-github' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'api-design' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'linux' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'linux' AS skill_slug, 'security' AS domain_code
  UNION ALL SELECT 'cloud-computing' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'cloud-computing' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'docker' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'testing-qa' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'cybersecurity-fundamentals' AS skill_slug, 'security' AS domain_code
  UNION ALL SELECT 'cryptography' AS skill_slug, 'security' AS domain_code
  UNION ALL SELECT 'networking' AS skill_slug, 'security' AS domain_code
  UNION ALL SELECT 'networking' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'data-visualization' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'data-visualization' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'statistics' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'statistics' AS skill_slug, 'science_research' AS domain_code
  UNION ALL SELECT 'excel' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'excel' AS skill_slug, 'finance' AS domain_code
  UNION ALL SELECT 'excel' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'pandas' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'numpy' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'deep-learning' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'nlp' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'computer-vision' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'prompt-engineering' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'prompt-engineering' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'generative-ai' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'generative-ai' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'iot' AS skill_slug, 'engineering' AS domain_code
  UNION ALL SELECT 'embedded-systems' AS skill_slug, 'engineering' AS domain_code
  UNION ALL SELECT 'arduino' AS skill_slug, 'engineering' AS domain_code
  UNION ALL SELECT 'robotics' AS skill_slug, 'engineering' AS domain_code
  UNION ALL SELECT 'cad' AS skill_slug, 'engineering' AS domain_code
  UNION ALL SELECT 'cad' AS skill_slug, 'design' AS domain_code
  UNION ALL SELECT 'solidworks' AS skill_slug, 'engineering' AS domain_code
  UNION ALL SELECT 'autocad' AS skill_slug, 'engineering' AS domain_code
  UNION ALL SELECT 'autocad' AS skill_slug, 'design' AS domain_code
  UNION ALL SELECT 'electronics-circuits' AS skill_slug, 'engineering' AS domain_code
  UNION ALL SELECT 'renewable-energy' AS skill_slug, 'engineering' AS domain_code
  UNION ALL SELECT 'renewable-energy' AS skill_slug, 'sustainability' AS domain_code
  UNION ALL SELECT 'gis' AS skill_slug, 'engineering' AS domain_code
  UNION ALL SELECT 'gis' AS skill_slug, 'science_research' AS domain_code
  UNION ALL SELECT 'research-data-analysis' AS skill_slug, 'science_research' AS domain_code
  UNION ALL SELECT 'research-data-analysis' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'ui-ux-design' AS skill_slug, 'design' AS domain_code
  UNION ALL SELECT 'figma' AS skill_slug, 'design' AS domain_code
  UNION ALL SELECT 'graphic-design' AS skill_slug, 'design' AS domain_code
  UNION ALL SELECT 'graphic-design' AS skill_slug, 'marketing' AS domain_code
  UNION ALL SELECT 'video-editing' AS skill_slug, 'design' AS domain_code
  UNION ALL SELECT 'video-editing' AS skill_slug, 'marketing' AS domain_code
  UNION ALL SELECT 'photography' AS skill_slug, 'design' AS domain_code
  UNION ALL SELECT 'photography' AS skill_slug, 'marketing' AS domain_code
  UNION ALL SELECT 'motion-graphics' AS skill_slug, 'design' AS domain_code
  UNION ALL SELECT 'critical-thinking' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'critical-thinking' AS skill_slug, 'science_research' AS domain_code
  UNION ALL SELECT 'problem-solving' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'problem-solving' AS skill_slug, 'engineering' AS domain_code
  UNION ALL SELECT 'problem-solving' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'logical-reasoning' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'logical-reasoning' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'business-analysis' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'financial-analysis' AS skill_slug, 'finance' AS domain_code
  UNION ALL SELECT 'financial-analysis' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'market-research' AS skill_slug, 'marketing' AS domain_code
  UNION ALL SELECT 'market-research' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'data-storytelling' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'data-storytelling' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'entrepreneurial-thinking' AS skill_slug, 'entrepreneurship' AS domain_code
  UNION ALL SELECT 'entrepreneurial-thinking' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'sales' AS skill_slug, 'marketing' AS domain_code
  UNION ALL SELECT 'sales' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'digital-marketing' AS skill_slug, 'marketing' AS domain_code
  UNION ALL SELECT 'seo' AS skill_slug, 'marketing' AS domain_code
  UNION ALL SELECT 'content-strategy' AS skill_slug, 'marketing' AS domain_code
  UNION ALL SELECT 'accounting' AS skill_slug, 'finance' AS domain_code
  UNION ALL SELECT 'risk-management' AS skill_slug, 'finance' AS domain_code
  UNION ALL SELECT 'risk-management' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'product-strategy' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'requirements-gathering' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'requirements-gathering' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'communication' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'communication' AS skill_slug, 'social_impact' AS domain_code
  UNION ALL SELECT 'public-speaking' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'public-speaking' AS skill_slug, 'social_impact' AS domain_code
  UNION ALL SELECT 'technical-writing' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'technical-writing' AS skill_slug, 'science_research' AS domain_code
  UNION ALL SELECT 'storytelling' AS skill_slug, 'marketing' AS domain_code
  UNION ALL SELECT 'storytelling' AS skill_slug, 'design' AS domain_code
  UNION ALL SELECT 'negotiation' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'negotiation' AS skill_slug, 'hr' AS domain_code
  UNION ALL SELECT 'active-listening' AS skill_slug, 'hr' AS domain_code
  UNION ALL SELECT 'active-listening' AS skill_slug, 'social_impact' AS domain_code
  UNION ALL SELECT 'teamwork' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'teamwork' AS skill_slug, 'engineering' AS domain_code
  UNION ALL SELECT 'teamwork' AS skill_slug, 'social_impact' AS domain_code
  UNION ALL SELECT 'leadership' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'leadership' AS skill_slug, 'hr' AS domain_code
  UNION ALL SELECT 'leadership' AS skill_slug, 'social_impact' AS domain_code
  UNION ALL SELECT 'project-management' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'project-management' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'project-management' AS skill_slug, 'engineering' AS domain_code
  UNION ALL SELECT 'time-management' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'conflict-resolution' AS skill_slug, 'hr' AS domain_code
  UNION ALL SELECT 'conflict-resolution' AS skill_slug, 'social_impact' AS domain_code
  UNION ALL SELECT 'event-management' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'event-management' AS skill_slug, 'social_impact' AS domain_code
  UNION ALL SELECT 'mentoring' AS skill_slug, 'hr' AS domain_code
  UNION ALL SELECT 'mentoring' AS skill_slug, 'social_impact' AS domain_code
  UNION ALL SELECT 'research-methodology' AS skill_slug, 'science_research' AS domain_code
  UNION ALL SELECT 'academic-writing' AS skill_slug, 'science_research' AS domain_code
  UNION ALL SELECT 'literature-review' AS skill_slug, 'science_research' AS domain_code
  UNION ALL SELECT 'scientific-communication' AS skill_slug, 'science_research' AS domain_code
  UNION ALL SELECT 'sustainability' AS skill_slug, 'sustainability' AS domain_code
  UNION ALL SELECT 'climate-literacy' AS skill_slug, 'sustainability' AS domain_code
  UNION ALL SELECT 'climate-literacy' AS skill_slug, 'science_research' AS domain_code
  UNION ALL SELECT 'community-outreach' AS skill_slug, 'social_impact' AS domain_code
  UNION ALL SELECT 'hr-management' AS skill_slug, 'hr' AS domain_code
  UNION ALL SELECT 'customer-service' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'customer-service' AS skill_slug, 'marketing' AS domain_code
  UNION ALL SELECT 'teaching-facilitation' AS skill_slug, 'social_impact' AS domain_code
  UNION ALL SELECT 'teaching-facilitation' AS skill_slug, 'science_research' AS domain_code
  UNION ALL SELECT 'accessibility' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'accessibility' AS skill_slug, 'design' AS domain_code
  UNION ALL SELECT 'agile-scrum' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'agile-scrum' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'data-ethics' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'data-ethics' AS skill_slug, 'security' AS domain_code
  UNION ALL SELECT 'blockchain' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'blockchain' AS skill_slug, 'finance' AS domain_code
  UNION ALL SELECT 'mobile-development' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'game-development' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'game-development' AS skill_slug, 'design' AS domain_code
  UNION ALL SELECT 'devops' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'machine-learning-ops' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'machine-learning-ops' AS skill_slug, 'it_software' AS domain_code
  UNION ALL SELECT 'power-bi' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'power-bi' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'tableau' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'tableau' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'r-programming' AS skill_slug, 'data_analytics' AS domain_code
  UNION ALL SELECT 'r-programming' AS skill_slug, 'science_research' AS domain_code
  UNION ALL SELECT 'matlab' AS skill_slug, 'engineering' AS domain_code
  UNION ALL SELECT 'matlab' AS skill_slug, 'science_research' AS domain_code
  UNION ALL SELECT 'finite-element-analysis' AS skill_slug, 'engineering' AS domain_code
  UNION ALL SELECT 'supply-chain' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'supply-chain' AS skill_slug, 'engineering' AS domain_code
  UNION ALL SELECT 'operations-management' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'operations-management' AS skill_slug, 'engineering' AS domain_code
  UNION ALL SELECT 'public-policy' AS skill_slug, 'social_impact' AS domain_code
  UNION ALL SELECT 'public-policy' AS skill_slug, 'business' AS domain_code
  UNION ALL SELECT 'waste-management' AS skill_slug, 'sustainability' AS domain_code
  UNION ALL SELECT 'health-informatics' AS skill_slug, 'healthcare_nonclinical' AS domain_code
  UNION ALL SELECT 'health-informatics' AS skill_slug, 'data_analytics' AS domain_code
) x JOIN skills s ON s.skill_slug=x.skill_slug JOIN domains d ON d.domain_code=x.domain_code;

-- Expanded career paths.
INSERT IGNORE INTO career_paths (career_category_id, domain_id, career_code, career_title, description)
SELECT cc.career_category_id, d.domain_id, x.career_code, x.career_title, x.description FROM (
  SELECT 'technology' AS cat, 'it_software' AS dom, 'frontend_developer' AS career_code, 'Frontend Developer' AS career_title, 'Builds accessible, responsive user interfaces' AS description
  UNION ALL SELECT 'technology' AS cat, 'it_software' AS dom, 'backend_developer' AS career_code, 'Backend Developer' AS career_title, 'Builds server-side services and APIs' AS description
  UNION ALL SELECT 'technology' AS cat, 'it_software' AS dom, 'full_stack_developer' AS career_code, 'Full-Stack Developer' AS career_title, 'Builds end-to-end web applications' AS description
  UNION ALL SELECT 'technology' AS cat, 'it_software' AS dom, 'mobile_app_developer' AS career_code, 'Mobile App Developer' AS career_title, 'Builds applications for mobile devices' AS description
  UNION ALL SELECT 'technology' AS cat, 'it_software' AS dom, 'devops_engineer' AS career_code, 'DevOps Engineer' AS career_title, 'Automates delivery and platform operations' AS description
  UNION ALL SELECT 'technology' AS cat, 'security' AS dom, 'security_engineer' AS career_code, 'Security Engineer' AS career_title, 'Builds and maintains security controls' AS description
  UNION ALL SELECT 'technology' AS cat, 'data_analytics' AS dom, 'data_analyst' AS career_code, 'Data Analyst' AS career_title, 'Transforms data into useful business insights' AS description
  UNION ALL SELECT 'technology' AS cat, 'data_analytics' AS dom, 'data_engineer' AS career_code, 'Data Engineer' AS career_title, 'Builds reliable data pipelines and platforms' AS description
  UNION ALL SELECT 'design' AS cat, 'design' AS dom, 'graphic_designer' AS career_code, 'Graphic Designer' AS career_title, 'Creates visual communication and brand assets' AS description
  UNION ALL SELECT 'design' AS cat, 'design' AS dom, 'product_designer' AS career_code, 'Product Designer' AS career_title, 'Designs end-to-end product experiences' AS description
  UNION ALL SELECT 'business' AS cat, 'marketing' AS dom, 'digital_marketer' AS career_code, 'Digital Marketer' AS career_title, 'Plans and measures digital campaigns' AS description
  UNION ALL SELECT 'business' AS cat, 'marketing' AS dom, 'content_creator' AS career_code, 'Content Creator' AS career_title, 'Creates content for digital audiences' AS description
  UNION ALL SELECT 'business' AS cat, 'finance' AS dom, 'accountant' AS career_code, 'Accountant' AS career_title, 'Maintains financial records and reporting' AS description
  UNION ALL SELECT 'business' AS cat, 'finance' AS dom, 'financial_analyst' AS career_code, 'Financial Analyst' AS career_title, 'Evaluates financial performance and forecasts' AS description
  UNION ALL SELECT 'business' AS cat, 'hr' AS dom, 'recruiter' AS career_code, 'Recruiter' AS career_title, 'Finds and evaluates candidates for roles' AS description
  UNION ALL SELECT 'engineering' AS cat, 'engineering' AS dom, 'robotics_engineer' AS career_code, 'Robotics Engineer' AS career_title, 'Designs robotic systems and automation' AS description
  UNION ALL SELECT 'engineering' AS cat, 'engineering' AS dom, 'embedded_engineer' AS career_code, 'Embedded Systems Engineer' AS career_title, 'Develops firmware and device software' AS description
  UNION ALL SELECT 'engineering' AS cat, 'engineering' AS dom, 'renewable_energy_engineer' AS career_code, 'Renewable Energy Engineer' AS career_title, 'Works on clean energy systems' AS description
  UNION ALL SELECT 'science' AS cat, 'science_research' AS dom, 'research_assistant' AS career_code, 'Research Assistant' AS career_title, 'Supports experiments, literature and data work' AS description
  UNION ALL SELECT 'social' AS cat, 'social_impact' AS dom, 'ngo_program_coordinator' AS career_code, 'NGO Programme Coordinator' AS career_title, 'Coordinates community and nonprofit programmes' AS description
  UNION ALL SELECT 'social' AS cat, 'sustainability' AS dom, 'sustainability_analyst' AS career_code, 'Sustainability Analyst' AS career_title, 'Measures and improves environmental performance' AS description
  UNION ALL SELECT 'healthcare' AS cat, 'healthcare_nonclinical' AS dom, 'healthcare_data_analyst' AS career_code, 'Healthcare Data Analyst' AS career_title, 'Analyzes healthcare operations and data' AS description
  UNION ALL SELECT 'business' AS cat, 'business' AS dom, 'operations_analyst' AS career_code, 'Operations Analyst' AS career_title, 'Improves processes and business performance' AS description
  UNION ALL SELECT 'business' AS cat, 'entrepreneurship' AS dom, 'startup_founder' AS career_code, 'Startup Founder' AS career_title, 'Validates problems and builds new ventures' AS description
) x JOIN career_categories cc ON cc.category_code=x.cat JOIN domains d ON d.domain_code=x.dom;

INSERT IGNORE INTO career_roles (career_id, role_title, role_summary, sort_order)
SELECT c.career_id, x.role_title, x.role_summary, x.sort_order FROM (
  SELECT 'software_engineer' AS career_code, 'Frontend Engineer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'software_engineer' AS career_code, 'Backend Engineer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
  UNION ALL SELECT 'software_engineer' AS career_code, 'Full-Stack Engineer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 3 AS sort_order
  UNION ALL SELECT 'software_engineer' AS career_code, 'Mobile Engineer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 4 AS sort_order
  UNION ALL SELECT 'software_engineer' AS career_code, 'Platform Engineer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 5 AS sort_order
  UNION ALL SELECT 'data_scientist' AS career_code, 'Applied Data Scientist' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'data_scientist' AS career_code, 'Research Data Scientist' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
  UNION ALL SELECT 'ai_ml_engineer' AS career_code, 'Machine Learning Engineer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'ai_ml_engineer' AS career_code, 'AI Engineer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
  UNION ALL SELECT 'ai_ml_engineer' AS career_code, 'MLOps Engineer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 3 AS sort_order
  UNION ALL SELECT 'cybersecurity_analyst' AS career_code, 'Security Operations Analyst' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'cybersecurity_analyst' AS career_code, 'Penetration Tester' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
  UNION ALL SELECT 'ui_ux_designer' AS career_code, 'UX Researcher' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'ui_ux_designer' AS career_code, 'Product Designer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
  UNION ALL SELECT 'business_analyst' AS career_code, 'Business Systems Analyst' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'business_analyst' AS career_code, 'Process Analyst' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
  UNION ALL SELECT 'product_manager' AS career_code, 'Associate Product Manager' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'product_manager' AS career_code, 'Technical Product Manager' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
  UNION ALL SELECT 'entrepreneur' AS career_code, 'Startup Founder' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'entrepreneur' AS career_code, 'Business Development Lead' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
  UNION ALL SELECT 'mechanical_engineer' AS career_code, 'Design Engineer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'mechanical_engineer' AS career_code, 'Manufacturing Engineer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
  UNION ALL SELECT 'electronics_engineer' AS career_code, 'Embedded Systems Engineer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'electronics_engineer' AS career_code, 'Hardware Engineer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
  UNION ALL SELECT 'civil_engineer' AS career_code, 'Structural Engineer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'civil_engineer' AS career_code, 'Site Engineer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
  UNION ALL SELECT 'research_scientist' AS career_code, 'Research Assistant' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'research_scientist' AS career_code, 'Research Associate' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
  UNION ALL SELECT 'marketing_specialist' AS career_code, 'Digital Marketing Executive' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'marketing_specialist' AS career_code, 'SEO Specialist' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
  UNION ALL SELECT 'finance_professional' AS career_code, 'Financial Analyst' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'finance_professional' AS career_code, 'Investment Research Analyst' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
  UNION ALL SELECT 'hr_professional' AS career_code, 'Recruiter' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'hr_professional' AS career_code, 'People Operations Specialist' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
  UNION ALL SELECT 'social_impact_professional' AS career_code, 'NGO Programme Officer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'social_impact_professional' AS career_code, 'Community Engagement Officer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
  UNION ALL SELECT 'sustainability_professional' AS career_code, 'ESG Analyst' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'sustainability_professional' AS career_code, 'Environmental Programme Officer' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
  UNION ALL SELECT 'healthcare_program_manager' AS career_code, 'Healthcare Operations Coordinator' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 1 AS sort_order
  UNION ALL SELECT 'healthcare_program_manager' AS career_code, 'Health Programme Analyst' AS role_title, 'Related entry-level and specialist pathway' AS role_summary, 2 AS sort_order
) x JOIN career_paths c ON c.career_code=x.career_code;

INSERT IGNORE INTO career_required_skills (career_id, skill_id, expected_level_id, importance_weight)
SELECT c.career_id, s.skill_id, pl.proficiency_level_id, 7.00 FROM (
  SELECT 'software_engineer' AS career_code, 'programming-general' AS skill_slug
  UNION ALL SELECT 'software_engineer' AS career_code, 'dsa' AS skill_slug
  UNION ALL SELECT 'software_engineer' AS career_code, 'java' AS skill_slug
  UNION ALL SELECT 'software_engineer' AS career_code, 'git-github' AS skill_slug
  UNION ALL SELECT 'software_engineer' AS career_code, 'sql-databases' AS skill_slug
  UNION ALL SELECT 'software_engineer' AS career_code, 'problem-solving' AS skill_slug
  UNION ALL SELECT 'software_engineer' AS career_code, 'testing-qa' AS skill_slug
  UNION ALL SELECT 'frontend_developer' AS career_code, 'javascript' AS skill_slug
  UNION ALL SELECT 'frontend_developer' AS career_code, 'html' AS skill_slug
  UNION ALL SELECT 'frontend_developer' AS career_code, 'css' AS skill_slug
  UNION ALL SELECT 'frontend_developer' AS career_code, 'react' AS skill_slug
  UNION ALL SELECT 'frontend_developer' AS career_code, 'accessibility' AS skill_slug
  UNION ALL SELECT 'frontend_developer' AS career_code, 'git-github' AS skill_slug
  UNION ALL SELECT 'backend_developer' AS career_code, 'programming-general' AS skill_slug
  UNION ALL SELECT 'backend_developer' AS career_code, 'api-design' AS skill_slug
  UNION ALL SELECT 'backend_developer' AS career_code, 'sql-databases' AS skill_slug
  UNION ALL SELECT 'backend_developer' AS career_code, 'git-github' AS skill_slug
  UNION ALL SELECT 'backend_developer' AS career_code, 'testing-qa' AS skill_slug
  UNION ALL SELECT 'backend_developer' AS career_code, 'cybersecurity-fundamentals' AS skill_slug
  UNION ALL SELECT 'full_stack_developer' AS career_code, 'javascript' AS skill_slug
  UNION ALL SELECT 'full_stack_developer' AS career_code, 'html' AS skill_slug
  UNION ALL SELECT 'full_stack_developer' AS career_code, 'css' AS skill_slug
  UNION ALL SELECT 'full_stack_developer' AS career_code, 'react' AS skill_slug
  UNION ALL SELECT 'full_stack_developer' AS career_code, 'nodejs' AS skill_slug
  UNION ALL SELECT 'full_stack_developer' AS career_code, 'sql-databases' AS skill_slug
  UNION ALL SELECT 'full_stack_developer' AS career_code, 'git-github' AS skill_slug
  UNION ALL SELECT 'mobile_app_developer' AS career_code, 'java' AS skill_slug
  UNION ALL SELECT 'mobile_app_developer' AS career_code, 'javascript' AS skill_slug
  UNION ALL SELECT 'mobile_app_developer' AS career_code, 'problem-solving' AS skill_slug
  UNION ALL SELECT 'mobile_app_developer' AS career_code, 'git-github' AS skill_slug
  UNION ALL SELECT 'mobile_app_developer' AS career_code, 'testing-qa' AS skill_slug
  UNION ALL SELECT 'devops_engineer' AS career_code, 'linux' AS skill_slug
  UNION ALL SELECT 'devops_engineer' AS career_code, 'cloud-computing' AS skill_slug
  UNION ALL SELECT 'devops_engineer' AS career_code, 'docker' AS skill_slug
  UNION ALL SELECT 'devops_engineer' AS career_code, 'devops' AS skill_slug
  UNION ALL SELECT 'devops_engineer' AS career_code, 'git-github' AS skill_slug
  UNION ALL SELECT 'devops_engineer' AS career_code, 'networking' AS skill_slug
  UNION ALL SELECT 'data_scientist' AS career_code, 'python' AS skill_slug
  UNION ALL SELECT 'data_scientist' AS career_code, 'statistics' AS skill_slug
  UNION ALL SELECT 'data_scientist' AS career_code, 'pandas' AS skill_slug
  UNION ALL SELECT 'data_scientist' AS career_code, 'numpy' AS skill_slug
  UNION ALL SELECT 'data_scientist' AS career_code, 'machine-learning' AS skill_slug
  UNION ALL SELECT 'data_scientist' AS career_code, 'data-visualization' AS skill_slug
  UNION ALL SELECT 'data_scientist' AS career_code, 'sql-databases' AS skill_slug
  UNION ALL SELECT 'data_analyst' AS career_code, 'sql-databases' AS skill_slug
  UNION ALL SELECT 'data_analyst' AS career_code, 'excel' AS skill_slug
  UNION ALL SELECT 'data_analyst' AS career_code, 'data-visualization' AS skill_slug
  UNION ALL SELECT 'data_analyst' AS career_code, 'statistics' AS skill_slug
  UNION ALL SELECT 'data_analyst' AS career_code, 'power-bi' AS skill_slug
  UNION ALL SELECT 'data_analyst' AS career_code, 'data-storytelling' AS skill_slug
  UNION ALL SELECT 'data_engineer' AS career_code, 'python' AS skill_slug
  UNION ALL SELECT 'data_engineer' AS career_code, 'sql-databases' AS skill_slug
  UNION ALL SELECT 'data_engineer' AS career_code, 'cloud-computing' AS skill_slug
  UNION ALL SELECT 'data_engineer' AS career_code, 'linux' AS skill_slug
  UNION ALL SELECT 'data_engineer' AS career_code, 'docker' AS skill_slug
  UNION ALL SELECT 'data_engineer' AS career_code, 'api-design' AS skill_slug
  UNION ALL SELECT 'ai_ml_engineer' AS career_code, 'python' AS skill_slug
  UNION ALL SELECT 'ai_ml_engineer' AS career_code, 'machine-learning' AS skill_slug
  UNION ALL SELECT 'ai_ml_engineer' AS career_code, 'deep-learning' AS skill_slug
  UNION ALL SELECT 'ai_ml_engineer' AS career_code, 'statistics' AS skill_slug
  UNION ALL SELECT 'ai_ml_engineer' AS career_code, 'generative-ai' AS skill_slug
  UNION ALL SELECT 'ai_ml_engineer' AS career_code, 'machine-learning-ops' AS skill_slug
  UNION ALL SELECT 'cybersecurity_analyst' AS career_code, 'network-security' AS skill_slug
  UNION ALL SELECT 'cybersecurity_analyst' AS career_code, 'cybersecurity-fundamentals' AS skill_slug
  UNION ALL SELECT 'cybersecurity_analyst' AS career_code, 'linux' AS skill_slug
  UNION ALL SELECT 'cybersecurity_analyst' AS career_code, 'cryptography' AS skill_slug
  UNION ALL SELECT 'cybersecurity_analyst' AS career_code, 'networking' AS skill_slug
  UNION ALL SELECT 'security_engineer' AS career_code, 'cybersecurity-fundamentals' AS skill_slug
  UNION ALL SELECT 'security_engineer' AS career_code, 'network-security' AS skill_slug
  UNION ALL SELECT 'security_engineer' AS career_code, 'linux' AS skill_slug
  UNION ALL SELECT 'security_engineer' AS career_code, 'cryptography' AS skill_slug
  UNION ALL SELECT 'security_engineer' AS career_code, 'cloud-computing' AS skill_slug
  UNION ALL SELECT 'ui_ux_designer' AS career_code, 'ui-ux-design' AS skill_slug
  UNION ALL SELECT 'ui_ux_designer' AS career_code, 'figma' AS skill_slug
  UNION ALL SELECT 'ui_ux_designer' AS career_code, 'graphic-design' AS skill_slug
  UNION ALL SELECT 'ui_ux_designer' AS career_code, 'accessibility' AS skill_slug
  UNION ALL SELECT 'ui_ux_designer' AS career_code, 'communication' AS skill_slug
  UNION ALL SELECT 'product_designer' AS career_code, 'ui-ux-design' AS skill_slug
  UNION ALL SELECT 'product_designer' AS career_code, 'figma' AS skill_slug
  UNION ALL SELECT 'product_designer' AS career_code, 'graphic-design' AS skill_slug
  UNION ALL SELECT 'product_designer' AS career_code, 'accessibility' AS skill_slug
  UNION ALL SELECT 'product_designer' AS career_code, 'research-methodology' AS skill_slug
  UNION ALL SELECT 'graphic_designer' AS career_code, 'graphic-design' AS skill_slug
  UNION ALL SELECT 'graphic_designer' AS career_code, 'figma' AS skill_slug
  UNION ALL SELECT 'graphic_designer' AS career_code, 'storytelling' AS skill_slug
  UNION ALL SELECT 'graphic_designer' AS career_code, 'video-editing' AS skill_slug
  UNION ALL SELECT 'business_analyst' AS career_code, 'business-analysis' AS skill_slug
  UNION ALL SELECT 'business_analyst' AS career_code, 'requirements-gathering' AS skill_slug
  UNION ALL SELECT 'business_analyst' AS career_code, 'excel' AS skill_slug
  UNION ALL SELECT 'business_analyst' AS career_code, 'communication' AS skill_slug
  UNION ALL SELECT 'business_analyst' AS career_code, 'critical-thinking' AS skill_slug
  UNION ALL SELECT 'product_manager' AS career_code, 'product-management' AS skill_slug
  UNION ALL SELECT 'product_manager' AS career_code, 'product-strategy' AS skill_slug
  UNION ALL SELECT 'product_manager' AS career_code, 'project-management' AS skill_slug
  UNION ALL SELECT 'product_manager' AS career_code, 'communication' AS skill_slug
  UNION ALL SELECT 'product_manager' AS career_code, 'market-research' AS skill_slug
  UNION ALL SELECT 'entrepreneur' AS career_code, 'entrepreneurial-thinking' AS skill_slug
  UNION ALL SELECT 'entrepreneur' AS career_code, 'market-research' AS skill_slug
  UNION ALL SELECT 'entrepreneur' AS career_code, 'financial-analysis' AS skill_slug
  UNION ALL SELECT 'entrepreneur' AS career_code, 'public-speaking' AS skill_slug
  UNION ALL SELECT 'entrepreneur' AS career_code, 'project-management' AS skill_slug
  UNION ALL SELECT 'startup_founder' AS career_code, 'entrepreneurial-thinking' AS skill_slug
  UNION ALL SELECT 'startup_founder' AS career_code, 'market-research' AS skill_slug
  UNION ALL SELECT 'startup_founder' AS career_code, 'financial-analysis' AS skill_slug
  UNION ALL SELECT 'startup_founder' AS career_code, 'sales' AS skill_slug
  UNION ALL SELECT 'startup_founder' AS career_code, 'leadership' AS skill_slug
  UNION ALL SELECT 'mechanical_engineer' AS career_code, 'cad' AS skill_slug
  UNION ALL SELECT 'mechanical_engineer' AS career_code, 'solidworks' AS skill_slug
  UNION ALL SELECT 'mechanical_engineer' AS career_code, 'finite-element-analysis' AS skill_slug
  UNION ALL SELECT 'mechanical_engineer' AS career_code, 'problem-solving' AS skill_slug
  UNION ALL SELECT 'mechanical_engineer' AS career_code, 'project-management' AS skill_slug
  UNION ALL SELECT 'electronics_engineer' AS career_code, 'electronics-circuits' AS skill_slug
  UNION ALL SELECT 'electronics_engineer' AS career_code, 'embedded-systems' AS skill_slug
  UNION ALL SELECT 'electronics_engineer' AS career_code, 'arduino' AS skill_slug
  UNION ALL SELECT 'electronics_engineer' AS career_code, 'iot' AS skill_slug
  UNION ALL SELECT 'electronics_engineer' AS career_code, 'problem-solving' AS skill_slug
  UNION ALL SELECT 'robotics_engineer' AS career_code, 'robotics' AS skill_slug
  UNION ALL SELECT 'robotics_engineer' AS career_code, 'embedded-systems' AS skill_slug
  UNION ALL SELECT 'robotics_engineer' AS career_code, 'arduino' AS skill_slug
  UNION ALL SELECT 'robotics_engineer' AS career_code, 'electronics-circuits' AS skill_slug
  UNION ALL SELECT 'robotics_engineer' AS career_code, 'matlab' AS skill_slug
  UNION ALL SELECT 'civil_engineer' AS career_code, 'autocad' AS skill_slug
  UNION ALL SELECT 'civil_engineer' AS career_code, 'structural-analysis' AS skill_slug
  UNION ALL SELECT 'civil_engineer' AS career_code, 'project-management' AS skill_slug
  UNION ALL SELECT 'civil_engineer' AS career_code, 'communication' AS skill_slug
  UNION ALL SELECT 'renewable_energy_engineer' AS career_code, 'renewable-energy' AS skill_slug
  UNION ALL SELECT 'renewable_energy_engineer' AS career_code, 'electronics-circuits' AS skill_slug
  UNION ALL SELECT 'renewable_energy_engineer' AS career_code, 'sustainability' AS skill_slug
  UNION ALL SELECT 'renewable_energy_engineer' AS career_code, 'data-visualization' AS skill_slug
  UNION ALL SELECT 'research_scientist' AS career_code, 'research-methodology' AS skill_slug
  UNION ALL SELECT 'research_scientist' AS career_code, 'academic-writing' AS skill_slug
  UNION ALL SELECT 'research_scientist' AS career_code, 'literature-review' AS skill_slug
  UNION ALL SELECT 'research_scientist' AS career_code, 'statistics' AS skill_slug
  UNION ALL SELECT 'research_scientist' AS career_code, 'scientific-communication' AS skill_slug
  UNION ALL SELECT 'research_assistant' AS career_code, 'research-methodology' AS skill_slug
  UNION ALL SELECT 'research_assistant' AS career_code, 'literature-review' AS skill_slug
  UNION ALL SELECT 'research_assistant' AS career_code, 'academic-writing' AS skill_slug
  UNION ALL SELECT 'research_assistant' AS career_code, 'research-data-analysis' AS skill_slug
  UNION ALL SELECT 'marketing_specialist' AS career_code, 'digital-marketing' AS skill_slug
  UNION ALL SELECT 'marketing_specialist' AS career_code, 'seo' AS skill_slug
  UNION ALL SELECT 'marketing_specialist' AS career_code, 'content-strategy' AS skill_slug
  UNION ALL SELECT 'marketing_specialist' AS career_code, 'market-research' AS skill_slug
  UNION ALL SELECT 'marketing_specialist' AS career_code, 'communication' AS skill_slug
  UNION ALL SELECT 'digital_marketer' AS career_code, 'digital-marketing' AS skill_slug
  UNION ALL SELECT 'digital_marketer' AS career_code, 'seo' AS skill_slug
  UNION ALL SELECT 'digital_marketer' AS career_code, 'data-visualization' AS skill_slug
  UNION ALL SELECT 'digital_marketer' AS career_code, 'content-strategy' AS skill_slug
  UNION ALL SELECT 'digital_marketer' AS career_code, 'market-research' AS skill_slug
  UNION ALL SELECT 'content_creator' AS career_code, 'content-strategy' AS skill_slug
  UNION ALL SELECT 'content_creator' AS career_code, 'storytelling' AS skill_slug
  UNION ALL SELECT 'content_creator' AS career_code, 'video-editing' AS skill_slug
  UNION ALL SELECT 'content_creator' AS career_code, 'graphic-design' AS skill_slug
  UNION ALL SELECT 'content_creator' AS career_code, 'communication' AS skill_slug
  UNION ALL SELECT 'finance_professional' AS career_code, 'accounting' AS skill_slug
  UNION ALL SELECT 'finance_professional' AS career_code, 'financial-analysis' AS skill_slug
  UNION ALL SELECT 'finance_professional' AS career_code, 'excel' AS skill_slug
  UNION ALL SELECT 'finance_professional' AS career_code, 'risk-management' AS skill_slug
  UNION ALL SELECT 'finance_professional' AS career_code, 'statistics' AS skill_slug
  UNION ALL SELECT 'financial_analyst' AS career_code, 'financial-analysis' AS skill_slug
  UNION ALL SELECT 'financial_analyst' AS career_code, 'accounting' AS skill_slug
  UNION ALL SELECT 'financial_analyst' AS career_code, 'excel' AS skill_slug
  UNION ALL SELECT 'financial_analyst' AS career_code, 'statistics' AS skill_slug
  UNION ALL SELECT 'financial_analyst' AS career_code, 'risk-management' AS skill_slug
  UNION ALL SELECT 'accountant' AS career_code, 'accounting' AS skill_slug
  UNION ALL SELECT 'accountant' AS career_code, 'excel' AS skill_slug
  UNION ALL SELECT 'accountant' AS career_code, 'communication' AS skill_slug
  UNION ALL SELECT 'hr_professional' AS career_code, 'hr-management' AS skill_slug
  UNION ALL SELECT 'hr_professional' AS career_code, 'communication' AS skill_slug
  UNION ALL SELECT 'hr_professional' AS career_code, 'active-listening' AS skill_slug
  UNION ALL SELECT 'hr_professional' AS career_code, 'negotiation' AS skill_slug
  UNION ALL SELECT 'recruiter' AS career_code, 'hr-management' AS skill_slug
  UNION ALL SELECT 'recruiter' AS career_code, 'active-listening' AS skill_slug
  UNION ALL SELECT 'recruiter' AS career_code, 'communication' AS skill_slug
  UNION ALL SELECT 'recruiter' AS career_code, 'negotiation' AS skill_slug
  UNION ALL SELECT 'social_impact_professional' AS career_code, 'community-outreach' AS skill_slug
  UNION ALL SELECT 'social_impact_professional' AS career_code, 'communication' AS skill_slug
  UNION ALL SELECT 'social_impact_professional' AS career_code, 'project-management' AS skill_slug
  UNION ALL SELECT 'social_impact_professional' AS career_code, 'public-speaking' AS skill_slug
  UNION ALL SELECT 'ngo_program_coordinator' AS career_code, 'community-outreach' AS skill_slug
  UNION ALL SELECT 'ngo_program_coordinator' AS career_code, 'project-management' AS skill_slug
  UNION ALL SELECT 'ngo_program_coordinator' AS career_code, 'event-management' AS skill_slug
  UNION ALL SELECT 'ngo_program_coordinator' AS career_code, 'communication' AS skill_slug
  UNION ALL SELECT 'sustainability_professional' AS career_code, 'sustainability' AS skill_slug
  UNION ALL SELECT 'sustainability_professional' AS career_code, 'climate-literacy' AS skill_slug
  UNION ALL SELECT 'sustainability_professional' AS career_code, 'waste-management' AS skill_slug
  UNION ALL SELECT 'sustainability_professional' AS career_code, 'data-visualization' AS skill_slug
  UNION ALL SELECT 'sustainability_analyst' AS career_code, 'sustainability' AS skill_slug
  UNION ALL SELECT 'sustainability_analyst' AS career_code, 'climate-literacy' AS skill_slug
  UNION ALL SELECT 'sustainability_analyst' AS career_code, 'data-visualization' AS skill_slug
  UNION ALL SELECT 'sustainability_analyst' AS career_code, 'excel' AS skill_slug
  UNION ALL SELECT 'healthcare_program_manager' AS career_code, 'communication' AS skill_slug
  UNION ALL SELECT 'healthcare_program_manager' AS career_code, 'project-management' AS skill_slug
  UNION ALL SELECT 'healthcare_program_manager' AS career_code, 'health-informatics' AS skill_slug
  UNION ALL SELECT 'healthcare_program_manager' AS career_code, 'data-ethics' AS skill_slug
  UNION ALL SELECT 'healthcare_data_analyst' AS career_code, 'health-informatics' AS skill_slug
  UNION ALL SELECT 'healthcare_data_analyst' AS career_code, 'sql-databases' AS skill_slug
  UNION ALL SELECT 'healthcare_data_analyst' AS career_code, 'data-visualization' AS skill_slug
  UNION ALL SELECT 'healthcare_data_analyst' AS career_code, 'statistics' AS skill_slug
  UNION ALL SELECT 'operations_analyst' AS career_code, 'operations-management' AS skill_slug
  UNION ALL SELECT 'operations_analyst' AS career_code, 'excel' AS skill_slug
  UNION ALL SELECT 'operations_analyst' AS career_code, 'data-visualization' AS skill_slug
  UNION ALL SELECT 'operations_analyst' AS career_code, 'critical-thinking' AS skill_slug
) x JOIN career_paths c ON c.career_code=x.career_code JOIN skills s ON s.skill_slug=x.skill_slug JOIN proficiency_levels pl ON pl.level_code="intermediate";

INSERT IGNORE INTO interests (interest_slug, interest_name, domain_id)
SELECT x.interest_slug, x.interest_name, d.domain_id FROM (
  SELECT 'software-development' AS interest_slug, 'Software Development' AS interest_name, 'it_software' AS domain_code
  UNION ALL SELECT 'artificial-intelligence' AS interest_slug, 'Artificial Intelligence' AS interest_name, 'data_analytics' AS domain_code
  UNION ALL SELECT 'data-science' AS interest_slug, 'Data Science' AS interest_name, 'data_analytics' AS domain_code
  UNION ALL SELECT 'cybersecurity' AS interest_slug, 'Cybersecurity' AS interest_name, 'security' AS domain_code
  UNION ALL SELECT 'ui-ux' AS interest_slug, 'UI/UX Design' AS interest_name, 'design' AS domain_code
  UNION ALL SELECT 'robotics' AS interest_slug, 'Robotics' AS interest_name, 'engineering' AS domain_code
  UNION ALL SELECT 'electronics' AS interest_slug, 'Electronics' AS interest_name, 'engineering' AS domain_code
  UNION ALL SELECT 'mechanical-design' AS interest_slug, 'Mechanical Design' AS interest_name, 'engineering' AS domain_code
  UNION ALL SELECT 'civil-infrastructure' AS interest_slug, 'Civil Infrastructure' AS interest_name, 'engineering' AS domain_code
  UNION ALL SELECT 'entrepreneurship' AS interest_slug, 'Entrepreneurship' AS interest_name, 'entrepreneurship' AS domain_code
  UNION ALL SELECT 'marketing' AS interest_slug, 'Marketing' AS interest_name, 'marketing' AS domain_code
  UNION ALL SELECT 'finance' AS interest_slug, 'Finance' AS interest_name, 'finance' AS domain_code
  UNION ALL SELECT 'human-resources' AS interest_slug, 'Human Resources' AS interest_name, 'hr' AS domain_code
  UNION ALL SELECT 'scientific-research' AS interest_slug, 'Scientific Research' AS interest_name, 'science_research' AS domain_code
  UNION ALL SELECT 'community-service' AS interest_slug, 'Community Service' AS interest_name, 'social_impact' AS domain_code
  UNION ALL SELECT 'environment' AS interest_slug, 'Environment and Climate' AS interest_name, 'sustainability' AS domain_code
  UNION ALL SELECT 'healthcare-innovation' AS interest_slug, 'Healthcare Innovation' AS interest_name, 'healthcare_nonclinical' AS domain_code
  UNION ALL SELECT 'public-speaking' AS interest_slug, 'Public Speaking' AS interest_name, 'business' AS domain_code
  UNION ALL SELECT 'leadership' AS interest_slug, 'Leadership' AS interest_name, 'business' AS domain_code
  UNION ALL SELECT 'creative-media' AS interest_slug, 'Creative Media' AS interest_name, 'design' AS domain_code
  UNION ALL SELECT 'product-management' AS interest_slug, 'Product Management' AS interest_name, 'business' AS domain_code
  UNION ALL SELECT 'open-source' AS interest_slug, 'Open Source' AS interest_name, 'it_software' AS domain_code
  UNION ALL SELECT 'competitive-programming' AS interest_slug, 'Competitive Programming' AS interest_name, 'it_software' AS domain_code
  UNION ALL SELECT 'volunteering' AS interest_slug, 'Volunteering' AS interest_name, 'social_impact' AS domain_code
) x JOIN domains d ON d.domain_code=x.domain_code;

INSERT IGNORE INTO learning_resources (resource_type_id, title, provider_name, resource_url, description, is_free, is_active)
SELECT rt.resource_type_id, x.title, x.provider_name, x.resource_url, x.description, x.is_free, 1 FROM (
  SELECT 'Python official tutorial' AS title, 'Python.org' AS provider_name, 'https://docs.python.org/3/tutorial/' AS resource_url, 'Official Python tutorial; free documentation' AS description, 1 AS is_free, 'course' AS resource_type_code, 'python' AS skill_slug
  UNION ALL SELECT 'Java tutorials' AS title, 'Oracle' AS provider_name, 'https://docs.oracle.com/javase/tutorial/' AS resource_url, 'Java language and platform tutorials' AS description, 1 AS is_free, 'course' AS resource_type_code, 'java' AS skill_slug
  UNION ALL SELECT 'MDN Web Docs' AS title, 'MDN' AS provider_name, 'https://developer.mozilla.org/' AS resource_url, 'HTML, CSS and JavaScript documentation' AS description, 1 AS is_free, 'article' AS resource_type_code, 'javascript' AS skill_slug
  UNION ALL SELECT 'React Learn' AS title, 'React' AS provider_name, 'https://react.dev/learn' AS resource_url, 'React official learning guide' AS description, 1 AS is_free, 'course' AS resource_type_code, 'react' AS skill_slug
  UNION ALL SELECT 'GitHub Skills' AS title, 'GitHub' AS provider_name, 'https://skills.github.com/' AS resource_url, 'Interactive GitHub learning exercises' AS description, 1 AS is_free, 'practice' AS resource_type_code, 'git-github' AS skill_slug
  UNION ALL SELECT 'SQLBolt' AS title, 'SQLBolt' AS provider_name, 'https://sqlbolt.com/' AS resource_url, 'Interactive SQL lessons' AS description, 1 AS is_free, 'practice' AS resource_type_code, 'sql-databases' AS skill_slug
  UNION ALL SELECT 'Kaggle Learn' AS title, 'Kaggle' AS provider_name, 'https://www.kaggle.com/learn' AS resource_url, 'Data science micro-courses and practice' AS description, 1 AS is_free, 'course' AS resource_type_code, 'data-visualization' AS skill_slug
  UNION ALL SELECT 'Google Machine Learning Crash Course' AS title, 'Google' AS provider_name, 'https://developers.google.com/machine-learning/crash-course' AS resource_url, 'Machine learning lessons and exercises' AS description, 1 AS is_free, 'course' AS resource_type_code, 'machine-learning' AS skill_slug
  UNION ALL SELECT 'Cisco Networking Academy' AS title, 'Cisco' AS provider_name, 'https://www.netacad.com/' AS resource_url, 'Networking and cybersecurity learning catalogue; availability varies' AS description, 1 AS is_free, 'course' AS resource_type_code, 'networking' AS skill_slug
  UNION ALL SELECT 'OWASP Web Security Testing Guide' AS title, 'OWASP' AS provider_name, 'https://owasp.org/www-project-web-security-testing-guide/' AS resource_url, 'Web application security testing guide' AS description, 1 AS is_free, 'article' AS resource_type_code, 'cybersecurity-fundamentals' AS skill_slug
  UNION ALL SELECT 'Figma Learn' AS title, 'Figma' AS provider_name, 'https://help.figma.com/hc/en-us/categories/360002051613-Learn-design' AS resource_url, 'Design and prototyping learning resources' AS description, 1 AS is_free, 'course' AS resource_type_code, 'figma' AS skill_slug
  UNION ALL SELECT 'Canva Design School' AS title, 'Canva' AS provider_name, 'https://www.canva.com/designschool/' AS resource_url, 'Visual design learning resources' AS description, 1 AS is_free, 'course' AS resource_type_code, 'graphic-design' AS skill_slug
  UNION ALL SELECT 'Microsoft Learn Power BI' AS title, 'Microsoft' AS provider_name, 'https://learn.microsoft.com/power-bi/' AS resource_url, 'Power BI learning paths and documentation' AS description, 1 AS is_free, 'course' AS resource_type_code, 'power-bi' AS skill_slug
  UNION ALL SELECT 'Tableau Training' AS title, 'Tableau' AS provider_name, 'https://www.tableau.com/learn/training' AS resource_url, 'Tableau learning and training resources' AS description, 1 AS is_free, 'course' AS resource_type_code, 'tableau' AS skill_slug
  UNION ALL SELECT 'R for Data Science' AS title, 'R4DS' AS provider_name, 'https://r4ds.hadley.nz/' AS resource_url, 'Free online book for data science with R' AS description, 1 AS is_free, 'book' AS resource_type_code, 'r-programming' AS skill_slug
  UNION ALL SELECT 'NASA Climate Change' AS title, 'NASA' AS provider_name, 'https://climate.nasa.gov/' AS resource_url, 'Climate science learning resource' AS description, 1 AS is_free, 'article' AS resource_type_code, 'climate-literacy' AS skill_slug
  UNION ALL SELECT 'MIT OpenCourseWare' AS title, 'MIT' AS provider_name, 'https://ocw.mit.edu/' AS resource_url, 'Open course materials; course terms vary' AS description, 1 AS is_free, 'course' AS resource_type_code, 'research-methodology' AS skill_slug
  UNION ALL SELECT 'Coursera catalogue' AS title, 'Coursera' AS provider_name, 'https://www.coursera.org/' AS resource_url, 'Course directory; free access varies by course' AS description, 0 AS is_free, 'course' AS resource_type_code, 'problem-solving' AS skill_slug
  UNION ALL SELECT 'edX catalogue' AS title, 'edX' AS provider_name, 'https://www.edx.org/' AS resource_url, 'Course directory; free access varies by course' AS description, 0 AS is_free, 'course' AS resource_type_code, 'critical-thinking' AS skill_slug
  UNION ALL SELECT 'freeCodeCamp' AS title, 'freeCodeCamp' AS provider_name, 'https://www.freecodecamp.org/' AS resource_url, 'Free coding curriculum and projects' AS description, 1 AS is_free, 'practice' AS resource_type_code, 'javascript' AS skill_slug
  UNION ALL SELECT 'HackerRank' AS title, 'HackerRank' AS provider_name, 'https://www.hackerrank.com/' AS resource_url, 'Programming and technical practice' AS description, 1 AS is_free, 'practice' AS resource_type_code, 'dsa' AS skill_slug
  UNION ALL SELECT 'LeetCode' AS title, 'LeetCode' AS provider_name, 'https://leetcode.com/' AS resource_url, 'Algorithm and coding practice; access tiers vary' AS description, 0 AS is_free, 'practice' AS resource_type_code, 'dsa' AS skill_slug
  UNION ALL SELECT 'roadmap.sh' AS title, 'roadmap.sh' AS provider_name, 'https://roadmap.sh/' AS resource_url, 'Community roadmaps for software and technology skills' AS description, 1 AS is_free, 'article' AS resource_type_code, 'programming-general' AS skill_slug
  UNION ALL SELECT 'HubSpot Academy' AS title, 'HubSpot' AS provider_name, 'https://academy.hubspot.com/' AS resource_url, 'Marketing and business courses; offerings may change' AS description, 1 AS is_free, 'course' AS resource_type_code, 'digital-marketing' AS skill_slug
  UNION ALL SELECT 'Y Combinator Startup School' AS title, 'Y Combinator' AS provider_name, 'https://www.startupschool.org/' AS resource_url, 'Startup learning resources; availability may change' AS description, 1 AS is_free, 'course' AS resource_type_code, 'entrepreneurial-thinking' AS skill_slug
) x JOIN resource_types rt ON rt.type_code=x.resource_type_code
WHERE NOT EXISTS (SELECT 1 FROM learning_resources existing WHERE existing.title=x.title AND existing.provider_name=x.provider_name);

INSERT IGNORE INTO skill_learning_resources (skill_id, resource_id)
SELECT s.skill_id, r.resource_id FROM (
  SELECT 'python' AS skill_slug, 'Python official tutorial' AS resource_title
  UNION ALL SELECT 'java' AS skill_slug, 'Java tutorials' AS resource_title
  UNION ALL SELECT 'javascript' AS skill_slug, 'MDN Web Docs' AS resource_title
  UNION ALL SELECT 'react' AS skill_slug, 'React Learn' AS resource_title
  UNION ALL SELECT 'git-github' AS skill_slug, 'GitHub Skills' AS resource_title
  UNION ALL SELECT 'sql-databases' AS skill_slug, 'SQLBolt' AS resource_title
  UNION ALL SELECT 'data-visualization' AS skill_slug, 'Kaggle Learn' AS resource_title
  UNION ALL SELECT 'machine-learning' AS skill_slug, 'Google Machine Learning Crash Course' AS resource_title
  UNION ALL SELECT 'networking' AS skill_slug, 'Cisco Networking Academy' AS resource_title
  UNION ALL SELECT 'cybersecurity-fundamentals' AS skill_slug, 'OWASP Web Security Testing Guide' AS resource_title
  UNION ALL SELECT 'figma' AS skill_slug, 'Figma Learn' AS resource_title
  UNION ALL SELECT 'graphic-design' AS skill_slug, 'Canva Design School' AS resource_title
  UNION ALL SELECT 'power-bi' AS skill_slug, 'Microsoft Learn Power BI' AS resource_title
  UNION ALL SELECT 'tableau' AS skill_slug, 'Tableau Training' AS resource_title
  UNION ALL SELECT 'r-programming' AS skill_slug, 'R for Data Science' AS resource_title
  UNION ALL SELECT 'climate-literacy' AS skill_slug, 'NASA Climate Change' AS resource_title
  UNION ALL SELECT 'research-methodology' AS skill_slug, 'MIT OpenCourseWare' AS resource_title
  UNION ALL SELECT 'problem-solving' AS skill_slug, 'Coursera catalogue' AS resource_title
  UNION ALL SELECT 'critical-thinking' AS skill_slug, 'edX catalogue' AS resource_title
  UNION ALL SELECT 'javascript' AS skill_slug, 'freeCodeCamp' AS resource_title
  UNION ALL SELECT 'dsa' AS skill_slug, 'HackerRank' AS resource_title
  UNION ALL SELECT 'dsa' AS skill_slug, 'LeetCode' AS resource_title
  UNION ALL SELECT 'programming-general' AS skill_slug, 'roadmap.sh' AS resource_title
  UNION ALL SELECT 'digital-marketing' AS skill_slug, 'HubSpot Academy' AS resource_title
  UNION ALL SELECT 'entrepreneurial-thinking' AS skill_slug, 'Y Combinator Startup School' AS resource_title
) x JOIN skills s ON s.skill_slug=x.skill_slug JOIN learning_resources r ON r.title=x.resource_title;

-- Official opportunity directories. These are evergreen discovery links, not dated event claims.
INSERT IGNORE INTO organizers (organizer_name, website_url, is_sample) VALUES
  ('Devpost', 'https://devpost.com/hackathons', 0),
  ('Unstop', 'https://unstop.com/', 0),
  ('Smart India Hackathon', 'https://www.sih.gov.in/', 0),
  ('Major League Hacking', 'https://mlh.io/seasons/2026/events', 0),
  ('Google', 'https://summerofcode.withgoogle.com/', 0),
  ('Google Developer Groups', 'https://developers.google.com/community/gdg', 0),
  ('Microsoft', 'https://learn.microsoft.com/events/', 0),
  ('Amazon Web Services', 'https://aws.amazon.com/events/', 0),
  ('Kaggle', 'https://www.kaggle.com/competitions', 0),
  ('HackerRank', 'https://www.hackerrank.com/contests', 0),
  ('Codeforces', 'https://codeforces.com/contests', 0),
  ('LeetCode', 'https://leetcode.com/contest/', 0),
  ('NASA', 'https://www.spaceappschallenge.org/', 0),
  ('IEEE', 'https://www.ieee.org/conferences/', 0),
  ('ACM', 'https://www.acm.org/conferences', 0),
  ('Internshala', 'https://internshala.com/internships/', 0),
  ('AICTE', 'https://internship.aicte-india.org/', 0),
  ('National Career Service', 'https://www.ncs.gov.in/', 0),
  ('IndiaBioscience', 'https://indiabioscience.org/', 0),
  ('SINE IIT Bombay', 'https://sineiitb.org/', 0),
  ('Volunteer India', 'https://www.volunteerindia.org/', 0),
  ('Give.do', 'https://give.do/', 0),
  ('Climate Action Network', 'https://climatenetwork.org/', 0),
  ('Figma', 'https://www.figma.com/community', 0),
  ('Coursera', 'https://www.coursera.org/', 0),
  ('edX', 'https://www.edx.org/', 0),
  ('freeCodeCamp', 'https://www.freecodecamp.org/learn/', 0),
  ('Cisco Networking Academy', 'https://www.netacad.com/', 0),
  ('Google Cloud', 'https://www.cloudskillsboost.google/', 0),
  ('Microsoft Learn', 'https://learn.microsoft.com/training/', 0);

INSERT IGNORE INTO activity_sources (source_type_id, source_name, source_website, is_enabled, sync_status)
SELECT st.source_type_id, x.source_name, x.source_website, 0, "disabled" FROM (
  SELECT 'Opportunity Directory - Devpost' AS source_name, 'https://devpost.com/hackathons' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Unstop' AS source_name, 'https://unstop.com/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Smart India Hackathon' AS source_name, 'https://www.sih.gov.in/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Major League Hacking' AS source_name, 'https://mlh.io/seasons/2026/events' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Google' AS source_name, 'https://summerofcode.withgoogle.com/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Google Developer Groups' AS source_name, 'https://developers.google.com/community/gdg' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Microsoft' AS source_name, 'https://learn.microsoft.com/events/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Amazon Web Services' AS source_name, 'https://aws.amazon.com/events/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Kaggle' AS source_name, 'https://www.kaggle.com/competitions' AS source_website
  UNION ALL SELECT 'Opportunity Directory - HackerRank' AS source_name, 'https://www.hackerrank.com/contests' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Codeforces' AS source_name, 'https://codeforces.com/contests' AS source_website
  UNION ALL SELECT 'Opportunity Directory - LeetCode' AS source_name, 'https://leetcode.com/contest/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - NASA' AS source_name, 'https://www.spaceappschallenge.org/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - IEEE' AS source_name, 'https://www.ieee.org/conferences/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - ACM' AS source_name, 'https://www.acm.org/conferences' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Internshala' AS source_name, 'https://internshala.com/internships/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - AICTE' AS source_name, 'https://internship.aicte-india.org/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - National Career Service' AS source_name, 'https://www.ncs.gov.in/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - IndiaBioscience' AS source_name, 'https://indiabioscience.org/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - SINE IIT Bombay' AS source_name, 'https://sineiitb.org/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Volunteer India' AS source_name, 'https://www.volunteerindia.org/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Give.do' AS source_name, 'https://give.do/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Climate Action Network' AS source_name, 'https://climatenetwork.org/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Figma' AS source_name, 'https://www.figma.com/community' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Coursera' AS source_name, 'https://www.coursera.org/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - edX' AS source_name, 'https://www.edx.org/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - freeCodeCamp' AS source_name, 'https://www.freecodecamp.org/learn/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Cisco Networking Academy' AS source_name, 'https://www.netacad.com/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Google Cloud' AS source_name, 'https://www.cloudskillsboost.google/' AS source_website
  UNION ALL SELECT 'Opportunity Directory - Microsoft Learn' AS source_name, 'https://learn.microsoft.com/training/' AS source_website
) x JOIN source_types st ON st.type_code="manual_admin";

INSERT IGNORE INTO activities
(activity_type_id, category_id, domain_id, mode_id, organizer_id, source_id, activity_status_id, verification_status_id, title, description, country, registration_url, official_source_url, external_activity_id, is_sample)
SELECT t.activity_type_id, cat.category_id, d.domain_id, m.mode_id, o.organizer_id, src.source_id, ast.activity_status_id, vs.verification_status_id, x.title, x.description, "India", x.url, x.url, x.external_id, 0
FROM (
  SELECT 'hackathon' AS type_code, 'technical' AS category_code, 'it_software' AS domain_code, 'Opportunity Directory - Devpost' AS source_name, 'Devpost' AS organizer_name, 'Opportunity directory: Devpost' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://devpost.com/hackathons' AS url, 'directory-devpost' AS external_id
  UNION ALL SELECT 'non_technical_competition' AS type_code, 'learning' AS category_code, 'other' AS domain_code, 'Opportunity Directory - Unstop' AS source_name, 'Unstop' AS organizer_name, 'Opportunity directory: Unstop' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://unstop.com/' AS url, 'directory-unstop' AS external_id
  UNION ALL SELECT 'hackathon' AS type_code, 'technical' AS category_code, 'it_software' AS domain_code, 'Opportunity Directory - Smart India Hackathon' AS source_name, 'Smart India Hackathon' AS organizer_name, 'Opportunity directory: Smart India Hackathon' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://www.sih.gov.in/' AS url, 'directory-smart-india-hackathon' AS external_id
  UNION ALL SELECT 'hackathon' AS type_code, 'technical' AS category_code, 'it_software' AS domain_code, 'Opportunity Directory - Major League Hacking' AS source_name, 'Major League Hacking' AS organizer_name, 'Opportunity directory: Major League Hacking' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://mlh.io/seasons/2026/events' AS url, 'directory-major-league-hacking' AS external_id
  UNION ALL SELECT 'internship' AS type_code, 'career' AS category_code, 'it_software' AS domain_code, 'Opportunity Directory - Google' AS source_name, 'Google' AS organizer_name, 'Opportunity directory: Google' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://summerofcode.withgoogle.com/' AS url, 'directory-google' AS external_id
  UNION ALL SELECT 'workshop' AS type_code, 'learning' AS category_code, 'it_software' AS domain_code, 'Opportunity Directory - Google Developer Groups' AS source_name, 'Google Developer Groups' AS organizer_name, 'Opportunity directory: Google Developer Groups' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://developers.google.com/community/gdg' AS url, 'directory-google-developer-groups' AS external_id
  UNION ALL SELECT 'webinar' AS type_code, 'learning' AS category_code, 'other' AS domain_code, 'Opportunity Directory - Microsoft' AS source_name, 'Microsoft' AS organizer_name, 'Opportunity directory: Microsoft' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://learn.microsoft.com/events/' AS url, 'directory-microsoft' AS external_id
  UNION ALL SELECT 'conference' AS type_code, 'learning' AS category_code, 'other' AS domain_code, 'Opportunity Directory - Amazon Web Services' AS source_name, 'Amazon Web Services' AS organizer_name, 'Opportunity directory: Amazon Web Services' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://aws.amazon.com/events/' AS url, 'directory-amazon-web-services' AS external_id
  UNION ALL SELECT 'technical_competition' AS type_code, 'technical' AS category_code, 'data_analytics' AS domain_code, 'Opportunity Directory - Kaggle' AS source_name, 'Kaggle' AS organizer_name, 'Opportunity directory: Kaggle' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://www.kaggle.com/competitions' AS url, 'directory-kaggle' AS external_id
  UNION ALL SELECT 'coding_competition' AS type_code, 'technical' AS category_code, 'it_software' AS domain_code, 'Opportunity Directory - HackerRank' AS source_name, 'HackerRank' AS organizer_name, 'Opportunity directory: HackerRank' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://www.hackerrank.com/contests' AS url, 'directory-hackerrank' AS external_id
  UNION ALL SELECT 'coding_competition' AS type_code, 'technical' AS category_code, 'it_software' AS domain_code, 'Opportunity Directory - Codeforces' AS source_name, 'Codeforces' AS organizer_name, 'Opportunity directory: Codeforces' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://codeforces.com/contests' AS url, 'directory-codeforces' AS external_id
  UNION ALL SELECT 'coding_competition' AS type_code, 'technical' AS category_code, 'it_software' AS domain_code, 'Opportunity Directory - LeetCode' AS source_name, 'LeetCode' AS organizer_name, 'Opportunity directory: LeetCode' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://leetcode.com/contest/' AS url, 'directory-leetcode' AS external_id
  UNION ALL SELECT 'technical_competition' AS type_code, 'technical' AS category_code, 'other' AS domain_code, 'Opportunity Directory - NASA' AS source_name, 'NASA' AS organizer_name, 'Opportunity directory: NASA' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://www.spaceappschallenge.org/' AS url, 'directory-nasa' AS external_id
  UNION ALL SELECT 'conference' AS type_code, 'learning' AS category_code, 'other' AS domain_code, 'Opportunity Directory - IEEE' AS source_name, 'IEEE' AS organizer_name, 'Opportunity directory: IEEE' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://www.ieee.org/conferences/' AS url, 'directory-ieee' AS external_id
  UNION ALL SELECT 'conference' AS type_code, 'learning' AS category_code, 'other' AS domain_code, 'Opportunity Directory - ACM' AS source_name, 'ACM' AS organizer_name, 'Opportunity directory: ACM' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://www.acm.org/conferences' AS url, 'directory-acm' AS external_id
  UNION ALL SELECT 'internship' AS type_code, 'career' AS category_code, 'it_software' AS domain_code, 'Opportunity Directory - Internshala' AS source_name, 'Internshala' AS organizer_name, 'Opportunity directory: Internshala' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://internshala.com/internships/' AS url, 'directory-internshala' AS external_id
  UNION ALL SELECT 'internship' AS type_code, 'career' AS category_code, 'it_software' AS domain_code, 'Opportunity Directory - AICTE' AS source_name, 'AICTE' AS organizer_name, 'Opportunity directory: AICTE' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://internship.aicte-india.org/' AS url, 'directory-aicte' AS external_id
  UNION ALL SELECT 'internship' AS type_code, 'career' AS category_code, 'it_software' AS domain_code, 'Opportunity Directory - National Career Service' AS source_name, 'National Career Service' AS organizer_name, 'Opportunity directory: National Career Service' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://www.ncs.gov.in/' AS url, 'directory-national-career-service' AS external_id
  UNION ALL SELECT 'research_opportunity' AS type_code, 'learning' AS category_code, 'other' AS domain_code, 'Opportunity Directory - IndiaBioscience' AS source_name, 'IndiaBioscience' AS organizer_name, 'Opportunity directory: IndiaBioscience' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://indiabioscience.org/' AS url, 'directory-indiabioscience' AS external_id
  UNION ALL SELECT 'entrepreneurship_event' AS type_code, 'learning' AS category_code, 'other' AS domain_code, 'Opportunity Directory - SINE IIT Bombay' AS source_name, 'SINE IIT Bombay' AS organizer_name, 'Opportunity directory: SINE IIT Bombay' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://sineiitb.org/' AS url, 'directory-sine-iit-bombay' AS external_id
  UNION ALL SELECT 'volunteering' AS type_code, 'social' AS category_code, 'other' AS domain_code, 'Opportunity Directory - Volunteer India' AS source_name, 'Volunteer India' AS organizer_name, 'Opportunity directory: Volunteer India' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://www.volunteerindia.org/' AS url, 'directory-volunteer-india' AS external_id
  UNION ALL SELECT 'volunteering' AS type_code, 'social' AS category_code, 'other' AS domain_code, 'Opportunity Directory - Give.do' AS source_name, 'Give.do' AS organizer_name, 'Opportunity directory: Give.do' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://give.do/' AS url, 'directory-givedo' AS external_id
  UNION ALL SELECT 'community_social_impact' AS type_code, 'social' AS category_code, 'other' AS domain_code, 'Opportunity Directory - Climate Action Network' AS source_name, 'Climate Action Network' AS organizer_name, 'Opportunity directory: Climate Action Network' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://climatenetwork.org/' AS url, 'directory-climate-action-network' AS external_id
  UNION ALL SELECT 'workshop' AS type_code, 'learning' AS category_code, 'it_software' AS domain_code, 'Opportunity Directory - Figma' AS source_name, 'Figma' AS organizer_name, 'Opportunity directory: Figma' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://www.figma.com/community' AS url, 'directory-figma' AS external_id
  UNION ALL SELECT 'course' AS type_code, 'career' AS category_code, 'data_analytics' AS domain_code, 'Opportunity Directory - Coursera' AS source_name, 'Coursera' AS organizer_name, 'Opportunity directory: Coursera' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://www.coursera.org/' AS url, 'directory-coursera' AS external_id
  UNION ALL SELECT 'course' AS type_code, 'career' AS category_code, 'data_analytics' AS domain_code, 'Opportunity Directory - edX' AS source_name, 'edX' AS organizer_name, 'Opportunity directory: edX' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://www.edx.org/' AS url, 'directory-edx' AS external_id
  UNION ALL SELECT 'course' AS type_code, 'career' AS category_code, 'other' AS domain_code, 'Opportunity Directory - freeCodeCamp' AS source_name, 'freeCodeCamp' AS organizer_name, 'Opportunity directory: freeCodeCamp' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://www.freecodecamp.org/learn/' AS url, 'directory-freecodecamp' AS external_id
  UNION ALL SELECT 'certification' AS type_code, 'career' AS category_code, 'it_software' AS domain_code, 'Opportunity Directory - Cisco Networking Academy' AS source_name, 'Cisco Networking Academy' AS organizer_name, 'Opportunity directory: Cisco Networking Academy' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://www.netacad.com/' AS url, 'directory-cisco-networking-academy' AS external_id
  UNION ALL SELECT 'training_program' AS type_code, 'career' AS category_code, 'data_analytics' AS domain_code, 'Opportunity Directory - Google Cloud' AS source_name, 'Google Cloud' AS organizer_name, 'Opportunity directory: Google Cloud' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://www.cloudskillsboost.google/' AS url, 'directory-google-cloud' AS external_id
  UNION ALL SELECT 'training_program' AS type_code, 'career' AS category_code, 'data_analytics' AS domain_code, 'Opportunity Directory - Microsoft Learn' AS source_name, 'Microsoft Learn' AS organizer_name, 'Opportunity directory: Microsoft Learn' AS title, 'Evergreen discovery link, not a dated event. Open the official organizer page to check current opportunities, eligibility, registration windows and deadlines. Dates and availability are not asserted by this database seed.' AS description, 'https://learn.microsoft.com/training/' AS url, 'directory-microsoft-learn' AS external_id
) x JOIN activity_types t ON t.type_code=x.type_code JOIN activity_categories cat ON cat.category_code=x.category_code LEFT JOIN domains d ON d.domain_code=x.domain_code JOIN activity_modes m ON m.mode_code="online" JOIN activity_sources src ON src.source_name=x.source_name JOIN organizers o ON o.organizer_name=x.organizer_name JOIN activity_statuses ast ON ast.status_code="published" JOIN verification_statuses vs ON vs.status_code="unverified";

INSERT IGNORE INTO activity_tags (tag_slug, tag_name) VALUES
  ('online', 'Online'),
  ('remote', 'Remote Friendly'),
  ('recurring', 'Recurring Opportunity'),
  ('directory-link', 'Opportunity Directory'),
  ('open-source', 'Open Source'),
  ('research', 'Research'),
  ('volunteer', 'Volunteer Opportunity'),
  ('career-growth', 'Career Growth'),
  ('portfolio', 'Portfolio Building'),
  ('beginner', 'Beginner'),
  ('student', 'Student Friendly');

-- Seeded assessment bank: single-choice questions with answer keys; PHP should score on the server.
INSERT IGNORE INTO assessments (skill_id, assessment_code, title, description, max_score, pass_score, is_active) SELECT s.skill_id, 'python-basics-v1', 'Python Fundamentals', 'Starter multiple-choice assessment. Extend with reviewed questions before high-stakes use.', 3.00, 2.00, 1 FROM skills s WHERE s.skill_slug='python';
INSERT IGNORE INTO assessments (skill_id, assessment_code, title, description, max_score, pass_score, is_active) SELECT s.skill_id, 'java-basics-v1', 'Java Fundamentals', 'Starter multiple-choice assessment. Extend with reviewed questions before high-stakes use.', 3.00, 2.00, 1 FROM skills s WHERE s.skill_slug='java';
INSERT IGNORE INTO assessments (skill_id, assessment_code, title, description, max_score, pass_score, is_active) SELECT s.skill_id, 'dsa-basics-v1', 'Data Structures and Algorithms', 'Starter multiple-choice assessment. Extend with reviewed questions before high-stakes use.', 3.00, 2.00, 1 FROM skills s WHERE s.skill_slug='dsa';
INSERT IGNORE INTO assessments (skill_id, assessment_code, title, description, max_score, pass_score, is_active) SELECT s.skill_id, 'sql-basics-v1', 'SQL and Database Fundamentals', 'Starter multiple-choice assessment. Extend with reviewed questions before high-stakes use.', 3.00, 2.00, 1 FROM skills s WHERE s.skill_slug='sql-databases';
INSERT IGNORE INTO assessments (skill_id, assessment_code, title, description, max_score, pass_score, is_active) SELECT s.skill_id, 'javascript-basics-v1', 'JavaScript Fundamentals', 'Starter multiple-choice assessment. Extend with reviewed questions before high-stakes use.', 3.00, 2.00, 1 FROM skills s WHERE s.skill_slug='javascript';
INSERT IGNORE INTO assessments (skill_id, assessment_code, title, description, max_score, pass_score, is_active) SELECT s.skill_id, 'html-basics-v1', 'HTML Fundamentals', 'Starter multiple-choice assessment. Extend with reviewed questions before high-stakes use.', 3.00, 2.00, 1 FROM skills s WHERE s.skill_slug='html';
INSERT IGNORE INTO assessments (skill_id, assessment_code, title, description, max_score, pass_score, is_active) SELECT s.skill_id, 'css-basics-v1', 'CSS Fundamentals', 'Starter multiple-choice assessment. Extend with reviewed questions before high-stakes use.', 3.00, 2.00, 1 FROM skills s WHERE s.skill_slug='css';
INSERT IGNORE INTO assessments (skill_id, assessment_code, title, description, max_score, pass_score, is_active) SELECT s.skill_id, 'problem-solving-v1', 'Problem Solving', 'Starter multiple-choice assessment. Extend with reviewed questions before high-stakes use.', 3.00, 2.00, 1 FROM skills s WHERE s.skill_slug='problem-solving';
INSERT IGNORE INTO assessments (skill_id, assessment_code, title, description, max_score, pass_score, is_active) SELECT s.skill_id, 'communication-v1', 'Communication Skills', 'Starter multiple-choice assessment. Extend with reviewed questions before high-stakes use.', 3.00, 2.00, 1 FROM skills s WHERE s.skill_slug='communication';
INSERT IGNORE INTO assessments (skill_id, assessment_code, title, description, max_score, pass_score, is_active) SELECT s.skill_id, 'uiux-basics-v1', 'UI/UX Fundamentals', 'Starter multiple-choice assessment. Extend with reviewed questions before high-stakes use.', 3.00, 2.00, 1 FROM skills s WHERE s.skill_slug='ui-ux-design';
INSERT IGNORE INTO assessments (skill_id, assessment_code, title, description, max_score, pass_score, is_active) SELECT s.skill_id, 'leadership-v1', 'Leadership Fundamentals', 'Starter multiple-choice assessment. Extend with reviewed questions before high-stakes use.', 3.00, 2.00, 1 FROM skills s WHERE s.skill_slug='leadership';
INSERT IGNORE INTO assessments (skill_id, assessment_code, title, description, max_score, pass_score, is_active) SELECT s.skill_id, 'cybersecurity-v1', 'Cybersecurity Fundamentals', 'Starter multiple-choice assessment. Extend with reviewed questions before high-stakes use.', 3.00, 2.00, 1 FROM skills s WHERE s.skill_slug='cybersecurity-fundamentals';
INSERT IGNORE INTO assessments (skill_id, assessment_code, title, description, max_score, pass_score, is_active) SELECT s.skill_id, 'data-viz-v1', 'Data Visualization Fundamentals', 'Starter multiple-choice assessment. Extend with reviewed questions before high-stakes use.', 3.00, 2.00, 1 FROM skills s WHERE s.skill_slug='data-visualization';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which keyword defines a function in Python?', 1.00, 1 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='python-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which collection stores key-value pairs?', 1.00, 2 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='python-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'What does len([2,4,6]) return?', 1.00, 3 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='python-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which keyword declares a class in Java?', 1.00, 1 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='java-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which method is the usual Java application entry point?', 1.00, 2 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='java-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which type stores true or false?', 1.00, 3 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='java-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which data structure follows FIFO?', 1.00, 1 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='dsa-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Binary search requires the input to be generally…', 1.00, 2 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='dsa-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which structure is commonly used for function calls?', 1.00, 3 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='dsa-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which SQL command retrieves rows?', 1.00, 1 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='sql-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which key uniquely identifies a row?', 1.00, 2 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='sql-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which clause filters grouped results?', 1.00, 3 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='sql-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which keyword declares a block-scoped variable that can be reassigned?', 1.00, 1 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='javascript-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which operator checks strict equality?', 1.00, 2 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='javascript-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'JSON stands for…', 1.00, 3 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='javascript-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which element creates a hyperlink?', 1.00, 1 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='html-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which attribute provides alternative text for an image?', 1.00, 2 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='html-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which element represents the main page content?', 1.00, 3 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='html-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which property changes text color?', 1.00, 1 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='css-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which layout system is designed for one-dimensional layouts?', 1.00, 2 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='css-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which selector targets an element with id="app"?', 1.00, 3 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='css-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'What is a good first step for an unfamiliar problem?', 1.00, 1 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='problem-solving-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Why test edge cases?', 1.00, 2 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='problem-solving-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'What does decomposition mean?', 1.00, 3 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='problem-solving-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Active listening means…', 1.00, 1 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='communication-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'A clear project update should include…', 1.00, 2 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='communication-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'When giving feedback, focus on…', 1.00, 3 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='communication-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'What is a wireframe mainly used for?', 1.00, 1 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='uiux-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Usability testing helps determine whether…', 1.00, 2 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='uiux-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Accessibility aims to…', 1.00, 3 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='uiux-basics-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'A useful way to delegate work is to clarify…', 1.00, 1 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='leadership-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'When a team disagrees, a leader should first…', 1.00, 2 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='leadership-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'A retrospective is used to…', 1.00, 3 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='leadership-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'What is phishing?', 1.00, 1 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='cybersecurity-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Why use multi-factor authentication?', 1.00, 2 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='cybersecurity-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'What should you do with a suspicious attachment?', 1.00, 3 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='cybersecurity-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'Which chart is often suitable for comparing categories?', 1.00, 1 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='data-viz-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'A misleading chart can result from…', 1.00, 2 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='data-viz-v1';
INSERT IGNORE INTO assessment_questions (assessment_id, question_type_id, question_text, points, sort_order) SELECT a.assessment_id, qt.question_type_id, 'A good visualization should primarily…', 1.00, 3 FROM assessments a JOIN question_types qt ON qt.type_code="single_choice" WHERE a.assessment_code='data-viz-v1';
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'func', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='python-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'def', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='python-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'function', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='python-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'method', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='python-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'list', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='python-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'tuple', 0, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='python-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'dict', 1, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='python-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'set', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='python-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '2', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='python-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '3', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='python-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '6', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='python-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Error', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='python-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'define', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='java-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'class', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='java-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'struct', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='java-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'object', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='java-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'start()', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='java-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'run()', 0, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='java-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'main()', 1, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='java-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'init()', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='java-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'int', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='java-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'String', 0, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='java-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'boolean', 1, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='java-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'char', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='java-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Stack', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='dsa-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Queue', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='dsa-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Tree', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='dsa-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Graph', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='dsa-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Sorted', 1, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='dsa-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Reversed', 0, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='dsa-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Random', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='dsa-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Duplicated', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='dsa-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Queue', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='dsa-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Stack', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='dsa-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Heap', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='dsa-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Hash set', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='dsa-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'GET', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='sql-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'SELECT', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='sql-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'FETCH ALL', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='sql-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'OPEN', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='sql-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Foreign key', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='sql-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Index only', 0, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='sql-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Primary key', 1, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='sql-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'View', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='sql-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'WHERE', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='sql-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'ORDER BY', 0, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='sql-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'HAVING', 1, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='sql-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'LIMIT', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='sql-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'const', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='javascript-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'let', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='javascript-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'static', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='javascript-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'define', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='javascript-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '=', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='javascript-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '=', 0, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='javascript-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '===', 1, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='javascript-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '!=', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='javascript-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Java Source Object Notation', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='javascript-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'JavaScript Object Notation', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='javascript-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Joined Standard Object Names', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='javascript-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Java Syntax Output Network', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='javascript-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '<link>', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='html-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '<a>', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='html-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '<href>', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='html-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '<url>', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='html-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'title', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='html-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'src', 0, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='html-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'alt', 1, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='html-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'name', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='html-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '<main>', 1, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='html-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '<head>', 0, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='html-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '<meta>', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='html-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '<style>', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='html-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'font-style', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='css-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'color', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='css-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'background-image', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='css-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'text-align', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='css-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Grid', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='css-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Flexbox', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='css-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Float only', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='css-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Table', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='css-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '.app', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='css-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '#app', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='css-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'app*', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='css-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, '@app', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='css-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Guess the answer', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='problem-solving-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Clarify requirements and constraints', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='problem-solving-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Start coding immediately', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='problem-solving-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Ignore edge cases', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='problem-solving-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'To make code longer', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='problem-solving-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'To find boundary-related failures', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='problem-solving-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'To avoid documentation', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='problem-solving-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'To reduce variable names', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='problem-solving-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Split a problem into smaller parts', 1, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='problem-solving-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Delete requirements', 0, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='problem-solving-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Copy a solution', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='problem-solving-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Skip testing', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='problem-solving-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Planning your reply while ignoring the speaker', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='communication-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Understanding and clarifying the speaker’s message', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='communication-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Talking continuously', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='communication-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Avoiding questions', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='communication-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Only opinions', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='communication-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Status, blockers and next steps', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='communication-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Only technical jargon', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='communication-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'No timeline', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='communication-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Personal attacks', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='communication-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Specific behavior and constructive suggestions', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='communication-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Rumors', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='communication-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Unrelated past mistakes', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='communication-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Final production code', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='uiux-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Early layout and structure', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='uiux-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Database backup', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='uiux-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Network testing', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='uiux-basics-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Users can complete tasks effectively', 1, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='uiux-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'A logo is expensive', 0, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='uiux-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Code has no comments', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='uiux-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'A site has many colors', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='uiux-basics-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Restrict users', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='uiux-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Make products usable by people with varied abilities', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='uiux-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Remove navigation', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='uiux-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Use smaller text everywhere', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='uiux-basics-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Only the deadline', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='leadership-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Expected outcome, ownership and deadline', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='leadership-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Nothing until completion', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='leadership-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Personal opinions only', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='leadership-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Silence everyone', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='leadership-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Understand perspectives and evidence', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='leadership-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Choose a side instantly', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='leadership-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Cancel the project', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='leadership-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Assign blame', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='leadership-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Reflect and improve future work', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='leadership-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Replace planning', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='leadership-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Hide problems', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='leadership-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'A database index', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='cybersecurity-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'A deceptive attempt to obtain sensitive information', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='cybersecurity-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'A backup method', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='cybersecurity-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'A coding style', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='cybersecurity-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'It adds another verification factor', 1, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='cybersecurity-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'It removes passwords from every system', 0, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='cybersecurity-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'It encrypts every file automatically', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='cybersecurity-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'It prevents all attacks', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='cybersecurity-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Open it quickly', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='cybersecurity-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Forward it to everyone', 0, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='cybersecurity-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Avoid opening and report it through the proper channel', 1, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='cybersecurity-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Disable security tools', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='cybersecurity-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Bar chart', 1, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='data-viz-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Network packet', 0, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='data-viz-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Plain paragraph only', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='data-viz-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Password table', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='data-viz-v1' AND q.sort_order=1;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Clear labels', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='data-viz-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'A truncated axis without context', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='data-viz-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Showing units', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='data-viz-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Citing data sources', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='data-viz-v1' AND q.sort_order=2;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Decorate the report', 0, 1 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='data-viz-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Communicate a data message accurately', 1, 2 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='data-viz-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Hide uncertainty', 0, 3 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='data-viz-v1' AND q.sort_order=3;
INSERT IGNORE INTO question_options (question_id, option_text, is_correct, sort_order) SELECT q.question_id, 'Use every color', 0, 4 FROM assessment_questions q JOIN assessments a ON a.assessment_id=q.assessment_id WHERE a.assessment_code='data-viz-v1' AND q.sort_order=3;

-- End of additive catalog extension.
