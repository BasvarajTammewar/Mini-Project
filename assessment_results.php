<?php
/**
 * CareerAct — Assessment Results & Decision Branching Page
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

// Fetch latest assessment record
$recordId = isset($_GET['record_id']) ? (int)$_GET['record_id'] : 0;
if ($recordId > 0) {
    $stmtRecord = $db->prepare("
        SELECT sar.*, cp.career_title, cp.description AS career_desc
        FROM student_assessment_records sar
        JOIN career_paths cp ON sar.career_id = cp.career_id
        WHERE sar.record_id = :rid AND sar.student_id = :sid
        LIMIT 1
    ");
    $stmtRecord->execute([':rid' => $recordId, ':sid' => $studentId]);
    $assessment = $stmtRecord->fetch();
} else {
    $stmtRecord = $db->prepare("
        SELECT sar.*, cp.career_title, cp.description AS career_desc
        FROM student_assessment_records sar
        JOIN career_paths cp ON sar.career_id = cp.career_id
        WHERE sar.student_id = :sid
        ORDER BY sar.created_at DESC LIMIT 1
    ");
    $stmtRecord->execute([':sid' => $studentId]);
    $assessment = $stmtRecord->fetch();
}

if (!$assessment) {
    header('Location: onboarding.php?step=2');
    exit;
}

$strengths = json_decode($assessment['strengths_summary'], true) ?? [];
$improvements = json_decode($assessment['improvements_summary'], true) ?? [];
$decisionPath = $assessment['decision_path'];
$readinessScore = (int)$assessment['readiness_score'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Results — CareerAct</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        .results-header-banner {
            background: var(--hero-gradient);
            border-radius: var(--radius-lg);
            padding: 32px;
            color: var(--white);
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(23, 105, 232, 0.25);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }
        .score-circle-badge {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            border: 3px solid rgba(255, 255, 255, 0.5);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--white);
            flex-shrink: 0;
        }
        .results-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        .result-card {
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 24px;
            box-shadow: var(--card-shadow);
        }
        .badge-path {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12.5px;
            font-weight: 700;
            margin-bottom: 12px;
        }
        .badge-path.path_a { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; }
        .badge-path.path_b { background: #EEF2FF; color: #3730A3; border: 1px solid #C7D2FE; }
        .badge-path.path_c { background: #FFFBEB; color: #92400E; border: 1px solid #FDE68A; }
        .competency-item {
            padding: 12px 14px;
            background: #F8FAFD;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
            margin-bottom: 10px;
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
                <span class="welcome-title">Assessment Results</span>
                <span class="welcome-subtitle">Transparent competency evaluation against your target career</span>
            </div>
            <div class="topbar-actions">
                <a href="roadmap.php" class="btn-view-pathway">View Career Roadmap →</a>
            </div>
        </header>

        <main class="dashboard-content">
            <!-- Header Banner -->
            <div class="results-header-banner">
                <div>
                    <span class="badge-path <?= $decisionPath ?>"><?= e($assessment['path_title']) ?></span>
                    <h1 style="font-size: 24px; font-weight: 800; margin: 4px 0 6px;">
                        Career Readiness: <?= e($assessment['career_title']) ?>
                    </h1>
                    <p style="font-size: 13.5px; opacity: 0.92; margin: 0; max-width: 600px;">
                        Evaluated based on your academic profile, reported extracurricular activities, problem-solving reflections, and verified credentials on <?= date('M j, Y', strtotime($assessment['created_at'])) ?>.
                    </p>
                </div>

                <div class="score-circle-badge">
                    <span style="font-size: 28px; font-weight: 800; line-height: 1;"><?= $readinessScore ?>%</span>
                    <span style="font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px;">Readiness</span>
                </div>
            </div>

            <!-- Decision & Next Step Banner -->
            <div class="result-card" style="margin-bottom: 24px; border-left: 4px solid var(--primary-blue);">
                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;">
                    <div>
                        <h2 style="font-size: 18px; font-weight: 800; color: var(--dark-navy); margin: 0 0 6px;">
                            <?php if ($decisionPath === 'path_a'): ?>
                                Path A: Advanced Competency Track Activated
                            <?php elseif ($decisionPath === 'path_b'): ?>
                                Path B: Improvement Roadmap Generated
                            <?php else: ?>
                                Path C: Clarification & Provisional Assessment
                            <?php endif; ?>
                        </h2>
                        <p style="font-size: 13.5px; color: var(--secondary-text); margin: 0; line-height: 1.5;">
                            <?php if ($decisionPath === 'path_a'): ?>
                                You have demonstrated core competency in your target track! The system recommends high-stakes collegiate hackathons, open-source maintainership, and capstone deployments.
                            <?php elseif ($decisionPath === 'path_b'): ?>
                                Critical competency gaps have been identified. Your personalized roadmap schedules foundational activities and targeted hackathons to bridge these gaps.
                            <?php else: ?>
                                Your reported activity descriptions were brief or missing code repository links. You can add project links at any time to upgrade your assessment from provisional to verified.
                            <?php endif; ?>
                        </p>
                    </div>
                    <a href="roadmap.php" class="btn-view-pathway" style="flex-shrink: 0;">
                        Open Roadmap
                    </a>
                </div>
            </div>

            <!-- Strengths and Improvements Grid -->
            <div class="results-grid">
                <!-- Current Strengths -->
                <div class="result-card">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                        <h3 style="font-size: 16px; font-weight: 800; color: #065F46;">✓ Current Demonstrated Strengths</h3>
                        <span style="font-size: 12px; font-weight: 700; color: #0F9D8A;"><?= count($strengths) ?> Competencies</span>
                    </div>

                    <?php if (!empty($strengths)): ?>
                        <?php foreach ($strengths as $st): ?>
                            <div class="competency-item">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <strong style="font-size: 14px; color: var(--dark-navy);"><?= e($st['skill_name']) ?></strong>
                                    <span style="font-size: 11.5px; font-weight: 700; color: #0F9D8A;">
                                        <?= e($st['current_level']) ?>
                                    </span>
                                </div>
                                <span style="font-size: 11.5px; color: var(--secondary-text); margin-top: 4px; display: block;">
                                    <?= e($st['evidence_note'] ?? 'Demonstrated through extracurricular activity record') ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="font-size: 13px; color: var(--secondary-text);">No fully met competencies yet. Complete foundational activities to build strengths.</p>
                    <?php endif; ?>
                </div>

                <!-- Improvement Areas -->
                <div class="result-card">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                        <h3 style="font-size: 16px; font-weight: 800; color: #3730A3;">⚡ Improvement Areas & Gaps</h3>
                        <span style="font-size: 12px; font-weight: 700; color: #4F46E5;"><?= count($improvements) ?> Areas to Build</span>
                    </div>

                    <?php if (!empty($improvements)): ?>
                        <?php foreach ($improvements as $imp): ?>
                            <div class="competency-item">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <strong style="font-size: 14px; color: var(--dark-navy);"><?= e($imp['skill_name']) ?></strong>
                                    <span style="font-size: 11px; font-weight: 700; color: #DC2626; background: #FEF2F2; padding: 2px 6px; border-radius: 4px;">
                                        Required: <?= e($imp['required_level']) ?>
                                    </span>
                                </div>
                                <p style="font-size: 12px; color: var(--secondary-text); margin: 4px 0 0; line-height: 1.4;">
                                    <?= e($imp['reason']) ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="font-size: 13px; color: #0F9D8A; font-weight: 600;">Outstanding! No major competency gaps detected against this career pathway.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Evidence Confidence Breakdown -->
            <div class="result-card">
                <h3 style="font-size: 16px; font-weight: 800; color: var(--dark-navy); margin-bottom: 12px;">Evidence & Assessment Confidence</h3>
                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 200px; background: #F8FAFD; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 14px 18px;">
                        <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--secondary-text);">Confidence Rating</span>
                        <div style="font-size: 16px; font-weight: 800; color: var(--dark-navy); text-transform: capitalize; margin-top: 4px;">
                            <?= e($assessment['confidence_level']) ?> Evaluation
                        </div>
                    </div>

                    <div style="flex: 1; min-width: 200px; background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: var(--radius-sm); padding: 14px 18px;">
                        <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #065F46;">Evidence-Backed Records</span>
                        <div style="font-size: 16px; font-weight: 800; color: #0F9D8A; margin-top: 4px;">
                            <?= (int)$assessment['evidence_verified_count'] ?> Links / Verified Credentials
                        </div>
                    </div>

                    <div style="flex: 1; min-width: 200px; background: #EEF2FF; border: 1px solid #C7D2FE; border-radius: var(--radius-sm); padding: 14px 18px;">
                        <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #3730A3;">Self-Reported Experiences</span>
                        <div style="font-size: 16px; font-weight: 800; color: #4338CA; margin-top: 4px;">
                            <?= (int)$assessment['self_reported_count'] ?> Narrative Entries
                        </div>
                    </div>
                </div>

                <div style="margin-top: 24px; display: flex; gap: 12px;">
                    <a href="roadmap.php" class="btn-primary" style="flex: 1;">
                        <span>Continue to Personalized Roadmap ➔</span>
                    </a>
                    <a href="dashboard.php" class="btn-primary" style="background: #F1F5F9; color: var(--dark-navy); text-decoration: none; width: auto; padding: 0 24px;">
                        Go to Dashboard
                    </a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
