<?php
/**
 * CareerAct — Skills Assessment Module
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

$successMsg = '';

// Handle Skill Assessment Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['skill_ratings'])) {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $ratings = (array)$_POST['skill_ratings'];
        
        foreach ($ratings as $skillId => $levelId) {
            $sId = (int)$skillId;
            $lId = (int)$levelId;
            if ($sId > 0 && $lId > 0 && $lId <= 5) {
                // Upsert skill rating
                $stmtUpsert = $db->prepare("
                    INSERT INTO student_skills (student_id, skill_id, proficiency_level_id, source_code, updated_at)
                    VALUES (:student_id, :skill_id, :level_id, 'assessed', NOW())
                    ON DUPLICATE KEY UPDATE 
                        proficiency_level_id = VALUES(proficiency_level_id),
                        source_code = 'assessed',
                        updated_at = NOW()
                ");
                $stmtUpsert->execute([
                    ':student_id' => $studentId,
                    ':skill_id'   => $sId,
                    ':level_id'   => $lId
                ]);
            }
        }
        $successMsg = 'Skills assessment saved successfully! Your competencies and progress bars have been updated.';
    }
}

// Fetch Core Competencies (Technical & Professional)
$stmtSkills = $db->query("
    SELECT s.skill_id, s.skill_name, sc.category_name
    FROM skills s
    JOIN skill_categories sc ON s.skill_category_id = sc.skill_category_id
    WHERE s.skill_id IN (1, 2, 3, 4, 30, 31, 72, 85, 38)
    ORDER BY s.skill_id ASC
");
$assessmentSkills = $stmtSkills->fetchAll();

// Fetch current student ratings
$stmtRatings = $db->prepare("SELECT skill_id, proficiency_level_id FROM student_skills WHERE student_id = :student_id");
$stmtRatings->execute([':student_id' => $studentId]);
$currentRatings = $stmtRatings->fetchAll(PDO::FETCH_KEY_PAIR);

// Fetch proficiency levels
$levels = $db->query("SELECT proficiency_level_id, level_name, level_value FROM proficiency_levels ORDER BY level_value ASC")->fetchAll();

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Skills Assessment — CareerAct</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        .assessment-card {
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 24px;
            box-shadow: var(--card-shadow);
            margin-top: 16px;
        }
        .skill-rating-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 0;
            border-bottom: 1px solid var(--border-color);
            gap: 16px;
        }
        .skill-rating-row:last-child {
            border-bottom: none;
        }
        .radio-options {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .radio-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            cursor: pointer;
            padding: 6px 10px;
            border-radius: 6px;
            background: #F8FAFD;
            border: 1px solid var(--border-color);
            transition: var(--transition);
        }
        .radio-label:hover {
            border-color: var(--primary-blue);
        }
        .radio-label input:checked + span {
            font-weight: 700;
            color: var(--primary-blue);
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
                    <li><a href="skills_assessment.php" class="nav-link active" aria-current="page"><svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg><span>Skills Assessment</span></a></li>
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
                <span class="welcome-title">Skills Assessment</span>
                <span class="welcome-subtitle">Benchmark your competencies against industry expectations</span>
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
                <h1 style="font-size: 22px; font-weight: 800; color: var(--dark-navy);">Rate Your Current Proficiency</h1>
                <p style="color: var(--secondary-text); font-size: 14px;">Select the level that accurately reflects your ability. Ratings update your dashboard progress in real-time.</p>
            </div>

            <form method="POST" action="skills_assessment.php" class="assessment-card">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                <?php foreach ($assessmentSkills as $sk): ?>
                    <?php 
                        $currentVal = (int)($currentRatings[$sk['skill_id']] ?? 0); 
                    ?>
                    <div class="skill-rating-row">
                        <div>
                            <span style="font-size: 11px; font-weight: 700; color: var(--primary-blue); text-transform: uppercase;"><?= e($sk['category_name']) ?></span>
                            <h3 style="font-size: 16px; font-weight: 700; color: var(--dark-navy); margin: 2px 0 0;"><?= e($sk['skill_name']) ?></h3>
                        </div>

                        <div class="radio-options">
                            <?php foreach ($levels as $lvl): ?>
                                <label class="radio-label">
                                    <input 
                                        type="radio" 
                                        name="skill_ratings[<?= (int)$sk['skill_id'] ?>]" 
                                        value="<?= (int)$lvl['proficiency_level_id'] ?>"
                                        <?= $currentVal === (int)$lvl['proficiency_level_id'] ? 'checked' : '' ?>
                                    >
                                    <span><?= e($lvl['level_name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div style="margin-top: 24px; text-align: right;">
                    <button type="submit" class="btn-view-pathway" style="padding: 11px 24px; font-size: 14px;">
                        Save Assessment Progress
                    </button>
                </div>
            </form>
        </main>
    </div>
</body>
</html>
