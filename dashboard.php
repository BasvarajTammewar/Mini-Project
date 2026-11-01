<?php
/**
 * CareerAct — Module 3: Student Dashboard
 * Modern, personalized student control panel integrating real MySQL database records.
 * Database: careeract
 */

declare(strict_types=1);

// Prevent browser from caching stale CSS or broken layout states
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';

// Enforce authentication & profile completion for students (Module 1 & 2 Requirement)
requireCompletedProfile('onboarding.php?msg=complete_profile');

$userId = getCurrentUserId();
$db = getDbConnection();

// ============================================================================
// 1. RETRIEVE AUTHENTICATED STUDENT RECORD & MODULE 2 PROFILE
// ============================================================================
$stmtStudent = $db->prepare("
    SELECT 
        u.id,
        u.name,
        u.email,
        u.role,
        u.account_status,
        u.created_at AS account_created_at,
        sp.education_level,
        sp.institution,
        sp.field_of_study,
        sp.graduation_year,
        sp.country_id,
        sp.city,
        sp.experience_level,
        sp.preferred_mode,
        sp.max_budget,
        sp.budget_currency,
        sp.bio,
        sp.resume_summary,
        sp.profile_completed,
        sp.updated_at AS profile_updated_at,
        c.name AS country_name
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    LEFT JOIN countries c ON sp.country_id = c.id
    WHERE u.id = :user_id
    LIMIT 1
");
$stmtStudent->execute([':user_id' => $userId]);
$student = $stmtStudent->fetch();

if (!$student) {
    header('Location: logout.php');
    exit;
}

$fullName  = trim($student['name'] ?? 'Student');
$nameParts = explode(' ', $fullName);
$firstName = $nameParts[0];
$initials  = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));

// Dynamic greeting based on current local time
$hour = (int)date('G');
if ($hour < 12) {
    $greeting = 'Good Morning';
} elseif ($hour < 17) {
    $greeting = 'Good Afternoon';
} else {
    $greeting = 'Good Evening';
}

// ============================================================================
// 2. RETRIEVE ACTIVE CAREER GOAL & CAREER DOMAIN
// ============================================================================
$stmtGoal = $db->prepare("
    SELECT 
        scg.career_id,
        scg.is_primary,
        scg.created_at AS goal_selected_at,
        c.title AS career_title,
        c.description AS career_desc,
        c.typical_level,
        c.employment_type,
        cs.name AS subfield_name,
        cf.name AS field_name
    FROM student_career_goals scg
    JOIN careers c ON scg.career_id = c.id
    LEFT JOIN career_subfields cs ON c.subfield_id = cs.id
    LEFT JOIN career_fields cf ON cs.field_id = cf.id
    WHERE scg.user_id = :user_id
    ORDER BY scg.is_primary DESC, scg.career_id ASC
    LIMIT 1
");
$stmtGoal->execute([':user_id' => $userId]);
$activeGoal = $stmtGoal->fetch();

// ============================================================================
// 3. PROFILE COMPLETION CALCULATION (SECTION 6 CARD B)
// ============================================================================
// Check each of the 6 profile sections
$sectionsStatus = [
    'basic_info'     => (!empty($student['education_level']) && !empty($student['institution']) && !empty($student['field_of_study'])),
    'education'      => false,
    'career_goal'    => ($activeGoal !== false && !empty($activeGoal['career_id'])),
    'interests'      => false,
    'skills'         => false,
    'activities'     => false,
];

// Check education records
$stmtEduCount = $db->prepare("SELECT COUNT(*) FROM student_education WHERE user_id = :user_id");
$stmtEduCount->execute([':user_id' => $userId]);
$eduCount = (int)$stmtEduCount->fetchColumn();
$sectionsStatus['education'] = ($eduCount > 0);

// Check interest fields
$stmtIntCount = $db->prepare("SELECT COUNT(*) FROM student_interest_fields WHERE user_id = :user_id");
$stmtIntCount->execute([':user_id' => $userId]);
$interestCount = (int)$stmtIntCount->fetchColumn();
$sectionsStatus['interests'] = ($interestCount > 0);

// Fetch student skills
$stmtSkills = $db->prepare("
    SELECT 
        ss.skill_id,
        ss.level,
        ss.source,
        ss.updated_at,
        s.name AS skill_name,
        s.skill_type,
        s.description AS skill_desc,
        sc.name AS category_name,
        sl.label AS level_label,
        sl.descriptor AS level_descriptor
    FROM student_skills ss
    JOIN skills s ON ss.skill_id = s.id
    LEFT JOIN skill_categories sc ON s.category_id = sc.id
    LEFT JOIN skill_levels sl ON ss.level = sl.level
    WHERE ss.user_id = :user_id
    ORDER BY ss.level DESC, s.name ASC
");
$stmtSkills->execute([':user_id' => $userId]);
$studentSkills = $stmtSkills->fetchAll();
$totalSkillsCount = count($studentSkills);
$sectionsStatus['skills'] = ($totalSkillsCount > 0);

// Breakdown of skills by category
$skillsByCategory = [];
foreach ($studentSkills as $sk) {
    $cat = !empty($sk['category_name']) ? $sk['category_name'] : 'General';
    $skillsByCategory[$cat] = ($skillsByCategory[$cat] ?? 0) + 1;
}

// Fetch student existing activities
$stmtAct = $db->prepare("
    SELECT 
        sea.id,
        sea.title,
        sea.role,
        sea.description,
        sea.start_date,
        sea.end_date,
        'Self-Reported' AS activity_type,
        'Active' AS participation_status,
        'Completed' AS completion_status,
        sea.start_date AS record_date,
        'existing' AS record_source
    FROM student_existing_activities sea
    WHERE sea.user_id = :user_id
    ORDER BY sea.start_date DESC
");
$stmtAct->execute([':user_id' => $userId]);
$existingActivities = $stmtAct->fetchAll();
$sectionsStatus['activities'] = (count($existingActivities) > 0);

// Calculate exact profile percentage
$completedSectionsCount = 0;
$completedSectionNames = [];
$incompleteSectionNames = [];

$sectionLabels = [
    'basic_info'  => 'Basic & Personal Details',
    'education'   => 'Educational Qualifications',
    'career_goal' => 'Target Career Path',
    'interests'   => 'Career Interest Fields',
    'skills'      => 'Current Skills Inventory',
    'activities'  => 'Extracurricular Activities',
];

foreach ($sectionsStatus as $secKey => $isSecDone) {
    if ($isSecDone) {
        $completedSectionsCount++;
        $completedSectionNames[] = $sectionLabels[$secKey];
    } else {
        $incompleteSectionNames[] = $sectionLabels[$secKey];
    }
}

$profilePercentage = (int)round(($completedSectionsCount / 6) * 100);
if (!empty($student['profile_completed'])) {
    $profilePercentage = max(100, $profilePercentage);
}

// ============================================================================
// 4. RETRIEVE ENROLLMENTS & COMBINE EXTRACTED ACTIVITIES (SECTION 6 CARD D & 13)
// ============================================================================
$stmtEnr = $db->prepare("
    SELECT 
        e.id,
        a.title,
        CASE 
            WHEN e.self_directed = 1 THEN 'Self-Directed'
            ELSE COALESCE(a.delivery_mode, 'Online')
        END AS activity_type,
        e.status AS participation_status,
        CONCAT(e.progress_pct, '% Progress') AS completion_status,
        COALESCE(e.started_at, e.created_at) AS record_date,
        'enrollment' AS record_source
    FROM enrollments e
    JOIN activities a ON e.activity_id = a.id
    WHERE e.user_id = :user_id
    ORDER BY e.created_at DESC
");
$stmtEnr->execute([':user_id' => $userId]);
$enrollmentActivities = $stmtEnr->fetchAll();

// Combine all student activities
$allStudentActivities = array_merge($existingActivities, $enrollmentActivities);
$totalActivitiesCount = count($allStudentActivities);

// Count active/completed
$completedActivitiesCount = 0;
foreach ($allStudentActivities as $act) {
    if ($act['completion_status'] === 'Completed' || ($act['participation_status'] ?? '') === 'completed') {
        $completedActivitiesCount++;
    }
}

// ============================================================================
// 5. RETRIEVE ASSESSMENT STATUS & HISTORY (SECTION 6 CARD E & 10)
// ============================================================================
$stmtAttempts = $db->prepare("
    SELECT 
        aa.id,
        aa.assessment_id,
        aa.career_id,
        aa.status,
        aa.started_at,
        aa.submitted_at,
        aa.score_pct,
        a.title AS assessment_title,
        c.title AS career_title
    FROM assessment_attempts aa
    LEFT JOIN assessments a ON aa.assessment_id = a.id
    LEFT JOIN careers c ON aa.career_id = c.id
    WHERE aa.user_id = :user_id
    ORDER BY aa.submitted_at DESC, aa.id DESC
");
$stmtAttempts->execute([':user_id' => $userId]);
$assessmentAttempts = $stmtAttempts->fetchAll();

$completedAssessments = array_filter($assessmentAttempts, function($att) {
    return $att['status'] === 'submitted';
});
$assessmentsCompletedCount = count($completedAssessments);
$latestAssessment = !empty($completedAssessments) ? reset($completedAssessments) : null;
$latestAssessmentScore = $latestAssessment ? (float)$latestAssessment['score_pct'] : null;
$lastAssessmentDate = $latestAssessment ? $latestAssessment['submitted_at'] : null;

// ============================================================================
// 6. RETRIEVE ACHIEVEMENTS & PORTFOLIO (SECTION 6 CARD F & 14)
// ============================================================================
$stmtAch = $db->prepare("
    SELECT 
        id,
        title,
        role,
        result,
        description,
        completed_on,
        evidence_type,
        evidence_url,
        verification_status
    FROM achievements
    WHERE user_id = :user_id
    ORDER BY completed_on DESC, id DESC
");
$stmtAch->execute([':user_id' => $userId]);
$achievements = $stmtAch->fetchAll();

$stmtPort = $db->prepare("
    SELECT 
        id,
        title,
        item_type,
        url,
        description,
        verification_status,
        created_at
    FROM portfolio_items
    WHERE user_id = :user_id
    ORDER BY created_at DESC
");
$stmtPort->execute([':user_id' => $userId]);
$portfolioItems = $stmtPort->fetchAll();

$achievementsCount = count($achievements);
$portfolioCount = count($portfolioItems);
$verifiedAchievementsCount = 0;
foreach ($achievements as $ach) {
    if ($ach['verification_status'] === 'verified') {
        $verifiedAchievementsCount++;
    }
}

// ============================================================================
// 7. RETRIEVE SKILL-GAP ANALYSIS PREVIEW (SECTION 11)
// ============================================================================
$stmtGaps = $db->prepare("
    SELECT 
        rsg.skill_id,
        rsg.required_level,
        rsg.current_level,
        rsg.gap,
        rsg.importance,
        rsg.evidence_status,
        rsg.classification,
        s.name AS skill_name,
        s.skill_type
    FROM run_skill_gaps rsg
    JOIN recommendation_runs rr ON rsg.run_id = rr.id
    JOIN skills s ON rsg.skill_id = s.id
    WHERE rr.user_id = :user_id
    ORDER BY rr.id DESC, rsg.gap DESC
");
$stmtGaps->execute([':user_id' => $userId]);
$skillGaps = $stmtGaps->fetchAll();

$strongSkills = [];
$improvingSkills = [];
$missingSkills = [];

foreach ($skillGaps as $gap) {
    if ($gap['classification'] === 'strong') {
        $strongSkills[] = $gap;
    } elseif (in_array($gap['classification'], ['developing', 'needs_improvement'])) {
        $improvingSkills[] = $gap;
    } else {
        $missingSkills[] = $gap;
    }
}

// ============================================================================
// 8. RETRIEVE ACTIVITY RECOMMENDATIONS PREVIEW (SECTION 12)
// ============================================================================
$stmtRec = $db->prepare("
    SELECT 
        ar.rank_no,
        ar.total_score,
        ar.reason,
        a.id AS activity_id,
        a.title AS activity_title,
        a.delivery_mode,
        a.difficulty,
        s.name AS related_skill_name
    FROM activity_recommendations ar
    JOIN recommendation_runs rr ON ar.run_id = rr.id
    JOIN activities a ON ar.activity_id = a.id
    LEFT JOIN skills s ON ar.primary_skill_id = s.id
    WHERE rr.user_id = :user_id
    ORDER BY rr.id DESC, ar.rank_no ASC
    LIMIT 4
");
$stmtRec->execute([':user_id' => $userId]);
$recommendations = $stmtRec->fetchAll();

// ============================================================================
// 9. RETRIEVE NOTIFICATIONS (SECTION 15)
// ============================================================================
$stmtNotif = $db->prepare("
    SELECT * FROM notifications 
    WHERE user_id = :user_id 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmtNotif->execute([':user_id' => $userId]);
$notifications = $stmtNotif->fetchAll();

$unreadNotificationsCount = 0;
foreach ($notifications as $n) {
    if (empty($n['is_read'])) {
        $unreadNotificationsCount++;
    }
}

// ============================================================================
// 10. RETRIEVE EDUCATION DETAILS & INTERESTS FOR PROFILE SUMMARY (SECTION 8)
// ============================================================================
$stmtEdu = $db->prepare("SELECT * FROM student_education WHERE user_id = :user_id ORDER BY start_year DESC LIMIT 2");
$stmtEdu->execute([':user_id' => $userId]);
$educationList = $stmtEdu->fetchAll();

$stmtInt = $db->prepare("
    SELECT cf.name 
    FROM student_interest_fields sif 
    JOIN career_fields cf ON sif.field_id = cf.id 
    WHERE sif.user_id = :user_id 
    ORDER BY cf.sort_order ASC 
    LIMIT 5
");
$stmtInt->execute([':user_id' => $userId]);
$interestFieldNames = $stmtInt->fetchAll(PDO::FETCH_COLUMN);

// ============================================================================
// 11. CAREER DEVELOPMENT PROGRESS STAGES (SECTION 7)
// ============================================================================
$stages = [
    1 => [
        'title'     => 'Profile Completed',
        'desc'      => 'Academic & career profile',
        'completed' => !empty($student['profile_completed']),
    ],
    2 => [
        'title'     => 'Skills Added',
        'desc'      => $totalSkillsCount . ' competencies listed',
        'completed' => ($totalSkillsCount > 0),
    ],
    3 => [
        'title'     => 'Assessment Completed',
        'desc'      => $assessmentsCompletedCount . ' assessments taken',
        'completed' => ($assessmentsCompletedCount > 0),
    ],
    4 => [
        'title'     => 'Skill-Gap Identified',
        'desc'      => count($skillGaps) . ' benchmark points',
        'completed' => (count($skillGaps) > 0),
    ],
    5 => [
        'title'     => 'Recommendations Received',
        'desc'      => count($recommendations) . ' tailored activities',
        'completed' => (count($recommendations) > 0),
    ],
    6 => [
        'title'     => 'Activities Explored',
        'desc'      => $totalActivitiesCount . ' tracked experiences',
        'completed' => ($totalActivitiesCount > 0),
    ],
    7 => [
        'title'     => 'Activities Completed',
        'desc'      => $completedActivitiesCount . ' finished milestones',
        'completed' => ($completedActivitiesCount > 0),
    ],
    8 => [
        'title'     => 'Portfolio Updated',
        'desc'      => ($portfolioCount + $achievementsCount) . ' verified items',
        'completed' => ($portfolioCount > 0 || $achievementsCount > 0),
    ],
];

$stagesCompletedCount = 0;
foreach ($stages as $stg) {
    if ($stg['completed']) {
        $stagesCompletedCount++;
    }
}
$stagesPercent = (int)round(($stagesCompletedCount / 8) * 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard — CareerAct</title>
    <meta name="description" content="Personalized Student Career and Extracurricular Activity Control Panel">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= time() ?>">
    <style>
        /* ====================================================================
           CRITICAL EMBEDDED DASHBOARD STYLES (IMMUNE TO BROWSER CSS CACHE)
           ==================================================================== */

        /* 1. Universal SVG Reset & Black-Fill Guard */
        svg, svg * {
            fill: none !important;
            box-sizing: border-box !important;
        }
        svg {
            display: inline-block !important;
            vertical-align: middle !important;
            stroke: currentColor !important;
            stroke-width: 2 !important;
            flex-shrink: 0 !important;
            max-width: 48px !important;
            max-height: 48px !important;
        }

        /* 2. Strict Scoped SVG Dimensions across all components */
        .sidebar-brand-icon svg { width: 20px !important; height: 20px !important; min-width: 20px !important; max-width: 20px !important; }
        .sidebar-nav svg, .logout-link svg { width: 18px !important; height: 18px !important; min-width: 18px !important; max-width: 18px !important; }
        .search-icon svg { width: 16px !important; height: 16px !important; min-width: 16px !important; max-width: 16px !important; }
        .notification-btn svg { width: 18px !important; height: 18px !important; min-width: 18px !important; max-width: 18px !important; }
        .user-chevron svg { width: 14px !important; height: 14px !important; min-width: 14px !important; max-width: 14px !important; }
        .hero-track-pill svg { width: 14px !important; height: 14px !important; min-width: 14px !important; max-width: 14px !important; }
        .hero-btn-white svg, .hero-btn-glass svg { width: 16px !important; height: 16px !important; min-width: 16px !important; max-width: 16px !important; stroke-width: 2.2 !important; }
        .overview-icon-box svg { width: 20px !important; height: 20px !important; min-width: 20px !important; max-width: 20px !important; min-height: 20px !important; max-height: 20px !important; }
        .overview-card-link svg { width: 14px !important; height: 14px !important; min-width: 14px !important; max-width: 14px !important; stroke-width: 2.2 !important; }
        .tracker-step-node svg { width: 16px !important; height: 16px !important; min-width: 16px !important; max-width: 16px !important; stroke-width: 2.5 !important; }
        .module-empty-state svg { width: 40px !important; height: 40px !important; min-width: 40px !important; max-width: 40px !important; stroke: #94A3B8 !important; stroke-width: 1.8 !important; margin-bottom: 8px !important; }
        .dropdown-menu-item svg { width: 16px !important; height: 16px !important; min-width: 16px !important; max-width: 16px !important; min-height: 16px !important; max-height: 16px !important; }
        .mobile-toggle-btn svg { width: 22px !important; height: 22px !important; min-width: 22px !important; max-width: 22px !important; }

        /* 3. Top Navigation Bar & Action Dropdowns */
        .dashboard-topbar {
            background-color: #FFFFFF !important;
            border-bottom: 1px solid #E4EAF2 !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 100 !important;
        }
        .topbar-actions {
            position: relative !important;
            display: flex !important;
            align-items: center !important;
            gap: 16px !important;
        }
        .dropdown-relative {
            position: relative !important;
        }
        .notification-dropdown {
            position: absolute !important;
            top: calc(100% + 10px) !important;
            right: 0 !important;
            width: 340px !important;
            background: #FFFFFF !important;
            border: 1px solid #E4EAF2 !important;
            border-radius: 12px !important;
            box-shadow: 0 10px 25px -5px rgba(22, 58, 112, 0.15), 0 8px 10px -6px rgba(22, 58, 112, 0.08) !important;
            z-index: 1050 !important;
            display: none;
            overflow: hidden !important;
        }
        .profile-dropdown-menu {
            position: absolute !important;
            top: calc(100% + 10px) !important;
            right: 0 !important;
            width: 220px !important;
            max-width: 220px !important;
            background: #FFFFFF !important;
            border: 1px solid #E4EAF2 !important;
            border-radius: 12px !important;
            box-shadow: 0 10px 25px -5px rgba(22, 58, 112, 0.15), 0 8px 10px -6px rgba(22, 58, 112, 0.08) !important;
            z-index: 1050 !important;
            display: none;
            padding: 8px 0 !important;
            overflow: hidden !important;
        }
        .notification-dropdown.show,
        .profile-dropdown-menu.show {
            display: block !important;
            animation: dropdownFadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }
        .dropdown-menu-item {
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            padding: 10px 16px !important;
            color: #17243B !important;
            text-decoration: none !important;
            font-size: 13.5px !important;
            font-weight: 600 !important;
            transition: all 0.2s ease !important;
            white-space: nowrap !important;
        }
        .dropdown-menu-item:hover {
            background-color: #EEF3FF !important;
            color: #1769E8 !important;
        }

        /* 4. Overview Cards 6-Grid */
        .overview-grid-6 {
            display: grid !important;
            grid-template-columns: repeat(3, 1fr) !important;
            gap: 18px !important;
            margin-top: 20px !important;
        }
        .overview-card {
            background: #FFFFFF !important;
            border: 1px solid #E4EAF2 !important;
            border-radius: 12px !important;
            padding: 20px !important;
            box-shadow: 0 2px 10px rgba(22, 58, 112, 0.04), 0 1px 3px rgba(228, 234, 242, 0.8) !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            min-height: 180px !important;
            position: relative !important;
            overflow: hidden !important;
            transition: all 0.2s ease !important;
        }
        .overview-card:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 8px 20px -4px rgba(22, 58, 112, 0.08) !important;
            border-color: #CBD5E1 !important;
        }
        .overview-card-header {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            margin-bottom: 14px !important;
        }
        .overview-card-title {
            font-size: 13px !important;
            font-weight: 700 !important;
            color: #667085 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            margin: 0 !important;
        }
        .overview-icon-box {
            width: 40px !important;
            height: 40px !important;
            min-width: 40px !important;
            max-width: 40px !important;
            min-height: 40px !important;
            max-height: 40px !important;
            border-radius: 10px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            overflow: hidden !important;
            flex-shrink: 0 !important;
        }
        .overview-icon-box.blue   { background-color: #EEF3FF !important; color: #1769E8 !important; }
        .overview-icon-box.green  { background-color: #EDFDF5 !important; color: #059669 !important; }
        .overview-icon-box.purple { background-color: #F3EDFF !important; color: #8A46EA !important; }
        .overview-icon-box.amber  { background-color: #FFF7EB !important; color: #D97706 !important; }
        .overview-icon-box.teal   { background-color: #E6F8F3 !important; color: #0F9D8A !important; }
        .overview-icon-box.indigo { background-color: #EEF2FF !important; color: #4F46E5 !important; }
        .overview-main-val {
            font-size: 20px !important;
            font-weight: 800 !important;
            color: #163A70 !important;
            line-height: 1.2 !important;
            margin-bottom: 4px !important;
        }
        .overview-subtext {
            font-size: 12.5px !important;
            color: #667085 !important;
            line-height: 1.4 !important;
            min-height: 35px !important;
        }
        .overview-card-footer {
            margin-top: 14px !important;
            padding-top: 12px !important;
            border-top: 1px solid #F1F5F9 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
        }
        .overview-card-link {
            font-size: 12.5px !important;
            font-weight: 700 !important;
            color: #1769E8 !important;
            text-decoration: none !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 4px !important;
            transition: all 0.2s ease !important;
        }
        .overview-card-link:hover {
            color: #1356be !important;
            gap: 6px !important;
        }

        /* 5. Career Development Tracker */
        .tracker-card {
            background: #FFFFFF !important;
            border: 1px solid #E4EAF2 !important;
            border-radius: 12px !important;
            padding: 24px !important;
            margin-top: 24px !important;
            box-shadow: 0 2px 10px rgba(22, 58, 112, 0.04) !important;
        }
        .tracker-header {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            margin-bottom: 18px !important;
        }
        .tracker-progress-summary {
            display: flex !important;
            align-items: center !important;
            gap: 12px !important;
        }
        .tracker-progress-bar-wrap {
            width: 140px !important;
            height: 8px !important;
            background: #EEF3FF !important;
            border-radius: 4px !important;
            overflow: hidden !important;
        }
        .tracker-progress-bar-fill {
            height: 100% !important;
            background: linear-gradient(135deg, #1D68E8 0%, #4747DC 100%) !important;
            border-radius: 4px !important;
            transition: width 0.6s ease !important;
        }
        .tracker-timeline {
            display: grid !important;
            grid-template-columns: repeat(8, 1fr) !important;
            gap: 8px !important;
            position: relative !important;
            padding-top: 10px !important;
        }
        .tracker-step {
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            text-align: center !important;
            position: relative !important;
        }
        .tracker-step-node {
            width: 36px !important;
            height: 36px !important;
            min-width: 36px !important;
            max-width: 36px !important;
            border-radius: 50% !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin-bottom: 10px !important;
            font-size: 13px !important;
            font-weight: 800 !important;
            z-index: 2 !important;
            overflow: hidden !important;
        }
        .tracker-step.completed .tracker-step-node {
            background-color: #0F9D8A !important;
            color: #FFFFFF !important;
            box-shadow: 0 4px 10px rgba(15, 157, 138, 0.3) !important;
        }
        .tracker-step.pending .tracker-step-node {
            background-color: #F1F5F9 !important;
            color: #94A3B8 !important;
            border: 2px dashed #CBD5E1 !important;
        }
        .tracker-step-title {
            font-size: 12px !important;
            font-weight: 700 !important;
            color: #163A70 !important;
            line-height: 1.3 !important;
            margin-bottom: 3px !important;
        }
        .tracker-step-status {
            font-size: 10.5px !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.3px !important;
        }
        .tracker-step.completed .tracker-step-status { color: #0F9D8A !important; }
        .tracker-step.pending .tracker-step-status { color: #94A3B8 !important; }

        /* 6. Dashboard Sections & Split Grid */
        .dashboard-split-grid {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            gap: 24px !important;
            margin-top: 24px !important;
        }
        .dashboard-section {
            background: #FFFFFF !important;
            border: 1px solid #E4EAF2 !important;
            border-radius: 12px !important;
            padding: 24px !important;
            margin-top: 24px !important;
            box-shadow: 0 2px 10px rgba(22, 58, 112, 0.04) !important;
        }
        .section-head-bar {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            margin-bottom: 18px !important;
            padding-bottom: 12px !important;
            border-bottom: 1px solid #F1F5F9 !important;
        }
        .section-main-heading {
            font-size: 17px !important;
            font-weight: 800 !important;
            color: #163A70 !important;
            margin: 0 !important;
            letter-spacing: -0.2px !important;
        }
        .section-sub-heading {
            font-size: 13px !important;
            color: #667085 !important;
            margin-top: 3px !important;
        }

        /* 7. Mobile Toggle Button Guard */
        .mobile-toggle-btn {
            display: none !important;
        }
        @media (max-width: 992px) {
            .mobile-toggle-btn {
                display: block !important;
            }
            .overview-grid-6 {
                grid-template-columns: repeat(2, 1fr) !important;
            }
            .dashboard-split-grid {
                grid-template-columns: 1fr !important;
            }
            .tracker-timeline {
                grid-template-columns: repeat(4, 1fr) !important;
                gap: 16px !important;
            }
        }
        @media (max-width: 680px) {
            .overview-grid-6 {
                grid-template-columns: 1fr !important;
            }
            .tracker-timeline {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 14px !important;
            }
        }
    </style>
</head>
<body class="dashboard-body">

    <!-- Mobile Drawer Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- ====================================================================
         1. SIDEBAR NAVIGATION (SECTION 4 - 14 ITEMS)
         ==================================================================== -->
    <aside class="dashboard-sidebar" id="dashboardSidebar" aria-label="Portal Navigation">
        <div>
            <!-- Branding Header -->
            <div class="sidebar-header">
                <a href="dashboard.php" class="sidebar-brand-wrapper">
                    <div class="sidebar-brand-icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
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
                    <!-- 1. Dashboard -->
                    <li>
                        <a href="dashboard.php" class="nav-link active" aria-current="page">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect></svg>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <!-- 2. My Profile -->
                    <li>
                        <a href="profile.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            <span>My Profile</span>
                        </a>
                    </li>
                    <!-- 3. Career Exploration -->
                    <li>
                        <a href="career_goals.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg>
                            <span>Career Exploration</span>
                        </a>
                    </li>
                    <!-- 4. My Skills -->
                    <li>
                        <a href="my_skills.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                            <span>My Skills</span>
                        </a>
                    </li>
                    <!-- 5. Skill Assessment -->
                    <li>
                        <a href="skills_assessment.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                            <span>Skill Assessment</span>
                        </a>
                    </li>
                    <!-- 6. Skill-Gap Analysis -->
                    <li>
                        <a href="skill_gap.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg>
                            <span>Skill-Gap Analysis</span>
                        </a>
                    </li>
                    <!-- 7. Recommendations -->
                    <li>
                        <a href="recommendations.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path></svg>
                            <span>Recommendations</span>
                        </a>
                    </li>
                    <!-- 8. Explore Activities -->
                    <li>
                        <a href="activities.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg>
                            <span>Explore Activities</span>
                        </a>
                    </li>
                    <!-- 9. Saved Activities -->
                    <li>
                        <a href="saved_activities.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path></svg>
                            <span>Saved Activities</span>
                        </a>
                    </li>
                    <!-- 10. My Enrollments -->
                    <li>
                        <a href="enrollments.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg>
                            <span>My Enrollments</span>
                        </a>
                    </li>
                    <!-- 11. My Portfolio -->
                    <li>
                        <a href="portfolio.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                            <span>My Portfolio</span>
                        </a>
                    </li>
                    <!-- 12. Resume Builder -->
                    <li>
                        <a href="resume.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            <span>Resume Builder</span>
                        </a>
                    </li>
                    <!-- 13. Settings -->
                    <li>
                        <a href="settings.php" class="nav-link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                            <span>Settings</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>

        <!-- 14. Logout -->
        <div class="sidebar-footer">
            <a href="logout.php" class="logout-link" id="sidebarLogout">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- ====================================================================
         2. MAIN DASHBOARD CONTENT AREA
         ==================================================================== -->
    <div class="dashboard-main">

        <!-- ================================================================
             DASHBOARD HEADER (SECTION 5)
             ================================================================ -->
        <header class="dashboard-topbar">
            <!-- Mobile Toggle -->
            <button type="button" class="mobile-toggle-btn" id="mobileMenuBtn" style="display: none;" aria-label="Toggle navigation menu">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
            </button>

            <!-- Personalized Greeting -->
            <div class="topbar-welcome">
                <span class="welcome-title"><?= e($greeting) ?>, <?= e($firstName) ?>!</span>
                <span class="welcome-subtitle">Your career journey continues. Let's develop your skills and achieve your goals.</span>
            </div>

            <!-- Topbar Search -->
            <form action="activities.php" method="GET" class="topbar-search-form" role="search">
                <div class="search-wrapper">
                    <span class="search-icon" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </span>
                    <input 
                        type="text" 
                        name="q" 
                        class="search-input" 
                        placeholder="Search activities, skills, or career pathways..." 
                        aria-label="Search activities and skills"
                    >
                </div>
            </form>

            <!-- Actions: Notifications & Student Profile Menu -->
            <div class="topbar-actions">
                <!-- Notifications Dropdown (Section 15) -->
                <div class="dropdown-relative">
                    <button type="button" class="notification-btn" id="notifDropdownToggle" aria-label="Notifications" title="Notifications">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                        <?php if ($unreadNotificationsCount > 0): ?>
                            <span class="notification-badge" aria-hidden="true"></span>
                        <?php endif; ?>
                    </button>

                    <div class="notification-dropdown" id="notificationDropdownMenu" style="display: none;" role="menu">
                        <div class="dropdown-header">
                            <span>Notifications</span>
                            <span style="font-size: 11px; font-weight: 600; color: #1769E8;"><?= count($notifications) ?> Total</span>
                        </div>
                        <div class="notification-list">
                            <?php if (empty($notifications)): ?>
                                <div style="padding: 24px 16px; text-align: center; color: #667085; font-size: 13px;">
                                    <svg viewBox="0 0 24 24" width="28" height="28" stroke="#94A3B8" stroke-width="1.8" fill="none" style="margin-bottom: 6px;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                                    <div>You're all caught up!</div>
                                    <div style="font-size: 11.5px; color: #94A3B8; margin-top: 2px;">No new alerts or deadlines.</div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($notifications as $notif): ?>
                                    <div class="notification-item <?= empty($notif['is_read']) ? 'unread' : '' ?>">
                                        <div style="flex: 1;">
                                            <div style="font-weight: 600; color: #163A70;"><?= e($notif['message']) ?></div>
                                            <div style="font-size: 11px; color: #94A3B8; margin-top: 3px;"><?= date('M j, Y • g:i A', strtotime($notif['created_at'])) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Student Profile Menu Chip -->
                <div class="dropdown-relative">
                    <button type="button" class="user-profile-chip" id="userMenuToggle" aria-haspopup="true" style="background:none; border:none; cursor:pointer; text-align:left;">
                        <div class="avatar-circle"><?= e($initials) ?></div>
                        <div class="user-text-info">
                            <span class="user-name"><?= e($fullName) ?></span>
                            <span class="user-level"><?= e(ucfirst($student['experience_level'] ?? 'Student')) ?></span>
                        </div>
                        <span class="user-chevron" aria-hidden="true">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </span>
                    </button>

                    <div class="profile-dropdown-menu" id="userDropdownMenu" style="display: none; position: absolute; top: calc(100% + 10px); right: 0; width: 220px; max-width: 220px; background: #FFFFFF; border: 1px solid #E4EAF2; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(22, 58, 112, 0.15); z-index: 1050; padding: 8px 0; overflow: hidden;" role="menu">
                        <a href="profile.php" class="dropdown-menu-item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px!important;height:16px!important;min-width:16px!important;max-width:16px!important;min-height:16px!important;max-height:16px!important;fill:none!important;flex-shrink:0!important;display:inline-block!important;"><path fill="none" stroke="currentColor" d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle fill="none" stroke="currentColor" cx="12" cy="7" r="4"></circle></svg>
                            <span>My Profile</span>
                        </a>
                        <a href="settings.php" class="dropdown-menu-item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px!important;height:16px!important;min-width:16px!important;max-width:16px!important;min-height:16px!important;max-height:16px!important;fill:none!important;flex-shrink:0!important;display:inline-block!important;"><circle fill="none" stroke="currentColor" cx="12" cy="12" r="3"></circle><path fill="none" stroke="currentColor" d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                            <span>Settings</span>
                        </a>
                        <div style="border-top: 1px solid #F1F5F9; margin: 4px 0;"></div>
                        <a href="logout.php" class="dropdown-menu-item" style="color: #DC2626;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px!important;height:16px!important;min-width:16px!important;max-width:16px!important;min-height:16px!important;max-height:16px!important;fill:none!important;flex-shrink:0!important;display:inline-block!important;"><path fill="none" stroke="currentColor" d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline fill="none" stroke="currentColor" points="16 17 21 12 16 7"></polyline><line fill="none" stroke="currentColor" x1="21" y1="12" x2="9" y2="12"></line></svg>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Dashboard Viewport -->
        <main class="dashboard-content" id="mainContent">

            <!-- Hero Banner -->
            <section class="hero-banner" aria-label="Student Journey Overview">
                <div class="hero-track-pill">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>
                    <span>
                        <?php if ($activeGoal): ?>
                            Target Career Track: <?= e($activeGoal['career_title']) ?>
                        <?php else: ?>
                            Target Track: Explore & Set Your Career Goal
                        <?php endif; ?>
                    </span>
                </div>

                <h1 class="hero-heading">Welcome back, <?= e($fullName) ?></h1>
                <p class="hero-desc">
                    Your central control panel for career goal tracking, verified competencies, assessment progression, and recommended extracurricular activities.
                </p>

                <div class="hero-actions">
                    <a href="activities.php" class="hero-btn-white" id="heroExploreBtn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg>
                        <span>Explore Activities</span>
                    </a>
                    <a href="career_goals.php" class="hero-btn-glass" id="heroUpdateGoalBtn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg>
                        <span><?= $activeGoal ? 'Update Career Goal' : 'Choose Career Goal' ?></span>
                    </a>
                </div>
            </section>

            <!-- ================================================================
                 3. STUDENT OVERVIEW CARDS (SECTION 6 - 6 CARDS)
                 ================================================================ -->
            <section aria-label="Student Overview Cards">
                <div class="overview-grid-6">

                    <!-- Card A: Career Goal -->
                    <div class="overview-card" id="cardCareerGoal">
                        <div>
                            <div class="overview-card-header">
                                <span class="overview-card-title">Career Goal</span>
                                <div class="overview-icon-box blue" aria-hidden="true">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg>
                                </div>
                            </div>
                            <?php if ($activeGoal): ?>
                                <div class="overview-main-val"><?= e($activeGoal['career_title']) ?></div>
                                <div class="overview-subtext">
                                    Domain: <strong><?= e($activeGoal['field_name'] ?? $activeGoal['subfield_name'] ?? 'General') ?></strong>
                                    <?php if (!empty($activeGoal['typical_level'])): ?>
                                        <br><span style="font-size:11.5px;">Level: <?= e(ucfirst($activeGoal['typical_level'])) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="overview-main-val" style="font-size: 16px; color: #94A3B8;">No Goal Selected</div>
                                <div class="overview-subtext">Set your primary career goal to unlock tailored activity pathways.</div>
                            <?php endif; ?>
                        </div>
                        <div class="overview-card-footer">
                            <a href="career_goals.php" class="overview-card-link">
                                <span><?= $activeGoal ? 'View Pathway' : 'Set Career Goal' ?></span>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                            </a>
                        </div>
                    </div>

                    <!-- Card B: Profile Completion -->
                    <div class="overview-card" id="cardProfileCompletion">
                        <div>
                            <div class="overview-card-header">
                                <span class="overview-card-title">Profile Completion</span>
                                <div class="overview-icon-box green" aria-hidden="true">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                                </div>
                            </div>
                            <div class="overview-main-val" style="display:flex; align-items:baseline; gap:6px;">
                                <span><?= $profilePercentage ?>%</span>
                                <span class="badge-pill <?= $profilePercentage >= 100 ? 'success' : 'warning' ?>">
                                    <?= $profilePercentage >= 100 ? 'Complete' : ($completedSectionsCount . '/6 Sections') ?>
                                </span>
                            </div>
                            <div class="overview-subtext">
                                <?php if ($profilePercentage >= 100): ?>
                                    All 6 core sections completed & verified in database.
                                <?php else: ?>
                                    Pending: <?= e(implode(', ', $incompleteSectionNames)) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="overview-card-footer">
                            <a href="<?= $profilePercentage >= 100 ? 'profile.php' : 'onboarding.php?edit=1' ?>" class="overview-card-link">
                                <span><?= $profilePercentage >= 100 ? 'View Profile' : 'Complete Profile' ?></span>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                            </a>
                        </div>
                    </div>

                    <!-- Card C: Total Skills -->
                    <div class="overview-card" id="cardTotalSkills">
                        <div>
                            <div class="overview-card-header">
                                <span class="overview-card-title">Total Skills</span>
                                <div class="overview-icon-box purple" aria-hidden="true">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                                </div>
                            </div>
                            <div class="overview-main-val"><?= $totalSkillsCount ?> Skills</div>
                            <div class="overview-subtext">
                                <?php if ($totalSkillsCount > 0): ?>
                                    <?php 
                                        $catSummaries = [];
                                        foreach (array_slice($skillsByCategory, 0, 2) as $cName => $cCnt) {
                                            $catSummaries[] = "{$cCnt} {$cName}";
                                        }
                                        echo e(implode(' • ', $catSummaries));
                                    ?>
                                <?php else: ?>
                                    No skills added to your profile yet.
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="overview-card-footer">
                            <a href="my_skills.php" class="overview-card-link">
                                <span>View All Skills</span>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                            </a>
                        </div>
                    </div>

                    <!-- Card D: Extracurricular Activities -->
                    <div class="overview-card" id="cardActivities">
                        <div>
                            <div class="overview-card-header">
                                <span class="overview-card-title">Extracurriculars</span>
                                <div class="overview-icon-box amber" aria-hidden="true">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg>
                                </div>
                            </div>
                            <div class="overview-main-val"><?= $totalActivitiesCount ?> Activities</div>
                            <div class="overview-subtext">
                                <?php if ($totalActivitiesCount > 0): ?>
                                    <?= e(implode(', ', array_slice(array_column($allStudentActivities, 'title'), 0, 2))) ?>
                                <?php else: ?>
                                    No active extracurricular activities recorded.
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="overview-card-footer">
                            <a href="activities.php" class="overview-card-link">
                                <span>Explore Activities</span>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                            </a>
                        </div>
                    </div>

                    <!-- Card E: Assessment Status -->
                    <div class="overview-card" id="cardAssessmentStatus">
                        <div>
                            <div class="overview-card-header">
                                <span class="overview-card-title">Assessment Status</span>
                                <div class="overview-icon-box teal" aria-hidden="true">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                                </div>
                            </div>
                            <?php if ($assessmentsCompletedCount > 0 && $latestAssessmentScore !== null): ?>
                                <div class="overview-main-val"><?= (int)$latestAssessmentScore ?>% Score</div>
                                <div class="overview-subtext">
                                    Status: <strong>Completed</strong> (<?= $assessmentsCompletedCount ?> Total)<br>
                                    <span style="font-size:11px;">Latest: <?= date('M j, Y', strtotime($lastAssessmentDate)) ?></span>
                                </div>
                            <?php else: ?>
                                <div class="overview-main-val" style="font-size: 16px; color: #94A3B8;">Not Completed</div>
                                <div class="overview-subtext">0 assessments completed. Take assessment to benchmark skills.</div>
                            <?php endif; ?>
                        </div>
                        <div class="overview-card-footer">
                            <a href="skills_assessment.php" class="overview-card-link">
                                <span><?= $assessmentsCompletedCount > 0 ? 'Review Assessment' : 'Start Assessment' ?></span>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                            </a>
                        </div>
                    </div>

                    <!-- Card F: Achievements -->
                    <div class="overview-card" id="cardAchievements">
                        <div>
                            <div class="overview-card-header">
                                <span class="overview-card-title">Achievements</span>
                                <div class="overview-icon-box indigo" aria-hidden="true">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                                </div>
                            </div>
                            <div class="overview-main-val"><?= ($verifiedAchievementsCount + $achievementsCount) ?> Recorded</div>
                            <div class="overview-subtext">
                                <?php if (($verifiedAchievementsCount + $achievementsCount) > 0): ?>
                                    <?= $verifiedAchievementsCount ?> verified credentials & portfolio items.
                                <?php else: ?>
                                    No verified achievements recorded yet.
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="overview-card-footer">
                            <a href="portfolio.php" class="overview-card-link">
                                <span>View Portfolio</span>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                            </a>
                        </div>
                    </div>

                </div>
            </section>

            <!-- ================================================================
                 4. CAREER DEVELOPMENT PROGRESS SECTION (SECTION 7 - 8 STAGES)
                 ================================================================ -->
            <section class="tracker-card" aria-label="Career Development Progress Tracker">
                <div class="tracker-header">
                    <div>
                        <h2 class="section-main-heading">Career Development Progress</h2>
                        <span class="section-sub-heading">Track your milestone completion through the 8 stages of career readiness</span>
                    </div>
                    <div class="tracker-progress-summary">
                        <span style="font-size: 13px; font-weight: 700; color: #163A70;"><?= $stagesCompletedCount ?> of 8 Completed</span>
                        <div class="tracker-progress-bar-wrap" title="<?= $stagesPercent ?>% Complete">
                            <div class="tracker-progress-bar-fill" style="width: <?= $stagesPercent ?>%;"></div>
                        </div>
                    </div>
                </div>

                <div class="tracker-timeline">
                    <?php foreach ($stages as $stepNum => $stage): ?>
                        <div class="tracker-step <?= $stage['completed'] ? 'completed' : 'pending' ?>">
                            <div class="tracker-step-node">
                                <?php if ($stage['completed']): ?>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                <?php else: ?>
                                    <span><?= $stepNum ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="tracker-step-title"><?= e($stage['title']) ?></div>
                            <div class="tracker-step-status"><?= $stage['completed'] ? 'Completed' : 'Pending' ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- ================================================================
                 5. MY CAREER PROFILE SUMMARY SECTION (SECTION 8)
                 ================================================================ -->
            <section class="dashboard-section" id="myCareerProfileSection" aria-label="My Career Profile Summary">
                <div class="section-head-bar">
                    <div>
                        <h2 class="section-main-heading">My Career Profile</h2>
                        <span class="section-sub-heading">Summary of your Module 2 verified student profile</span>
                    </div>
                    <a href="profile.php" class="overview-card-link" style="font-size: 13.5px;">
                        <span>View Full Profile</span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">
                    <!-- Goal & Domain -->
                    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 16px;">
                        <span style="font-size: 11px; font-weight: 700; color: #1769E8; text-transform: uppercase;">Target Goal & Domain</span>
                        <div style="font-size: 15px; font-weight: 800; color: #163A70; margin-top: 4px;">
                            <?= e($activeGoal['career_title'] ?? 'Goal Not Selected') ?>
                        </div>
                        <div style="font-size: 12.5px; color: #667085; margin-top: 2px;">
                            Domain: <?= e($activeGoal['field_name'] ?? 'General') ?>
                        </div>
                    </div>

                    <!-- Education -->
                    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 16px;">
                        <span style="font-size: 11px; font-weight: 700; color: #0F9D8A; text-transform: uppercase;">Education</span>
                        <div style="font-size: 15px; font-weight: 800; color: #163A70; margin-top: 4px;">
                            <?= e(ucfirst($student['education_level'] ?? 'Undergraduate')) ?> • <?= e($student['field_of_study'] ?? 'Engineering') ?>
                        </div>
                        <div style="font-size: 12.5px; color: #667085; margin-top: 2px;">
                            <?= e($student['institution'] ?? 'Campus') ?> (Class of <?= e((string)($student['graduation_year'] ?? '2026')) ?>)
                        </div>
                    </div>

                    <!-- Interests -->
                    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 16px;">
                        <span style="font-size: 11px; font-weight: 700; color: #8A46EA; text-transform: uppercase;">Interest Fields</span>
                        <div style="margin-top: 6px; display: flex; flex-wrap: wrap; gap: 6px;">
                            <?php if (!empty($interestFieldNames)): ?>
                                <?php foreach ($interestFieldNames as $inf): ?>
                                    <span class="badge-pill neutral"><?= e($inf) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span style="font-size: 12.5px; color: #94A3B8;">No interests specified yet.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Activities Preview -->
                    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 16px;">
                        <span style="font-size: 11px; font-weight: 700; color: #D97706; text-transform: uppercase;">Extracurriculars</span>
                        <div style="font-size: 13.5px; font-weight: 700; color: #163A70; margin-top: 4px;">
                            <?= count($existingActivities) ?> Self-Reported, <?= count($enrollmentActivities) ?> Enrolled
                        </div>
                        <div style="font-size: 12px; color: #667085; margin-top: 2px;">
                            <?= count($allStudentActivities) > 0 ? e($allStudentActivities[0]['title']) : 'No activities recorded yet' ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ================================================================
                 6. SPLIT GRID: MY SKILLS (SEC 9) & ASSESSMENT OVERVIEW (SEC 10)
                 ================================================================ -->
            <div class="dashboard-split-grid">

                <!-- 9. MY SKILLS OVERVIEW (SECTION 9) -->
                <section class="dashboard-section" style="margin-top: 0;" aria-label="Skills Overview">
                    <div class="section-head-bar">
                        <div>
                            <h2 class="section-main-heading">My Skills Overview</h2>
                            <span class="section-sub-heading">Current competencies tracked in database</span>
                        </div>
                        <a href="my_skills.php" class="overview-card-link">View All Skills &rarr;</a>
                    </div>

                    <?php if (empty($studentSkills)): ?>
                        <div class="module-empty-state">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="1.8"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                            <div class="module-empty-title">You haven't added any skills yet.</div>
                            <div class="module-empty-desc">Add your technical and interpersonal competencies to benchmark your career readiness.</div>
                            <a href="onboarding.php?edit=1#step-skills" class="btn-view-details" style="display:inline-flex;">Add Skills to Profile</a>
                        </div>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <?php foreach (array_slice($studentSkills, 0, 5) as $sk): ?>
                                <?php 
                                    $lvl = (int)$sk['level'];
                                    $pctMap = [1 => 30, 2 => 50, 3 => 70, 4 => 85, 5 => 95];
                                    $pct = $pctMap[$lvl] ?? 60;
                                ?>
                                <div style="border-bottom: 1px solid #F1F5F9; padding-bottom: 10px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                        <span style="font-weight: 700; font-size: 13.5px; color: #163A70;"><?= e($sk['skill_name']) ?></span>
                                        <div style="display: flex; gap: 6px; align-items: center;">
                                            <span class="badge-pill neutral" style="font-size: 10.5px;"><?= e($sk['category_name'] ?? 'General') ?></span>
                                            <span class="badge-pill <?= $sk['source'] === 'assessed' ? 'success' : 'info' ?>" style="font-size: 10.5px;">
                                                <?= $sk['source'] === 'assessed' ? 'Assessed' : 'Self-Reported' ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; font-size: 11.5px; color: #667085; margin-bottom: 4px;">
                                        <span>Proficiency: <?= e($sk['level_label'] ?? 'Level ' . $lvl) ?></span>
                                        <span>Level <?= $lvl ?>/5</span>
                                    </div>
                                    <div class="progress-track">
                                        <div class="progress-bar <?= $sk['source'] === 'assessed' ? 'teal' : 'blue' ?>" style="width: <?= $pct ?>%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- 10. ASSESSMENT OVERVIEW (SECTION 10) -->
                <section class="dashboard-section" style="margin-top: 0;" aria-label="Assessment Overview">
                    <div class="section-head-bar">
                        <div>
                            <h2 class="section-main-heading">Skill Assessment</h2>
                            <span class="section-sub-heading">Standardized readiness benchmarking</span>
                        </div>
                        <a href="skills_assessment.php" class="overview-card-link">Start Assessment &rarr;</a>
                    </div>

                    <?php if ($assessmentsCompletedCount === 0): ?>
                        <div class="module-empty-state">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="1.8"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                            <div class="module-empty-title">Assessment Pending</div>
                            <div class="module-empty-desc">You haven't completed your skill assessment yet. Start your assessment to understand your current abilities.</div>
                            <a href="skills_assessment.php" class="btn-view-details" style="display:inline-flex;">Take First Assessment</a>
                        </div>
                    <?php else: ?>
                        <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 18px; margin-bottom: 14px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <span style="font-size: 13px; font-weight: 700; color: #163A70;">Latest Assessment Result</span>
                                <span class="badge-pill success">Score: <?= (int)$latestAssessmentScore ?>%</span>
                            </div>
                            <div style="font-size: 12.5px; color: #667085; line-height: 1.5;">
                                Completed on: <strong><?= date('M j, Y • g:i A', strtotime($lastAssessmentDate)) ?></strong><br>
                                Assessments Completed: <strong><?= $assessmentsCompletedCount ?> Total</strong>
                            </div>
                        </div>

                        <?php if (count($assessmentAttempts) > 1): ?>
                            <div style="font-size: 12.5px; font-weight: 700; color: #163A70; margin-bottom: 8px;">Assessment History:</div>
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                <?php foreach (array_slice($assessmentAttempts, 1, 3) as $att): ?>
                                    <div style="display: flex; justify-content: space-between; font-size: 12px; padding: 8px 12px; background: #FFF; border: 1px solid #E4EAF2; border-radius: 6px;">
                                        <span>Attempt #<?= (int)$att['id'] ?> (<?= e($att['career_title'] ?? 'General') ?>)</span>
                                        <strong><?= (int)$att['score_pct'] ?>%</strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

            </div>

            <!-- ================================================================
                 7. SPLIT GRID: SKILL-GAP (SEC 11) & RECOMMENDATIONS (SEC 12)
                 ================================================================ -->
            <div class="dashboard-split-grid">

                <!-- 11. SKILL-GAP OVERVIEW (SECTION 11) -->
                <section class="dashboard-section" style="margin-top: 0;" aria-label="Skill-Gap Overview">
                    <div class="section-head-bar">
                        <div>
                            <h2 class="section-main-heading">Skill-Gap Overview</h2>
                            <span class="section-sub-heading">Benchmarked against target career pathway</span>
                        </div>
                        <a href="skill_gap.php" class="overview-card-link">View Skill-Gap Analysis &rarr;</a>
                    </div>

                    <?php if (empty($skillGaps)): ?>
                        <div class="module-empty-state">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="1.8"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg>
                            <div class="module-empty-title">Skill-Gap Analysis Pending</div>
                            <div class="module-empty-desc">Skill-gap analysis will be generated automatically after you complete your skill assessment and career benchmarking.</div>
                            <a href="skills_assessment.php" class="btn-view-details" style="display:inline-flex;">Benchmark Your Skills</a>
                        </div>
                    <?php else: ?>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div style="background: #EDFDF5; border: 1px solid #A7F3D0; border-radius: 8px; padding: 14px;">
                                <div style="font-size: 11px; font-weight: 700; color: #059669; text-transform: uppercase;">Strong Competencies</div>
                                <div style="font-size: 18px; font-weight: 800; color: #065F46; margin: 4px 0;"><?= count($strongSkills) ?> Skills</div>
                                <div style="font-size: 11.5px; color: #047857;">
                                    <?= count($strongSkills) > 0 ? e(implode(', ', array_slice(array_column($strongSkills, 'skill_name'), 0, 2))) : 'None benchmarked' ?>
                                </div>
                            </div>
                            <div style="background: #FFF7EB; border: 1px solid #FDE68A; border-radius: 8px; padding: 14px;">
                                <div style="font-size: 11px; font-weight: 700; color: #D97706; text-transform: uppercase;">Needs Improvement</div>
                                <div style="font-size: 18px; font-weight: 800; color: #92400E; margin: 4px 0;"><?= count($improvingSkills) ?> Skills</div>
                                <div style="font-size: 11.5px; color: #B45309;">
                                    <?= count($improvingSkills) > 0 ? e(implode(', ', array_slice(array_column($improvingSkills, 'skill_name'), 0, 2))) : 'All verified' ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- 12. RECOMMENDATIONS PREVIEW (SECTION 12) -->
                <section class="dashboard-section" style="margin-top: 0;" aria-label="Recommendations Preview">
                    <div class="section-head-bar">
                        <div>
                            <h2 class="section-main-heading">Recommendations Preview</h2>
                            <span class="section-sub-heading">Curated extracurricular experiences</span>
                        </div>
                        <a href="recommendations.php" class="overview-card-link">View All Recommendations &rarr;</a>
                    </div>

                    <?php if (empty($recommendations)): ?>
                        <div class="module-empty-state">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="1.8"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path></svg>
                            <div class="module-empty-title">No Recommendations Generated Yet</div>
                            <div class="module-empty-desc">Your personalized recommendations will appear here after skill-gap analysis.</div>
                            <a href="activities.php" class="btn-view-details" style="display:inline-flex;">Explore Catalog</a>
                        </div>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <?php foreach ($recommendations as $rec): ?>
                                <div style="border: 1px solid #E4EAF2; border-radius: 8px; padding: 12px; background: #FAFCFF;">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                        <span style="font-weight: 700; font-size: 13.5px; color: #163A70;"><?= e($rec['activity_title']) ?></span>
                                        <span class="badge-pill info" style="font-size: 10px;"><?= (int)$rec['total_score'] ?>% Match</span>
                                    </div>
                                    <div style="font-size: 12px; color: #667085; margin: 4px 0;"><?= e($rec['reason']) ?></div>
                                    <div style="display: flex; justify-content: space-between; font-size: 11px; color: #1769E8;">
                                        <span>Skill Target: <?= e($rec['related_skill_name'] ?? 'Core Competency') ?></span>
                                        <a href="activities.php?id=<?= (int)$rec['activity_id'] ?>" style="font-weight: 700; text-decoration: none;">Details &rarr;</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

            </div>

            <!-- ================================================================
                 8. SPLIT GRID: RECENT ACTIVITIES (SEC 13) & ACHIEVEMENTS (SEC 14)
                 ================================================================ -->
            <div class="dashboard-split-grid">

                <!-- 13. RECENT ACTIVITIES (SECTION 13) -->
                <section class="dashboard-section" style="margin-top: 0;" aria-label="Recent Activities">
                    <div class="section-head-bar">
                        <div>
                            <h2 class="section-main-heading">Recent Activities</h2>
                            <span class="section-sub-heading">Extracurricular participation & enrollments</span>
                        </div>
                        <a href="activities.php" class="overview-card-link">Explore Activities &rarr;</a>
                    </div>

                    <?php if (empty($allStudentActivities)): ?>
                        <div class="module-empty-state">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="1.8"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg>
                            <div class="module-empty-title">No Recent Activities</div>
                            <div class="module-empty-desc">Explore extracurricular activities to develop your professional skills.</div>
                            <a href="activities.php" class="btn-view-details" style="display:inline-flex;">Browse Activities</a>
                        </div>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <?php foreach (array_slice($allStudentActivities, 0, 4) as $act): ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: #FAFCFF; border: 1px solid #E4EAF2; border-radius: 8px;">
                                    <div>
                                        <div style="font-weight: 700; font-size: 13.5px; color: #163A70;"><?= e($act['title']) ?></div>
                                        <div style="font-size: 11.5px; color: #667085; margin-top: 2px;">
                                            <?= e($act['activity_type']) ?> • <?= !empty($act['record_date']) ? date('M j, Y', strtotime($act['record_date'])) : 'In Progress' ?>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="badge-pill <?= ($act['completion_status'] === 'Completed' || ($act['participation_status'] ?? '') === 'completed') ? 'success' : 'info' ?>">
                                            <?= e($act['completion_status']) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- 14. ACHIEVEMENTS PREVIEW (SECTION 14) -->
                <section class="dashboard-section" style="margin-top: 0;" aria-label="Achievements Preview">
                    <div class="section-head-bar">
                        <div>
                            <h2 class="section-main-heading">Achievements Preview</h2>
                            <span class="section-sub-heading">Certificates, projects, and hackathons</span>
                        </div>
                        <a href="portfolio.php" class="overview-card-link">View Portfolio &rarr;</a>
                    </div>

                    <?php if (empty($achievements) && empty($portfolioItems)): ?>
                        <div class="module-empty-state">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="1.8"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                            <div class="module-empty-title">No Achievements Recorded Yet</div>
                            <div class="module-empty-desc">Your verified achievements and certificates will appear here as you complete activities.</div>
                            <a href="portfolio.php" class="btn-view-details" style="display:inline-flex;">View Portfolio</a>
                        </div>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <?php foreach (array_slice($achievements, 0, 3) as $ach): ?>
                                <div style="border: 1px solid #E4EAF2; border-radius: 8px; padding: 12px; background: #FAFCFF;">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                        <span style="font-weight: 700; font-size: 13.5px; color: #163A70;"><?= e($ach['title']) ?></span>
                                        <span class="badge-pill success" style="font-size: 10px;">Verified</span>
                                    </div>
                                    <div style="font-size: 12px; color: #667085; margin: 4px 0;"><?= e($ach['description']) ?></div>
                                    <div style="font-size: 11px; color: #94A3B8;">Role: <?= e($ach['role']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

            </div>

        </main>
    </div>

    <!-- UI Interactivity Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile Menu Toggle
            const mobileBtn = document.getElementById('mobileMenuBtn');
            const sidebar = document.getElementById('dashboardSidebar');
            const overlay = document.getElementById('sidebarOverlay');

            if (mobileBtn && sidebar && overlay) {
                mobileBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('open');
                    overlay.classList.toggle('show');
                });

                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('show');
                });
            }

            // Notification Dropdown Toggle
            const notifToggle = document.getElementById('notifDropdownToggle');
            const notifMenu = document.getElementById('notificationDropdownMenu');

            if (notifToggle && notifMenu) {
                notifToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    notifMenu.classList.toggle('show');
                    if (userMenu) userMenu.classList.remove('show');
                });
            }

            // User Profile Menu Dropdown Toggle
            const userToggle = document.getElementById('userMenuToggle');
            const userMenu = document.getElementById('userDropdownMenu');

            if (userToggle && userMenu) {
                userToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const willShow = !userMenu.classList.contains('show');
                    if (willShow) {
                        userMenu.classList.add('show');
                        userMenu.style.display = 'block';
                    } else {
                        userMenu.classList.remove('show');
                        userMenu.style.display = 'none';
                    }
                    if (notifMenu) {
                        notifMenu.classList.remove('show');
                        notifMenu.style.display = 'none';
                    }
                });
            }

            // Close dropdowns on click outside
            document.addEventListener('click', function() {
                if (notifMenu) {
                    notifMenu.classList.remove('show');
                    notifMenu.style.display = 'none';
                }
                if (userMenu) {
                    userMenu.classList.remove('show');
                    userMenu.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>
