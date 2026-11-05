<?php
/**
 * CareerAct — Student Profile Module
 * Module 2: Profile Viewing & Management
 * Database: carrearact2
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';

$userId = getCurrentUserId();
$db = getDbConnection();

// Fetch Student Profile & User Identity
$stmt = $db->prepare('
    SELECT sp.*, u.name, u.email, u.role, u.account_status, u.created_at AS account_created, c.name AS country_name
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    LEFT JOIN countries c ON sp.country_id = c.id
    WHERE u.id = :user_id
    LIMIT 1
');
$stmt->execute([':user_id' => $userId]);
$profile = $stmt->fetch();

$isCompleted = !empty($profile['profile_completed']);

// Fetch Education records
$eduStmt = $db->prepare('SELECT * FROM student_education WHERE user_id = :user_id ORDER BY id DESC');
$eduStmt->execute([':user_id' => $userId]);
$educationList = $eduStmt->fetchAll();

// Fetch Career Goals (Primary & Secondary)
$goalsStmt = $db->prepare('
    SELECT scg.*, c.title AS career_title, c.description AS career_desc
    FROM student_career_goals scg
    JOIN careers c ON scg.career_id = c.id
    WHERE scg.user_id = :user_id
    ORDER BY scg.is_primary DESC, c.title ASC
');
$goalsStmt->execute([':user_id' => $userId]);
$careerGoals = $goalsStmt->fetchAll();

// Fetch Interest Fields
$intStmt = $db->prepare('
    SELECT sif.*, cf.name AS field_name, cf.description AS field_desc
    FROM student_interest_fields sif
    JOIN career_fields cf ON sif.field_id = cf.id
    WHERE sif.user_id = :user_id
    ORDER BY cf.sort_order ASC, cf.name ASC
');
$intStmt->execute([':user_id' => $userId]);
$interestList = $intStmt->fetchAll();

// Fetch Self-Reported Skills
$skillsStmt = $db->prepare('
    SELECT ss.*, s.name AS skill_name, s.skill_type, sl.label AS level_label
    FROM student_skills ss
    JOIN skills s ON ss.skill_id = s.id
    LEFT JOIN skill_levels sl ON ss.level = sl.level
    WHERE ss.user_id = :user_id
    ORDER BY ss.level DESC, s.name ASC
');
$skillsStmt->execute([':user_id' => $userId]);
$skillsList = $skillsStmt->fetchAll();

// Fetch Extracurricular Activities
$actStmt = $db->prepare('
    SELECT sea.*, a.title AS catalog_title
    FROM student_existing_activities sea
    LEFT JOIN activities a ON sea.activity_id = a.id
    WHERE sea.user_id = :user_id
    ORDER BY sea.start_date DESC, sea.id DESC
');
$actStmt->execute([':user_id' => $userId]);
$activitiesList = $actStmt->fetchAll();

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Student Profile — CareerAct</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        .profile-container {
            max-width: 960px;
            margin: 0 auto;
        }
        .profile-section-card {
            background: #FFFFFF;
            border: 1px solid #E4EAF2;
            border-radius: 12px;
            padding: 24px 28px;
            margin-bottom: 24px;
            box-shadow: 0 4px 12px rgba(22, 58, 112, 0.04);
        }
        .profile-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 14px;
            margin-bottom: 18px;
            border-bottom: 1px solid #E4EAF2;
        }
        .profile-card-title {
            font-size: 17px;
            font-weight: 700;
            color: #163A70;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .profile-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }
        @media (max-width: 768px) {
            .profile-grid-2 { grid-template-columns: 1fr; }
        }
        .field-box {
            background: #F8FAFD;
            padding: 12px 16px;
            border-radius: 8px;
            border: 1px solid #E4EAF2;
        }
        .field-label {
            font-size: 11px;
            font-weight: 700;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .field-val {
            font-size: 14.5px;
            font-weight: 600;
            color: #0F172A;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12.5px;
            font-weight: 600;
        }
        .status-incomplete {
            background: #FEF3C7;
            color: #92400E;
        }
        .status-complete {
            background: #D1FAE5;
            color: #065F46;
        }
        .btn-edit-profile {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #1769E8;
            color: #FFFFFF;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.15s;
        }
        .btn-edit-profile:hover {
            background: #1255be;
        }
        .pill-tag {
            display: inline-block;
            background: #EFF6FF;
            color: #1769E8;
            border: 1px solid #BFDBFE;
            padding: 4px 12px;
            border-radius: 16px;
            font-size: 13px;
            font-weight: 600;
            margin: 4px 4px 4px 0;
        }
        .pill-tag.primary {
            background: #163A70;
            color: #FFFFFF;
            border-color: #163A70;
        }
        .skill-item-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background: #F8FAFD;
            border: 1px solid #E4EAF2;
            border-radius: 6px;
            margin-bottom: 8px;
        }
        .activity-card-row {
            padding: 14px 16px;
            background: #F8FAFD;
            border: 1px solid #E4EAF2;
            border-radius: 8px;
            margin-bottom: 12px;
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
                    <li>
                        <a href="dashboard.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect></svg>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="profile.php" class="nav-link active" aria-current="page">
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
            <form action="logout.php" method="POST" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <button type="submit" class="logout-link" style="background: none; border: none; cursor: pointer; width: 100%; text-align: left; font-family: inherit; font-size: inherit;">
                    <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                    <span>Logout</span>
                </button>
            </form>
        </div>
    </aside>

    <div class="dashboard-main">
        <header class="dashboard-topbar">
            <div class="topbar-welcome">
                <span class="welcome-title">Student Profile</span>
                <span class="welcome-subtitle">Academic profile, career goals, skills, and extracurricular record</span>
            </div>
            <div class="topbar-actions">
                <a href="onboarding.php?mode=edit" class="btn-edit-profile">✏️ Edit Profile</a>
                <?php if ($isCompleted): ?>
                    <a href="dashboard.php" class="btn-view-details">Dashboard →</a>
                <?php endif; ?>
            </div>
        </header>

        <main class="dashboard-content">
            <div class="profile-container">

                <!-- Incomplete Profile Warning Banner -->
                <?php if (!$isCompleted): ?>
                    <div style="background: #FFFBEB; border: 1.5px solid #FDE68A; border-radius: 10px; padding: 18px 24px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; gap: 16px;">
                        <div>
                            <strong style="color: #92400E; font-size: 15px;">Profile Incomplete</strong>
                            <p style="color: #78350F; font-size: 13.5px; margin: 4px 0 0 0;">
                                You must complete your full academic, career, and activity profile through the onboarding wizard before accessing the dashboard.
                            </p>
                        </div>
                        <a href="onboarding.php" class="btn-edit-profile" style="white-space: nowrap;">Complete Onboarding Wizard &rarr;</a>
                    </div>
                <?php endif; ?>

                <!-- 1. Identity & Personal Details -->
                <div class="profile-section-card">
                    <div class="profile-card-header">
                        <div style="display: flex; align-items: center; gap: 14px;">
                            <div class="avatar-circle" style="width: 52px; height: 52px; font-size: 20px;">
                                <?= strtoupper(substr($profile['name'] ?? 'S', 0, 1)) ?>
                            </div>
                            <div>
                                <h1 style="font-size: 20px; font-weight: 800; color: #163A70; margin: 0;"><?= e($profile['name'] ?? 'Student') ?></h1>
                                <span style="font-size: 13px; color: #64748B;"><?= e($profile['email'] ?? '') ?> • <?= ucfirst(e($profile['role'] ?? 'student')) ?></span>
                            </div>
                        </div>
                        <div>
                            <?php if ($isCompleted): ?>
                                <span class="status-badge status-complete">✓ Profile Complete</span>
                            <?php else: ?>
                                <span class="status-badge status-incomplete">⏳ Profile Incomplete</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="profile-grid-2">
                        <div class="field-box">
                            <div class="field-label">Location</div>
                            <div class="field-val">
                                <?= e($profile['city'] ?? '') ?><?= !empty($profile['city']) && !empty($profile['country_name']) ? ', ' : '' ?><?= e($profile['country_name'] ?? 'Not Specified') ?>
                            </div>
                        </div>

                        <div class="field-box">
                            <div class="field-label">Preferred Activity Mode</div>
                            <div class="field-val" style="text-transform: capitalize;"><?= e($profile['preferred_mode'] ?? 'Any') ?></div>
                        </div>

                        <div class="field-box">
                            <div class="field-label">Experience Level</div>
                            <div class="field-val" style="text-transform: capitalize;"><?= e($profile['experience_level'] ?? 'Beginner') ?></div>
                        </div>

                        <div class="field-box">
                            <div class="field-label">Account Created</div>
                            <div class="field-val"><?= !empty($profile['account_created']) ? date('M j, Y', strtotime($profile['account_created'])) : 'Recently' ?></div>
                        </div>
                    </div>

                    <?php if (!empty($profile['bio'])): ?>
                        <div style="margin-top: 16px; background: #F8FAFD; padding: 14px 18px; border-radius: 8px; border: 1px solid #E4EAF2;">
                            <div class="field-label">Biography / Intro</div>
                            <div style="font-size: 14px; color: #334155; line-height: 1.5;"><?= nl2br(e($profile['bio'])) ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 2. Educational Details -->
                <div class="profile-section-card">
                    <div class="profile-card-header">
                        <span class="profile-card-title">🎓 Educational Background</span>
                    </div>

                    <div class="profile-grid-2">
                        <div class="field-box">
                            <div class="field-label">College / University</div>
                            <div class="field-val"><?= e($profile['institution'] ?? 'Not Specified') ?></div>
                        </div>

                        <div class="field-box">
                            <div class="field-label">Field of Study / Branch</div>
                            <div class="field-val"><?= e($profile['field_of_study'] ?? 'Not Specified') ?></div>
                        </div>

                        <div class="field-box">
                            <div class="field-label">Education Level</div>
                            <div class="field-val" style="text-transform: capitalize;"><?= e($profile['education_level'] ?? 'Undergraduate') ?></div>
                        </div>

                        <div class="field-box">
                            <div class="field-label">Expected Graduation Year</div>
                            <div class="field-val"><?= e((string)($profile['graduation_year'] ?? 'Not Specified')) ?></div>
                        </div>
                    </div>

                    <?php if (!empty($educationList)): ?>
                        <div style="margin-top: 16px;">
                            <div class="field-label" style="margin-bottom: 8px;">Recorded Degrees & Qualifications</div>
                            <?php foreach ($educationList as $edu): ?>
                                <div style="padding: 10px 14px; background: #F8FAFD; border: 1px solid #E4EAF2; border-radius: 6px; margin-bottom: 6px; font-size: 13.5px;">
                                    <strong><?= e($edu['qualification']) ?></strong> — <?= e($edu['institution']) ?> 
                                    <?php if (!empty($edu['start_year']) || !empty($edu['end_year'])): ?>
                                        <span style="color: #64748B;">(<?= e((string)$edu['start_year']) ?> - <?= e((string)$edu['end_year']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 3. Career Goals -->
                <div class="profile-section-card">
                    <div class="profile-card-header">
                        <span class="profile-card-title">🎯 Career Goals & Target Roles</span>
                    </div>

                    <?php if (!empty($careerGoals)): ?>
                        <div>
                            <?php foreach ($careerGoals as $g): ?>
                                <div style="margin-bottom: 10px; padding: 12px 16px; background: #F8FAFD; border: 1px solid #E4EAF2; border-radius: 8px;">
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                        <span style="font-size: 15px; font-weight: 700; color: #163A70;"><?= e($g['career_title']) ?></span>
                                        <?php if (!empty($g['is_primary'])): ?>
                                            <span style="background: #1769E8; color: #FFF; font-size: 11.5px; font-weight: 700; padding: 2px 10px; border-radius: 12px;">PRIMARY GOAL</span>
                                        <?php else: ?>
                                            <span style="background: #E2E8F0; color: #475569; font-size: 11.5px; font-weight: 600; padding: 2px 10px; border-radius: 12px;">SECONDARY</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 13px; color: #64748B;"><?= e($g['career_desc']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="color: #64748B; font-size: 14px;">No career goals recorded yet.</div>
                    <?php endif; ?>

                    <?php if (!empty($profile['resume_summary'])): ?>
                        <div style="margin-top: 16px; background: #F8FAFD; padding: 14px 18px; border-radius: 8px; border: 1px solid #E4EAF2;">
                            <div class="field-label">Career Objective & Goal Statement</div>
                            <div style="font-size: 14px; color: #334155; line-height: 1.5;"><?= nl2br(e($profile['resume_summary'])) ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 4. Interests & Skills -->
                <div class="profile-section-card">
                    <div class="profile-card-header">
                        <span class="profile-card-title">💡 Interests & Self-Reported Skills</span>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <div class="field-label" style="margin-bottom: 8px;">Activity & Domain Interests</div>
                        <?php if (!empty($interestList)): ?>
                            <div>
                                <?php foreach ($interestList as $it): ?>
                                    <span class="pill-tag"><?= e($it['field_name']) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="color: #64748B; font-size: 13.5px;">No interests selected.</div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <div class="field-label" style="margin-bottom: 8px;">Self-Reported Skills</div>
                        <?php if (!empty($skillsList)): ?>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px;">
                                <?php foreach ($skillsList as $sk): ?>
                                    <div class="skill-item-bar">
                                        <span style="font-size: 14px; font-weight: 600; color: #1E293B;"><?= e($sk['skill_name']) ?></span>
                                        <span style="font-size: 12px; font-weight: 700; color: #1769E8; background: #EFF6FF; padding: 2px 8px; border-radius: 4px;">
                                            Level <?= (int)$sk['level'] ?> (<?= e($sk['level_label'] ?? 'Basic') ?>)
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="color: #64748B; font-size: 13.5px;">No skills declared yet.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 5. Extracurricular Activities -->
                <div class="profile-section-card">
                    <div class="profile-card-header">
                        <span class="profile-card-title">🏆 Recorded Extracurricular Activities</span>
                    </div>

                    <?php if (!empty($activitiesList)): ?>
                        <?php foreach ($activitiesList as $act): ?>
                            <div class="activity-card-row">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                                    <strong style="font-size: 15px; color: #163A70;"><?= e($act['title']) ?></strong>
                                    <?php if (!empty($act['start_date'])): ?>
                                        <span style="font-size: 12.5px; color: #64748B;">
                                            <?= date('M Y', strtotime($act['start_date'])) ?> <?= !empty($act['end_date']) ? '– ' . date('M Y', strtotime($act['end_date'])) : '(Ongoing)' ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($act['role'])): ?>
                                    <div style="font-size: 13px; font-weight: 600; color: #1769E8; margin-bottom: 4px;">Role: <?= e($act['role']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($act['description'])): ?>
                                    <div style="font-size: 13.5px; color: #475569; line-height: 1.4;"><?= nl2br(e($act['description'])) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="color: #64748B; font-size: 14px;">No extracurricular activities recorded yet.</div>
                    <?php endif; ?>
                </div>

            </div>
        </main>
    </div>
</body>
</html>
