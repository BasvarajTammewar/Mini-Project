<?php
/**
 * CareerAct — Personalized Career Roadmap Module
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

$successMsg = '';

// Handle Milestone Completion Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_milestone_id'])) {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $mId = (int)$_POST['toggle_milestone_id'];
        $stmtToggle = $db->prepare("
            UPDATE student_roadmap_milestones 
            SET is_completed = IF(is_completed = 1, 0, 1),
                completed_at = IF(is_completed = 1, NOW(), NULL)
            WHERE milestone_id = :mid AND student_id = :sid
        ");
        $stmtToggle->execute([':mid' => $mId, ':sid' => $studentId]);
        $successMsg = 'Roadmap milestone progress updated!';
    }
}

// Fetch Active Career Goal
$stmtGoal = $db->prepare("
    SELECT scg.career_id, cp.career_title, cp.description
    FROM student_career_goals scg
    JOIN career_paths cp ON scg.career_id = cp.career_id
    WHERE scg.student_id = :student_id AND scg.is_active = 1
    LIMIT 1
");
$stmtGoal->execute([':student_id' => $studentId]);
$careerGoal = $stmtGoal->fetch();

if (!$careerGoal) {
    header('Location: onboarding.php?step=2');
    exit;
}

// Fetch Roadmap Milestones
$stmtMilestones = $db->prepare("
    SELECT * FROM student_roadmap_milestones 
    WHERE student_id = :student_id 
    ORDER BY phase_number ASC, priority_level DESC, milestone_id ASC
");
$stmtMilestones->execute([':student_id' => $studentId]);
$milestones = $stmtMilestones->fetchAll();

// Group by Phase
$phases = [];
foreach ($milestones as $m) {
    $pNum = (int)$m['phase_number'];
    $phases[$pNum]['title'] = $m['phase_title'];
    $phases[$pNum]['items'][] = $m;
}

// Compute Roadmap Progress
$totalMilestones = count($milestones);
$completedMilestones = 0;
foreach ($milestones as $m) {
    if ((int)$m['is_completed'] === 1) {
        $completedMilestones++;
    }
}
$roadmapProgress = $totalMilestones > 0 ? (int)round(($completedMilestones / $totalMilestones) * 100) : 0;

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personalized Career Roadmap — CareerAct</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        .roadmap-progress-card {
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 24px;
            box-shadow: var(--card-shadow);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }
        .phase-block {
            margin-bottom: 28px;
        }
        .phase-header-title {
            font-size: 16px;
            font-weight: 800;
            color: var(--dark-navy);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .milestone-card {
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 20px;
            margin-bottom: 12px;
            box-shadow: var(--card-shadow);
            display: flex;
            align-items: flex-start;
            gap: 16px;
            transition: var(--transition);
        }
        .milestone-card:hover {
            border-color: var(--border-hover);
        }
        .milestone-card.is-done {
            background: #F8FAFD;
            border-color: #E2E8F0;
            opacity: 0.85;
        }
        .milestone-card.is-done .milestone-title {
            text-decoration: line-through;
            color: var(--secondary-text);
        }
        .priority-badge {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 8px;
            border-radius: 4px;
        }
        .priority-badge.critical { background: #FEE2E2; color: #DC2626; }
        .priority-badge.high { background: #FEF3C7; color: #D97706; }
        .priority-badge.medium { background: #EEF2FF; color: #4F46E5; }
        .priority-badge.advanced { background: #ECFDF5; color: #0F9D8A; }
    </style>
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
                    <li><a href="enrollments.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg><span>My Enrollments</span></a></li>
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
                <span class="welcome-title">Personalized Career Roadmap</span>
                <span class="welcome-subtitle">Target Pathway: <?= e($careerGoal['career_title']) ?></span>
            </div>
            <div class="topbar-actions">
                <a href="reassess.php" class="btn-view-details" title="Recalculate competencies after completing activities">
                    ↻ Re-Assess Progress
                </a>
                <a href="dashboard.php" class="btn-view-pathway">
                    Back to Dashboard
                </a>
            </div>
        </header>

        <main class="dashboard-content">
            <?php if (!empty($successMsg)): ?>
                <div style="background: #ECFDF5; border: 1px solid #A7F3D0; color: #065F46; padding: 14px 18px; border-radius: var(--radius-sm); margin-bottom: 20px; font-weight: 500;">
                    <?= e($successMsg) ?>
                </div>
            <?php endif; ?>

            <!-- Progress Card -->
            <div class="roadmap-progress-card">
                <div>
                    <span style="font-size: 11px; font-weight: 700; color: var(--primary-blue); text-transform: uppercase;">Milestone Completion</span>
                    <h2 style="font-size: 20px; font-weight: 800; color: var(--dark-navy); margin: 2px 0 4px;">
                        <?= $completedMilestones ?> of <?= $totalMilestones ?> Milestones Completed
                    </h2>
                    <p style="font-size: 13px; color: var(--secondary-text); margin: 0;">
                        Check off milestones as you complete coursework, hackathons, and practical builds.
                    </p>
                </div>
                <div style="width: 180px; text-align: right;">
                    <span style="font-size: 22px; font-weight: 800; color: var(--primary-blue);"><?= $roadmapProgress ?>%</span>
                    <div class="progress-track" style="margin-top: 6px;">
                        <div class="progress-bar blue" style="width: <?= $roadmapProgress ?>%;"></div>
                    </div>
                </div>
            </div>

            <!-- Phased Milestones List -->
            <div>
                <?php if (!empty($phases)): ?>
                    <?php foreach ($phases as $pNum => $phase): ?>
                        <div class="phase-block">
                            <h3 class="phase-header-title">
                                <span style="display: inline-flex; width: 24px; height: 24px; border-radius: 50%; background: var(--light-blue); color: var(--primary-blue); font-size: 12px; font-weight: 800; align-items: center; justify-content: center;">
                                    <?= $pNum ?>
                                </span>
                                <span><?= e($phase['title']) ?></span>
                            </h3>

                            <?php foreach ($phase['items'] as $item): ?>
                                <?php $isDone = ((int)$item['is_completed'] === 1); ?>
                                <article class="milestone-card <?= $isDone ? 'is-done' : '' ?>">
                                    <form method="POST" action="roadmap.php" style="margin-top: 2px;">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="toggle_milestone_id" value="<?= (int)$item['milestone_id'] ?>">
                                        <button type="submit" style="background: none; border: none; cursor: pointer; padding: 0;">
                                            <div style="width: 22px; height: 22px; border: 2px solid <?= $isDone ? '#0F9D8A' : 'var(--border-color)' ?>; border-radius: 6px; background: <?= $isDone ? '#0F9D8A' : '#FFF' ?>; display: flex; align-items: center; justify-content: center;">
                                                <?php if ($isDone): ?>
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FFF" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                <?php endif; ?>
                                            </div>
                                        </button>
                                    </form>

                                    <div style="flex: 1;">
                                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                            <span class="priority-badge <?= e($item['priority_level']) ?>"><?= e($item['priority_level']) ?></span>
                                            <span style="font-size: 11.5px; font-weight: 600; color: var(--secondary-text);">Target: <?= e($item['target_period']) ?></span>
                                        </div>

                                        <h4 class="milestone-title" style="font-size: 16px; font-weight: 700; color: var(--dark-navy); margin: 0 0 4px;">
                                            <?= e($item['competency_title']) ?>
                                        </h4>

                                        <p style="font-size: 13px; color: var(--main-text); margin: 0 0 8px; line-height: 1.5;">
                                            <strong>Action:</strong> <?= e($item['suggested_action']) ?>
                                        </p>

                                        <div style="background: #F8FAFD; border: 1px solid var(--border-color); border-radius: 6px; padding: 8px 12px; font-size: 12px; color: var(--secondary-text);">
                                            <strong>Why:</strong> <?= e($item['reason_text']) ?> • <strong>Expected Outcome:</strong> <?= e($item['expected_outcome']) ?>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-panel">
                        <p style="font-weight: 600;">No roadmap milestones generated yet.</p>
                        <a href="onboarding.php?step=3" class="btn-view-pathway" style="display: inline-block; margin-top: 12px;">Run Assessment to Generate Roadmap</a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
