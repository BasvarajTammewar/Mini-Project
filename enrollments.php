<?php
/**
 * CareerAct — My Enrollments Module
 * Tracks student registered, in-progress, and completed extracurricular activities and opportunities.
 */

declare(strict_types=1);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';

requireCompletedProfile('onboarding.php?msg=complete_profile');

$userId = getCurrentUserId();
$db = getDbConnection();

// Retrieve user info
$stmtUser = $db->prepare("SELECT name, email FROM users WHERE id = :user_id LIMIT 1");
$stmtUser->execute([':user_id' => $userId]);
$student = $stmtUser->fetch();
$fullName = $student['name'] ?? 'Student';
$initials = strtoupper(substr($fullName, 0, 1));

$successMsg = '';

// Handle Completion Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_completed_id'])) {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $enrollmentId = (int)$_POST['mark_completed_id'];
        $stmtUpdate = $db->prepare("
            UPDATE enrollments 
            SET status = 'completed', 
                progress_pct = 100,
                completed_at = CURDATE(),
                updated_at = NOW()
            WHERE id = :eid AND user_id = :user_id
        ");
        $stmtUpdate->execute([':eid' => $enrollmentId, ':user_id' => $userId]);
        $successMsg = 'Activity marked as completed! Your dashboard milestone progress has been updated.';
    }
}

// Fetch Student Enrollments from modern table
$stmt = $db->prepare("
    SELECT 
        e.id AS enrollment_id,
        e.status,
        e.progress_pct,
        e.created_at AS enrolled_at,
        e.completed_at,
        a.title,
        a.description,
        a.delivery_mode,
        ac.name AS category_name,
        COALESCE(o.title, a.title) AS opportunity_title,
        COALESCE(p.name, 'Verified Academic Partner') AS organizer_name
    FROM enrollments e
    JOIN activities a ON e.activity_id = a.id
    LEFT JOIN activity_categories ac ON a.category_id = ac.id
    LEFT JOIN opportunities o ON e.opportunity_id = o.id
    LEFT JOIN providers p ON o.provider_id = p.id
    WHERE e.user_id = :user_id AND e.status IN ('registered', 'in_progress', 'completed')
    ORDER BY (e.status = 'completed') ASC, e.created_at DESC
");
$stmt->execute([':user_id' => $userId]);
$enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Enrollments — CareerAct</title>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= time() ?>">
    <style>
        svg, svg path, svg circle, svg polygon, svg rect, svg line, svg polyline { fill: none !important; box-sizing: border-box; }
        svg { display: inline-block !important; vertical-align: middle !important; stroke: currentColor !important; stroke-width: 2 !important; }
        .sidebar-brand-icon svg { width: 20px !important; height: 20px !important; }
        .sidebar-nav svg, .logout-link svg { width: 18px !important; height: 18px !important; }
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
                    <li><a href="profile.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><span>My Profile</span></a></li>
                    <li><a href="career_goals.php" class="nav-link"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg><span>Career Exploration</span></a></li>
                    <li><a href="my_skills.php" class="nav-link"><svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg><span>My Skills</span></a></li>
                    <li><a href="skills_assessment.php" class="nav-link"><svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg><span>Skill Assessment</span></a></li>
                    <li><a href="skill_gap.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg><span>Skill-Gap Analysis</span></a></li>
                    <li><a href="recommendations.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path></svg><span>Recommendations</span></a></li>
                    <li><a href="activities.php" class="nav-link"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg><span>Explore Activities</span></a></li>
                    <li><a href="saved_activities.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path></svg><span>Saved Activities</span></a></li>
                    <li><a href="enrollments.php" class="nav-link active" aria-current="page"><svg viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg><span>My Enrollments</span></a></li>
                    <li><a href="portfolio.php" class="nav-link"><svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg><span>My Portfolio</span></a></li>
                    <li><a href="resume.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg><span>Resume Builder</span></a></li>
                    <li><a href="settings.php" class="nav-link"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg><span>Settings</span></a></li>
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
                <span class="welcome-title">My Enrollments</span>
                <span class="welcome-subtitle">Track registered, in-progress, and verified extracurricular experiences</span>
            </div>
            <div class="topbar-actions">
                <a href="dashboard.php" class="btn-view-details">← Back to Dashboard</a>
            </div>
        </header>

        <main class="dashboard-content">
            <?php if (!empty($successMsg)): ?>
                <div style="background: #ECFDF5; border: 1px solid #A7F3D0; color: #065F46; padding: 14px 18px; border-radius: var(--radius-sm); margin-bottom: 20px; font-weight: 500;">
                    <?= e($successMsg) ?>
                </div>
            <?php endif; ?>

            <div>
                <?php if (!empty($enrollments)): ?>
                    <?php foreach ($enrollments as $item): ?>
                        <?php $isComp = ($item['status'] === 'completed'); ?>
                        <article class="activity-card" style="margin-bottom: 16px; background: #FFF; border: 1px solid #E4EAF2; border-radius: 12px; padding: 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px;">
                            <div class="activity-icon-box <?= $isComp ? 'green' : 'blue' ?>" style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; <?= $isComp ? 'background: #ECFDF5; color: #0F9D8A;' : 'background: #EEF3FF; color: #1769E8;' ?>">
                                <?php if ($isComp): ?>
                                    <svg width="22" height="22" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                <?php else: ?>
                                    <svg width="22" height="22" viewBox="0 0 24 24"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path></svg>
                                <?php endif; ?>
                            </div>

                            <div class="activity-card-body" style="flex: 1;">
                                <div class="activity-badges-row" style="display: flex; gap: 8px; margin-bottom: 6px;">
                                    <span class="activity-pill type" style="background: #F1F5F9; color: #475569; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px;"><?= e($item['category_name'] ?? 'Activity') ?></span>
                                    <?php if ($isComp): ?>
                                        <span class="activity-pill match" style="background: #EDFDF5; color: #059669; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px;">✓ Completed</span>
                                    <?php else: ?>
                                        <span class="activity-pill deadline" style="background: #EEF3FF; color: #1769E8; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px;">⏳ Registered / In Progress</span>
                                    <?php endif; ?>
                                </div>

                                <h2 class="activity-title" style="font-size: 16px; font-weight: 700; color: #163A70; margin: 0 0 4px;"><?= e($item['opportunity_title']) ?></h2>
                                <p style="font-size: 12.5px; color: #64748B; margin: 0 0 6px;">
                                    Organized by <strong><?= e($item['organizer_name']) ?></strong> • <?= ucfirst(e($item['delivery_mode'])) ?>
                                </p>
                                <span style="font-size: 11.5px; color: #94A3B8;">
                                    Enrolled: <?= date('M j, Y', strtotime($item['enrolled_at'])) ?>
                                    <?php if (!empty($item['completed_at'])): ?>
                                        • Completed: <?= date('M j, Y', strtotime($item['completed_at'])) ?>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div class="activity-card-action">
                                <?php if (!$isComp): ?>
                                    <form method="POST" action="enrollments.php" style="margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="mark_completed_id" value="<?= (int)$item['enrollment_id'] ?>">
                                        <button type="submit" class="btn-view-details" style="background: #0F9D8A; color: #FFF; border: none; padding: 8px 16px; border-radius: 8px; font-size: 12.5px; font-weight: 700; cursor: pointer;">
                                            Mark Completed
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size: 12px; font-weight: 700; color: #0F9D8A; display: inline-flex; align-items: center; gap: 4px;">
                                        <svg width="14" height="14" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                        <span>Verified Milestone</span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-discovery-state" style="background: #FFF; border: 1px dashed #CBD5E1; border-radius: 12px; padding: 40px; text-align: center;">
                        <p style="font-weight: 700; font-size: 16px; color: #163A70; margin: 0 0 6px;">You have no enrolled activities yet.</p>
                        <p style="font-size: 13px; color: #64748B; margin: 0 0 16px;">Enroll in hackathons, workshops, or projects in Explore Activities to track your progress.</p>
                        <a href="activities.php" class="btn-filter-events" style="display: inline-flex; text-decoration: none; padding: 9px 18px; border-radius: 8px;">Explore Activities & Events</a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
