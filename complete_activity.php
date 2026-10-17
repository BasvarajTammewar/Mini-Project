<?php
/**
 * CareerAct — Activity Completion & Evidence Submission Module
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';

$userId = (int)$_SESSION['user_id'];
$db = getDbConnection();

// Fetch student profile
$stmtStudent = $db->prepare("SELECT student_id, full_name FROM student_profiles WHERE user_id = :user_id LIMIT 1");
$stmtStudent->execute([':user_id' => $userId]);
$student = $stmtStudent->fetch();
$studentId = $student ? (int)$student['student_id'] : 0;

$enrollmentId = (int)($_GET['id'] ?? 0);
if ($enrollmentId <= 0) {
    header('Location: enrollments.php');
    exit;
}

// Fetch enrollment
$stmtEnroll = $db->prepare("
    SELECT sae.*, a.title, a.activity_id, t.type_name, o.organizer_name
    FROM student_activity_enrollments sae
    JOIN activities a ON sae.activity_id = a.activity_id
    JOIN activity_types t ON a.activity_type_id = t.activity_type_id
    LEFT JOIN organizers o ON a.organizer_id = o.organizer_id
    WHERE sae.enrollment_id = :eid AND sae.student_id = :sid
    LIMIT 1
");
$stmtEnroll->execute([':eid' => $enrollmentId, ':sid' => $studentId]);
$enrollment = $stmtEnroll->fetch();

if (!$enrollment) {
    header('Location: enrollments.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_complete'])) {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $reflection = trim((string)($_POST['student_reflection'] ?? ''));
        $skillsText = trim((string)($_POST['skills_gained_text'] ?? ''));
        $outcomes   = trim((string)($_POST['outcomes'] ?? ''));
        $proofUrl   = trim((string)($_POST['proof_url'] ?? ''));
        $certTitle  = trim((string)($_POST['certificate_reference'] ?? ''));

        if (empty($reflection)) {
            $errors['reflection'] = 'Please summarize your individual contribution and what you learned.';
        } else {
            // Update enrollment status
            $upStmt = $db->prepare("
                UPDATE student_activity_enrollments
                SET completion_status = 'completed',
                    enrollment_status_id = 5,
                    completed_at = NOW(),
                    student_reflection = :reflection,
                    skills_gained_text = :skills,
                    proof_url = :proof,
                    certificate_reference = :cert,
                    completion_verification = :verif,
                    updated_at = NOW()
                WHERE enrollment_id = :eid AND student_id = :sid
            ");
            $verifStatus = !empty($proofUrl) ? 'pending' : 'verified';
            $upStmt->execute([
                ':reflection' => $reflection,
                ':skills'     => $skillsText,
                ':proof'      => $proofUrl,
                ':cert'       => $certTitle,
                ':verif'      => $verifStatus,
                ':eid'        => $enrollmentId,
                ':sid'        => $studentId
            ]);

            // Save project record if repository URL provided
            if (!empty($proofUrl) && filter_var($proofUrl, FILTER_VALIDATE_URL)) {
                $insProj = $db->prepare("
                    INSERT INTO student_projects (student_id, project_title, description, project_url, start_date, end_date, created_at)
                    VALUES (:sid, :title, :desc, :url, DATE_SUB(CURRENT_DATE, INTERVAL 14 DAY), CURRENT_DATE, NOW())
                ");
                $insProj->execute([
                    ':sid'   => $studentId,
                    ':title' => $enrollment['title'] . ' Project Output',
                    ':desc'  => $reflection,
                    ':url'   => $proofUrl
                ]);
            }

            // Save achievement if outcomes specified
            if (!empty($outcomes)) {
                $insAch = $db->prepare("
                    INSERT INTO achievements (student_id, enrollment_id, title, achievement_type, issuer_name, achieved_on, description, verification_status, created_at)
                    VALUES (:sid, :eid, :title, 'recognition', :issuer, CURRENT_DATE, :desc, :verif, NOW())
                ");
                $insAch->execute([
                    ':sid'    => $studentId,
                    ':eid'    => $enrollmentId,
                    ':title'  => $outcomes,
                    ':issuer' => $enrollment['organizer_name'] ?? 'CareerAct',
                    ':desc'   => "Achieved during {$enrollment['title']}",
                    ':verif'  => !empty($proofUrl) ? 'pending' : 'unverified'
                ]);
            }

            // Redirect with success message
            header('Location: enrollments.php?msg=completed');
            exit;
        }
    }
}

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Evidence — <?= e($enrollment['title']) ?></title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body class="dashboard-body">

    <!-- Sidebar Navigation -->
    <aside class="dashboard-sidebar" aria-label="Portal Navigation">
        <div>
            <div class="sidebar-header">
                <a href="dashboard.php" class="sidebar-brand-wrapper">
                    <div class="sidebar-brand-icon"><svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg></div>
                    <div class="sidebar-brand-text">
                        <span class="sidebar-brand-name">CareerAct</span>
                        <span class="sidebar-brand-subtitle">STUDENT PORTAL</span>
                    </div>
                </a>
            </div>
            <nav>
                <ul class="sidebar-nav">
                    <li><a href="dashboard.php" class="nav-link"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect></svg><span>Dashboard</span></a></li>
                    <li><a href="activities.php" class="nav-link"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg><span>Explore Activities</span></a></li>
                    <li><a href="career_goals.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path><line x1="4" y1="22" x2="4" y2="15"></line></svg><span>Career Goals</span></a></li>
                    <li><a href="skills_assessment.php" class="nav-link"><svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg><span>Skills Assessment</span></a></li>
                    <li><a href="enrollments.php" class="nav-link active"><svg viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg><span>My Enrollments</span></a></li>
                    <li><a href="resume.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg><span>My Resume</span></a></li>
                    <li><a href="profile.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><span>My Profile</span></a></li>
                </ul>
            </nav>
        </div>
        <div class="sidebar-footer">
            <a href="logout.php" class="logout-link"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg><span>Logout</span></a>
        </div>
    </aside>

    <div class="dashboard-main">
        <header class="dashboard-topbar">
            <div class="topbar-welcome">
                <span class="welcome-title">Record Activity Completion</span>
                <span class="welcome-subtitle"><?= e($enrollment['title']) ?></span>
            </div>
            <div class="topbar-actions">
                <a href="enrollments.php" class="btn-view-details">← Back to Enrollments</a>
            </div>
        </header>

        <main class="dashboard-content">
            <div style="max-width: 800px; background: var(--white); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 32px; box-shadow: var(--card-shadow);">
                <h1 style="font-size: 20px; font-weight: 800; color: var(--dark-navy); margin: 0 0 6px;">
                    Submit Reflection & Practical Evidence
                </h1>
                <p style="font-size: 13.5px; color: var(--secondary-text); margin-bottom: 24px;">
                    Documenting what you contributed and attaching links upgrades your assessment confidence and strengthens your verified transcript.
                </p>

                <form method="POST" action="complete_activity.php?id=<?= $enrollmentId ?>" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action_complete" value="1">

                    <div class="form-group">
                        <label class="form-label">What Did You Learn and What Modules Did You Build? <span class="req">*</span></label>
                        <textarea name="student_reflection" class="form-input" rows="4" placeholder="Detail your individual problem-solving responsibilities, languages used, and challenges overcome..." required><?= e($enrollment['student_reflection'] ?? '') ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Competencies & Skills Practiced</label>
                            <input type="text" name="skills_gained_text" class="form-input" placeholder="e.g. React, Express.js, Docker, API Design" value="<?= e($enrollment['skills_gained_text'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Outcome / Key Result</label>
                            <input type="text" name="outcomes" class="form-input" placeholder="e.g. Deployed to production, Certificate awarded, Won 1st Prize">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Evidence / Repository URL</label>
                            <input type="url" name="proof_url" class="form-input" placeholder="https://github.com/username/project-repo" value="<?= e($enrollment['proof_url'] ?? '') ?>">
                            <span style="font-size: 11.5px; color: #0F9D8A;">✓ Verifiable links receive high assessment confidence.</span>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Certificate ID / Reference</label>
                            <input type="text" name="certificate_reference" class="form-input" placeholder="e.g. CERT-2026-98124" value="<?= e($enrollment['certificate_reference'] ?? '') ?>">
                        </div>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 24px;">
                        <a href="enrollments.php" class="btn-primary" style="background: #F1F5F9; color: var(--main-text); text-decoration: none; width: auto; padding: 0 24px;">Cancel</a>
                        <button type="submit" class="btn-primary" style="flex: 1; background: #0F9D8A;">
                            Save Evidence & Mark Activity Completed ✓
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
