<?php
/**
 * CareerAct — AI Career Roadmap (Feature 6)
 * 
 * Interactive, phased career roadmap connecting student career goals,
 * assessed skill gaps, and verified extracurricular activities.
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
require_once __DIR__ . '/includes/roadmap_service.php';

requireCompletedProfile('onboarding.php?msg=complete_profile');

$userId = getCurrentUserId();
$db = getDbConnection();

// Fetch student profile info
$stmtUser = $db->prepare("SELECT name, email FROM users WHERE id = :uid LIMIT 1");
$stmtUser->execute([':uid' => $userId]);
$student = $stmtUser->fetch(PDO::FETCH_ASSOC);
$fullName = $student['name'] ?? 'Student';
$initials = strtoupper(substr($fullName, 0, 1));

$roadmapService = new RoadmapService($db);

// Fetch target career goal
$careerGoal = $roadmapService->getStudentCareerGoal($userId);
if (!$careerGoal) {
    header('Location: career_goals.php');
    exit;
}
$careerTitle = $careerGoal['career_title'];

$successMsg = '';
$errorMsg   = '';

// Handle Milestone Toggle or Roadmap Regeneration
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        if (isset($_POST['toggle_milestone_id'])) {
            $mId = (int)$_POST['toggle_milestone_id'];
            if ($roadmapService->toggleMilestone($userId, $mId)) {
                $successMsg = 'Roadmap milestone status updated!';
            }
        } elseif (isset($_POST['action_regenerate_roadmap'])) {
            $res = $roadmapService->generateRoadmap($userId, true);
            if ($res['success']) {
                $successMsg = 'AI Career Roadmap refreshed and synchronized with your latest assessments and completed achievements!';
            } else {
                $errorMsg = $res['error'] ?? 'Could not regenerate roadmap.';
            }
        }
    } else {
        $errorMsg = 'Security validation failed (invalid CSRF token). Please try again.';
    }
}

// Ensure roadmap exists or generate first time
$roadmapData = $roadmapService->getRoadmap($userId);
if (empty($roadmapData['milestones'])) {
    $resGen = $roadmapService->generateRoadmap($userId, false);
    if ($resGen['success']) {
        $roadmapData = $resGen['roadmap'];
    }
}

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Career Roadmap — CareerAct</title>
    <meta name="description" content="Personalized career journey and extracurricular development milestones tailored to your dream career.">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= time() ?>">
    <style>
        svg { fill: none !important; stroke: currentColor !important; display: inline-block; vertical-align: middle; }
        
        /* Hero Banner */
        .roadmap-hero {
            background: linear-gradient(135deg, #163A70 0%, #1769E8 60%, #4F46E5 100%);
            border-radius: 16px;
            padding: 32px;
            color: #FFFFFF;
            margin-bottom: 24px;
            box-shadow: 0 8px 24px rgba(23, 105, 232, 0.2);
            position: relative;
            overflow: hidden;
        }
        .roadmap-hero::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 320px;
            height: 320px;
            background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        /* Progress Card */
        .roadmap-progress-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            padding: 22px 28px;
            margin-bottom: 28px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }
        .progress-track {
            height: 10px;
            background: #E2E8F0;
            border-radius: 6px;
            overflow: hidden;
            width: 100%;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #1769E8, #10B981);
            border-radius: 6px;
            transition: width 0.8s ease;
        }

        /* Timeline & Phase Blocks */
        .phase-block {
            margin-bottom: 32px;
        }
        .phase-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .phase-badge-num {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #1769E8;
            color: #FFFFFF;
            font-size: 14px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(23, 105, 232, 0.3);
        }
        .phase-title-text {
            font-size: 18px;
            font-weight: 800;
            color: #0F172A;
            margin: 0;
        }

        /* Milestone Card */
        .milestone-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            padding: 20px 24px;
            margin-bottom: 14px;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.03);
            display: flex;
            align-items: flex-start;
            gap: 18px;
            transition: all 0.2s ease;
        }
        .milestone-card:hover {
            border-color: #CBD5E1;
            transform: translateX(2px);
        }
        .milestone-card.is-completed {
            background: #F8FAFC;
            border-color: #E2E8F0;
            opacity: 0.85;
        }
        .milestone-card.is-completed .milestone-title {
            text-decoration: line-through;
            color: #64748B;
        }

        .priority-badge {
            font-size: 10.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 8px;
            border-radius: 4px;
        }
        .priority-critical { background: #FEE2E2; color: #DC2626; border: 1px solid #FECACA; }
        .priority-high     { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; }
        .priority-medium   { background: #EEF2FF; color: #4338CA; border: 1px solid #C7D2FE; }
        .priority-advanced { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; }

        .checkbox-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            margin-top: 2px;
        }
        .checkbox-box {
            width: 22px;
            height: 22px;
            border: 2px solid #CBD5E1;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #FFFFFF;
            transition: all 0.2s ease;
        }
        .checkbox-box.checked {
            background: #059669;
            border-color: #059669;
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
                        <a href="roadmap.php" class="nav-link active" aria-current="page">
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
                <span class="welcome-title">Personalized Career Roadmap</span>
                <span class="welcome-subtitle">Target Pathway: <?= e($careerTitle) ?></span>
            </div>
            <div class="topbar-actions">
                <form method="POST" action="roadmap.php" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action_regenerate_roadmap" value="1">
                    <button type="submit" class="btn-view-details" style="padding: 7px 14px; cursor: pointer; border: 1px solid #CBD5E1; background: #FFF;">
                        ↻ Regenerate AI Roadmap
                    </button>
                </form>
                <a href="profile.php" class="user-profile-chip">
                    <div class="avatar-circle"><?= e($initials) ?></div>
                    <div class="user-text-info"><span class="user-name"><?= e($fullName) ?></span><span class="user-level">Student</span></div>
                </a>
            </div>
        </header>

        <main class="dashboard-content" style="max-width: 1080px; margin: 0 auto; padding: 24px 20px;">

            <?php if (!empty($successMsg)): ?>
                <div style="background:#ECFDF5; border:1px solid #A7F3D0; color:#065F46; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-size:13.5px;">
                    <?= e($successMsg) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($errorMsg)): ?>
                <div style="background:#FEF2F2; border:1px solid #FECACA; color:#991B1B; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-size:13.5px;">
                    <?= e($errorMsg) ?>
                </div>
            <?php endif; ?>

            <!-- Hero Section -->
            <div class="roadmap-hero">
                <span style="background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 20px; font-size: 11.5px; font-weight: 700; text-transform: uppercase;">
                    ✦ AI Developmental Pathway
                </span>
                <h1 style="font-size: 26px; font-weight: 800; margin: 10px 0 10px 0; line-height: 1.3;">
                    Your Pathway to Becoming a <?= e($careerTitle) ?>
                </h1>
                <p style="font-size: 14.5px; color: rgba(255,255,255,0.9); margin: 0; max-width: 780px; line-height: 1.6;">
                    This progressive roadmap bridges your current competencies with verified extracurricular hackathons, competitions, and technical builds to help you reach career readiness.
                </p>
            </div>

            <!-- Progress Tracking Card -->
            <div class="roadmap-progress-card">
                <div style="flex: 1; min-width: 260px;">
                    <span style="font-size: 11.5px; font-weight: 700; color: #1769E8; text-transform: uppercase; letter-spacing: 0.5px;">
                        Career Milestone Progression
                    </span>
                    <h2 style="font-size: 20px; font-weight: 800; color: #0F172A; margin: 4px 0 6px 0;">
                        <?= (int)$roadmapData['completed_milestones'] ?> of <?= (int)$roadmapData['total_milestones'] ?> Milestones Completed
                    </h2>
                    <p style="font-size: 13px; color: #64748B; margin: 0;">
                        Check off milestones as you participate in hackathons, complete activities, or submit portfolio evidence.
                    </p>
                </div>

                <div style="width: 220px; text-align: right;">
                    <div style="font-size: 24px; font-weight: 800; color: #1769E8; margin-bottom: 6px;">
                        <?= (int)$roadmapData['progress_pct'] ?>%
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill" style="width: <?= (int)$roadmapData['progress_pct'] ?>%;"></div>
                    </div>
                </div>
            </div>

            <!-- Phased Stages Timeline -->
            <div>
                <?php if (!empty($roadmapData['phases'])): ?>
                    <?php foreach ($roadmapData['phases'] as $phase): ?>
                        <div class="phase-block">
                            <div class="phase-header">
                                <div class="phase-badge-num">
                                    <?= (int)$phase['phase_number'] ?>
                                </div>
                                <h3 class="phase-title-text">
                                    <?= e($phase['phase_title']) ?>
                                </h3>
                            </div>

                            <?php foreach ($phase['items'] as $item): 
                                $isDone = ((int)$item['is_completed'] === 1);
                                $pLevel = strtolower((string)$item['priority_level']);
                            ?>
                                <article class="milestone-card <?= $isDone ? 'is-completed' : '' ?>">
                                    <!-- Interactive Checkbox Toggle -->
                                    <form method="POST" action="roadmap.php">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="toggle_milestone_id" value="<?= (int)$item['milestone_id'] ?>">
                                        <button type="submit" class="checkbox-btn" title="<?= $isDone ? 'Mark as incomplete' : 'Mark as completed' ?>">
                                            <div class="checkbox-box <?= $isDone ? 'checked' : '' ?>">
                                                <?php if ($isDone): ?>
                                                    <svg width="14" height="14" viewBox="0 0 24 24" stroke="#FFFFFF" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                <?php endif; ?>
                                            </div>
                                        </button>
                                    </form>

                                    <div style="flex: 1;">
                                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px; flex-wrap: wrap;">
                                            <span class="priority-badge priority-<?= $pLevel ?>">
                                                <?= e(ucfirst($pLevel)) ?> Priority
                                            </span>
                                            <span style="font-size: 11.5px; font-weight: 600; color: #64748B;">
                                                Target: <?= e($item['target_period']) ?>
                                            </span>
                                            <?php if ($isDone && !empty($item['completed_at'])): ?>
                                                <span style="font-size: 11px; color: #059669; font-weight: 700;">
                                                    ✓ Verified on <?= date('M j, Y', strtotime($item['completed_at'])) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <h4 class="milestone-title" style="font-size: 16px; font-weight: 800; color: #0F172A; margin: 0 0 6px 0;">
                                            <?= e($item['competency_title']) ?>
                                        </h4>

                                        <p style="font-size: 13.5px; color: #334155; margin: 0 0 10px 0; line-height: 1.5;">
                                            <strong>Suggested Action:</strong> <?= e($item['suggested_action']) ?>
                                        </p>

                                        <?php if (!empty($item['activity_title'])): ?>
                                            <div style="margin-bottom: 10px;">
                                                <a href="activity_details.php?id=<?= (int)$item['recommended_activity_id'] ?>" style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: #1769E8; background: #EEF4FF; border: 1px solid #BFDBFE; padding: 4px 10px; border-radius: 6px; text-decoration: none;">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                                    Recommended Activity: <?= e($item['activity_title']) ?> &rarr;
                                                </a>
                                            </div>
                                        <?php endif; ?>

                                        <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 10px 14px; font-size: 12px; color: #475569; line-height: 1.5;">
                                            <div><strong>Why:</strong> <?= e($item['reason_text']) ?></div>
                                            <div style="margin-top: 4px;"><strong>Expected Outcome:</strong> <?= e($item['expected_outcome']) ?></div>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 14px; padding: 48px 24px; text-align: center;">
                        <h3 style="font-size: 18px; font-weight: 800; color: #0F172A; margin-bottom: 8px;">No Roadmap Milestones Yet</h3>
                        <p style="font-size: 13.5px; color: #64748B; margin-bottom: 20px;">Click below to generate your personalized AI career roadmap.</p>
                        <form method="POST" action="roadmap.php">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action_regenerate_roadmap" value="1">
                            <button type="submit" class="btn-action-primary" style="background:#1769E8; color:#fff; border:none; padding:10px 20px; border-radius:8px; font-weight:700; cursor:pointer;">
                                Generate My Career Roadmap
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</body>
</html>
