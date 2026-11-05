<?php
/**
 * CareerAct — Module 12: Student Portfolio & Achievement Showcase Studio
 * 
 * Manages the student's personal portfolio showcase:
 * - Selective visibility controls (sections and individual items)
 * - Private-by-default sharing with unique share link generation
 * - Gemini AI bio generation and description enhancement with student review
 * - Contact privacy protection (email and phone hidden by default)
 * - Projects, certificates, and achievements management
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
require_once __DIR__ . '/includes/portfolio_service.php';

requireCompletedProfile('onboarding.php?msg=complete_profile');

$userId = getCurrentUserId();
$db = getDbConnection();

$stmtUser = $db->prepare("SELECT name, email FROM users WHERE id = :user_id LIMIT 1");
$stmtUser->execute([':user_id' => $userId]);
$student = $stmtUser->fetch(PDO::FETCH_ASSOC);
$fullName = $student['name'] ?? 'Student';
$initials = strtoupper(substr($fullName, 0, 1));

$successMsg = '';
$errorMsg = '';
$aiBioDraft = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($submittedToken)) {
        $errorMsg = 'Security validation failed (invalid CSRF token). Please try again.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));

        // Action A: Update Portfolio Settings & Privacy
        if ($action === 'update_settings') {
            $isPublic     = !empty($_POST['is_public']) ? 1 : 0;
            $headline     = trim((string)($_POST['headline'] ?? ''));
            $bio          = trim((string)($_POST['bio'] ?? ''));
            $displayEmail = !empty($_POST['display_email']) ? 1 : 0;
            $displayPhone = !empty($_POST['display_phone']) ? 1 : 0;
            $linkedin     = trim((string)($_POST['linkedin_url'] ?? ''));
            $github       = trim((string)($_POST['github_url'] ?? ''));
            $website      = trim((string)($_POST['website_url'] ?? ''));

            // Visible sections map
            $sections = [
                'summary'      => !empty($_POST['sec_summary']),
                'education'    => !empty($_POST['sec_education']),
                'skills'       => !empty($_POST['sec_skills']),
                'activities'   => !empty($_POST['sec_activities']),
                'projects'     => !empty($_POST['sec_projects']),
                'certificates' => !empty($_POST['sec_certificates']),
                'achievements' => !empty($_POST['sec_achievements']),
            ];

            // Item-level visibility map
            $itemVis = $_POST['item_vis'] ?? [];
            $itemVisibilityMap = [];
            if (is_array($itemVis)) {
                foreach ($itemVis as $k => $v) {
                    $itemVisibilityMap[$k] = !empty($v);
                }
            }

            // Custom descriptions map
            $customDescInput = $_POST['custom_desc'] ?? [];
            $customDescMap = [];
            if (is_array($customDescInput)) {
                foreach ($customDescInput as $k => $d) {
                    $dStr = trim((string)$d);
                    if ($dStr !== '') {
                        $customDescMap[$k] = $dStr;
                    }
                }
            }

            $updateData = [
                'is_public'            => $isPublic,
                'headline'             => $headline,
                'bio'                  => $bio,
                'display_email'        => $displayEmail,
                'display_phone'        => $displayPhone,
                'linkedin_url'         => $linkedin,
                'github_url'           => $github,
                'website_url'          => $website,
                'visible_sections'     => $sections,
                'item_visibility'      => $itemVisibilityMap,
                'custom_descriptions' => $customDescMap,
            ];

            if (updateStudentPortfolioSettings($db, $userId, $updateData)) {
                $successMsg = 'Portfolio settings, privacy controls, and content saved successfully!';
            } else {
                $errorMsg = 'Unable to save portfolio settings.';
            }
        }

        // Action B: Regenerate Share Link
        if ($action === 'regenerate_share_link') {
            regeneratePortfolioShareToken($db, $userId);
            $successMsg = 'Share link regenerated. Your previous share URL has been revoked.';
        }

        // Action C: Generate AI Bio Draft
        if ($action === 'generate_ai_bio') {
            $aiBioDraft = generatePortfolioBioDraft($db, $userId);
            $successMsg = '✦ AI Bio Draft generated! Review and click "Accept Draft" to use it in your portfolio.';
        }

        // Action D: Add Portfolio Project / Certificate
        if ($action === 'add_portfolio_item') {
            $title = trim((string)($_POST['item_title'] ?? ''));
            $itemType = trim((string)($_POST['item_type'] ?? 'project'));
            $url = trim((string)($_POST['item_url'] ?? ''));
            $desc = trim((string)($_POST['item_description'] ?? ''));

            if (!in_array($itemType, ['project', 'certificate', 'publication', 'link', 'other'], true)) {
                $itemType = 'project';
            }

            if ($title !== '') {
                $stmtIns = $db->prepare("
                    INSERT INTO portfolio_items (user_id, title, item_type, url, description, show_on_resume, created_at)
                    VALUES (:uid, :title, :type, :url, :desc, 1, NOW())
                ");
                $stmtIns->execute([
                    ':uid'   => $userId,
                    ':title' => $title,
                    ':type'  => $itemType,
                    ':url'   => $url !== '' ? $url : null,
                    ':desc'  => $desc !== '' ? $desc : null,
                ]);
                $successMsg = "Item '{$title}' added to your portfolio!";
            } else {
                $errorMsg = 'Please enter a title for this portfolio item.';
            }
        }

        // Action E: Delete Portfolio Item
        if ($action === 'delete_item') {
            $delId = (int)($_POST['item_id'] ?? 0);
            if ($delId > 0) {
                $stmtDel = $db->prepare("DELETE FROM portfolio_items WHERE id = :id AND user_id = :uid");
                $stmtDel->execute([':id' => $delId, ':uid' => $userId]);
                $successMsg = 'Portfolio item removed.';
            }
        }
    }
}

// Fetch full private portfolio data for studio management
$portfolioData = getFullPortfolioData($db, $userId, false);
$settings = $portfolioData['settings'];
$cand = $portfolioData['candidate'];

// Construct share URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$shareUrl = $protocol . $host . "/Mini-Project/portfolio_view.php?share=" . urlencode($settings['share_token']);

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Portfolio Studio — CareerAct</title>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= time() ?>">
    <style>
        svg, svg path, svg circle, svg polygon, svg rect, svg line, svg polyline { fill: none !important; box-sizing: border-box; }
        svg { display: inline-block !important; vertical-align: middle !important; stroke: currentColor !important; stroke-width: 2 !important; }
        .sidebar-brand-icon svg { width: 20px !important; height: 20px !important; }
        .sidebar-nav svg, .logout-link svg { width: 18px !important; height: 18px !important; }

        .port-header-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .port-sharing-panel {
            background: #F8FAFC;
            border: 1.5px solid #CBD5E1;
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 24px;
        }
        .share-url-box {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }
        .share-url-input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #CBD5E1;
            border-radius: 8px;
            font-size: 13px;
            background: #FFFFFF;
            color: #334155;
            font-family: monospace;
        }

        .studio-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 1024px) {
            .studio-grid {
                grid-template-columns: 1fr;
            }
        }

        .studio-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .sc-title {
            font-size: 14px;
            font-weight: 800;
            color: #0F172A;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .form-label {
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            display: block;
            margin-bottom: 4px;
        }
        .form-input {
            width: 100%;
            box-sizing: border-box;
            border: 1.5px solid #CBD5E1;
            border-radius: 8px;
            padding: 9px 12px;
            font-size: 13px;
            color: #1E293B;
            font-family: inherit;
            margin-bottom: 12px;
        }
        .form-input:focus {
            outline: none;
            border-color: #1769E8;
        }

        .toggle-switch-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #F1F5F9;
        }
        .toggle-switch-row:last-child {
            border-bottom: none;
        }
        .toggle-label {
            font-size: 13px;
            font-weight: 600;
            color: #334155;
        }

        .item-mgmt-row {
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 10px;
            background: #FAFCFF;
        }
        .ai-draft-box {
            background: #EEF4FF;
            border: 1px solid #C7D9FB;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 14px;
            font-size: 13px;
            color: #1E293B;
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
                        <a href="portfolio.php" class="nav-link active" aria-current="page">
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
                <span class="welcome-title">Student Portfolio & Showcase Studio</span>
                <span class="welcome-subtitle">Module 12: Verified Achievements & Selective Public Sharing</span>
            </div>
            <div class="topbar-actions">
                <a href="resume.php" class="btn-view-details" style="padding: 7px 14px;">Open AI Resume &rarr;</a>
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

            <!-- Header Panel -->
            <div class="port-header-card">
                <div>
                    <h1 style="font-size:20px; font-weight:800; color:#0F172A; margin:0 0 4px;">My Verified Achievement Portfolio</h1>
                    <p style="font-size:13px; color:#64748B; margin:0;">
                        Present verified credentials, hackathon leadership, and technical builds. Sourced strictly from confirmed records.
                    </p>
                </div>
                <div style="display:flex; gap:10px; align-items:center;">
                    <?php if ($settings['is_public']): ?>
                        <a href="<?= e($shareUrl) ?>" target="_blank" rel="noopener noreferrer" style="background:#1769E8; color:#fff; padding:8px 16px; border-radius:8px; font-weight:700; font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                            <span>View Public Page &#8599;</span>
                        </a>
                    <?php else: ?>
                        <span style="font-size:12.5px; font-weight:700; color:#64748B; background:#F1F5F9; border:1px solid #CBD5E1; padding:6px 14px; border-radius:8px;">
                            🔒 Private Portfolio
                        </span>
                    <?php endif; ?>
                    <button type="button" onclick="document.getElementById('addItemModal').style.display='flex';" style="background:#F1F5F9; color:#1E293B; border:1px solid #CBD5E1; padding:8px 14px; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer;">
                        + Add Project / Link
                    </button>
                </div>
            </div>

            <!-- Sharing & Privacy Control Panel -->
            <div class="port-sharing-panel">
                <form method="POST" action="portfolio.php">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="update_settings">

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:12px;">
                        <div>
                            <strong style="font-size:14px; color:#0F172A;">Public Showcase Sharing</strong>
                            <p style="font-size:12px; color:#64748B; margin:2px 0 0;">
                                When enabled, anyone with your secure link can view the sections you approve. Contact info is protected by default.
                            </p>
                        </div>
                        <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; font-weight:700; color:#1E293B;">
                            <input type="checkbox" name="is_public" value="1" <?= $settings['is_public'] ? 'checked' : '' ?> onchange="this.form.submit()">
                            <span><?= $settings['is_public'] ? 'Public Sharing Active' : 'Make Portfolio Public' ?></span>
                        </label>
                    </div>

                    <?php if ($settings['is_public']): ?>
                        <div class="share-url-box">
                            <input type="text" id="shareUrlInput" class="share-url-input" value="<?= e($shareUrl) ?>" readonly>
                            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('shareUrlInput').value); alert('Share link copied to clipboard!');" style="background:#1769E8; color:#fff; border:none; padding:8px 14px; border-radius:8px; font-weight:700; font-size:12px; cursor:pointer;">
                                Copy Link
                            </button>
                            <button type="button" onclick="if(confirm('Revoke current share link and generate a new one?')){ document.getElementById('regenTokenForm').submit(); }" style="background:#FFF; color:#64748B; border:1px solid #CBD5E1; padding:8px 12px; border-radius:8px; font-weight:600; font-size:12px; cursor:pointer;">
                                Revoke / Reset Link
                            </button>
                        </div>
                    <?php endif; ?>
                </form>

                <!-- Hidden form for regenerating token -->
                <form method="POST" action="portfolio.php" id="regenTokenForm" style="display:none;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="regenerate_share_link">
                </form>
            </div>

            <!-- Studio Form Grid: Content Customization & Privacy Checkboxes -->
            <form method="POST" action="portfolio.php" id="portfolioStudioForm">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="update_settings">
                <input type="hidden" name="is_public" value="<?= $settings['is_public'] ? '1' : '0' ?>">

                <div class="studio-grid">

                    <!-- Left Column: Biography, Content Overrides & Item Visibility -->
                    <div>

                        <!-- Card: Headline & Biography -->
                        <div class="studio-card">
                            <div class="sc-title">
                                <span>Headline & Professional Biography</span>
                                <button type="button" onclick="document.getElementById('aiBioForm').submit();" style="background:#EEF4FF; border:1px solid #C7D9FB; color:#1769E8; border-radius:6px; padding:4px 10px; font-size:11.5px; font-weight:700; cursor:pointer;">
                                    ✦ AI Draft Bio
                                </button>
                            </div>

                            <?php if (!empty($aiBioDraft)): ?>
                                <div class="ai-draft-box">
                                    <strong style="color:#1769E8; display:block; margin-bottom:4px;">✦ AI Generated Draft:</strong>
                                    <p style="margin:0 0 10px;"><?= e($aiBioDraft) ?></p>
                                    <button type="button" onclick="document.getElementById('bioTextarea').value = <?= json_encode($aiBioDraft) ?>; this.parentElement.style.display='none';" style="background:#1769E8; color:#fff; border:none; padding:4px 10px; border-radius:6px; font-size:11.5px; font-weight:700; cursor:pointer;">
                                        Accept Draft &rarr;
                                    </button>
                                </div>
                            <?php endif; ?>

                            <label class="form-label">Professional Headline</label>
                            <input type="text" name="headline" class="form-input" value="<?= e($settings['headline'] ?: $cand['headline']) ?>" placeholder="e.g. Full-Stack Developer & Cloud Architecture Enthusiast">

                            <label class="form-label">Biography / About Me</label>
                            <textarea name="bio" id="bioTextarea" rows="4" class="form-input" placeholder="Introduce your career goals and verified competencies..."><?= e($settings['bio'] ?: $cand['bio']) ?></textarea>
                        </div>

                        <!-- Card: Completed Activities Customization & Visibility -->
                        <div class="studio-card">
                            <div class="sc-title">
                                <span>Completed Extracurricular Activities</span>
                                <span style="font-size:11px; font-weight:600; color:#64748B;">Strictly verified completions only</span>
                            </div>

                            <?php if (empty($portfolioData['activities'])): ?>
                                <p style="font-size:13px; color:#94A3B8; margin:0;">No completed activities recorded yet. Complete enrolled activities in Module 9/10 to showcase here.</p>
                            <?php else: ?>
                                <?php foreach ($portfolioData['activities'] as $act): 
                                    $key = $act['item_key'];
                                    $checked = !isset($settings['item_visibility'][$key]) || !empty($settings['item_visibility'][$key]);
                                ?>
                                    <div class="item-mgmt-row">
                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                            <div>
                                                <strong style="font-size:13.5px; color:#0F172A;"><?= e($act['title']) ?></strong>
                                                <span style="font-size:12px; color:#64748B;"> &bull; Role: <?= e($act['role']) ?></span>
                                            </div>
                                            <label style="font-size:12px; font-weight:700; color:#475569; display:inline-flex; align-items:center; gap:4px;">
                                                <input type="checkbox" name="item_vis[<?= e($key) ?>]" value="1" <?= $checked ? 'checked' : '' ?>>
                                                <span>Show in Portfolio</span>
                                            </label>
                                        </div>
                                        <textarea name="custom_desc[<?= e($key) ?>]" rows="2" class="form-input" style="margin-bottom:0;" placeholder="Custom description for this achievement..."><?= e($act['description']) ?></textarea>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Card: Technical Projects & Builds -->
                        <div class="studio-card">
                            <div class="sc-title">
                                <span>Technical Projects & Artifacts</span>
                                <button type="button" onclick="document.getElementById('addItemModal').style.display='flex';" style="background:none; border:none; color:#1769E8; font-weight:700; font-size:12px; cursor:pointer;">
                                    + Add Item
                                </button>
                            </div>

                            <?php if (empty($portfolioData['projects'])): ?>
                                <p style="font-size:13px; color:#94A3B8; margin:0;">No projects added yet. Click "+ Add Item" above to showcase a repository or application.</p>
                            <?php else: ?>
                                <?php foreach ($portfolioData['projects'] as $pj): 
                                    $key = $pj['item_key'];
                                    $checked = !isset($settings['item_visibility'][$key]) || !empty($settings['item_visibility'][$key]);
                                ?>
                                    <div class="item-mgmt-row">
                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                            <div>
                                                <strong style="font-size:13.5px; color:#0F172A;"><?= e($pj['title']) ?></strong>
                                                <?php if (!empty($pj['url'])): ?>
                                                    <a href="<?= e($pj['url']) ?>" target="_blank" rel="noopener noreferrer" style="font-size:11.5px; color:#1769E8; margin-left:6px;">[Link &#8599;]</a>
                                                <?php endif; ?>
                                            </div>
                                            <label style="font-size:12px; font-weight:700; color:#475569; display:inline-flex; align-items:center; gap:4px;">
                                                <input type="checkbox" name="item_vis[<?= e($key) ?>]" value="1" <?= $checked ? 'checked' : '' ?>>
                                                <span>Show in Portfolio</span>
                                            </label>
                                        </div>
                                        <textarea name="custom_desc[<?= e($key) ?>]" rows="2" class="form-input" style="margin-bottom:0;" placeholder="Project description..."><?= e($pj['description']) ?></textarea>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                    </div>

                    <!-- Right Column: Privacy, Social Links, and Section Toggles -->
                    <div>

                        <!-- Card: Social Links & Contact Privacy -->
                        <div class="studio-card">
                            <div class="sc-title">
                                <span>Contact & Social Profiles</span>
                            </div>

                            <label class="form-label">LinkedIn Profile URL</label>
                            <input type="url" name="linkedin_url" class="form-input" value="<?= e($settings['linkedin_url']) ?>" placeholder="https://linkedin.com/in/...">

                            <label class="form-label">GitHub Profile URL</label>
                            <input type="url" name="github_url" class="form-input" value="<?= e($settings['github_url']) ?>" placeholder="https://github.com/...">

                            <label class="form-label">Personal Website / Blog</label>
                            <input type="url" name="website_url" class="form-input" value="<?= e($settings['website_url']) ?>" placeholder="https://yoursite.com">

                            <div style="margin-top:14px; padding-top:12px; border-top:1px solid #F1F5F9;">
                                <strong style="font-size:12px; color:#334155; display:block; margin-bottom:8px;">Contact Information Protection</strong>
                                <label style="display:flex; align-items:center; gap:8px; font-size:12.5px; color:#475569; margin-bottom:6px; cursor:pointer;">
                                    <input type="checkbox" name="display_email" value="1" <?= $settings['display_email'] ? 'checked' : '' ?>>
                                    <span>Display Email in Public Showcase</span>
                                </label>
                                <label style="display:flex; align-items:center; gap:8px; font-size:12.5px; color:#475569; cursor:pointer;">
                                    <input type="checkbox" name="display_phone" value="1" <?= $settings['display_phone'] ? 'checked' : '' ?>>
                                    <span>Display Phone in Public Showcase</span>
                                </label>
                            </div>
                        </div>

                        <!-- Card: Section Visibility Controls -->
                        <div class="studio-card">
                            <div class="sc-title">
                                <span>Visible Sections</span>
                            </div>
                            <p style="font-size:11.5px; color:#64748B; margin:0 0 10px;">Select which sections appear when your portfolio is viewed publicly:</p>

                            <?php 
                            $secMap = [
                                'summary'      => 'Summary & About Me',
                                'education'    => 'Education Details',
                                'skills'       => 'Confirmed Skills (' . count($portfolioData['skills']) . ')',
                                'activities'   => 'Completed Activities (' . count($portfolioData['activities']) . ')',
                                'projects'     => 'Projects & Artifacts (' . count($portfolioData['projects']) . ')',
                                'certificates' => 'Certificates (' . count($portfolioData['certificates']) . ')',
                                'achievements' => 'Honors & Achievements (' . count($portfolioData['achievements']) . ')',
                            ];
                            foreach ($secMap as $sKey => $sTitle):
                                $secChecked = !empty($settings['visible_sections'][$sKey]);
                            ?>
                                <div class="toggle-switch-row">
                                    <span class="toggle-label"><?= e($sTitle) ?></span>
                                    <input type="checkbox" name="sec_<?= e($sKey) ?>" value="1" <?= $secChecked ? 'checked' : '' ?>>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="submit" style="width:100%; background:#1769E8; color:#fff; border:none; padding:12px; border-radius:8px; font-weight:700; font-size:14px; cursor:pointer; box-shadow:0 2px 6px rgba(23,105,232,0.3);">
                            Save Portfolio Changes
                        </button>

                    </div>

                </div>
            </form>

            <!-- Hidden Form for AI Bio Generation -->
            <form method="POST" action="portfolio.php" id="aiBioForm" style="display:none;">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="generate_ai_bio">
            </form>

        </main>
    </div>

    <!-- Modal: Add Item -->
    <div id="addItemModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:#FFF; border-radius:14px; padding:28px; width:100%; max-width:480px; box-shadow:0 10px 30px rgba(0,0,0,0.2);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h3 style="font-size:18px; font-weight:800; color:#0F172A; margin:0;">Add Project or Certificate</h3>
                <button type="button" onclick="document.getElementById('addItemModal').style.display='none';" style="background:none; border:none; font-size:20px; color:#64748B; cursor:pointer;">&times;</button>
            </div>

            <form method="POST" action="portfolio.php">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="add_portfolio_item">

                <div style="margin-bottom:12px;">
                    <label class="form-label">Item Type</label>
                    <select name="item_type" class="form-input">
                        <option value="project">Project / Application</option>
                        <option value="certificate">Certificate / Credential</option>
                        <option value="publication">Research / Publication</option>
                        <option value="link">Repository / Portfolio Link</option>
                    </select>
                </div>

                <div style="margin-bottom:12px;">
                    <label class="form-label">Title</label>
                    <input type="text" name="item_title" placeholder="e.g. Real-Time Chat Engine" required class="form-input">
                </div>

                <div style="margin-bottom:12px;">
                    <label class="form-label">Link / Repository URL (Optional)</label>
                    <input type="url" name="item_url" placeholder="https://github.com/..." class="form-input">
                </div>

                <div style="margin-bottom:18px;">
                    <label class="form-label">Description</label>
                    <textarea name="item_description" rows="3" placeholder="Brief summary of technology stack and deliverables..." class="form-input"></textarea>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="document.getElementById('addItemModal').style.display='none';" style="background:#F1F5F9; border:1px solid #CBD5E1; padding:8px 16px; border-radius:8px; font-weight:700; cursor:pointer;">Cancel</button>
                    <button type="submit" style="background:#1769E8; color:#fff; border:none; padding:8px 16px; border-radius:8px; font-weight:700; cursor:pointer;">Add Item</button>
                </div>
            </form>
        </div>
    </div>

</body>
</html>
