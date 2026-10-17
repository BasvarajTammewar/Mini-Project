<?php
/**
 * CareerAct — Career Goals Module
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';

$userId = (int)$_SESSION['user_id'];
$db = getDbConnection();

// Get student_id
$stmtStudent = $db->prepare("SELECT student_id, full_name FROM student_profiles WHERE user_id = :user_id LIMIT 1");
$stmtStudent->execute([':user_id' => $userId]);
$student = $stmtStudent->fetch();
$studentId = $student ? (int)$student['student_id'] : 0;
$fullName = $student ? $student['full_name'] : ($_SESSION['full_name'] ?? 'Student');

$successMsg = '';

// Handle Career Goal Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_career_id'])) {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $newCareerId = (int)$_POST['set_career_id'];
        
        // Deactivate previous goals
        $db->prepare("UPDATE student_career_goals SET is_active = 0, deselected_at = NOW() WHERE student_id = :student_id")
           ->execute([':student_id' => $studentId]);

        // Insert new active goal
        $db->prepare("
            INSERT INTO student_career_goals (student_id, career_id, goal_rank, is_active, selected_at)
            VALUES (:student_id, :career_id, 1, 1, NOW())
        ")->execute([
            ':student_id' => $studentId,
            ':career_id'  => $newCareerId
        ]);

        $successMsg = 'Career Goal updated successfully! Your dashboard recommendations have been re-aligned.';
    }
}

// Fetch all available Career Paths
$stmtCareers = $db->query("
    SELECT cp.career_id, cp.career_title, cp.career_code, cp.description,
           (SELECT COUNT(*) FROM career_required_skills crs WHERE crs.career_id = cp.career_id) AS required_skills_count
    FROM career_paths cp
    WHERE cp.is_active = 1
    ORDER BY cp.career_id ASC
");
$allCareers = $stmtCareers->fetchAll();

// Get current active goal
$stmtActive = $db->prepare("SELECT career_id FROM student_career_goals WHERE student_id = :student_id AND is_active = 1 LIMIT 1");
$stmtActive->execute([':student_id' => $studentId]);
$currentActiveCareerId = (int)$stmtActive->fetchColumn();

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Career Goals — CareerAct</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        .career-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .career-card {
            background: var(--white);
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 22px;
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            transition: var(--transition);
        }
        .career-card:hover {
            border-color: var(--primary-blue);
            transform: translateY(-2px);
        }
        .career-card.is-active {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 2px var(--primary-blue), 0 8px 20px rgba(23, 105, 232, 0.15);
            background: linear-gradient(180deg, #FFFFFF 0%, #F8FAFD 100%);
        }
        .active-badge-pill {
            position: absolute;
            top: 16px;
            right: 16px;
            background: #ECFDF5;
            color: #0F9D8A;
            border: 1px solid #A7F3D0;
            padding: 3px 9px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
        }
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
                    <li><a href="career_goals.php" class="nav-link active" aria-current="page"><svg viewBox="0 0 24 24"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path><line x1="4" y1="22" x2="4" y2="15"></line></svg><span>Career Goals</span></a></li>
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
                <span class="welcome-title">Career Goals</span>
                <span class="welcome-subtitle">Define your target career pathway to unlock personalized recommendations</span>
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

            <div style="margin-bottom: 16px;">
                <h1 style="font-size: 22px; font-weight: 800; color: var(--dark-navy);">Select Your Target Career Track</h1>
                <p style="color: var(--secondary-text); font-size: 14px;">Your active choice informs recommendation weights, skill gap analysis, and extracurricular targeting.</p>
            </div>

            <div class="career-grid">
                <?php foreach ($allCareers as $career): ?>
                    <?php $isActive = ((int)$career['career_id'] === $currentActiveCareerId); ?>
                    <div class="career-card <?= $isActive ? 'is-active' : '' ?>">
                        <div>
                            <?php if ($isActive): ?>
                                <span class="active-badge-pill">✓ Active Goal</span>
                            <?php endif; ?>
                            <h2 style="font-size: 18px; font-weight: 800; color: var(--dark-navy); margin-top: 4px;"><?= e($career['career_title']) ?></h2>
                            <p style="color: var(--secondary-text); font-size: 13px; margin: 8px 0 16px; line-height: 1.5;">
                                <?= e($career['description'] ?? 'Prepare for high-impact industry roles with verified competencies.') ?>
                            </p>
                            <span style="font-size: 12px; font-weight: 600; color: var(--primary-blue);">
                                ⚡ <?= (int)$career['required_skills_count'] ?> Industry Benchmark Skills
                            </span>
                        </div>

                        <form method="POST" action="career_goals.php" style="margin-top: 18px;">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="set_career_id" value="<?= (int)$career['career_id'] ?>">
                            <?php if ($isActive): ?>
                                <button type="button" class="btn-view-pathway" style="width: 100%; justify-content: center; background: #0F9D8A;" disabled>
                                    ✓ Currently Selected
                                </button>
                            <?php else: ?>
                                <button type="submit" class="btn-view-pathway" style="width: 100%; justify-content: center;">
                                    Set as Active Goal
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>
</body>
</html>
