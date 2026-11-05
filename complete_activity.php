<?php
/**
 * CareerAct — Activity Completion & Achievement Management (Feature 5)
 * 
 * Allows students to record activity achievements, upload verified evidence/certificates,
 * generate professional Gemini AI summaries, and synchronize with Portfolio, Resume, and Roadmap.
 * 
 * Database: carrearact2
 */

declare(strict_types=1);

if (!headers_sent()) {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/gemini_service.php';
require_once __DIR__ . '/includes/roadmap_service.php';

requireCompletedProfile('onboarding.php?msg=complete_profile');

$userId = getCurrentUserId();
$db = getDbConnection();

$enrollmentId = (int)($_GET['id'] ?? ($_GET['enrollment_id'] ?? 0));
if ($enrollmentId <= 0) {
    header('Location: enrollments.php');
    exit;
}

// Fetch enrollment and verify ownership
$stmtEnroll = $db->prepare("
    SELECT 
        e.*,
        a.id AS activity_id,
        a.title AS activity_title,
        a.description AS activity_desc,
        a.delivery_mode,
        ac.name AS category_name,
        COALESCE(o.title, a.title) AS opp_title,
        COALESCE(p.name, 'National Academic Partner') AS organizer_name
    FROM enrollments e
    JOIN activities a ON e.activity_id = a.id
    LEFT JOIN activity_categories ac ON a.category_id = ac.id
    LEFT JOIN opportunities o ON e.opportunity_id = o.id
    LEFT JOIN providers p ON o.provider_id = p.id
    WHERE e.id = :eid AND e.user_id = :uid
    LIMIT 1
");
$stmtEnroll->execute([':eid' => $enrollmentId, ':uid' => $userId]);
$enrollment = $stmtEnroll->fetch(PDO::FETCH_ASSOC);

if (!$enrollment) {
    header('Location: enrollments.php?msg=not_found');
    exit;
}

// Fetch existing achievement if already submitted
$stmtExistingAch = $db->prepare("SELECT * FROM achievements WHERE enrollment_id = :eid AND user_id = :uid LIMIT 1");
$stmtExistingAch->execute([':eid' => $enrollmentId, ':uid' => $userId]);
$existingAch = $stmtExistingAch->fetch(PDO::FETCH_ASSOC);

// Fetch student info
$stmtUser = $db->prepare("SELECT name FROM users WHERE id = :uid LIMIT 1");
$stmtUser->execute([':uid' => $userId]);
$student = $stmtUser->fetch(PDO::FETCH_ASSOC);
$fullName = $student['name'] ?? 'Student';
$initials = strtoupper(substr($fullName, 0, 1));

$gemini = new GeminiService();
$errors = [];
$successMsg = '';

// Handle AI Summary Generation AJAX or POST request
$aiSummaryGenerated = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action_ai_summary'])) {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $role = trim((string)($_POST['role'] ?? 'Participant'));
        $result = trim((string)($_POST['result'] ?? 'Successfully Completed'));
        $desc = trim((string)($_POST['description'] ?? ''));
        $reflection = trim((string)($_POST['reflection'] ?? ''));
        $skills = trim((string)($_POST['skills_developed'] ?? ''));

        if ($gemini->isConfigured()) {
            try {
                $sys = "You are a professional resume writer and career development advisor for CareerAct.
Synthesize a concise, high-impact resume achievement bullet point (1-2 sentences, maximum 45 words)
based strictly on the student's actual role, verified activity, reflection, and skills developed.
Do NOT fabricate awards, rankings, certificates, or unearned achievements.
Respond with plain text only. No quotes, no markdown fences.";

                $prompt = "Activity: {$enrollment['opp_title']} ({$enrollment['category_name']})
Role: {$role}
Result/Outcome: {$result}
Key Contributions: {$desc}
Student Reflection: {$reflection}
Skills Developed: {$skills}

Generate the professional achievement summary:";

                $aiSummaryGenerated = trim($gemini->callGeminiApi($sys, $prompt, 15));
            } catch (Exception $e) {
                // Heuristic fallback
            }
        }

        if ($aiSummaryGenerated === '') {
            $aiSummaryGenerated = "Served as {$role} in {$enrollment['opp_title']}, applying practical problem-solving and {$skills} to achieve {$result}.";
        }
    }
}

// Handle Form Submission (Complete Activity & Save Achievement)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action_save_completion'])) {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $completedDate = trim((string)($_POST['completed_date'] ?? date('Y-m-d')));
        $role          = trim((string)($_POST['role'] ?? 'Participant'));
        $result        = trim((string)($_POST['result'] ?? 'Completed'));
        $description   = trim((string)($_POST['description'] ?? ''));
        $reflection    = trim((string)($_POST['reflection'] ?? ''));
        $skills        = trim((string)($_POST['skills_developed'] ?? ''));
        $projectUrl    = trim((string)($_POST['project_url'] ?? ''));
        $resumeText    = trim((string)($_POST['resume_text'] ?? ''));
        $showOnResume  = isset($_POST['show_on_resume']) ? 1 : 0;

        if (empty($description)) {
            $errors['description'] = 'Please describe your key contributions and work completed.';
        }

        if (!empty($projectUrl) && !filter_var($projectUrl, FILTER_VALIDATE_URL)) {
            $errors['project_url'] = 'Please enter a valid project repository or portfolio URL.';
        }

        // Handle Secure File Upload (Certificate / Proof of Work)
        $uploadedFilePath = $existingAch['evidence_file_path'] ?? null;
        $uploadedFileMime = $existingAch['evidence_file_mime'] ?? null;
        $uploadedFileSize = $existingAch['evidence_file_size'] ?? null;
        $uploadedFileHash = $existingAch['evidence_file_sha256'] ?? null;
        $evidenceType     = !empty($projectUrl) ? 'project_link' : ($existingAch['evidence_type'] ?? 'other');

        if (isset($_FILES['certificate_file']) && $_FILES['certificate_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmp  = $_FILES['certificate_file']['tmp_name'];
            $fileSize = (int)$_FILES['certificate_file']['size'];
            $fileName = $_FILES['certificate_file']['name'];
            $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            $allowedExts = ['pdf', 'png', 'jpg', 'jpeg'];
            $allowedMimes = ['application/pdf', 'image/png', 'image/jpeg'];

            // Validate file size (max 5MB)
            if ($fileSize > 5 * 1024 * 1024) {
                $errors['certificate_file'] = 'Supporting file size must be less than 5MB.';
            } elseif (!in_array($fileExt, $allowedExts, true)) {
                $errors['certificate_file'] = 'Invalid file type. Only PDF, PNG, and JPG files are accepted.';
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $fileTmp);
                finfo_close($finfo);

                if (!in_array($mimeType, $allowedMimes, true)) {
                    $errors['certificate_file'] = 'Invalid file MIME format detected. File rejected.';
                } else {
                    $hash = hash_file('sha256', $fileTmp);
                    $destDir = __DIR__ . '/uploads/evidence/';
                    if (!is_dir($destDir)) {
                        mkdir($destDir, 0755, true);
                    }
                    $safeFileName = 'cert_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
                    $destPath = $destDir . $safeFileName;

                    if (move_uploaded_file($fileTmp, $destPath)) {
                        $uploadedFilePath = 'uploads/evidence/' . $safeFileName;
                        $uploadedFileMime = $mimeType;
                        $uploadedFileSize = $fileSize;
                        $uploadedFileHash = $hash;
                        $evidenceType     = 'certificate';
                    } else {
                        $errors['certificate_file'] = 'Failed to securely store certificate on server.';
                    }
                }
            }
        }

        if (empty($errors)) {
            if (empty($resumeText)) {
                $resumeText = "Participated as {$role} in {$enrollment['opp_title']}, demonstrating {$skills} and achieving {$result}.";
            }

            $db->beginTransaction();
            try {
                // 1. Update Enrollment Status
                $stmtUpEnroll = $db->prepare("
                    UPDATE enrollments 
                    SET status = 'completed',
                        progress_pct = 100,
                        completed_at = :comp_date,
                        notes = :reflection,
                        updated_at = NOW()
                    WHERE id = :eid AND user_id = :uid
                ");
                $stmtUpEnroll->execute([
                    ':comp_date'   => $completedDate,
                    ':reflection'  => $reflection,
                    ':eid'         => $enrollmentId,
                    ':uid'         => $userId
                ]);

                // 2. Insert or Update Achievements Record
                if ($existingAch) {
                    $stmtUpAch = $db->prepare("
                        UPDATE achievements 
                        SET title = :title,
                            role = :role,
                            result = :result,
                            description = :desc,
                            completed_on = :comp_on,
                            evidence_type = :ev_type,
                            evidence_url = :ev_url,
                            evidence_note = :ev_note,
                            resume_text = :res_text,
                            show_on_resume = :show_res,
                            evidence_file_path = :fpath,
                            evidence_file_mime = :fmime,
                            evidence_file_size = :fsize,
                            evidence_file_sha256 = :fhash
                        WHERE id = :aid AND user_id = :uid
                    ");
                    $stmtUpAch->execute([
                        ':title'    => $enrollment['opp_title'],
                        ':role'     => $role,
                        ':result'   => $result,
                        ':desc'     => $description,
                        ':comp_on'  => $completedDate,
                        ':ev_type'  => $evidenceType,
                        ':ev_url'   => $projectUrl,
                        ':ev_note'  => $reflection,
                        ':res_text' => $resumeText,
                        ':show_res' => $showOnResume,
                        ':fpath'    => $uploadedFilePath,
                        ':fmime'    => $uploadedFileMime,
                        ':fsize'    => $uploadedFileSize,
                        ':fhash'    => $uploadedFileHash,
                        ':aid'      => $existingAch['id'],
                        ':uid'      => $userId
                    ]);
                } else {
                    $stmtInsAch = $db->prepare("
                        INSERT INTO achievements (
                            user_id, enrollment_id, title, role, result, description,
                            completed_on, evidence_type, evidence_url, evidence_note,
                            verification_status, resume_text, show_on_resume,
                            evidence_file_path, evidence_file_mime, evidence_file_size,
                            evidence_file_sha256, created_at
                        ) VALUES (
                            :uid, :eid, :title, :role, :result, :desc,
                            :comp_on, :ev_type, :ev_url, :ev_note,
                            'self_reported', :res_text, :show_res,
                            :fpath, :fmime, :fsize,
                            :fhash, NOW()
                        )
                    ");
                    $stmtInsAch->execute([
                        ':uid'      => $userId,
                        ':eid'      => $enrollmentId,
                        ':title'    => $enrollment['opp_title'],
                        ':role'     => $role,
                        ':result'   => $result,
                        ':desc'     => $description,
                        ':comp_on'  => $completedDate,
                        ':ev_type'  => $evidenceType,
                        ':ev_url'   => $projectUrl,
                        ':ev_note'  => $reflection,
                        ':res_text' => $resumeText,
                        ':show_res' => $showOnResume,
                        ':fpath'    => $uploadedFilePath,
                        ':fmime'    => $uploadedFileMime,
                        ':fsize'    => $uploadedFileSize,
                        ':fhash'    => $uploadedFileHash
                    ]);
                }

                // 3. Integrate with Portfolio Items (Add if not already exists)
                $stmtCheckPort = $db->prepare("
                    SELECT id FROM portfolio_items 
                    WHERE user_id = :uid AND title = :title LIMIT 1
                ");
                $stmtCheckPort->execute([':uid' => $userId, ':title' => $enrollment['opp_title']]);
                $portItemId = $stmtCheckPort->fetchColumn();

                $portItemType = ($evidenceType === 'certificate') ? 'certificate' : (!empty($projectUrl) ? 'project' : 'other');

                if (!$portItemId) {
                    $stmtInsPort = $db->prepare("
                        INSERT INTO portfolio_items (
                            user_id, title, item_type, url, description,
                            show_on_resume, verification_status,
                            evidence_file_path, evidence_file_mime, evidence_file_size,
                            evidence_file_sha256, created_at
                        ) VALUES (
                            :uid, :title, :itype, :url, :desc,
                            :show_res, 'self_reported',
                            :fpath, :fmime, :fsize,
                            :fhash, NOW()
                        )
                    ");
                    $stmtInsPort->execute([
                        ':uid'      => $userId,
                        ':title'    => $enrollment['opp_title'],
                        ':itype'    => $portItemType,
                        ':url'      => $projectUrl,
                        ':desc'     => $resumeText,
                        ':show_res' => $showOnResume,
                        ':fpath'    => $uploadedFilePath,
                        ':fmime'    => $uploadedFileMime,
                        ':fsize'    => $uploadedFileSize,
                        ':fhash'    => $uploadedFileHash
                    ]);
                }

                // 4. Update Career Roadmap Milestones automatically
                $roadmapService = new RoadmapService($db, $gemini);
                $roadmapService->autoUpdateRoadmapOnActivityCompletion($userId, (int)$enrollment['activity_id']);

                $db->commit();
                header('Location: enrollments.php?tab=completed&msg=activity_completed');
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                $errors['db'] = 'Database error saving achievement: ' . $e->getMessage();
            }
        }
    } else {
        $errors['csrf'] = 'Security validation failed (invalid CSRF token). Please try again.';
    }
}

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Activity — CareerAct</title>
    <meta name="description" content="Record completed extracurricular achievement, upload evidence, and generate AI summary.">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= time() ?>">
    <style>
        svg { fill: none !important; stroke: currentColor !important; display: inline-block; vertical-align: middle; }
        .form-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
            margin-bottom: 32px;
        }
        .form-label {
            display: block;
            font-size: 13.5px;
            font-weight: 700;
            color: #0F172A;
            margin-bottom: 6px;
        }
        .form-hint {
            font-size: 12px;
            color: #64748B;
            margin-bottom: 8px;
            line-height: 1.4;
        }
        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #CBD5E1;
            border-radius: 8px;
            font-size: 13.5px;
            color: #1E293B;
            background: #F8FAFC;
            box-sizing: border-box;
            outline: none;
            transition: all 0.2s ease;
        }
        .form-control:focus {
            background: #FFFFFF;
            border-color: #1769E8;
            box-shadow: 0 0 0 3px rgba(23, 105, 232, 0.12);
        }
        .form-group {
            margin-bottom: 22px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
        }
        .btn-submit {
            background: #059669;
            color: #FFFFFF;
            font-size: 14.5px;
            font-weight: 700;
            padding: 12px 28px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-submit:hover {
            background: #047857;
        }
        .btn-ai-gen {
            background: #EEF4FF;
            color: #1D4ED8;
            border: 1px solid #BFDBFE;
            font-size: 12.5px;
            font-weight: 700;
            padding: 7px 14px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-ai-gen:hover {
            background: #DBEAFE;
        }
    </style>
</head>
<body class="dashboard-body">

    <!-- Sidebar Navigation -->
    <aside class="dashboard-sidebar" id="dashboardSidebar" aria-label="Portal Navigation">
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
                    <li>
                        <a href="dashboard.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect></svg>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="profile.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            <span>My Profile</span>
                        </a>
                    </li>
                    <li>
                        <a href="career_goals.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg>
                            <span>Career Exploration</span>
                        </a>
                    </li>
                    <li>
                        <a href="my_skills.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                            <span>My Skills</span>
                        </a>
                    </li>
                    <li>
                        <a href="skills_assessment.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                            <span>Skill Assessment</span>
                        </a>
                    </li>
                    <li>
                        <a href="skill_gap.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg>
                            <span>Skill-Gap Analysis</span>
                        </a>
                    </li>
                    <li>
                        <a href="activities.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg>
                            <span>Explore Activities</span>
                        </a>
                    </li>
                    <li>
                        <a href="roadmap.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            <span>Career Roadmap</span>
                        </a>
                    </li>
                    <li>
                        <a href="saved_activities.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path></svg>
                            <span>Saved Activities</span>
                        </a>
                    </li>
                    <li>
                        <a href="enrollments.php" class="nav-link active" aria-current="page">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg>
                            <span>My Enrollments</span>
                        </a>
                    </li>
                    <li>
                        <a href="portfolio.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                            <span>My Portfolio</span>
                        </a>
                    </li>
                    <li>
                        <a href="resume.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            <span>Resume Builder</span>
                        </a>
                    </li>
                    <li>
                        <a href="notifications.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                            <span>Notifications</span><?php if (!empty($unreadNotificationsCount) && $unreadNotificationsCount > 0): ?><span class="badge-pill danger" style="font-size:10px; padding:2px 6px; margin-left:auto; background:#EF4444; color:#fff; border-radius:10px;"><?= $unreadNotificationsCount ?></span><?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="settings.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                            <span>Settings</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>

        <div class="sidebar-footer">
            <a href="logout.php" class="logout-link"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg><span>Logout</span></a>
        </div>
    </aside>

    <!-- Main Content Area -->
    <div class="dashboard-main">
        <header class="dashboard-topbar">
            <div class="topbar-welcome">
                <span class="welcome-title">Record Activity Completion</span>
                <span class="welcome-subtitle"><?= e($enrollment['opp_title']) ?></span>
            </div>
            <div class="topbar-actions">
                <a href="enrollments.php" class="btn-view-details" style="padding: 7px 14px;">&larr; Back to Activities</a>
                <a href="profile.php" class="user-profile-chip">
                    <div class="avatar-circle"><?= e($initials) ?></div>
                    <div class="user-text-info"><span class="user-name"><?= e($fullName) ?></span><span class="user-level">Student</span></div>
                </a>
            </div>
        </header>

        <main class="dashboard-content" style="max-width: 960px; margin: 0 auto; padding: 24px 20px;">

            <?php if (!empty($errors)): ?>
                <div style="background:#FEF2F2; border:1px solid #FECACA; color:#991B1B; padding:14px 18px; border-radius:10px; margin-bottom:24px; font-size:13.5px;">
                    <strong>Please address the following items:</strong>
                    <ul style="margin:6px 0 0 0; padding-left:20px;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="form-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; border-bottom: 1px solid #E2E8F0; padding-bottom: 18px;">
                    <div>
                        <span style="font-size: 11px; font-weight: 700; color: #1769E8; text-transform: uppercase;">
                            <?= e($enrollment['category_name']) ?>
                        </span>
                        <h1 style="font-size: 22px; font-weight: 800; color: #0F172A; margin: 4px 0 4px 0;">
                            <?= e($enrollment['opp_title']) ?>
                        </h1>
                        <p style="font-size: 13px; color: #64748B; margin: 0;">
                            Organized by <?= e($enrollment['organizer_name']) ?>
                        </p>
                    </div>
                    <span style="background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 800;">
                        Achievement Verification
                    </span>
                </div>

                <form method="POST" action="complete_activity.php?id=<?= $enrollmentId ?>" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action_save_completion" value="1">

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Completion Date *</label>
                            <input type="date" name="completed_date" class="form-control" value="<?= e($existingAch['completed_on'] ?? date('Y-m-d')) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Role or Contribution Title *</label>
                            <input type="text" name="role" class="form-control" placeholder="e.g. Team Lead, Full-Stack Developer, Finalist" value="<?= e($existingAch['role'] ?? 'Participant') ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Outcome / Result *</label>
                            <input type="text" name="result" class="form-control" placeholder="e.g. Completed, Top 5 Finalist, 1st Place Winner, Certified" value="<?= e($existingAch['result'] ?? 'Successfully Completed') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Skills Developed / Demonstrated</label>
                            <input type="text" name="skills_developed" class="form-control" placeholder="e.g. Problem Solving, Python, Teamwork, API Design" value="<?= e($enrollment['learning_outcomes'] ?? 'Problem Solving, Teamwork') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Key Contributions & Project Output *</label>
                        <div class="form-hint">Describe what you built, problem statements tackled, and your direct technical or practical contributions.</div>
                        <textarea name="description" rows="4" class="form-control" placeholder="Describe the project or task you completed, key technologies used, and practical challenges resolved..." required><?= e($existingAch['description'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Personal Reflection & Learning Takeaway</label>
                        <div class="form-hint">Reflect on how this experience impacted your professional readiness or career goals.</div>
                        <textarea name="reflection" rows="3" class="form-control" placeholder="What key insights, team dynamics, or problem-solving approaches did you learn from this event?"><?= e($existingAch['evidence_note'] ?? $enrollment['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Project / Portfolio URL (Optional)</label>
                            <div class="form-hint">GitHub repository, deployed demo, or project writeup.</div>
                            <input type="url" name="project_url" class="form-control" placeholder="https://github.com/..." value="<?= e($existingAch['evidence_url'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Certificate or Evidence Upload (Optional)</label>
                            <div class="form-hint">PDF, PNG, JPG (Max 5MB). Encrypted and stored safely.</div>
                            <input type="file" name="certificate_file" class="form-control" accept=".pdf,.png,.jpg,.jpeg">
                            <?php if (!empty($existingAch['evidence_file_path'])): ?>
                                <div style="font-size: 11.5px; color: #059669; margin-top: 4px;">
                                    ✓ Existing file attached: <?= e(basename($existingAch['evidence_file_path'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- AI Resume Achievement Summary Section -->
                    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 20px; margin-bottom: 24px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <label class="form-label" style="margin: 0;">
                                Professional AI Resume Summary
                            </label>
                            <button type="submit" name="action_ai_summary" value="1" class="btn-ai-gen">
                                ✦ Generate Professional AI Summary
                            </button>
                        </div>
                        <div class="form-hint">
                            Gemini synthesizes a high-impact, verified bullet point for your Resume Builder and Portfolio based on your entered information.
                        </div>
                        <textarea name="resume_text" rows="2" class="form-control" style="background:#FFFFFF; font-weight: 500;" placeholder="AI summary will appear here or write your custom bullet point..."><?= e($aiSummaryGenerated ?: ($existingAch['resume_text'] ?? '')) ?></textarea>

                        <label style="display: flex; align-items: center; gap: 8px; margin-top: 12px; font-size: 13px; font-weight: 600; color: #334155; cursor: pointer;">
                            <input type="checkbox" name="show_on_resume" value="1" <?= (!isset($existingAch['show_on_resume']) || (int)$existingAch['show_on_resume'] === 1) ? 'checked' : '' ?>>
                            Include this achievement automatically on my generated resume & public portfolio
                        </label>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 12px; border-top: 1px solid #E2E8F0;">
                        <a href="enrollments.php" style="color: #64748B; font-size: 13.5px; text-decoration: none; font-weight: 600;">
                            &larr; Cancel and Return
                        </a>
                        <button type="submit" class="btn-submit">
                            ✓ Save Achievement & Update Profile
                        </button>
                    </div>
                </form>
            </div>

        </main>
    </div>
</body>
</html>
