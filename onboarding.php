<?php
/**
 * CareerAct — Student Guided Onboarding Wizard
 * Multi-step guided setup: Profile -> Career Goal -> Extracurricular Activities -> Assessment
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/assessment_engine.php';

$userId = (int)$_SESSION['user_id'];
$db = getDbConnection();

// Fetch student profile
$stmt = $db->prepare("SELECT * FROM student_profiles WHERE user_id = :user_id LIMIT 1");
$stmt->execute([':user_id' => $userId]);
$student = $stmt->fetch();

if (!$student) {
    // Safety fallback
    header('Location: login.php');
    exit;
}

$studentId = (int)$student['student_id'];

// Determine current step
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
if ($step < 1 || $step > 3) {
    $step = 1;
}

$errors = [];
$success = '';

// Step 1: Save Profile Completion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_step_1'])) {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $fullName     = trim((string)($_POST['full_name'] ?? ''));
        $phone        = trim((string)($_POST['phone'] ?? ''));
        $collegeName  = trim((string)($_POST['college_name'] ?? ''));
        $degreeName   = trim((string)($_POST['degree_name'] ?? ''));
        $branchName   = trim((string)($_POST['branch_name'] ?? ''));
        $academicYear = (int)($_POST['academic_year'] ?? 1);
        $biography    = trim((string)($_POST['biography'] ?? ''));
        $linkedinUrl  = trim((string)($_POST['linkedin_url'] ?? ''));

        if (empty($fullName) || empty($collegeName) || empty($degreeName) || empty($branchName)) {
            $errors['general'] = 'Please fill in all required academic fields.';
        } else {
            $stmtUp = $db->prepare("
                UPDATE student_profiles
                SET full_name = :full_name,
                    phone = :phone,
                    college_name = :college_name,
                    degree_name = :degree_name,
                    branch_name = :branch_name,
                    course_name = :course_name,
                    academic_year = :academic_year,
                    biography = :biography,
                    linkedin_url = :linkedin_url,
                    updated_at = NOW()
                WHERE student_id = :student_id
            ");
            $stmtUp->execute([
                ':full_name'     => $fullName,
                ':phone'         => $phone,
                ':college_name'  => $collegeName,
                ':degree_name'   => $degreeName,
                ':branch_name'   => $branchName,
                ':course_name'   => $branchName,
                ':academic_year' => $academicYear,
                ':biography'     => $biography,
                ':linkedin_url'  => $linkedinUrl,
                ':student_id'    => $studentId
            ]);

            // Save selected initial skills if provided
            if (!empty($_POST['initial_skills']) && is_array($_POST['initial_skills'])) {
                $insSkill = $db->prepare("
                    INSERT INTO student_skills (student_id, skill_id, proficiency_level_id, source_code, updated_at)
                    VALUES (:student_id, :skill_id, 2, 'self_declared', NOW())
                    ON DUPLICATE KEY UPDATE proficiency_level_id = VALUES(proficiency_level_id)
                ");
                foreach ($_POST['initial_skills'] as $sId) {
                    $insSkill->execute([':student_id' => $studentId, ':skill_id' => (int)$sId]);
                }
            }

            header('Location: onboarding.php?step=2');
            exit;
        }
    }
}

// Step 2: Save Career Goal Assessment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_step_2'])) {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $careerId = (int)($_POST['career_id'] ?? 0);
        $expLevel = trim((string)($_POST['experience_level'] ?? 'Beginner'));
        $timeline = trim((string)($_POST['expected_timeline'] ?? '6-12 Months'));
        $learningPref = trim((string)($_POST['learning_methods'] ?? 'Hands-on Hackathons'));
        $shortTermGoal = trim((string)($_POST['short_term_goal'] ?? ''));

        if ($careerId <= 0) {
            $errors['general'] = 'Please select a target career path.';
        } else {
            // Deactivate existing goals
            $db->prepare("UPDATE student_career_goals SET is_active = 0, deselected_at = NOW() WHERE student_id = :student_id")
               ->execute([':student_id' => $studentId]);

            // Insert new active goal
            $db->prepare("
                INSERT INTO student_career_goals (student_id, career_id, goal_rank, is_active, selected_at)
                VALUES (:student_id, :career_id, 1, 1, NOW())
            ")->execute([':student_id' => $studentId, ':career_id' => $careerId]);

            header('Location: onboarding.php?step=3');
            exit;
        }
    }
}

// Step 3: Save Extracurricular Activity Description & Trigger Assessment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_step_3'])) {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $isBeginner = isset($_POST['is_beginner']);

        if (!$isBeginner) {
            $actTitle    = trim((string)($_POST['activity_title'] ?? ''));
            $actType     = (int)($_POST['activity_type_id'] ?? 1);
            $reflection  = trim((string)($_POST['student_reflection'] ?? ''));
            $skillsText  = trim((string)($_POST['skills_gained_text'] ?? ''));
            $outcomes    = trim((string)($_POST['outcomes'] ?? ''));
            $proofUrl    = trim((string)($_POST['proof_url'] ?? ''));
            $roleInTeam  = trim((string)($_POST['role_in_team'] ?? ''));

            if (empty($actTitle) || empty($reflection)) {
                $errors['general'] = 'Please describe your activity title and what you actually contributed.';
            } else {
                // Find or link a generic/custom activity record
                $stmtFindAct = $db->prepare("SELECT activity_id FROM activities WHERE title LIKE :title LIMIT 1");
                $stmtFindAct->execute([':title' => '%' . substr($actTitle, 0, 30) . '%']);
                $foundActId = $stmtFindAct->fetchColumn();

                if (!$foundActId) {
                    // Create entry in activities catalog
                    $stmtNewAct = $db->prepare("
                        INSERT INTO activities (
                            activity_type_id, mode_id, organizer_id, activity_status_id, verification_status_id,
                            title, description, created_by_user_id, created_at
                        ) VALUES (
                            :type_id, 1, (SELECT organizer_id FROM organizers LIMIT 1), 3, 1,
                            :title, :desc, :user_id, NOW()
                        )
                    ");
                    $stmtNewAct->execute([
                        ':type_id' => $actType,
                        ':title'   => $actTitle,
                        ':desc'    => $reflection,
                        ':user_id' => $userId
                    ]);
                    $foundActId = (int)$db->lastInsertId();
                }

                // Insert into student_activity_enrollments
                $stmtInsEnroll = $db->prepare("
                    INSERT INTO student_activity_enrollments (
                        student_id, activity_id, enrollment_status_id, completion_status,
                        completion_verification, enrolled_at, completed_at, proof_url,
                        student_reflection, skills_gained_text
                    ) VALUES (
                        :student_id, :act_id, 5, 'completed', :verification, NOW(), NOW(), :proof, :reflection, :skills
                    )
                ");
                $verificationState = !empty($proofUrl) ? 'pending' : 'not_required';
                $stmtInsEnroll->execute([
                    ':student_id'    => $studentId,
                    ':act_id'        => $foundActId,
                    ':verification'  => $verificationState,
                    ':proof'         => $proofUrl,
                    ':reflection'    => $reflection . (!empty($roleInTeam) ? " | Role: {$roleInTeam}" : '') . (!empty($outcomes) ? " | Outcomes: {$outcomes}" : ''),
                    ':skills'        => $skillsText
                ]);

                // Also record project if repository or demo URL provided
                if (!empty($proofUrl)) {
                    $stmtProj = $db->prepare("
                        INSERT INTO student_projects (student_id, project_title, description, project_url, start_date, created_at)
                        VALUES (:student_id, :title, :desc, :url, CURRENT_DATE, NOW())
                    ");
                    $stmtProj->execute([
                        ':student_id' => $studentId,
                        ':title'      => $actTitle,
                        ':desc'       => $reflection,
                        ':url'        => $proofUrl
                    ]);
                }
            }
        }

        if (empty($errors)) {
            // Find student's active career goal
            $stmtActiveGoal = $db->prepare("SELECT career_id FROM student_career_goals WHERE student_id = :student_id AND is_active = 1 LIMIT 1");
            $stmtActiveGoal->execute([':student_id' => $studentId]);
            $activeCareerId = (int)$stmtActiveGoal->fetchColumn();

            if ($activeCareerId <= 0) {
                $activeCareerId = 1; // Default to Software Engineer if unassigned
            }

            // Run genuine rule-based assessment engine
            $results = runCareerAssessment($db, $studentId, $activeCareerId);

            // Redirect directly to the Assessment Results Page
            header('Location: assessment_results.php?record_id=' . $results['record_id']);
            exit;
        }
    }
}

// Fetch helper data
$careers = $db->query("SELECT career_id, career_title, career_code, description FROM career_paths WHERE is_active = 1 ORDER BY career_id ASC")->fetchAll();
$popularSkills = $db->query("SELECT skill_id, skill_name FROM skills WHERE skill_id IN (1, 2, 3, 4, 30, 31, 72, 85) ORDER BY skill_name ASC")->fetchAll();
$activityTypes = $db->query("SELECT activity_type_id, type_name FROM activity_types ORDER BY is_technical DESC, type_name ASC LIMIT 10")->fetchAll();

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Onboarding & Career Assessment — CareerAct</title>
    <link rel="stylesheet" href="assets/css/auth.css">
    <style>
        .wizard-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
            background: var(--page-bg);
        }
        .wizard-container {
            width: 100%;
            max-width: 860px;
            background: var(--white);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: var(--card-shadow);
            overflow: hidden;
        }
        .wizard-header {
            background: var(--gradient-brand);
            padding: 32px 36px;
            color: var(--white);
        }
        .step-progress-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 20px;
            position: relative;
        }
        .step-bubble {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            border: 1.5px solid rgba(255, 255, 255, 0.4);
            z-index: 2;
        }
        .step-bubble.active {
            background: var(--white);
            color: var(--primary-blue);
            box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.3);
        }
        .step-bubble.completed {
            background: #10B981;
            border-color: #10B981;
            color: var(--white);
        }
        .wizard-content {
            padding: 36px;
        }
        .career-card-radio {
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 16px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .career-card-radio:hover {
            border-color: var(--primary-blue);
            background: var(--light-blue);
        }
        .career-card-radio input:checked + .career-card-content h3 {
            color: var(--primary-blue);
        }
        .skills-chip-select {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }
        .skill-chip-checkbox {
            cursor: pointer;
        }
        .skill-chip-checkbox input {
            display: none;
        }
        .skill-chip-label {
            display: inline-block;
            padding: 6px 14px;
            background: #F1F5F9;
            border: 1px solid var(--border-color);
            border-radius: 20px;
            font-size: 12.5px;
            font-weight: 500;
            color: var(--main-text);
            transition: var(--transition);
        }
        .skill-chip-checkbox input:checked + .skill-chip-label {
            background: var(--primary-blue);
            color: var(--white);
            border-color: var(--primary-blue);
        }
    </style>
</head>
<body>
<div class="wizard-page">
    <div class="wizard-container">
        <!-- Header & Steps Indicator -->
        <div class="wizard-header">
            <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #BAE6FD;">Guided Onboarding</span>
            <h1 style="font-size: 24px; font-weight: 800; margin: 4px 0 6px;">CareerAct Student Career Assessment</h1>
            <p style="font-size: 13.5px; color: rgba(255, 255, 255, 0.9); margin: 0;">
                Complete your profile and experience description to generate an evidence-backed assessment and custom career roadmap.
            </p>

            <div class="step-progress-bar">
                <div class="step-bubble <?= $step >= 1 ? ($step > 1 ? 'completed' : 'active') : '' ?>">1</div>
                <div style="flex: 1; height: 2px; background: rgba(255, 255, 255, 0.3); margin: 0 8px;"></div>
                <div class="step-bubble <?= $step >= 2 ? ($step > 2 ? 'completed' : 'active') : '' ?>">2</div>
                <div style="flex: 1; height: 2px; background: rgba(255, 255, 255, 0.3); margin: 0 8px;"></div>
                <div class="step-bubble <?= $step === 3 ? 'active' : '' ?>">3</div>
            </div>
        </div>

        <div class="wizard-content">
            <?php if (!empty($errors['general'])): ?>
                <div class="alert alert-error" style="margin-bottom: 20px;">
                    <span><?= e($errors['general']) ?></span>
                </div>
            <?php endif; ?>

            <!-- ============================================================
                 STEP 1: Student Profile Completion
                 ============================================================ -->
            <?php if ($step === 1): ?>
                <div style="margin-bottom: 24px;">
                    <h2 style="font-size: 20px; font-weight: 800; color: var(--dark-navy);">Step 1: Academic & Contact Profile</h2>
                    <p style="font-size: 13.5px; color: var(--secondary-text);">Provide your current institution and contact details to anchor your portfolio records.</p>
                </div>

                <form method="POST" action="onboarding.php?step=1" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action_step_1" value="1">

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Full Name <span class="req">*</span></label>
                            <input type="text" name="full_name" class="form-input" value="<?= e($student['full_name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-input" placeholder="+91 98765 43210" value="<?= e($student['phone'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">College / University <span class="req">*</span></label>
                            <input type="text" name="college_name" class="form-input" value="<?= e($student['college_name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Degree & Program <span class="req">*</span></label>
                            <input type="text" name="degree_name" class="form-input" placeholder="e.g. B.Tech / B.E." value="<?= e($student['degree_name']) ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Course / Branch <span class="req">*</span></label>
                            <input type="text" name="branch_name" class="form-input" placeholder="e.g. Computer Science & Engineering" value="<?= e($student['branch_name'] ?? $student['course_name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Academic Year <span class="req">*</span></label>
                            <select name="academic_year" class="form-select" required>
                                <option value="1" <?= $student['academic_year'] == 1 ? 'selected' : '' ?>>1st Year (Freshman)</option>
                                <option value="2" <?= $student['academic_year'] == 2 ? 'selected' : '' ?>>2nd Year (Sophomore)</option>
                                <option value="3" <?= $student['academic_year'] == 3 ? 'selected' : '' ?>>3rd Year (Junior)</option>
                                <option value="4" <?= $student['academic_year'] == 4 ? 'selected' : '' ?>>4th Year (Senior)</option>
                                <option value="5" <?= $student['academic_year'] == 5 ? 'selected' : '' ?>>Postgraduate / Year 5+</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Select Your Known Competencies / Core Skills</label>
                        <div class="skills-chip-select">
                            <?php foreach ($popularSkills as $sk): ?>
                                <label class="skill-chip-checkbox">
                                    <input type="checkbox" name="initial_skills[]" value="<?= (int)$sk['skill_id'] ?>">
                                    <span class="skill-chip-label"><?= e($sk['skill_name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Brief Biography / Technical Interests</label>
                        <textarea name="biography" class="form-input" rows="3" placeholder="Tell us about what technologies, domains, or extracurricular areas excite you..."><?= e($student['biography'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="btn-primary" style="margin-top: 20px;">
                        <span>Continue to Career Goal →</span>
                    </button>
                </form>

            <!-- ============================================================
                 STEP 2: Career Goal Assessment
                 ============================================================ -->
            <?php elseif ($step === 2): ?>
                <div style="margin-bottom: 24px;">
                    <h2 style="font-size: 20px; font-weight: 800; color: var(--dark-navy);">Step 2: Define Your Target Career Goal</h2>
                    <p style="font-size: 13.5px; color: var(--secondary-text);">Select the profession or industry role you are actively working towards.</p>
                </div>

                <form method="POST" action="onboarding.php?step=2" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action_step_2" value="1">

                    <div class="form-group">
                        <label class="form-label">Target Career Pathway <span class="req">*</span></label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 6px;">
                            <?php foreach ($careers as $c): ?>
                                <label class="career-card-radio">
                                    <input type="radio" name="career_id" value="<?= (int)$c['career_id'] ?>" <?= $c['career_id'] == 1 ? 'checked' : '' ?> required>
                                    <div class="career-card-content">
                                        <h3 style="font-size: 15px; font-weight: 700; margin: 4px 0 2px;"><?= e($c['career_title']) ?></h3>
                                        <p style="font-size: 12px; color: var(--secondary-text); margin: 0; line-height: 1.4;"><?= e($c['description']) ?></p>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-row" style="margin-top: 14px;">
                        <div class="form-group">
                            <label class="form-label">Current Experience Level</label>
                            <select name="experience_level" class="form-select">
                                <option value="Beginner">Beginner (Starting from fundamentals)</option>
                                <option value="Intermediate" selected>Intermediate (Built simple projects)</option>
                                <option value="Advanced">Advanced (Participated in hackathons/internships)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Target Placement / Achievement Timeline</label>
                            <select name="expected_timeline" class="form-select">
                                <option value="3-6 Months">3 to 6 Months (Immediate semester target)</option>
                                <option value="6-12 Months" selected>6 to 12 Months (Upcoming placement season)</option>
                                <option value="1-2 Years">1 to 2 Years (Long-term university roadmap)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Preferred Learning & Engagement Methods</label>
                        <select name="learning_methods" class="form-select">
                            <option value="Hands-on Hackathons">Hands-on Hackathons & Build Sprints</option>
                            <option value="Competitive Programming">Algorithmic Competitions & Contests</option>
                            <option value="Workshops & Courses">Structured Workshops & Guided Seminars</option>
                            <option value="Open Source & Projects">Independent Open Source Engineering</option>
                        </select>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 20px;">
                        <a href="onboarding.php?step=1" class="btn-primary" style="background: #F1F5F9; color: var(--main-text); text-decoration: none; width: auto; padding: 0 24px;">← Back</a>
                        <button type="submit" class="btn-primary" style="flex: 1;">
                            <span>Continue to Extracurricular Experience →</span>
                        </button>
                    </div>
                </form>

            <!-- ============================================================
                 STEP 3: Existing Extracurricular Activity Description
                 ============================================================ -->
            <?php elseif ($step === 3): ?>
                <div style="margin-bottom: 24px;">
                    <h2 style="font-size: 20px; font-weight: 800; color: var(--dark-navy);">Step 3: Extracurricular & Practical Experience</h2>
                    <p style="font-size: 13.5px; color: var(--secondary-text);">Describe the extracurricular activities, hackathons, projects, clubs, or workshops you have participated in.</p>
                </div>

                <form method="POST" action="onboarding.php?step=3" class="auth-form" id="step3Form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action_step_3" value="1">

                    <!-- Beginner toggle -->
                    <div style="background: var(--light-blue); border: 1px solid #BFDBFE; border-radius: var(--radius-sm); padding: 14px 16px; margin-bottom: 16px;">
                        <label class="checkbox-container">
                            <input type="checkbox" name="is_beginner" value="1" id="beginnerToggle">
                            <span class="checkbox-label" style="font-weight: 600; color: var(--dark-navy);">
                                I am just getting started and do not have previous extracurricular experiences yet.
                            </span>
                        </label>
                        <p style="font-size: 12px; color: var(--secondary-text); margin: 4px 0 0 26px;">
                            We will formulate a foundational, beginner-friendly roadmap without penalizing your assessment.
                        </p>
                    </div>

                    <div id="experienceFields">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Activity Name / Event Title <span class="req">*</span></label>
                                <input type="text" name="activity_title" class="form-input" placeholder="e.g. Smart India Hackathon, Web Dev Workshop, Robotics Club" required id="actTitleInput">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Activity Category / Type <span class="req">*</span></label>
                                <select name="activity_type_id" class="form-select">
                                    <?php foreach ($activityTypes as $at): ?>
                                        <option value="<?= (int)$at['activity_type_id'] ?>"><?= e($at['type_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">What Did You Actually Do? (Your Specific Contribution) <span class="req">*</span></label>
                            <textarea name="student_reflection" class="form-input" rows="4" placeholder="Describe the problem you solved, what modules or code you implemented, and your individual technical contribution..." required id="actReflectionInput"></textarea>
                            <span style="font-size: 12px; color: var(--secondary-text);">Detailed descriptions receive higher confidence ratings in our assessment engine.</span>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Your Role / Team Responsibility</label>
                                <input type="text" name="role_in_team" class="form-input" placeholder="e.g. Backend Lead, Solo Builder, Participant, Event Coordinator">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Skills & Technologies Used</label>
                                <input type="text" name="skills_gained_text" class="form-input" placeholder="e.g. Python, SQL, REST APIs, Git, Team Leadership">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Key Outcomes & Achievements</label>
                                <input type="text" name="outcomes" class="form-input" placeholder="e.g. Won 2nd Place, Deployed MVP on Vercel, Received Certificate">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Evidence / Link (Repository, Live Demo, or Certificate)</label>
                                <input type="url" name="proof_url" class="form-input" placeholder="https://github.com/your-username/project or certificate link">
                                <span style="font-size: 11.5px; color: #0F9D8A; font-weight: 500;">✓ Adding verifiable links awards evidence bonuses.</span>
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 20px;">
                        <a href="onboarding.php?step=2" class="btn-primary" style="background: #F1F5F9; color: var(--main-text); text-decoration: none; width: auto; padding: 0 24px;">← Back</a>
                        <button type="submit" class="btn-primary" style="flex: 1; background: #0F9D8A;">
                            <span>Run Assessment & Generate Roadmap 🚀</span>
                        </button>
                    </div>
                </form>

                <script>
                    const toggle = document.getElementById('beginnerToggle');
                    const expFields = document.getElementById('experienceFields');
                    const actTitle = document.getElementById('actTitleInput');
                    const actReflection = document.getElementById('actReflectionInput');

                    toggle.addEventListener('change', function() {
                        if (this.checked) {
                            expFields.style.opacity = '0.4';
                            expFields.style.pointerEvents = 'none';
                            actTitle.removeAttribute('required');
                            actReflection.removeAttribute('required');
                        } else {
                            expFields.style.opacity = '1';
                            expFields.style.pointerEvents = 'auto';
                            actTitle.setAttribute('required', 'required');
                            actReflection.setAttribute('required', 'required');
                        }
                    });
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
