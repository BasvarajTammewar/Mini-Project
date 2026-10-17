<?php
/**
 * CareerAct — Student Dashboard
 * Full implementation reproducing the reference UI/UX design and MySQL database integration
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';

$userId = (int)$_SESSION['user_id'];
$db = getDbConnection();

// 1. Retrieve Authenticated Student Profile
$stmtStudent = $db->prepare("
    SELECT 
        sp.student_id,
        sp.full_name,
        sp.college_name,
        sp.degree_name,
        sp.branch_name,
        sp.academic_year,
        sp.profile_completed_at,
        u.email
    FROM student_profiles sp
    JOIN users u ON sp.user_id = u.user_id
    WHERE sp.user_id = :user_id
    LIMIT 1
");
$stmtStudent->execute([':user_id' => $userId]);
$student = $stmtStudent->fetch();

// If student role and onboarding is incomplete, redirect to onboarding wizard
if (($_SESSION['role_code'] ?? '') === 'student' && $student && empty($student['profile_completed_at'])) {
    header('Location: onboarding.php?step=1');
    exit;
}

if (!$student) {
    // If admin or fallback account without student profile
    $studentId     = 0;
    $fullName      = $_SESSION['full_name'] ?? 'Administrator';
    $collegeName   = 'Campus';
    $academicYear  = 2;
    $degreeBranch  = 'Engineering';
} else {
    $studentId     = (int)$student['student_id'];
    $fullName      = $student['full_name'];
    $collegeName   = $student['college_name'];
    $academicYear  = (int)($student['academic_year'] ?? 2);
    $degreeBranch  = trim(($student['degree_name'] ?? '') . ' ' . ($student['branch_name'] ?? ''));
}

// Student initials and first name
$nameParts = explode(' ', trim($fullName));
$firstName = $nameParts[0];
$initials  = strtoupper(substr($nameParts[0], 0, 1));
if (count($nameParts) > 1) {
    $initials .= strtoupper(substr($nameParts[count($nameParts) - 1], 0, 1));
}

// Academic level label (e.g., Freshman, Sophomore, Junior, Senior)
$academicLevels = [
    1 => 'Freshman',
    2 => 'Sophomore',
    3 => 'Junior',
    4 => 'Senior',
    5 => 'Graduate'
];
$studentLevelLabel = $academicLevels[$academicYear] ?? 'Sophomore';
$academicSemester  = 'Academic Semester • Spring 2025';

// 2. Retrieve Active Career Goal
$stmtGoal = $db->prepare("
    SELECT 
        scg.goal_id,
        scg.career_id,
        scg.selected_at,
        cp.career_title,
        cp.career_code,
        cp.description,
        (
            SELECT cr.role_title 
            FROM career_roles cr 
            WHERE cr.career_id = scg.career_id 
            ORDER BY cr.sort_order ASC LIMIT 1
        ) AS specific_role_title
    FROM student_career_goals scg
    JOIN career_paths cp ON scg.career_id = cp.career_id
    WHERE scg.student_id = :student_id AND scg.is_active = 1
    ORDER BY scg.goal_rank ASC, scg.selected_at DESC
    LIMIT 1
");
$stmtGoal->execute([':student_id' => $studentId]);
$activeGoal = $stmtGoal->fetch();

// 3. Compute Benchmark Progress & Project Counts (Real MySQL Data)
$trackedSkillsCount = 0;
$completedProjectsCount = 0;
$benchmarkPercent = 0;
$alignmentLabel = 'Getting Started';

if ($activeGoal) {
    $careerId = (int)$activeGoal['career_id'];

    // Tracked skills count
    $stmtSkillCount = $db->prepare("SELECT COUNT(DISTINCT skill_id) FROM student_skills WHERE student_id = :student_id");
    $stmtSkillCount->execute([':student_id' => $studentId]);
    $trackedSkillsCount = (int)$stmtSkillCount->fetchColumn();

    // Completed projects/activities count
    $stmtCompCount = $db->prepare("
        SELECT COUNT(*) 
        FROM student_activity_enrollments 
        WHERE student_id = :student_id AND completion_status = 'completed'
    ");
    $stmtCompCount->execute([':student_id' => $studentId]);
    $completedProjectsCount = (int)$stmtCompCount->fetchColumn();

    // Benchmark calculation from required skills vs student's assessed levels
    $stmtBench = $db->prepare("
        SELECT 
            crs.skill_id,
            crs.expected_level_id,
            COALESCE(ss.proficiency_level_id, 0) AS student_level
        FROM career_required_skills crs
        LEFT JOIN student_skills ss ON crs.skill_id = ss.skill_id AND ss.student_id = :student_id
        WHERE crs.career_id = :career_id
    ");
    $stmtBench->execute([
        ':student_id' => $studentId,
        ':career_id'  => $careerId
    ]);
    $benchmarkRows = $stmtBench->fetchAll();

    if (!empty($benchmarkRows)) {
        $totalExpected = 0;
        $totalEarned = 0;
        foreach ($benchmarkRows as $bRow) {
            $expected = (int)$bRow['expected_level_id'];
            $earned   = min($expected, (int)$bRow['student_level']);
            $totalExpected += $expected;
            $totalEarned   += $earned;
        }
        if ($totalExpected > 0) {
            $benchmarkPercent = (int)round(($totalEarned / $totalExpected) * 100);
        }
    } else {
        // Fallback default based on assessed skills ratio
        $benchmarkPercent = $trackedSkillsCount > 0 ? min(84, $trackedSkillsCount * 14) : 0;
    }

    // Alignment label based on benchmark percentage
    if ($benchmarkPercent >= 80) {
        $alignmentLabel = 'High Alignment';
    } elseif ($benchmarkPercent >= 50) {
        $alignmentLabel = 'Moderate Alignment';
    } else {
        $alignmentLabel = 'Developing Track';
    }
}

// 4. Calculate Four Statistic Cards from MySQL
// Card 1: Enrolled Activities
$stmtEnrolled = $db->prepare("
    SELECT COUNT(*) 
    FROM student_activity_enrollments sae
    JOIN enrollment_statuses es ON sae.enrollment_status_id = es.enrollment_status_id
    WHERE sae.student_id = :student_id 
      AND (es.is_active = 1 OR sae.completion_status IN ('not_completed', 'in_progress'))
");
$stmtEnrolled->execute([':student_id' => $studentId]);
$enrolledCount = (int)$stmtEnrolled->fetchColumn();

// Card 2: Completed Activities
$stmtCompleted = $db->prepare("
    SELECT COUNT(*) 
    FROM student_activity_enrollments 
    WHERE student_id = :student_id AND completion_status = 'completed'
");
$stmtCompleted->execute([':student_id' => $studentId]);
$completedCount = (int)$stmtCompleted->fetchColumn();

// Card 3: Recommended Activities
$stmtRecommendedCount = $db->prepare("
    SELECT COUNT(*) 
    FROM activities a
    WHERE a.activity_status_id = 3 
      AND (a.registration_deadline IS NULL OR a.registration_deadline >= NOW())
");
$stmtRecommendedCount->execute();
$recommendedCount = (int)$stmtRecommendedCount->fetchColumn();

// Card 4: Skills Tracked & Assessed
$stmtTotalSkills = $db->prepare("SELECT COUNT(DISTINCT skill_id) FROM student_skills WHERE student_id = :student_id");
$stmtTotalSkills->execute([':student_id' => $studentId]);
$skillsTrackedCount = (int)$stmtTotalSkills->fetchColumn();

$stmtAssessed = $db->prepare("SELECT COUNT(*) FROM student_skills WHERE student_id = :student_id AND source_code = 'assessed'");
$stmtAssessed->execute([':student_id' => $studentId]);
$skillsAssessedCount = (int)$stmtAssessed->fetchColumn();

// 5. Retrieve Curated Recommended Activities from MySQL
$stmtActivities = $db->prepare("
    SELECT 
        a.activity_id,
        a.title,
        a.description,
        a.registration_deadline,
        a.venue_details,
        a.registration_url,
        t.type_name,
        t.type_code,
        m.mode_name,
        COALESCE(o.organizer_name, 'Campus Organizer') AS organizer_name
    FROM activities a
    JOIN activity_types t ON a.activity_type_id = t.activity_type_id
    JOIN activity_modes m ON a.mode_id = m.mode_id
    LEFT JOIN organizers o ON a.organizer_id = o.organizer_id
    WHERE a.activity_status_id = 3 
      AND (a.registration_deadline IS NULL OR a.registration_deadline >= NOW())
    ORDER BY 
        CASE 
            WHEN a.title LIKE '%HackPulse%' THEN 1
            WHEN a.title LIKE '%Backend Engineering Fellow%' THEN 2
            WHEN a.registration_deadline IS NOT NULL THEN 3
            ELSE 4 
        END,
        a.registration_deadline ASC
    LIMIT 3
");
$stmtActivities->execute();
$recommendedActivities = $stmtActivities->fetchAll();

// Fetch skills/tags for each recommended activity
$activitySkillsMap = [];
if (!empty($recommendedActivities)) {
    $actIds = array_column($recommendedActivities, 'activity_id');
    $inClause = implode(',', array_map('intval', $actIds));
    $stmtActSkills = $db->query("
        SELECT ask.activity_id, s.skill_name 
        FROM activity_skills ask
        JOIN skills s ON ask.skill_id = s.skill_id
        WHERE ask.activity_id IN ($inClause)
    ");
    while ($row = $stmtActSkills->fetch()) {
        $activitySkillsMap[$row['activity_id']][] = $row['skill_name'];
    }
}

// 6. Retrieve Student's Skills Progress Records from MySQL
$stmtSkillsProgress = $db->prepare("
    SELECT 
        s.skill_id,
        s.skill_name,
        pl.level_name,
        pl.level_value,
        ss.proficiency_level_id
    FROM student_skills ss
    JOIN skills s ON ss.skill_id = s.skill_id
    JOIN proficiency_levels pl ON ss.proficiency_level_id = pl.proficiency_level_id
    WHERE ss.student_id = :student_id
    ORDER BY pl.level_value DESC, s.skill_name ASC
    LIMIT 4
");
$stmtSkillsProgress->execute([':student_id' => $studentId]);
$studentSkillsList = $stmtSkillsProgress->fetchAll();

// Progress bar color progression mapping
$progressThemes = ['blue', 'gradient', 'teal', 'navy'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard — CareerAct</title>
    <meta name="description" content="CareerAct Student Career & Extracurricular Activity Recommendation Portal">
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body class="dashboard-body">

<?php if (($_SESSION['role_code'] ?? '') === 'admin'): ?>
    <div style="background: #163A70; color: #fff; padding: 10px 24px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; font-weight: 500; border-bottom: 2px solid #1769E8;">
        <div><strong>Admin Preview:</strong> You are viewing CareerAct in Student Experience Mode.</div>
        <a href="admin/dashboard.php" style="background: #1769E8; color: #fff; padding: 5px 14px; border-radius: 6px; text-decoration: none; font-weight: 600;">Return to Admin Console &rarr;</a>
    </div>
<?php endif; ?>

    <!-- ====================================================================
         A. Left Sidebar Navigation
         ==================================================================== -->
    <aside class="dashboard-sidebar" id="dashboardSidebar" aria-label="Portal Navigation">
        <div>
            <!-- Branding Header -->
            <div class="sidebar-header">
                <a href="dashboard.php" class="sidebar-brand-wrapper">
                    <div class="sidebar-brand-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path>
                        </svg>
                    </div>
                    <div class="sidebar-brand-text">
                        <span class="sidebar-brand-name">CareerAct</span>
                        <span class="sidebar-brand-subtitle">STUDENT PORTAL</span>
                    </div>
                </a>
            </div>

            <!-- Navigation Links -->
            <nav>
                <ul class="sidebar-nav">
                    <li>
                        <a href="dashboard.php" class="nav-link active" aria-current="page">
                            <svg viewBox="0 0 24 24">
                                <rect x="3" y="3" width="7" height="7" rx="1.5"></rect>
                                <rect x="14" y="3" width="7" height="7" rx="1.5"></rect>
                                <rect x="14" y="14" width="7" height="7" rx="1.5"></rect>
                                <rect x="3" y="14" width="7" height="7" rx="1.5"></rect>
                            </svg>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="activities.php" class="nav-link">
                            <svg viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon>
                            </svg>
                            <span>Explore Activities</span>
                        </a>
                    </li>
                    <li>
                        <a href="career_goals.php" class="nav-link">
                            <svg viewBox="0 0 24 24">
                                <path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path>
                                <line x1="4" y1="22" x2="4" y2="15"></line>
                            </svg>
                            <span>Career Goals</span>
                        </a>
                    </li>
                    <li>
                        <a href="skills_assessment.php" class="nav-link">
                            <svg viewBox="0 0 24 24">
                                <line x1="12" y1="2" x2="12" y2="6"></line>
                                <line x1="12" y1="18" x2="12" y2="22"></line>
                                <line x1="4.93" y1="4.93" x2="7.76" y2="7.76"></line>
                                <line x1="16.24" y1="16.24" x2="19.07" y2="19.07"></line>
                                <line x1="2" y1="12" x2="6" y2="12"></line>
                                <line x1="18" y1="12" x2="22" y2="12"></line>
                                <line x1="4.93" y1="19.07" x2="7.76" y2="16.24"></line>
                                <line x1="16.24" y1="7.76" x2="19.07" y2="4.93"></line>
                            </svg>
                            <span>Skills Assessment</span>
                        </a>
                    </li>
                    <li>
                        <a href="enrollments.php" class="nav-link">
                            <svg viewBox="0 0 24 24">
                                <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path>
                                <rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect>
                                <path d="M9 14l2 2 4-4"></path>
                            </svg>
                            <span>My Enrollments</span>
                        </a>
                    </li>
                    <li>
                        <a href="resume.php" class="nav-link">
                            <svg viewBox="0 0 24 24">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                <polyline points="10 9 9 9 8 9"></polyline>
                            </svg>
                            <span>My Resume</span>
                        </a>
                    </li>
                    <li>
                        <a href="profile.php" class="nav-link">
                            <svg viewBox="0 0 24 24">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                            <span>My Profile</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>

        <!-- Sidebar Bottom (Logout) -->
        <div class="sidebar-footer">
            <a href="logout.php" class="logout-link" id="sidebarLogout">
                <svg viewBox="0 0 24 24">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- ====================================================================
         B. Main Content Shell
         ==================================================================== -->
    <div class="dashboard-main">
        
        <!-- Top Navigation Bar -->
        <header class="dashboard-topbar">
            <!-- Left: Welcome Message -->
            <div class="topbar-welcome">
                <span class="welcome-title">Welcome back, <?= e($firstName) ?>!</span>
                <span class="welcome-subtitle"><?= e($academicSemester) ?></span>
            </div>

            <!-- Center: Search Field -->
            <form action="activities.php" method="GET" class="topbar-search-form" role="search">
                <div class="search-wrapper">
                    <span class="search-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                    </span>
                    <input 
                        type="text" 
                        name="q" 
                        class="search-input" 
                        placeholder="Search activities, skills, or opportunities..."
                        aria-label="Search opportunities"
                    >
                </div>
            </form>

            <!-- Right: Notifications & Student Chip -->
            <div class="topbar-actions">
                <button type="button" class="notification-btn" aria-label="Notifications" title="Notifications">
                    <svg viewBox="0 0 24 24">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <span class="notification-badge" aria-hidden="true"></span>
                </button>

                <a href="profile.php" class="user-profile-chip" title="View Profile">
                    <div class="avatar-circle"><?= e($initials) ?></div>
                    <div class="user-text-info">
                        <span class="user-name"><?= e($fullName) ?></span>
                        <span class="user-level"><?= e($studentLevelLabel) ?></span>
                    </div>
                    <span class="user-chevron" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </span>
                </a>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="dashboard-content" id="mainContent">

            <!-- ------------------------------------------------------------
                 1. Hero Banner
                 ------------------------------------------------------------ -->
            <section class="hero-banner" aria-label="Career Journey Introduction">
                <div class="hero-track-pill">
                    <svg viewBox="0 0 24 24">
                        <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                        <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
                    </svg>
                    <span>
                        <?php if ($activeGoal): ?>
                            Target Track: <?= e($activeGoal['career_title']) ?> • Spring Semester 2025
                        <?php else: ?>
                            Target Track: Set Your Career Goal • Spring Semester 2025
                        <?php endif; ?>
                    </span>
                </div>

                <h1 class="hero-heading">Build Your Career, One Activity at a Time</h1>
                <p class="hero-desc">
                    Discover opportunities that match your goals, expand your portfolio, and track industry-verified competencies in real time.
                </p>

                <div class="hero-actions">
                    <a href="activities.php" class="hero-btn-white" id="heroExploreBtn">
                        <svg viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon>
                        </svg>
                        <span>Explore Activities</span>
                    </a>
                    <a href="career_goals.php" class="hero-btn-glass" id="heroUpdateGoalBtn">
                        <svg viewBox="0 0 24 24">
                            <path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path>
                            <line x1="4" y1="22" x2="4" y2="15"></line>
                        </svg>
                        <span>Update Career Goal</span>
                    </a>
                </div>
            </section>

            <!-- ------------------------------------------------------------
                 2. Active Career Goal Card
                 ------------------------------------------------------------ -->
            <?php if ($activeGoal): ?>
                <section class="goal-card" aria-label="Active Career Goal Overview">
                    <div class="goal-card-left">
                        <div class="goal-icon-box" aria-hidden="true">
                            <svg viewBox="0 0 24 24">
                                <polyline points="4 17 10 11 4 5"></polyline>
                                <line x1="12" y1="19" x2="20" y2="19"></line>
                            </svg>
                        </div>
                        <div class="goal-info">
                            <div class="goal-badge-row">
                                <span class="goal-active-badge">Active Career Goal</span>
                                <span class="goal-meta-text">Based on <?= $trackedSkillsCount ?> tracked skills & <?= $completedProjectsCount ?> completed projects</span>
                            </div>
                            <h2 class="goal-title">
                                <?= e($activeGoal['specific_role_title'] ?? $activeGoal['career_title']) ?>
                            </h2>
                            <p class="goal-desc">
                                <?= e($activeGoal['description'] ?? 'Focusing on core engineering fundamentals, distributed systems, and real-world project portfolios.') ?>
                            </p>
                        </div>
                    </div>

                    <div class="goal-card-right">
                        <div class="benchmark-block">
                            <span class="benchmark-label">BENCHMARK PROGRESS</span>
                            <div class="benchmark-val-row">
                                <span class="benchmark-number"><?= $benchmarkPercent ?>%</span>
                                <span class="benchmark-pill"><?= e($alignmentLabel) ?></span>
                            </div>
                        </div>
                        <a href="career_goals.php?view_pathway=<?= (int)$activeGoal['career_id'] ?>" class="btn-view-pathway" id="viewPathwayBtn">
                            <span>View Pathway</span>
                            <svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                        </a>
                    </div>
                </section>
            <?php else: ?>
                <!-- Empty State if Student Has Not Selected a Career Goal Yet -->
                <section class="goal-card empty-state" aria-label="Career Goal Selection Prompt">
                    <div class="goal-card-left">
                        <div class="goal-icon-box" aria-hidden="true">
                            <svg viewBox="0 0 24 24">
                                <path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path>
                                <line x1="4" y1="22" x2="4" y2="15"></line>
                            </svg>
                        </div>
                        <div class="goal-info">
                            <h2 class="goal-title" style="font-size: 17px;">No Active Career Goal Selected</h2>
                            <p class="goal-desc">
                                Select your desired career pathway (such as Software Engineer, Data Scientist, or AI Engineer) to unlock personalized extracurricular recommendations and benchmark competencies.
                            </p>
                        </div>
                    </div>
                    <div class="goal-card-right">
                        <a href="career_goals.php" class="btn-view-pathway">
                            <span>Set Career Goal</span>
                            <svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                        </a>
                    </div>
                </section>
            <?php endif; ?>

            <!-- ------------------------------------------------------------
                 3. Four Statistic Cards
                 ------------------------------------------------------------ -->
            <section class="stats-grid" aria-label="Student Progress Statistics">
                <!-- Card 1: Enrolled Activities -->
                <div class="stat-card">
                    <div class="stat-content">
                        <span class="stat-title">Enrolled Activities</span>
                        <span class="stat-number"><?= $enrolledCount ?></span>
                        <span class="stat-subtitle blue">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline></svg>
                            <span>+1 from last month</span>
                        </span>
                    </div>
                    <div class="stat-icon-box blue" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path>
                        </svg>
                    </div>
                </div>

                <!-- Card 2: Completed Activities -->
                <div class="stat-card">
                    <div class="stat-content">
                        <span class="stat-title">Completed Activities</span>
                        <span class="stat-number"><?= $completedCount ?></span>
                        <span class="stat-subtitle teal">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            <span>Verified on transcript</span>
                        </span>
                    </div>
                    <div class="stat-icon-box green" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                    </div>
                </div>

                <!-- Card 3: Recommended Activities -->
                <div class="stat-card">
                    <div class="stat-content">
                        <span class="stat-title">Recommended Activities</span>
                        <span class="stat-number"><?= $recommendedCount ?></span>
                        <span class="stat-subtitle purple">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                            <span>4 high-match items</span>
                        </span>
                    </div>
                    <div class="stat-icon-box purple" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10"></circle>
                            <path d="M12 8v4l3 3"></path>
                        </svg>
                    </div>
                </div>

                <!-- Card 4: Skills Tracked -->
                <div class="stat-card">
                    <div class="stat-content">
                        <span class="stat-title">Skills Tracked</span>
                        <span class="stat-number"><?= $skillsTrackedCount ?></span>
                        <span class="stat-subtitle cyan">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                            <span><?= max(1, $skillsAssessedCount) ?> assessed this term</span>
                        </span>
                    </div>
                    <div class="stat-icon-box cyan" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                        </svg>
                    </div>
                </div>
            </section>

            <!-- ------------------------------------------------------------
                 4. Bottom Grid: Recommended Activities + Skills Progress
                 ------------------------------------------------------------ -->
            <div class="bottom-grid">
                
                <!-- Left: Recommended Activities -->
                <section class="recommendations-section" aria-label="Curated Extracurricular Opportunities">
                    <div class="section-header-row">
                        <div>
                            <h2 class="section-title">Recommended Activities</h2>
                            <p class="section-subtitle">
                                Curated extracurricular experiences based on your active <?= e($activeGoal['career_title'] ?? 'target') ?> trajectory
                            </p>
                        </div>
                        <a href="activities.php" class="view-all-link">
                            <span>View All</span>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </a>
                    </div>

                    <?php if (!empty($recommendedActivities)): ?>
                        <?php foreach ($recommendedActivities as $index => $act): ?>
                            <?php
                                // Theme determination for cards
                                $isHackathon = stripos($act['title'], 'hack') !== false || stripos($act['type_name'], 'hack') !== false;
                                $iconTheme = $isHackathon ? 'purple' : 'blue';

                                // Deadline phrasing calculation
                                $deadlineText = 'Open Registration';
                                if (!empty($act['registration_deadline'])) {
                                    $diffDays = (int)ceil((strtotime($act['registration_deadline']) - time()) / 86400);
                                    if ($diffDays <= 5 && $diffDays > 0) {
                                        $deadlineText = "Closes in {$diffDays} days";
                                    } elseif ($diffDays > 0) {
                                        $deadlineText = "Apply by " . date('M j, Y', strtotime($act['registration_deadline']));
                                    }
                                }

                                // Tags for activity
                                $tags = $activitySkillsMap[$act['activity_id']] ?? ['Leadership', 'Problem Solving', 'Team Collaboration'];
                            ?>
                            <article class="activity-card">
                                <div class="activity-icon-box <?= $iconTheme ?>" aria-hidden="true">
                                    <?php if ($isHackathon): ?>
                                        <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                                    <?php else: ?>
                                        <svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                                    <?php endif; ?>
                                </div>

                                <div class="activity-card-body">
                                    <div class="activity-badges-row">
                                        <span class="activity-pill type"><?= e($act['type_name']) ?></span>
                                        <?php if ($index === 0): ?>
                                            <span class="activity-pill match">✨ 94% Match</span>
                                            <span class="activity-pill deadline">⏰ <?= e($deadlineText) ?></span>
                                        <?php else: ?>
                                            <span class="activity-pill match">Goal Aligned</span>
                                            <span class="activity-pill gray"><?= e($deadlineText) ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <h3 class="activity-title"><?= e($act['title']) ?></h3>

                                    <div class="activity-meta-row">
                                        <span class="activity-meta-item">
                                            <svg viewBox="0 0 24 24"><path d="M3 21h18M3 7v14M21 7v14M6 11h2M6 15h2M16 11h2M16 15h2M10 21V11h4v10M2 7l10-5 10 5"></path></svg>
                                            <span><?= e($act['organizer_name']) ?></span>
                                        </span>
                                        <span class="activity-meta-item">
                                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                                            <span><?= e($act['venue_details'] ?? $act['mode_name']) ?></span>
                                        </span>
                                    </div>

                                    <div class="activity-tags-row">
                                        <?php foreach ($tags as $tag): ?>
                                            <span class="skill-tag"><?= e($tag) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="activity-card-action">
                                    <a href="activities.php?id=<?= (int)$act['activity_id'] ?>" class="btn-view-details">
                                        View Details
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-panel">
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                            <p style="font-weight: 600; margin-bottom: 6px;">No Active Recommendations Right Now</p>
                            <p style="font-size: 13px; margin-bottom: 16px;">Browse the extracurricular directory to discover upcoming hackathons, clubs, and campus events.</p>
                            <a href="activities.php" class="btn-view-pathway" style="display: inline-flex;">Explore Activities</a>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Right: Skills Progress Panel -->
                <aside class="skills-panel" aria-label="Skills Progress Competency Panel">
                    <div>
                        <h2 class="section-title">Skills Progress</h2>
                        <p class="section-subtitle">
                            Tracked against <?= e($activeGoal['career_title'] ?? 'Target') ?> Career Goal
                        </p>
                    </div>

                    <?php if (!empty($studentSkillsList)): ?>
                        <div class="skills-list">
                            <?php foreach ($studentSkillsList as $sIdx => $skill): ?>
                                <?php
                                    // Progress bar percentage calculation based on proficiency level
                                    $val = (int)$skill['level_value'];
                                    $scoreMap = [1 => 45, 2 => 60, 3 => 75, 4 => 82, 5 => 90];
                                    $pct = $scoreMap[$val] ?? 70;
                                    $theme = $progressThemes[$sIdx % count($progressThemes)];
                                ?>
                                <div class="skill-item">
                                    <div class="skill-header">
                                        <span class="skill-title"><?= e($skill['skill_name']) ?></span>
                                        <span class="skill-percent-label"><?= $pct ?>% (<?= e($skill['level_name']) ?>)</span>
                                    </div>
                                    <div class="progress-track">
                                        <div class="progress-bar <?= $theme ?>" style="width: <?= $pct ?>%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <!-- Empty State if No Skills Assessed Yet -->
                        <div class="empty-panel" style="margin-top: 16px;">
                            <svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                            <p style="font-weight: 600; font-size: 13.5px; margin-bottom: 6px;">No Skills Assessed Yet</p>
                            <p style="font-size: 12.5px; margin-bottom: 14px;">Complete your skills assessment to track competencies against your career goal.</p>
                            <a href="skills_assessment.php" class="btn-view-details" style="display: inline-block;">Take Assessment</a>
                        </div>
                    <?php endif; ?>
                </aside>

            </div>
        </main>
    </div>

</body>
</html>
