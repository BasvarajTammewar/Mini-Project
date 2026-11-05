<?php
/**
 * CareerAct — Module 11: AI Resume Builder & Portfolio
 * 
 * Generates professional, ATS-optimized, editable resumes using only confirmed
 * profile information, skills, education, certificates, and completed extracurricular activities.
 * 
 * Features:
 * - Target role selection (pre-filled with active career goal or custom role)
 * - Transparent data source review before generation
 * - Gemini AI draft generation with action-verb synthesis
 * - Section-by-section regeneration (Summary, Experience, Skills)
 * - Multi-version resume saving and draft management
 * - Print-friendly preview and native ATS PDF export
 * - Plain text and HTML download
 * - Strict multi-tenant isolation and security (CSRF, prepared statements)
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
require_once __DIR__ . '/includes/resume_service.php';

requireCompletedProfile('onboarding.php?msg=complete_profile');

$userId = getCurrentUserId();
$db = getDbConnection();

// 1. Fetch Student Details
$stmtUser = $db->prepare("
    SELECT u.name, u.email, sp.city, sp.education_level, sp.field_of_study, sp.institution 
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.id = :uid LIMIT 1
");
$stmtUser->execute([':uid' => $userId]);
$student = $stmtUser->fetch(PDO::FETCH_ASSOC);

$fullName  = trim((string)($student['name'] ?? 'Student'));
$nameParts = explode(' ', $fullName);
$firstName = $nameParts[0];
$initials  = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));

// 2. Fetch Available Careers for Target Role Dropdown
$allCareersStmt = $db->query("SELECT id, title FROM careers ORDER BY title ASC");
$careerOptions = $allCareersStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Verified Student Data Sources
$sources = getStudentResumeSources($db, $userId);

$defaultRole = $sources['career_goal']['career_title'] ?? 'Software Developer';
$defaultCareerId = $sources['career_goal']['career_id'] ?? null;

$successMsg = '';
$errorMsg   = '';

// 4. Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($submittedToken)) {
        $errorMsg = 'Security validation failed (invalid CSRF token). Please try again.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));

        // Action A: Generate New AI Resume Draft
        if ($action === 'generate_draft') {
            $targetRole = trim((string)($_POST['target_role'] ?? $defaultRole));
            $customRole = trim((string)($_POST['custom_target_role'] ?? ''));
            if ($targetRole === 'custom' && $customRole !== '') {
                $targetRole = $customRole;
            }
            $careerId = !empty($_POST['career_id']) ? (int)$_POST['career_id'] : null;
            $resumeTitle = trim((string)($_POST['resume_title'] ?? ($targetRole . ' Resume')));

            $draft = generateResumeDraft($db, $userId, $targetRole, $careerId);
            $draft['title'] = $resumeTitle !== '' ? $resumeTitle : ($targetRole . ' Resume');

            // Save automatically as a new version
            $newId = saveStudentResume($db, $userId, $draft);
            header("Location: resume.php?id={$newId}&msg=draft_created");
            exit;
        }

        // Action B: Save Edited Resume
        if ($action === 'save_resume') {
            $resumeId = (int)($_POST['resume_id'] ?? 0);
            $targetRole = trim((string)($_POST['target_role'] ?? $defaultRole));
            $resumeTitle = trim((string)($_POST['resume_title'] ?? ($targetRole . ' Resume')));
            $summary = trim((string)($_POST['summary'] ?? ''));

            // Parse skills
            $skillsInput = $_POST['skills'] ?? [];
            $parsedSkills = [];
            if (is_array($skillsInput)) {
                foreach ($skillsInput as $cat => $sVal) {
                    if (is_string($sVal)) {
                        $parsedSkills[$cat] = array_filter(array_map('trim', explode(',', $sVal)));
                    } elseif (is_array($sVal)) {
                        $parsedSkills[$cat] = array_filter(array_map('trim', $sVal));
                    }
                }
            }

            // Parse experience
            $experienceInput = $_POST['experience'] ?? [];
            $parsedExp = [];
            if (is_array($experienceInput)) {
                foreach ($experienceInput as $exp) {
                    $bullets = [];
                    if (!empty($exp['bullet_points'])) {
                        if (is_array($exp['bullet_points'])) {
                            $bullets = array_values(array_filter(array_map('trim', $exp['bullet_points'])));
                        } else {
                            $bullets = array_values(array_filter(array_map('trim', explode("\n", (string)$exp['bullet_points']))));
                        }
                    }
                    $parsedExp[] = [
                        'title'         => trim((string)($exp['title'] ?? '')),
                        'role'          => trim((string)($exp['role'] ?? '')),
                        'organization'  => trim((string)($exp['organization'] ?? '')),
                        'date_display'  => trim((string)($exp['date_display'] ?? '')),
                        'bullet_points' => $bullets,
                    ];
                }
            }

            // Parse projects
            $projectsInput = $_POST['projects'] ?? [];
            $parsedProj = [];
            if (is_array($projectsInput)) {
                foreach ($projectsInput as $p) {
                    if (!empty($p['title'])) {
                        $parsedProj[] = [
                            'title'       => trim((string)$p['title']),
                            'description' => trim((string)($p['description'] ?? '')),
                            'url'         => trim((string)($p['url'] ?? '')),
                        ];
                    }
                }
            }

            // Parse certificates
            $certInput = $_POST['certificates'] ?? [];
            $parsedCert = [];
            if (is_array($certInput)) {
                foreach ($certInput as $c) {
                    if (!empty($c['title'])) {
                        $parsedCert[] = [
                            'title'  => trim((string)$c['title']),
                            'issuer' => trim((string)($c['issuer'] ?? '')),
                            'date'   => trim((string)($c['date'] ?? '')),
                        ];
                    }
                }
            }

            // Parse achievements
            $achInput = $_POST['achievements'] ?? [];
            $parsedAch = [];
            if (is_array($achInput)) {
                foreach ($achInput as $a) {
                    if (!empty($a['title'])) {
                        $parsedAch[] = [
                            'title'       => trim((string)$a['title']),
                            'description' => trim((string)($a['description'] ?? '')),
                        ];
                    }
                }
            }

            $resumeData = [
                'id'             => $resumeId,
                'target_role'    => $targetRole,
                'title'          => $resumeTitle,
                'summary'        => $summary,
                'skills'         => $parsedSkills,
                'experience'     => $parsedExp,
                'projects'       => $parsedProj,
                'certificates'   => $parsedCert,
                'achievements'   => $parsedAch,
                'education'      => $sources['education'],
                'contact_info'   => [
                    'full_name' => $fullName,
                    'email'     => (string)($student['email'] ?? ''),
                    'city'      => (string)($student['city'] ?? ''),
                    'phone'     => '',
                    'links'     => $sources['links'],
                ],
            ];

            try {
                $savedId = saveStudentResume($db, $userId, $resumeData);
                $successMsg = 'Resume saved successfully! All updates are persisted.';
                header("Location: resume.php?id={$savedId}&msg=saved");
                exit;
            } catch (Exception $e) {
                $errorMsg = 'Error saving resume: ' . $e->getMessage();
            }
        }

        // Action C: Regenerate Specific Section
        if ($action === 'regenerate_section') {
            $resumeId = (int)($_POST['resume_id'] ?? 0);
            $sectionKey = trim((string)($_POST['section_key'] ?? ''));
            $targetRole = trim((string)($_POST['target_role'] ?? $defaultRole));

            $currentResume = $resumeId > 0 ? getStudentResume($db, $userId, $resumeId) : null;
            if ($currentResume) {
                $regenResult = regenerateResumeSection($db, $userId, $sectionKey, $targetRole, $currentResume);
                foreach ($regenResult as $k => $v) {
                    $currentResume[$k] = $v;
                }
                saveStudentResume($db, $userId, $currentResume);
                header("Location: resume.php?id={$resumeId}&msg=section_regenerated");
                exit;
            }
        }

        // Action D: Delete Resume Version
        if ($action === 'delete_resume') {
            $resumeId = (int)($_POST['resume_id'] ?? 0);
            if ($resumeId > 0 && deleteStudentResume($db, $userId, $resumeId)) {
                header("Location: resume.php?msg=deleted");
                exit;
            } else {
                $errorMsg = 'Unable to delete resume version.';
            }
        }
    }
}

// 5. Handle Query Actions (Text/HTML Download)
$downloadFormat = trim((string)($_GET['download'] ?? ''));
$requestedId = (int)($_GET['id'] ?? 0);

if ($downloadFormat !== '' && $requestedId > 0) {
    $dlResume = getStudentResume($db, $userId, $requestedId);
    if ($dlResume) {
        $safeTitle = preg_replace('/[^a-zA-Z0-9_-]/', '_', $dlResume['title'] ?: 'Resume');
        if ($downloadFormat === 'txt') {
            header('Content-Type: text/plain; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$safeTitle}.txt\"");

            echo strtoupper($dlResume['contact_info']['full_name'] ?? $fullName) . "\n";
            echo implode(' | ', array_filter([
                $dlResume['contact_info']['email'] ?? '',
                $dlResume['contact_info']['city'] ?? '',
            ])) . "\n\n";

            if (!empty($dlResume['summary'])) {
                echo "PROFESSIONAL SUMMARY\n";
                echo str_repeat('-', 40) . "\n";
                echo $dlResume['summary'] . "\n\n";
            }

            if (!empty($dlResume['education'])) {
                echo "EDUCATION\n";
                echo str_repeat('-', 40) . "\n";
                foreach ($dlResume['education'] as $ed) {
                    echo "{$ed['qualification']} — {$ed['institution']}\n";
                }
                echo "\n";
            }

            if (!empty($dlResume['skills'])) {
                echo "SKILLS\n";
                echo str_repeat('-', 40) . "\n";
                foreach ($dlResume['skills'] as $cat => $sList) {
                    if (!empty($sList)) {
                        echo ucfirst($cat) . ": " . implode(', ', $sList) . "\n";
                    }
                }
                echo "\n";
            }

            if (!empty($dlResume['experience'])) {
                echo "EXPERIENCE & EXTRACURRICULAR ACTIVITIES\n";
                echo str_repeat('-', 40) . "\n";
                foreach ($dlResume['experience'] as $exp) {
                    echo "{$exp['role']} — {$exp['organization']} ({$exp['date_display']})\n";
                    if (!empty($exp['bullet_points'])) {
                        foreach ($exp['bullet_points'] as $b) {
                            echo "  • {$b}\n";
                        }
                    }
                    echo "\n";
                }
            }

            if (!empty($dlResume['projects'])) {
                echo "PROJECTS\n";
                echo str_repeat('-', 40) . "\n";
                foreach ($dlResume['projects'] as $p) {
                    echo "{$p['title']}: {$p['description']}\n";
                }
                echo "\n";
            }

            if (!empty($dlResume['certificates'])) {
                echo "CERTIFICATIONS\n";
                echo str_repeat('-', 40) . "\n";
                foreach ($dlResume['certificates'] as $c) {
                    echo "• {$c['title']} — {$c['issuer']} ({$c['date']})\n";
                }
                echo "\n";
            }

            exit;
        }

        if ($downloadFormat === 'html') {
            header('Content-Type: text/html; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$safeTitle}.html\"");
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title><?= e($dlResume['title']) ?></title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; margin: 40px auto; max-width: 800px; color: #1E293B; line-height: 1.5; }
                    h1 { font-size: 26px; margin: 0 0 6px; text-transform: uppercase; color: #0F172A; text-align: center; }
                    .contact-line { text-align: center; font-size: 13px; color: #64748B; margin-bottom: 24px; }
                    h2 { font-size: 14px; text-transform: uppercase; letter-spacing: 0.8px; border-bottom: 1.5px solid #CBD5E1; padding-bottom: 4px; margin: 20px 0 10px; color: #1769E8; }
                    ul { margin: 6px 0 14px 20px; padding: 0; }
                    li { font-size: 13px; margin-bottom: 4px; color: #334155; }
                    .item-header { display: flex; justify-content: space-between; font-weight: 700; font-size: 14px; color: #0F172A; }
                    .date { font-weight: 400; color: #64748B; font-size: 12px; }
                </style>
            </head>
            <body>
                <?= renderAtsResumeHtml($dlResume) ?>
            </body>
            </html>
            <?php
            exit;
        }
    }
}

// 6. Retrieve All Student Resumes
$savedResumes = getStudentResumeList($db, $userId);

// 7. Resolve Active Resume for Viewing / Editing
$activeResume = null;
if ($requestedId > 0) {
    $activeResume = getStudentResume($db, $userId, $requestedId);
} elseif (!empty($savedResumes)) {
    $activeResume = getStudentResume($db, $userId, (int)$savedResumes[0]['id']);
}

// If no resumes exist yet, synthesize an initial draft automatically
if (!$activeResume) {
    $activeResume = generateResumeDraft($db, $userId, $defaultRole, $defaultCareerId);
    $activeResume['title'] = $defaultRole . ' Resume';
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'draft_created') $successMsg = '✦ AI Resume Draft created! You can now review, edit, or regenerate each section.';
    if ($_GET['msg'] === 'saved') $successMsg = '✓ Resume saved successfully! All updates are persisted.';
    if ($_GET['msg'] === 'section_regenerated') $successMsg = '✦ Section regenerated with fresh AI action-verb wording.';
    if ($_GET['msg'] === 'deleted') $successMsg = 'Resume version deleted.';
}

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($activeResume['title'] ?? 'Resume Builder') ?> — CareerAct</title>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= time() ?>">
    <style>
        svg, svg path, svg circle, svg polygon, svg rect, svg line, svg polyline { fill: none !important; box-sizing: border-box; }
        svg { display: inline-block !important; vertical-align: middle !important; stroke: currentColor !important; stroke-width: 2 !important; }
        .sidebar-brand-icon svg { width: 20px !important; height: 20px !important; }
        .sidebar-nav svg, .logout-link svg { width: 18px !important; height: 18px !important; }

        /* Module 11 Resume Workspace Layout */
        .resume-builder-header {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .rb-title-group h1 {
            font-size: 20px;
            font-weight: 800;
            color: #0F172A;
            margin: 0 0 4px;
        }
        .rb-title-group p {
            font-size: 13px;
            color: #64748B;
            margin: 0;
        }
        .rb-actions-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .rb-version-select {
            padding: 8px 12px;
            border: 1.5px solid #CBD5E1;
            border-radius: 8px;
            font-size: 13px;
            color: #1E293B;
            font-weight: 600;
            background: #FFF;
            cursor: pointer;
        }
        .rb-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            border: none;
        }
        .rb-btn-primary {
            background: #1769E8;
            color: #FFFFFF;
        }
        .rb-btn-primary:hover {
            background: #1255C0;
        }
        .rb-btn-secondary {
            background: #F1F5F9;
            color: #334155;
            border: 1px solid #CBD5E1;
        }
        .rb-btn-secondary:hover {
            background: #E2E8F0;
        }
        .rb-btn-success {
            background: #0F9D8A;
            color: #FFFFFF;
        }
        .rb-btn-success:hover {
            background: #0B7A6B;
        }
        .rb-btn-danger {
            background: #FEF2F2;
            color: #DC2626;
            border: 1px solid #FECACA;
        }
        .rb-btn-danger:hover {
            background: #FEE2E2;
        }

        /* Data Sources Verification Banner */
        .source-verification-pill-bar {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            padding: 12px 18px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            font-size: 12.5px;
            color: #475569;
        }
        .source-pill-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
        }
        .source-pill-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10B981;
        }

        /* Two-Column Editor & Preview Grid */
        .resume-split-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 1024px) {
            .resume-split-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Editor Cards */
        .editor-section-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 18px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .esc-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }
        .esc-title {
            font-size: 14px;
            font-weight: 800;
            color: #0F172A;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .esc-regen-btn {
            background: #EEF4FF;
            color: #1769E8;
            border: 1px solid #C7D9FB;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .esc-regen-btn:hover {
            background: #DBEAFE;
        }

        .form-control-text {
            width: 100%;
            box-sizing: border-box;
            border: 1.5px solid #CBD5E1;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 13px;
            color: #1E293B;
            font-family: inherit;
            line-height: 1.5;
            transition: border-color 0.2s;
        }
        .form-control-text:focus {
            outline: none;
            border-color: #1769E8;
        }

        /* ATS Clean Preview Sheet */
        .preview-sticky-wrap {
            position: sticky;
            top: 24px;
        }
        .ats-resume-sheet {
            background: #FFFFFF;
            border: 1px solid #CBD5E1;
            border-radius: 8px;
            padding: 40px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            color: #0F172A;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.45;
        }
        .ats-header {
            text-align: center;
            margin-bottom: 20px;
        }
        .ats-candidate-name {
            font-size: 24px;
            font-weight: 800;
            color: #0F172A;
            margin: 0 0 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .ats-contact-line {
            font-size: 12.5px;
            color: #475569;
            font-weight: 500;
        }
        .ats-section {
            margin-top: 18px;
        }
        .ats-section-title {
            font-size: 12px;
            font-weight: 800;
            color: #1769E8;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin: 0 0 4px;
        }
        .ats-section-divider {
            height: 1.5px;
            background: #CBD5E1;
            margin-bottom: 10px;
        }
        .ats-summary-text {
            font-size: 12.5px;
            color: #334155;
            line-height: 1.5;
            margin: 0;
            text-align: justify;
        }
        .ats-item-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 4px;
        }
        .ats-item-title {
            font-size: 13px;
            font-weight: 700;
            color: #0F172A;
        }
        .ats-item-org {
            font-size: 12.5px;
            color: #475569;
            font-weight: 600;
        }
        .ats-item-subtitle {
            font-size: 12px;
            color: #64748B;
        }
        .ats-item-date {
            font-size: 11.5px;
            color: #64748B;
            font-weight: 600;
            white-space: nowrap;
        }
        .ats-bullet-list {
            margin: 4px 0 10px 18px;
            padding: 0;
        }
        .ats-bullet-list li {
            font-size: 12px;
            color: #334155;
            margin-bottom: 3px;
            line-height: 1.45;
        }
        .ats-skill-group {
            font-size: 12px;
            margin-bottom: 4px;
            line-height: 1.4;
        }
        .ats-skill-label {
            color: #0F172A;
            font-weight: 700;
        }
        .ats-skill-values {
            color: #334155;
        }
        .ats-proj-item, .ats-exp-item {
            margin-bottom: 12px;
        }
        .ats-proj-desc {
            font-size: 12px;
            color: #475569;
            margin: 2px 0 0;
            line-height: 1.4;
        }
        .ats-item-link {
            font-size: 11px;
            color: #1769E8;
            text-decoration: underline;
        }

        /* Native Print & Export Styles */
        @media print {
            body {
                background: #FFFFFF !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .dashboard-sidebar,
            .dashboard-topbar,
            .resume-builder-header,
            .source-verification-pill-bar,
            .editor-column,
            .preview-title-bar,
            .btn-view-pathway,
            .topbar-actions,
            .sidebar-footer {
                display: none !important;
            }
            .dashboard-main {
                margin: 0 !important;
                padding: 0 !important;
            }
            .dashboard-content {
                padding: 0 !important;
            }
            .resume-split-grid {
                display: block !important;
            }
            .ats-resume-sheet {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
            }
            .ats-section-divider {
                background: #000 !important;
            }
            .ats-section-title {
                color: #000 !important;
            }
        }
    </style>
</head>
<body class="dashboard-body">

    <!-- Sidebar Navigation -->
    <aside class="dashboard-sidebar" id="dashboardSidebar" aria-label="Portal Navigation">
        <div>
            <div class="sidebar-header">
                <a href="dashboard.php" class="sidebar-brand-wrapper">
                    <div class="sidebar-brand-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
                    </div>
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
                        <a href="enrollments.php" class="nav-link">
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
                        <a href="resume.php" class="nav-link active" aria-current="page">
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
            <a href="logout.php" class="logout-link" id="sidebarLogout">
                <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- Main Content Area -->
    <div class="dashboard-main">
        <header class="dashboard-topbar">
            <div class="topbar-welcome">
                <span class="welcome-title">AI Resume Builder & Portfolio</span>
                <span class="welcome-subtitle">Module 11: Professional, Role-Targeted ATS Resume Studio</span>
            </div>
            <div class="topbar-actions">
                <a href="portfolio.php" class="btn-view-details" style="padding: 7px 14px;">My Portfolio &rarr;</a>
                <a href="profile.php" class="user-profile-chip">
                    <div class="avatar-circle"><?= e($initials) ?></div>
                    <div class="user-text-info"><span class="user-name"><?= e($fullName) ?></span><span class="user-level">Student</span></div>
                </a>
            </div>
        </header>

        <main class="dashboard-content">

            <?php if (!empty($successMsg)): ?>
                <div style="background:#ECFDF5; border:1px solid #A7F3D0; color:#065F46; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-size:13.5px; font-weight:600;">
                    <?= e($successMsg) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($errorMsg)): ?>
                <div style="background:#FEF2F2; border:1px solid #FECACA; color:#991B1B; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-size:13.5px; font-weight:600;">
                    <?= e($errorMsg) ?>
                </div>
            <?php endif; ?>

            <!-- Workspace Action Toolbar -->
            <div class="resume-builder-header">
                <div class="rb-title-group">
                    <h1><?= e($activeResume['title'] ?? 'Resume Workspace') ?></h1>
                    <p>Target Career Role: <strong><?= e($activeResume['target_role'] ?? $defaultRole) ?></strong></p>
                </div>

                <div class="rb-actions-group">
                    <!-- Version Switcher -->
                    <?php if (!empty($savedResumes)): ?>
                        <select class="rb-version-select" onchange="if(this.value) window.location.href='resume.php?id='+this.value;" title="Switch between saved resume versions">
                            <option value="">Switch Saved Version (<?= count($savedResumes) ?>)</option>
                            <?php foreach ($savedResumes as $sv): ?>
                                <option value="<?= (int)$sv['id'] ?>" <?= ((int)($activeResume['id'] ?? 0) === (int)$sv['id']) ? 'selected' : '' ?>>
                                    <?= e($sv['title']) ?> (<?= e($sv['target_role']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <button type="button" onclick="document.getElementById('newVersionModal').style.display='flex';" class="rb-btn rb-btn-secondary" title="Create a new resume tailored to another career role">
                        <svg width="14" height="14" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        <span>New Role Version</span>
                    </button>

                    <button type="button" onclick="window.print()" class="rb-btn rb-btn-primary" title="Print or save as ATS clean PDF">
                        <svg width="14" height="14" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        <span>Print / Export PDF</span>
                    </button>

                    <?php if (!empty($activeResume['id'])): ?>
                        <a href="resume.php?id=<?= (int)$activeResume['id'] ?>&download=txt" class="rb-btn rb-btn-secondary" title="Download ATS Plain Text">
                            <span>Text (.txt)</span>
                        </a>
                        <a href="resume.php?id=<?= (int)$activeResume['id'] ?>&download=html" class="rb-btn rb-btn-secondary" title="Download HTML">
                            <span>HTML</span>
                        </a>
                        <form method="POST" action="resume.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this resume version?');">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_resume">
                            <input type="hidden" name="resume_id" value="<?= (int)$activeResume['id'] ?>">
                            <button type="submit" class="rb-btn rb-btn-danger" title="Delete this resume version">
                                <svg width="13" height="13" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Verified Data Sources Integrity Bar -->
            <div class="source-verification-pill-bar">
                <div style="display:flex; align-items:center; gap:18px; flex-wrap:wrap;">
                    <span class="source-pill-item">
                        <span class="source-pill-dot"></span>
                        <strong>Verified Facts Only</strong> (Strictly excludes uncompleted activities)
                    </span>
                    <span>Education: <strong><?= count($sources['education']) ?></strong></span>
                    <span>Confirmed Skills: <strong><?= count($sources['skills']) ?></strong></span>
                    <span>Completed Experiences: <strong><?= count($sources['completed_experience']) ?></strong></span>
                    <span>Projects: <strong><?= count($sources['projects']) ?></strong></span>
                    <span>Certificates: <strong><?= count($sources['certificates']) ?></strong></span>
                </div>
                <div>
                    <button type="button" onclick="document.getElementById('sourceDataDrawer').style.display = (document.getElementById('sourceDataDrawer').style.display==='none'?'block':'none');" style="background:none; border:none; color:#1769E8; font-weight:700; cursor:pointer; font-size:12px;">
                        Inspect Verified Sources &darr;
                    </button>
                </div>
            </div>

            <!-- Source Data Inspection Drawer (Collapsible) -->
            <div id="sourceDataDrawer" style="display:none; background:#FFF; border:1px solid #E2E8F0; border-radius:10px; padding:20px; margin-bottom:24px; font-size:12.5px; color:#475569;">
                <h3 style="font-size:13px; font-weight:800; color:#0F172A; margin:0 0 10px 0; text-transform:uppercase;">Student Verified Information Used for this Resume:</h3>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:16px;">
                    <div>
                        <strong style="color:#0F172A;">Confirmed Skills:</strong>
                        <p style="margin:4px 0;"><?= !empty($sources['skills']) ? implode(', ', array_column($sources['skills'], 'name')) : 'None recorded yet.' ?></p>
                    </div>
                    <div>
                        <strong style="color:#0F172A;">Completed Extracurricular Activities:</strong>
                        <ul style="margin:4px 0 0 16px; padding:0;">
                            <?php foreach ($sources['completed_experience'] as $cExp): ?>
                                <li><?= e($cExp['title']) ?> (<?= e($cExp['role']) ?>)</li>
                            <?php endforeach; ?>
                            <?php if (empty($sources['completed_experience'])): ?>
                                <li style="list-style:none; margin-left:-16px; color:#94A3B8;">No completed activities recorded. Complete activities in Module 9/10 to showcase here.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div>
                        <strong style="color:#0F172A;">Certificates & Credentials:</strong>
                        <p style="margin:4px 0;"><?= !empty($sources['certificates']) ? implode(', ', array_column($sources['certificates'], 'title')) : 'None recorded.' ?></p>
                    </div>
                </div>
            </div>

            <!-- Main Workspace: Editor on Left, Live ATS Sheet on Right -->
            <div class="resume-split-grid">
                
                <!-- Left Column: Interactive Section Editor -->
                <div class="editor-column">
                    <form method="POST" action="resume.php" id="resumeEditForm">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="save_resume">
                        <input type="hidden" name="resume_id" value="<?= (int)($activeResume['id'] ?? 0) ?>">
                        <input type="hidden" name="target_role" value="<?= e($activeResume['target_role'] ?? $defaultRole) ?>">

                        <!-- Header & Title Card -->
                        <div class="editor-section-card">
                            <div class="esc-header">
                                <span class="esc-title">Resume Metadata</span>
                            </div>
                            <div style="margin-bottom:12px;">
                                <label style="font-size:12px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">Resume Version Name</label>
                                <input type="text" name="resume_title" class="form-control-text" value="<?= e($activeResume['title'] ?? ($activeResume['target_role'] . ' Resume')) ?>" required>
                            </div>
                            <div>
                                <label style="font-size:12px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">Target Career Role</label>
                                <input type="text" class="form-control-text" value="<?= e($activeResume['target_role'] ?? $defaultRole) ?>" readonly style="background:#F8FAFC;">
                            </div>
                        </div>

                        <!-- Professional Summary Card -->
                        <div class="editor-section-card">
                            <div class="esc-header">
                                <span class="esc-title">Professional Summary</span>
                                <button type="button" onclick="triggerRegen('summary')" class="esc-regen-btn" title="AI rewrite tailored to target role">
                                    <span>✦ AI Rewrite</span>
                                </button>
                            </div>
                            <textarea name="summary" id="editorSummary" rows="4" class="form-control-text" placeholder="2-3 sentence career objective..."><?= e($activeResume['summary'] ?? '') ?></textarea>
                        </div>

                        <!-- Core Skills Card -->
                        <div class="editor-section-card">
                            <div class="esc-header">
                                <span class="esc-title">Core Skills & Competencies</span>
                                <button type="button" onclick="triggerRegen('skills')" class="esc-regen-btn" title="Reorder by role relevance">
                                    <span>✦ Prioritize</span>
                                </button>
                            </div>
                            <p style="font-size:11.5px; color:#64748B; margin:0 0 10px 0;">Comma-separated confirmed skills. Only skills you have entered and confirmed are permitted.</p>
                            
                            <?php 
                            $skillsArray = $activeResume['skills'] ?? [];
                            foreach (['technical' => 'Technical Skills', 'analytical' => 'Analytical Skills', 'interpersonal' => 'Interpersonal / Soft Skills', 'transferable' => 'Transferable Skills'] as $catKey => $catLabel):
                                $valStr = isset($skillsArray[$catKey]) ? implode(', ', $skillsArray[$catKey]) : '';
                            ?>
                                <div style="margin-bottom:10px;">
                                    <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:3px;"><?= e($catLabel) ?></label>
                                    <input type="text" name="skills[<?= e($catKey) ?>]" class="form-control-text" value="<?= e($valStr) ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Completed Activities & Experience Card -->
                        <div class="editor-section-card">
                            <div class="esc-header">
                                <span class="esc-title">Completed Extracurricular Activities</span>
                                <button type="button" onclick="triggerRegen('experience')" class="esc-regen-btn" title="AI synthesize action-verb bullets">
                                    <span>✦ AI Bullets</span>
                                </button>
                            </div>
                            <p style="font-size:11.5px; color:#64748B; margin:0 0 12px 0;">High-impact bullet points formulated from your completed extracurricular milestones.</p>

                            <div id="experienceItemsContainer">
                                <?php 
                                $expList = $activeResume['experience'] ?? [];
                                if (empty($expList)):
                                ?>
                                    <div style="background:#F8FAFC; border:1px dashed #CBD5E1; border-radius:8px; padding:16px; text-align:center; font-size:12.5px; color:#64748B;">
                                        No completed activities recorded yet. Complete activities in Module 9/10 to automatically populate this section.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($expList as $i => $exp): ?>
                                        <div style="border:1px solid #E2E8F0; border-radius:8px; padding:14px; margin-bottom:14px; background:#FAFCFF;">
                                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:8px;">
                                                <div>
                                                    <label style="font-size:11px; font-weight:700; color:#475569;">Activity / Event</label>
                                                    <input type="text" name="experience[<?= $i ?>][title]" class="form-control-text" value="<?= e($exp['title'] ?? '') ?>">
                                                </div>
                                                <div>
                                                    <label style="font-size:11px; font-weight:700; color:#475569;">Role</label>
                                                    <input type="text" name="experience[<?= $i ?>][role]" class="form-control-text" value="<?= e($exp['role'] ?? '') ?>">
                                                </div>
                                            </div>
                                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:8px;">
                                                <div>
                                                    <label style="font-size:11px; font-weight:700; color:#475569;">Organization / Host</label>
                                                    <input type="text" name="experience[<?= $i ?>][organization]" class="form-control-text" value="<?= e($exp['organization'] ?? '') ?>">
                                                </div>
                                                <div>
                                                    <label style="font-size:11px; font-weight:700; color:#475569;">Date Range</label>
                                                    <input type="text" name="experience[<?= $i ?>][date_display]" class="form-control-text" value="<?= e($exp['date_display'] ?? '') ?>">
                                                </div>
                                            </div>
                                            <div>
                                                <label style="font-size:11px; font-weight:700; color:#475569;">Bullet Points (1 per line)</label>
                                                <?php 
                                                $bText = !empty($exp['bullet_points']) ? implode("\n", $exp['bullet_points']) : '';
                                                ?>
                                                <textarea name="experience[<?= $i ?>][bullet_points]" rows="3" class="form-control-text"><?= e($bText) ?></textarea>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Projects Card -->
                        <div class="editor-section-card">
                            <div class="esc-header">
                                <span class="esc-title">Projects & Technical Builds</span>
                            </div>
                            <?php 
                            $projList = $activeResume['projects'] ?? [];
                            foreach ($projList as $pi => $pj):
                            ?>
                                <div style="border:1px solid #E2E8F0; border-radius:8px; padding:12px; margin-bottom:10px; background:#FAFCFF;">
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:6px;">
                                        <input type="text" name="projects[<?= $pi ?>][title]" class="form-control-text" placeholder="Project Name" value="<?= e($pj['title'] ?? '') ?>">
                                        <input type="text" name="projects[<?= $pi ?>][url]" class="form-control-text" placeholder="Repository/Live URL" value="<?= e($pj['url'] ?? '') ?>">
                                    </div>
                                    <textarea name="projects[<?= $pi ?>][description]" rows="2" class="form-control-text" placeholder="Project description..."><?= e($pj['description'] ?? '') ?></textarea>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($projList)): ?>
                                <p style="font-size:12px; color:#94A3B8; margin:0;">Add projects to <a href="portfolio.php" style="color:#1769E8; font-weight:700;">My Portfolio</a> to showcase here.</p>
                            <?php endif; ?>
                        </div>

                        <!-- Save Actions Bottom Bar -->
                        <div style="margin-top:20px; display:flex; gap:12px;">
                            <button type="submit" class="rb-btn rb-btn-success" style="padding:10px 24px; font-size:14px;">
                                <span>Save Resume Draft</span>
                            </button>
                        </div>
                    </form>

                    <!-- Hidden Form for Section Regeneration -->
                    <form method="POST" action="resume.php" id="regenSectionForm" style="display:none;">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="regenerate_section">
                        <input type="hidden" name="resume_id" value="<?= (int)($activeResume['id'] ?? 0) ?>">
                        <input type="hidden" name="target_role" value="<?= e($activeResume['target_role'] ?? $defaultRole) ?>">
                        <input type="hidden" name="section_key" id="regenSectionKey" value="">
                    </form>
                </div>

                <!-- Right Column: Live ATS Printable Sheet -->
                <div class="preview-column">
                    <div class="preview-sticky-wrap">
                        <div class="preview-title-bar" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                            <span style="font-size:13px; font-weight:800; color:#334155; text-transform:uppercase; letter-spacing:0.5px;">Live ATS Resume Preview</span>
                            <span style="font-size:11.5px; color:#10B981; font-weight:700; background:#ECFDF5; border:1px solid #A7F3D0; padding:3px 8px; border-radius:6px;">
                                ATS-Optimized Clean Format
                            </span>
                        </div>

                        <?= renderAtsResumeHtml($activeResume) ?>
                    </div>
                </div>

            </div>

        </main>
    </div>

    <!-- Modal: Create New Resume Version for Different Role -->
    <div id="newVersionModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:#FFF; border-radius:14px; padding:28px; width:100%; max-width:520px; box-shadow:0 10px 30px rgba(0,0,0,0.2);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h3 style="font-size:18px; font-weight:800; color:#0F172A; margin:0;">Create Role-Tailored Resume</h3>
                <button type="button" onclick="document.getElementById('newVersionModal').style.display='none';" style="background:none; border:none; font-size:20px; color:#64748B; cursor:pointer;">&times;</button>
            </div>
            <p style="font-size:13px; color:#64748B; line-height:1.5; margin-bottom:18px;">
                CareerAct will synthesize your verified education, confirmed skills, and completed extracurricular activities specifically tailored to this new target role.
            </p>

            <form method="POST" action="resume.php">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="generate_draft">

                <div style="margin-bottom:14px;">
                    <label style="font-size:12.5px; font-weight:700; color:#334155; display:block; margin-bottom:6px;">Select Target Career Role</label>
                    <select name="target_role" id="modalTargetRoleSelect" class="form-control-text" onchange="toggleCustomRole(this.value)">
                        <?php foreach ($careerOptions as $cOpt): ?>
                            <option value="<?= e($cOpt['title']) ?>" <?= (strcasecmp($defaultRole, $cOpt['title']) === 0) ? 'selected' : '' ?>>
                                <?= e($cOpt['title']) ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="custom">+ Other Target Role (Type Custom)</option>
                    </select>
                </div>

                <div id="customRoleInputWrapper" style="display:none; margin-bottom:14px;">
                    <label style="font-size:12.5px; font-weight:700; color:#334155; display:block; margin-bottom:6px;">Custom Target Role</label>
                    <input type="text" name="custom_target_role" placeholder="e.g. Full Stack Developer, AI Engineer, Product Analyst" class="form-control-text">
                </div>

                <div style="margin-bottom:20px;">
                    <label style="font-size:12.5px; font-weight:700; color:#334155; display:block; margin-bottom:6px;">Resume Version Name</label>
                    <input type="text" name="resume_title" placeholder="e.g. Software Developer - Campus Placements" class="form-control-text">
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="document.getElementById('newVersionModal').style.display='none';" class="rb-btn rb-btn-secondary">Cancel</button>
                    <button type="submit" class="rb-btn rb-btn-primary">Generate Role Draft</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function triggerRegen(sectionKey) {
            document.getElementById('regenSectionKey').value = sectionKey;
            document.getElementById('regenSectionForm').submit();
        }

        function toggleCustomRole(val) {
            const wrap = document.getElementById('customRoleInputWrapper');
            if (wrap) {
                wrap.style.display = (val === 'custom') ? 'block' : 'none';
            }
        }
    </script>
</body>
</html>
