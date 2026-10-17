<?php
/**
 * CareerAct — My Resume Module
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';

$userId = (int)$_SESSION['user_id'];
$db = getDbConnection();

// Get student profile
$stmt = $db->prepare("SELECT * FROM student_profiles WHERE user_id = :user_id LIMIT 1");
$stmt->execute([':user_id' => $userId]);
$student = $stmt->fetch();
$studentId = $student ? (int)$student['student_id'] : 0;

// Get active career goal
$stmtGoal = $db->prepare("
    SELECT cp.career_title, cp.description
    FROM student_career_goals scg
    JOIN career_paths cp ON scg.career_id = cp.career_id
    WHERE scg.student_id = :student_id AND scg.is_active = 1
    LIMIT 1
");
$stmtGoal->execute([':student_id' => $studentId]);
$goal = $stmtGoal->fetch();

// Get student's verified activities
$stmtActs = $db->prepare("
    SELECT a.title, t.type_name, sae.completed_at
    FROM student_activity_enrollments sae
    JOIN activities a ON sae.activity_id = a.activity_id
    JOIN activity_types t ON a.activity_type_id = t.activity_type_id
    WHERE sae.student_id = :student_id AND sae.completion_status = 'completed'
");
$stmtActs->execute([':student_id' => $studentId]);
$completedActs = $stmtActs->fetchAll();

// Get student's skills
$stmtSkills = $db->prepare("
    SELECT s.skill_name, pl.level_name
    FROM student_skills ss
    JOIN skills s ON ss.skill_id = s.skill_id
    JOIN proficiency_levels pl ON ss.proficiency_level_id = pl.proficiency_level_id
    WHERE ss.student_id = :student_id
");
$stmtSkills->execute([':student_id' => $studentId]);
$skills = $stmtSkills->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Resume — CareerAct</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        .resume-sheet {
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 44px;
            box-shadow: var(--card-shadow);
            max-width: 800px;
        }
        .resume-section {
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1.5px solid var(--border-color);
        }
        .resume-h2 {
            font-size: 13px;
            font-weight: 800;
            color: var(--primary-blue);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 10px;
        }
        .skills-tag-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .resume-skill-badge {
            background: #F1F5F9;
            color: #1E293B;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
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
                    <li><a href="career_goals.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path><line x1="4" y1="22" x2="4" y2="15"></line></svg><span>Career Goals</span></a></li>
                    <li><a href="skills_assessment.php" class="nav-link"><svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg><span>Skills Assessment</span></a></li>
                    <li><a href="enrollments.php" class="nav-link"><svg viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg><span>My Enrollments</span></a></li>
                    <li><a href="resume.php" class="nav-link active" aria-current="page"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg><span>My Resume</span></a></li>
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
                <span class="welcome-title">My Resume</span>
                <span class="welcome-subtitle">Auto-generated profile with verified competencies and activities</span>
            </div>
            <div class="topbar-actions">
                <button type="button" onclick="window.print()" class="btn-view-pathway">
                    Print / Export PDF
                </button>
            </div>
        </header>

        <main class="dashboard-content">
            <div class="resume-sheet">
                <!-- Header -->
                <div style="text-align: center; margin-bottom: 24px;">
                    <h1 style="font-size: 26px; font-weight: 800; color: var(--dark-navy); margin: 0;"><?= e($student['full_name']) ?></h1>
                    <p style="color: var(--secondary-text); font-size: 13.5px; margin-top: 6px;">
                        <?= e($student['college_name']) ?> • <?= e($student['degree_name']) ?> in <?= e($student['branch_name']) ?> • Year <?= e((string)$student['academic_year']) ?>
                    </p>
                </div>

                <!-- Career Objective -->
                <div class="resume-section" style="border-top: none;">
                    <div class="resume-h2">Career Objective & Target Track</div>
                    <p style="font-size: 13.5px; line-height: 1.6; color: var(--main-text); margin: 0;">
                        Targeting <strong><?= e($goal['career_title'] ?? 'Software Engineering') ?></strong>. 
                        <?= e($goal['description'] ?? 'Eager to leverage strong analytical, programming, and project execution skills to contribute to high-impact development initiatives.') ?>
                    </p>
                </div>

                <!-- Verified Activities -->
                <div class="resume-section">
                    <div class="resume-h2">Verified Extracurricular Activities & Practical Experience</div>
                    <?php if (!empty($completedActs)): ?>
                        <ul style="padding-left: 20px; font-size: 13.5px; line-height: 1.7; color: var(--main-text);">
                            <?php foreach ($completedActs as $cAct): ?>
                                <li>
                                    <strong><?= e($cAct['title']) ?></strong> (<?= e($cAct['type_name']) ?>)
                                    — Completed <?= date('M Y', strtotime($cAct['completed_at'])) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p style="font-size: 13px; color: var(--secondary-text); font-style: italic;">No completed activities recorded yet. Complete enrolled activities to populate this section.</p>
                    <?php endif; ?>
                </div>

                <!-- Skills -->
                <div class="resume-section">
                    <div class="resume-h2">Technical & Professional Competencies</div>
                    <?php if (!empty($skills)): ?>
                        <div class="skills-tag-wrap">
                            <?php foreach ($skills as $sk): ?>
                                <span class="resume-skill-badge">
                                    <?= e($sk['skill_name']) ?> (<?= e($sk['level_name']) ?>)
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p style="font-size: 13px; color: var(--secondary-text); font-style: italic;">No assessed skills recorded yet. Complete skills assessment to display competencies.</p>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
