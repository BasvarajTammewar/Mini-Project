<?php
/**
 * CareerAct — Student Profile Module
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';

$userId = (int)$_SESSION['user_id'];
$db = getDbConnection();

$stmt = $db->prepare("
    SELECT sp.*, u.email, u.created_at AS account_created, r.role_name
    FROM student_profiles sp
    JOIN users u ON sp.user_id = u.user_id
    JOIN roles r ON u.role_id = r.role_id
    WHERE sp.user_id = :user_id
    LIMIT 1
");
$stmt->execute([':user_id' => $userId]);
$profile = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — CareerAct</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        .profile-container {
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 32px;
            box-shadow: var(--card-shadow);
            max-width: 820px;
        }
        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 24px;
        }
        .profile-field {
            background: #F8FAFD;
            padding: 14px 18px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
        }
        .field-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--secondary-text);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .field-val {
            font-size: 15px;
            font-weight: 600;
            color: var(--dark-navy);
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
                    <li><a href="profile.php" class="nav-link active" aria-current="page"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><span>My Profile</span></a></li>
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
                <span class="welcome-title">My Profile</span>
                <span class="welcome-subtitle">Student academic and institution profile records</span>
            </div>
            <div class="topbar-actions">
                <a href="dashboard.php" class="btn-view-details">← Back to Dashboard</a>
            </div>
        </header>

        <main class="dashboard-content">
            <div class="profile-container">
                <div style="display: flex; align-items: center; gap: 16px; padding-bottom: 24px; border-bottom: 1px solid var(--border-color);">
                    <div class="avatar-circle" style="width: 54px; height: 54px; font-size: 20px;">
                        <?= strtoupper(substr($profile['full_name'] ?? 'S', 0, 1)) ?>
                    </div>
                    <div>
                        <h1 style="font-size: 22px; font-weight: 800; color: var(--dark-navy); margin: 0;"><?= e($profile['full_name']) ?></h1>
                        <span style="font-size: 13px; color: var(--secondary-text);"><?= e($profile['email']) ?> • <?= e($profile['role_name'] ?? 'Student') ?></span>
                    </div>
                </div>

                <div class="profile-grid">
                    <div class="profile-field">
                        <div class="field-label">College / University</div>
                        <div class="field-val"><?= e($profile['college_name'] ?? 'Not Specified') ?></div>
                    </div>

                    <div class="profile-field">
                        <div class="field-label">Degree & Program</div>
                        <div class="field-val"><?= e($profile['degree_name'] ?? 'Not Specified') ?></div>
                    </div>

                    <div class="profile-field">
                        <div class="field-label">Course / Branch</div>
                        <div class="field-val"><?= e($profile['branch_name'] ?? 'Not Specified') ?></div>
                    </div>

                    <div class="profile-field">
                        <div class="field-label">Academic Year</div>
                        <div class="field-val">Year <?= e((string)($profile['academic_year'] ?? 1)) ?></div>
                    </div>

                    <div class="profile-field">
                        <div class="field-label">Profile Visibility</div>
                        <div class="field-val" style="text-transform: capitalize; color: #0F9D8A;">✓ <?= e($profile['profile_visibility'] ?? 'private') ?></div>
                    </div>

                    <div class="profile-field">
                        <div class="field-label">Member Since</div>
                        <div class="field-val"><?= date('M j, Y', strtotime($profile['account_created'] ?? 'now')) ?></div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
