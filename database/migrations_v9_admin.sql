-- ============================================================================
-- CAREERACT — MIGRATION V9: ADMIN PANEL & OPPORTUNITY VERIFICATION
-- Database: carrearact2
-- Module 13: Admin Opportunity Verification Workflow & Audit Trail
-- ============================================================================

-- 1. Extend verification_status enum to support 'needs_correction' and 'rejected'
ALTER TABLE opportunities 
MODIFY COLUMN verification_status ENUM('sample','unverified','verified','needs_correction','rejected') 
NOT NULL DEFAULT 'unverified';

-- 2. Ensure initial administrator account exists
INSERT INTO users (name, email, password_hash, role, account_status, is_demo, created_at, updated_at)
SELECT 'System Administrator', 'admin@careeract.edu', 
       '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: password
       'admin', 'active', 0, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM users WHERE role = 'admin' LIMIT 1);

