<?php
/**
 * CareerAct — Module 2: Complete Student Profile & Guided Onboarding Wizard
 * Multi-Step Form: Personal Info -> Education -> Career Goals -> Interests -> Skills -> Activities -> Review & Submit
 * Database: carrearact2
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';

initSession();
requireAuth('login.php?msg=session_required');

if (isAdmin()) {
    header('Location: admin/dashboard.php');
    exit;
}

$userId = getCurrentUserId();
$db = getDbConnection();

// Verify user exists and is active
$userStmt = $db->prepare('SELECT id, name, email, role, account_status FROM users WHERE id = :id LIMIT 1');
$userStmt->execute([':id' => $userId]);
$currentUser = $userStmt->fetch();

if (!$currentUser || $currentUser['account_status'] !== 'active') {
    header('Location: logout.php');
    exit;
}

// 1. Fetch Existing Profile Data (for pre-population in Onboarding / Edit Mode)
$profStmt = $db->prepare('SELECT * FROM student_profiles WHERE user_id = :uid LIMIT 1');
$profStmt->execute([':uid' => $userId]);
$existingProfile = $profStmt->fetch() ?: [];

$isCompleted = !empty($existingProfile['profile_completed']);
$isEditMode  = $isCompleted || (isset($_GET['mode']) && $_GET['mode'] === 'edit');

// Fetch existing education
$eduStmt = $db->prepare('SELECT * FROM student_education WHERE user_id = :uid ORDER BY id DESC LIMIT 1');
$eduStmt->execute([':uid' => $userId]);
$existingEdu = $eduStmt->fetch() ?: [];

// Fetch existing career goals
$goalStmt = $db->prepare('SELECT career_id, is_primary FROM student_career_goals WHERE user_id = :uid');
$goalStmt->execute([':uid' => $userId]);
$existingGoals = $goalStmt->fetchAll();
$primaryCareerId = 0;
$secondaryCareerIds = [];
foreach ($existingGoals as $g) {
    if (!empty($g['is_primary'])) {
        $primaryCareerId = (int)$g['career_id'];
    } else {
        $secondaryCareerIds[] = (int)$g['career_id'];
    }
}

// Fetch existing interests
$intStmt = $db->prepare('SELECT field_id FROM student_interest_fields WHERE user_id = :uid');
$intStmt->execute([':uid' => $userId]);
$existingInterests = $intStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
$existingInterests = array_map('intval', $existingInterests);

// Fetch existing skills
$skillStmt = $db->prepare('SELECT skill_id, level FROM student_skills WHERE user_id = :uid');
$skillStmt->execute([':uid' => $userId]);
$existingSkillsMap = [];
foreach ($skillStmt->fetchAll() as $s) {
    $existingSkillsMap[(int)$s['skill_id']] = (int)$s['level'];
}

// Fetch existing extracurricular activities
$actStmt = $db->prepare('SELECT * FROM student_existing_activities WHERE user_id = :uid ORDER BY id ASC');
$actStmt->execute([':uid' => $userId]);
$existingActivities = $actStmt->fetchAll() ?: [];

// 2. Fetch Catalogue Data for Dropdowns and Options
$countries = $db->query('SELECT id, name, iso2 FROM countries ORDER BY name ASC')->fetchAll();
$cities    = $db->query('SELECT id, country_id, name FROM cities ORDER BY name ASC')->fetchAll();
$careers   = $db->query('SELECT id, subfield_id, title, description FROM careers WHERE is_active = 1 ORDER BY title ASC')->fetchAll();
$fields    = $db->query('SELECT id, code, name, description FROM career_fields WHERE is_active = 1 ORDER BY sort_order ASC, name ASC')->fetchAll();
$skills    = $db->query('SELECT s.id, s.category_id, s.name, s.skill_type, s.description, sc.name AS category_name FROM skills s LEFT JOIN skill_categories sc ON s.category_id = sc.id WHERE s.is_active = 1 ORDER BY s.category_id ASC, s.name ASC')->fetchAll();
$skillLevels = $db->query('SELECT level, label, descriptor FROM skill_levels WHERE level > 0 ORDER BY level ASC')->fetchAll();
$activityCatalog = $db->query('SELECT a.id, a.category_id, a.title, ac.name AS category_name FROM activities a LEFT JOIN activity_categories ac ON a.category_id = ac.id WHERE a.is_active = 1 ORDER BY a.title ASC')->fetchAll();

// Group skills by category
$skillsByCategory = [];
foreach ($skills as $sk) {
    $cat = !empty($sk['category_name']) ? $sk['category_name'] : 'General Skills';
    $skillsByCategory[$cat][] = $sk;
}

$errors = [];
$successMessage = '';

// 3. Process Profile Submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_profile'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors['general'] = 'Your session has expired or the form submission token was invalid. Please try again.';
    } else {
        // Collect & Sanitize Form Data
        // Step 1: Personal Info
        $fullName        = trim((string)($_POST['full_name'] ?? ''));
        $countryId       = !empty($_POST['country_id']) ? (int)$_POST['country_id'] : null;
        $city            = trim((string)($_POST['city'] ?? ''));
        $experienceLevel = trim((string)($_POST['experience_level'] ?? 'beginner'));
        $preferredMode   = trim((string)($_POST['preferred_mode'] ?? 'any'));
        $bio             = trim((string)($_POST['bio'] ?? ''));

        // Step 2: Education
        $educationLevel  = trim((string)($_POST['education_level'] ?? 'undergraduate'));
        $institution     = trim((string)($_POST['institution'] ?? ''));
        $fieldOfStudy    = trim((string)($_POST['field_of_study'] ?? ''));
        $qualification   = trim((string)($_POST['qualification'] ?? ''));
        $startYear       = !empty($_POST['start_year']) ? (int)$_POST['start_year'] : null;
        $graduationYear  = !empty($_POST['graduation_year']) ? (int)$_POST['graduation_year'] : null;

        // Step 3: Career Goals
        $submittedPrimaryCareer = !empty($_POST['primary_career_id']) ? (int)$_POST['primary_career_id'] : 0;
        $submittedSecondaryCareers = isset($_POST['secondary_career_ids']) && is_array($_POST['secondary_career_ids']) 
            ? array_map('intval', $_POST['secondary_career_ids']) 
            : [];
        // Ensure primary career is not in secondary careers
        $submittedSecondaryCareers = array_values(array_filter($submittedSecondaryCareers, fn($cId) => $cId > 0 && $cId !== $submittedPrimaryCareer));
        $resumeSummary = trim((string)($_POST['resume_summary'] ?? ''));

        // Step 4: Interests
        $submittedInterests = isset($_POST['interest_fields']) && is_array($_POST['interest_fields'])
            ? array_map('intval', $_POST['interest_fields'])
            : [];

        // Step 5: Skills
        $submittedSkills = isset($_POST['skills']) && is_array($_POST['skills']) ? $_POST['skills'] : [];
        $validatedSkills = []; // [skill_id => level]
        foreach ($submittedSkills as $sId => $sLvl) {
            $sIdInt = (int)$sId;
            $sLvlInt = max(1, min(5, (int)$sLvl));
            if ($sIdInt > 0) {
                $validatedSkills[$sIdInt] = $sLvlInt;
            }
        }

        // Step 6: Extracurricular Activities
        $activityTitles     = $_POST['activity_title'] ?? [];
        $activityCatalogIds = $_POST['activity_catalog_id'] ?? [];
        $activityRoles      = $_POST['activity_role'] ?? [];
        $activityDescs      = $_POST['activity_desc'] ?? [];
        $activityStarts     = $_POST['activity_start'] ?? [];
        $activityEnds       = $_POST['activity_end'] ?? [];

        // Server-Side Validations
        if (empty($fullName)) {
            $errors['full_name'] = 'Full Name is required.';
        } elseif (mb_strlen($fullName) > 120) {
            $errors['full_name'] = 'Full Name cannot exceed 120 characters.';
        }

        if (empty($institution)) {
            $errors['institution'] = 'College / University name is required.';
        } elseif (mb_strlen($institution) > 190) {
            $errors['institution'] = 'Institution name cannot exceed 190 characters.';
        }

        if (empty($fieldOfStudy)) {
            $errors['field_of_study'] = 'Field of Study / Branch is required.';
        }

        if (empty($graduationYear) || $graduationYear < 1980 || $graduationYear > 2040) {
            $errors['graduation_year'] = 'Please enter a valid expected graduation year (e.g. 2026).';
        }

        if ($submittedPrimaryCareer <= 0) {
            $errors['primary_career_id'] = 'Please select your primary career goal.';
        }

        if (empty($submittedInterests)) {
            $errors['interest_fields'] = 'Please select at least one field of interest.';
        }

        if (empty($validatedSkills)) {
            $errors['skills'] = 'Please select at least one existing skill and indicate your proficiency.';
        }

        // If validations pass, execute atomic database transaction
        if (empty($errors)) {
            try {
                $db->beginTransaction();

                // 1. Update user name if changed
                $stmtUserUp = $db->prepare('UPDATE users SET name = :name, updated_at = NOW() WHERE id = :id');
                $stmtUserUp->execute([':name' => $fullName, ':id' => $userId]);

                // 2. Upsert student_profiles with profile_completed = 1
                $validExp = in_array($experienceLevel, ['beginner', 'intermediate', 'advanced'], true) ? $experienceLevel : 'beginner';
                $validMode = in_array($preferredMode, ['any', 'online', 'offline', 'hybrid'], true) ? $preferredMode : 'any';
                $validEduLevel = in_array($educationLevel, ['high_school', 'diploma', 'undergraduate', 'postgraduate', 'doctorate', 'other'], true) ? $educationLevel : 'undergraduate';

                $checkProf = $db->prepare('SELECT user_id FROM student_profiles WHERE user_id = :uid');
                $checkProf->execute([':uid' => $userId]);

                if ($checkProf->fetch()) {
                    $upProf = $db->prepare('
                        UPDATE student_profiles
                        SET education_level   = :edu_level,
                            institution       = :institution,
                            field_of_study    = :field_of_study,
                            graduation_year   = :grad_year,
                            country_id        = :country_id,
                            city              = :city,
                            experience_level  = :exp_level,
                            preferred_mode    = :pref_mode,
                            bio               = :bio,
                            resume_summary    = :resume_summary,
                            profile_completed = 1,
                            updated_at        = NOW()
                        WHERE user_id = :uid
                    ');
                    $upProf->execute([
                        ':edu_level'       => $validEduLevel,
                        ':institution'     => $institution,
                        ':field_of_study'  => $fieldOfStudy,
                        ':grad_year'       => $graduationYear,
                        ':country_id'      => $countryId,
                        ':city'            => $city !== '' ? $city : null,
                        ':exp_level'       => $validExp,
                        ':pref_mode'       => $validMode,
                        ':bio'             => $bio !== '' ? $bio : null,
                        ':resume_summary'  => $resumeSummary !== '' ? $resumeSummary : null,
                        ':uid'             => $userId,
                    ]);
                } else {
                    $insProf = $db->prepare('
                        INSERT INTO student_profiles (
                            user_id, education_level, institution, field_of_study, graduation_year, country_id, city,
                            experience_level, preferred_mode, bio, resume_summary, profile_completed, updated_at
                        ) VALUES (
                            :uid, :edu_level, :institution, :field_of_study, :grad_year, :country_id, :city,
                            :exp_level, :pref_mode, :bio, :resume_summary, 1, NOW()
                        )
                    ');
                    $insProf->execute([
                        ':uid'             => $userId,
                        ':edu_level'       => $validEduLevel,
                        ':institution'     => $institution,
                        ':field_of_study'  => $fieldOfStudy,
                        ':grad_year'       => $graduationYear,
                        ':country_id'      => $countryId,
                        ':city'            => $city !== '' ? $city : null,
                        ':exp_level'       => $validExp,
                        ':pref_mode'       => $validMode,
                        ':bio'             => $bio !== '' ? $bio : null,
                        ':resume_summary'  => $resumeSummary !== '' ? $resumeSummary : null,
                    ]);
                }

                // 3. Upsert student_education
                $qualToSave = !empty($qualification) ? $qualification : ($validEduLevel . ' in ' . $fieldOfStudy);
                $checkEdu = $db->prepare('SELECT id FROM student_education WHERE user_id = :uid LIMIT 1');
                $checkEdu->execute([':uid' => $userId]);
                $existingEduRow = $checkEdu->fetch();

                if ($existingEduRow) {
                    $upEdu = $db->prepare('
                        UPDATE student_education
                        SET qualification = :qual,
                            institution   = :inst,
                            start_year    = :s_year,
                            end_year      = :e_year
                        WHERE id = :id AND user_id = :uid
                    ');
                    $upEdu->execute([
                        ':qual'   => $qualToSave,
                        ':inst'   => $institution,
                        ':s_year' => $startYear,
                        ':e_year' => $graduationYear,
                        ':id'     => $existingEduRow['id'],
                        ':uid'    => $userId,
                    ]);
                } else {
                    $insEdu = $db->prepare('
                        INSERT INTO student_education (user_id, qualification, institution, start_year, end_year)
                        VALUES (:uid, :qual, :inst, :s_year, :e_year)
                    ');
                    $insEdu->execute([
                        ':uid'    => $userId,
                        ':qual'   => $qualToSave,
                        ':inst'   => $institution,
                        ':s_year' => $startYear,
                        ':e_year' => $graduationYear,
                    ]);
                }

                // 4. Save student_career_goals (Primary & Secondary)
                $db->prepare('DELETE FROM student_career_goals WHERE user_id = :uid')->execute([':uid' => $userId]);

                $insPrimary = $db->prepare('
                    INSERT INTO student_career_goals (user_id, career_id, is_primary, created_at)
                    VALUES (:uid, :cid, 1, NOW())
                ');
                $insPrimary->execute([':uid' => $userId, ':cid' => $submittedPrimaryCareer]);

                if (!empty($submittedSecondaryCareers)) {
                    $insSecondary = $db->prepare('
                        INSERT INTO student_career_goals (user_id, career_id, is_primary, created_at)
                        VALUES (:uid, :cid, 0, NOW())
                    ');
                    foreach ($submittedSecondaryCareers as $secCid) {
                        $insSecondary->execute([':uid' => $userId, ':cid' => $secCid]);
                    }
                }

                // 5. Save student_interest_fields
                $db->prepare('DELETE FROM student_interest_fields WHERE user_id = :uid')->execute([':uid' => $userId]);
                $insInterest = $db->prepare('INSERT INTO student_interest_fields (user_id, field_id) VALUES (:uid, :fid)');
                foreach ($submittedInterests as $fId) {
                    if ($fId > 0) {
                        $insInterest->execute([':uid' => $userId, ':fid' => $fId]);
                    }
                }

                // 6. Save student_skills
                $db->prepare("DELETE FROM student_skills WHERE user_id = :uid AND source = 'self_reported'")->execute([':uid' => $userId]);
                $insSkill = $db->prepare('
                    INSERT INTO student_skills (user_id, skill_id, level, source, updated_at)
                    VALUES (:uid, :sid, :level, \'self_reported\', NOW())
                ');
                foreach ($validatedSkills as $sId => $sLvl) {
                    $insSkill->execute([':uid' => $userId, ':sid' => $sId, ':level' => $sLvl]);
                }

                // 7. Save student_existing_activities
                $db->prepare('DELETE FROM student_existing_activities WHERE user_id = :uid')->execute([':uid' => $userId]);
                if (is_array($activityTitles)) {
                    $insAct = $db->prepare('
                        INSERT INTO student_existing_activities (user_id, activity_id, title, role, description, start_date, end_date)
                        VALUES (:uid, :aid, :title, :role, :desc, :s_date, :e_date)
                    ');
                    foreach ($activityTitles as $idx => $rawTitle) {
                        $actTitle = trim((string)$rawTitle);
                        if (!empty($actTitle)) {
                            $actCatId = !empty($activityCatalogIds[$idx]) ? (int)$activityCatalogIds[$idx] : null;
                            $actRole  = trim((string)($activityRoles[$idx] ?? ''));
                            $actDesc  = trim((string)($activityDescs[$idx] ?? ''));
                            $actStart = !empty($activityStarts[$idx]) ? $activityStarts[$idx] : null;
                            $actEnd   = !empty($activityEnds[$idx]) ? $activityEnds[$idx] : null;

                            $insAct->execute([
                                ':uid'    => $userId,
                                ':aid'    => $actCatId > 0 ? $actCatId : null,
                                ':title'  => $actTitle,
                                ':role'   => $actRole !== '' ? $actRole : null,
                                ':desc'   => $actDesc !== '' ? $actDesc : null,
                                ':s_date' => $actStart,
                                ':e_date' => $actEnd,
                            ]);
                        }
                    }
                }

                // Commit Transaction
                $db->commit();

                // Update session state
                $_SESSION['name']      = $fullName;
                $_SESSION['full_name'] = $fullName;

                // Redirect to dashboard on success
                header('Location: dashboard.php?msg=profile_completed');
                exit;

            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Profile Save Error: ' . $e->getMessage());
                $errors['general'] = 'Failed to save profile: ' . $e->getMessage();
            }
        }
    }
}

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEditMode ? 'Edit Student Profile' : 'Student Profile Onboarding' ?> — CareerAct</title>
    <link rel="stylesheet" href="assets/css/auth.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        html, body {
            height: auto !important;
            min-height: 100vh !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
        }
        body.onboarding-page {
            min-height: 100vh !important;
            height: auto !important;
            background: #F3F6FA;
            display: block !important;
            padding: 32px 16px 80px 16px !important;
            overflow-y: auto !important;
        }
        .onboarding-container {
            width: 100%;
            max-width: 980px;
            margin: 0 auto;
            background: #FFFFFF;
            border-radius: 16px;
            box-shadow: 0 16px 40px -10px rgba(22, 58, 112, 0.12);
            border: 1px solid #E4EAF2;
            overflow: visible;
        }
        .wizard-header {
            background: linear-gradient(135deg, #163A70 0%, #1769E8 100%);
            padding: 36px 40px;
            color: #FFFFFF;
        }
        .wizard-header h1 {
            font-size: 26px;
            font-weight: 800;
            margin-bottom: 6px;
            letter-spacing: -0.5px;
        }
        .wizard-header p {
            color: rgba(255, 255, 255, 0.85);
            font-size: 15px;
            max-width: 680px;
            line-height: 1.5;
        }

        /* Step Progress Bar */
        .step-progress-wrapper {
            background: #FFFFFF;
            padding: 18px 32px;
            border-bottom: 1px solid #E4EAF2;
            overflow-x: auto;
        }
        .step-indicators {
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-width: 640px;
            position: relative;
        }
        .step-indicators::before {
            content: '';
            position: absolute;
            top: 16px;
            left: 24px;
            right: 24px;
            height: 2px;
            background: #E4EAF2;
            z-index: 1;
        }
        .step-item {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
            background: transparent;
            border: none;
            padding: 0;
            font-family: inherit;
        }
        .step-circle {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #FFFFFF;
            border: 2px solid #CBD5E1;
            color: #64748B;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.2s;
        }
        .step-item.active .step-circle {
            background: #1769E8;
            border-color: #1769E8;
            color: #FFFFFF;
            box-shadow: 0 0 0 4px rgba(23, 105, 232, 0.2);
        }
        .step-item.completed .step-circle {
            background: #059669;
            border-color: #059669;
            color: #FFFFFF;
        }
        .step-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748B;
            margin-top: 6px;
            white-space: nowrap;
        }
        .step-item.active .step-label {
            color: #163A70;
            font-weight: 700;
        }

        /* Wizard Body */
        .wizard-body {
            padding: 36px 40px;
        }
        .wizard-step-pane {
            display: none;
        }
        .wizard-step-pane.active {
            display: block;
            animation: fadeIn 0.25s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .step-section-title {
            font-size: 20px;
            font-weight: 700;
            color: #163A70;
            margin-bottom: 6px;
        }
        .step-section-desc {
            font-size: 14px;
            color: #64748B;
            margin-bottom: 24px;
        }

        /* Custom Form Controls */
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 768px) {
            .form-grid-2 { grid-template-columns: 1fr; }
            .wizard-body { padding: 24px 20px; }
            .wizard-header { padding: 24px 20px; }
        }

        .form-label {
            display: block;
            font-size: 13.5px;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 6px;
        }
        .form-label .req {
            color: #DC2626;
        }
        .form-control, .form-select, .form-textarea {
            width: 100%;
            padding: 10px 14px;
            font-size: 14.5px;
            border: 1px solid #CBD5E1;
            border-radius: 8px;
            background: #FFFFFF;
            color: #0F172A;
            box-sizing: border-box;
            transition: border-color 0.15s, box-shadow 0.15s;
            font-family: inherit;
        }
        .form-control:focus, .form-select:focus, .form-textarea:focus {
            border-color: #1769E8;
            outline: none;
            box-shadow: 0 0 0 3px rgba(23, 105, 232, 0.15);
        }
        .form-control[readonly] {
            background: #F1F5F9;
            color: #64748B;
            cursor: not-allowed;
        }

        /* Grid Selection Cards */
        .catalog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 14px;
            max-height: 380px;
            overflow-y: auto;
            padding: 4px;
        }
        .select-card {
            border: 1.5px solid #E2E8F0;
            border-radius: 10px;
            padding: 14px 16px;
            cursor: pointer;
            transition: all 0.18s;
            background: #FFFFFF;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            position: relative;
        }
        .select-card:hover {
            border-color: #94A3B8;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }
        .select-card.selected {
            border-color: #1769E8;
            background: #F0F6FF;
        }
        .select-card input[type="radio"],
        .select-card input[type="checkbox"] {
            margin-top: 3px;
            width: 17px;
            height: 17px;
            accent-color: #1769E8;
        }
        .card-content h4 {
            font-size: 14.5px;
            font-weight: 600;
            color: #0F172A;
            margin: 0 0 4px 0;
        }
        .card-content p {
            font-size: 12.5px;
            color: #64748B;
            margin: 0;
            line-height: 1.4;
        }

        /* Skills with Levels */
        .skills-picker-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 420px;
            overflow-y: auto;
            padding: 4px;
        }
        .skill-pick-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            padding: 10px 14px;
            gap: 16px;
        }
        .skill-pick-item.selected {
            background: #F0F6FF;
            border-color: #BFDBFE;
        }
        .skill-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }
        .skill-name-txt {
            font-size: 14px;
            font-weight: 600;
            color: #1E293B;
        }
        .skill-cat-badge {
            font-size: 11px;
            font-weight: 600;
            background: #E2E8F0;
            color: #475569;
            padding: 2px 8px;
            border-radius: 12px;
        }

        /* Activity Rows */
        .activity-entry-card {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            position: relative;
        }
        .btn-remove-act {
            position: absolute;
            top: 14px;
            right: 14px;
            background: #FEE2E2;
            color: #DC2626;
            border: none;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-add-act {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #EFF6FF;
            color: #1769E8;
            border: 1.5px dashed #93C5FD;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            justify-content: center;
            transition: background 0.15s;
        }
        .btn-add-act:hover {
            background: #DBEAFE;
        }

        /* Review Summary Cards & Scroll Container */
        .review-cards-scroll-area {
            max-height: 58vh;
            min-height: 320px;
            overflow-y: auto;
            padding: 4px 12px 4px 4px;
            margin-bottom: 20px;
            overscroll-behavior: contain;
            scrollbar-width: thin;
            scrollbar-color: #CBD5E1 #F1F5F9;
        }
        .review-cards-scroll-area::-webkit-scrollbar {
            width: 8px;
        }
        .review-cards-scroll-area::-webkit-scrollbar-track {
            background: #F1F5F9;
            border-radius: 8px;
        }
        .review-cards-scroll-area::-webkit-scrollbar-thumb {
            background: #CBD5E1;
            border-radius: 8px;
        }
        .review-cards-scroll-area::-webkit-scrollbar-thumb:hover {
            background: #94A3B8;
        }
        .review-card {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            padding: 18px 20px;
            margin-bottom: 16px;
        }
        .review-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #E2E8F0;
        }
        .review-title {
            font-size: 15px;
            font-weight: 700;
            color: #163A70;
        }
        .review-edit-btn {
            font-size: 12.5px;
            font-weight: 600;
            color: #1769E8;
            background: none;
            border: none;
            cursor: pointer;
            text-decoration: underline;
        }
        .review-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }
        .review-field-lbl {
            font-size: 11.5px;
            text-transform: uppercase;
            font-weight: 700;
            color: #64748B;
            margin-bottom: 2px;
        }
        .review-field-val {
            font-size: 14px;
            font-weight: 600;
            color: #0F172A;
            word-break: break-word;
        }

        /* Wizard Footer / Navigation Buttons */
        .wizard-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 40px;
            background: #F8FAFC;
            border-top: 1px solid #E4EAF2;
            position: sticky;
            bottom: 0;
            z-index: 99;
            box-shadow: 0 -4px 16px rgba(22, 58, 112, 0.08);
            border-bottom-left-radius: 16px;
            border-bottom-right-radius: 16px;
        }
        .btn-prev, .btn-next, .btn-submit-wizard {
            padding: 11px 24px;
            border-radius: 8px;
            font-size: 14.5px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            transition: all 0.15s;
        }
        .btn-prev {
            background: #E2E8F0;
            color: #334155;
        }
        .btn-prev:hover {
            background: #CBD5E1;
        }
        .btn-next, .btn-submit-wizard {
            background: #1769E8;
            color: #FFFFFF;
        }
        .btn-next:hover, .btn-submit-wizard:hover {
            background: #1255be;
        }
        .btn-submit-wizard {
            background: #059669;
        }
        .btn-submit-wizard:hover {
            background: #047857;
        }
    </style>
</head>
<body class="onboarding-page">

<div class="onboarding-container">
    
    <!-- Wizard Header -->
    <header class="wizard-header">
        <h1><?= $isEditMode ? 'Edit Your Student Profile' : 'Complete Your CareerAct Profile' ?></h1>
        <p>Complete your academic details, career objectives, interests, skills, and activities. This powers your personalized extracurricular career roadmaps.</p>
    </header>

    <!-- Step Progress Indicator -->
    <nav class="step-progress-wrapper" aria-label="Onboarding Steps">
        <div class="step-indicators" id="stepIndicators">
            <button type="button" class="step-item active" data-step="1">
                <span class="step-circle">1</span>
                <span class="step-label">Personal</span>
            </button>
            <button type="button" class="step-item" data-step="2">
                <span class="step-circle">2</span>
                <span class="step-label">Education</span>
            </button>
            <button type="button" class="step-item" data-step="3">
                <span class="step-circle">3</span>
                <span class="step-label">Career Goal</span>
            </button>
            <button type="button" class="step-item" data-step="4">
                <span class="step-circle">4</span>
                <span class="step-label">Interests</span>
            </button>
            <button type="button" class="step-item" data-step="5">
                <span class="step-circle">5</span>
                <span class="step-label">Skills</span>
            </button>
            <button type="button" class="step-item" data-step="6">
                <span class="step-circle">6</span>
                <span class="step-label">Activities</span>
            </button>
            <button type="button" class="step-item" data-step="7">
                <span class="step-circle">7</span>
                <span class="step-label">Review</span>
            </button>
        </div>
    </nav>

    <!-- General Error Banner -->
    <?php if (!empty($errors['general'])): ?>
        <div style="margin: 20px 40px 0; padding: 14px 18px; background: #FEF2F2; border: 1px solid #FCA5A5; color: #DC2626; border-radius: 8px; font-size: 14px;">
            <?= e($errors['general']) ?>
        </div>
    <?php endif; ?>

    <!-- Main Wizard Form -->
    <form action="onboarding.php" method="POST" id="onboardingForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="submit_profile" value="1">

        <main class="wizard-body">
            
            <!-- STEP 1: PERSONAL INFORMATION -->
            <section class="wizard-step-pane active" id="pane-step-1" aria-labelledby="step1-title">
                <h2 class="step-section-title" id="step1-title">Step 1 — Personal & Geographic Details</h2>
                <p class="step-section-desc">Verify your contact identity and location for location-matched extracurriculars.</p>

                <div class="form-grid-2">
                    <div style="margin-bottom: 16px;">
                        <label for="full_name" class="form-label">Full Name <span class="req">*</span></label>
                        <input type="text" id="full_name" name="full_name" class="form-control" 
                               value="<?= e($_POST['full_name'] ?? $currentUser['name']) ?>" required>
                        <?php if (isset($errors['full_name'])): ?>
                            <span style="color:#DC2626; font-size:12px;"><?= e($errors['full_name']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label for="email" class="form-label">Account Email Address</label>
                        <input type="email" id="email" class="form-control" value="<?= e($currentUser['email']) ?>" readonly>
                        <small style="color: #64748B; font-size: 12px;">Verified login credential.</small>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label for="country_id" class="form-label">Country</label>
                        <select id="country_id" name="country_id" class="form-select">
                            <option value="">Select your country</option>
                            <?php foreach ($countries as $c): ?>
                                <?php 
                                    $cSelected = (isset($_POST['country_id']) && (int)$_POST['country_id'] === (int)$c['id'])
                                        || (!isset($_POST['country_id']) && (int)($existingProfile['country_id'] ?? 0) === (int)$c['id'])
                                        || (!isset($_POST['country_id']) && empty($existingProfile['country_id']) && $c['iso2'] === 'IN');
                                ?>
                                <option value="<?= (int)$c['id'] ?>" <?= $cSelected ? 'selected' : '' ?>>
                                    <?= e($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label for="city" class="form-label">City</label>
                        <input type="text" id="city" name="city" class="form-control" placeholder="e.g. Pune, Bangalore, Mumbai"
                               value="<?= e($_POST['city'] ?? ($existingProfile['city'] ?? '')) ?>" list="citySuggestions">
                        <datalist id="citySuggestions">
                            <?php foreach ($cities as $ct): ?>
                                <option value="<?= e($ct['name']) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label for="experience_level" class="form-label">Overall Experience Level</label>
                        <select id="experience_level" name="experience_level" class="form-select">
                            <?php 
                                $currExp = $_POST['experience_level'] ?? ($existingProfile['experience_level'] ?? 'beginner');
                            ?>
                            <option value="beginner" <?= $currExp === 'beginner' ? 'selected' : '' ?>>Beginner (Starting out)</option>
                            <option value="intermediate" <?= $currExp === 'intermediate' ? 'selected' : '' ?>>Intermediate (Has built projects / coursework)</option>
                            <option value="advanced" <?= $currExp === 'advanced' ? 'selected' : '' ?>>Advanced (Prior internships / leadership)</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label for="preferred_mode" class="form-label">Preferred Activity Participation Mode</label>
                        <select id="preferred_mode" name="preferred_mode" class="form-select">
                            <?php 
                                $currMode = $_POST['preferred_mode'] ?? ($existingProfile['preferred_mode'] ?? 'any');
                            ?>
                            <option value="any" <?= $currMode === 'any' ? 'selected' : '' ?>>Any (Flexible)</option>
                            <option value="online" <?= $currMode === 'online' ? 'selected' : '' ?>>Online / Virtual Only</option>
                            <option value="offline" <?= $currMode === 'offline' ? 'selected' : '' ?>>Offline / On-Campus Only</option>
                            <option value="hybrid" <?= $currMode === 'hybrid' ? 'selected' : '' ?>>Hybrid (Mix of Online & In-person)</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 16px;">
                    <label for="bio" class="form-label">Brief Student Biography / Intro</label>
                    <textarea id="bio" name="bio" rows="3" class="form-textarea" placeholder="Tell us about yourself, what you study, and your academic passions..."><?= e($_POST['bio'] ?? ($existingProfile['bio'] ?? '')) ?></textarea>
                </div>
            </section>

            <!-- STEP 2: EDUCATION -->
            <section class="wizard-step-pane" id="pane-step-2" aria-labelledby="step2-title">
                <h2 class="step-section-title" id="step2-title">Step 2 — Educational Background</h2>
                <p class="step-section-desc">Record your current academic institution, degree, and study year.</p>

                <div class="form-grid-2">
                    <div style="margin-bottom: 16px;">
                        <label for="education_level" class="form-label">Current Education Level <span class="req">*</span></label>
                        <select id="education_level" name="education_level" class="form-select" required>
                            <?php 
                                $currEduLvl = $_POST['education_level'] ?? ($existingProfile['education_level'] ?? 'undergraduate');
                            ?>
                            <option value="high_school" <?= $currEduLvl === 'high_school' ? 'selected' : '' ?>>High School</option>
                            <option value="diploma" <?= $currEduLvl === 'diploma' ? 'selected' : '' ?>>Diploma</option>
                            <option value="undergraduate" <?= $currEduLvl === 'undergraduate' ? 'selected' : '' ?>>Undergraduate (B.Tech / B.E. / BCA / B.Sc)</option>
                            <option value="postgraduate" <?= $currEduLvl === 'postgraduate' ? 'selected' : '' ?>>Postgraduate (M.Tech / MCA / M.Sc / MBA)</option>
                            <option value="doctorate" <?= $currEduLvl === 'doctorate' ? 'selected' : '' ?>>Doctorate / PhD</option>
                            <option value="other" <?= $currEduLvl === 'other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label for="institution" class="form-label">College / University Name <span class="req">*</span></label>
                        <input type="text" id="institution" name="institution" class="form-control" placeholder="e.g. Pune Institute of Computer Technology"
                               value="<?= e($_POST['institution'] ?? ($existingProfile['institution'] ?? '')) ?>" required>
                        <?php if (isset($errors['institution'])): ?>
                            <span style="color:#DC2626; font-size:12px;"><?= e($errors['institution']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label for="field_of_study" class="form-label">Branch / Field of Study <span class="req">*</span></label>
                        <input type="text" id="field_of_study" name="field_of_study" class="form-control" placeholder="e.g. Computer Science & Engineering"
                               value="<?= e($_POST['field_of_study'] ?? ($existingProfile['field_of_study'] ?? '')) ?>" required>
                        <?php if (isset($errors['field_of_study'])): ?>
                            <span style="color:#DC2626; font-size:12px;"><?= e($errors['field_of_study']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label for="qualification" class="form-label">Degree / Program Title</label>
                        <input type="text" id="qualification" name="qualification" class="form-control" placeholder="e.g. Bachelor of Technology (CSE)"
                               value="<?= e($_POST['qualification'] ?? ($existingEdu['qualification'] ?? '')) ?>">
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label for="start_year" class="form-label">Enrollment Start Year</label>
                        <input type="number" id="start_year" name="start_year" class="form-control" min="2000" max="2040" placeholder="e.g. 2022"
                               value="<?= e((string)($_POST['start_year'] ?? ($existingEdu['start_year'] ?? ''))) ?>">
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label for="graduation_year" class="form-label">Expected Graduation Year <span class="req">*</span></label>
                        <input type="number" id="graduation_year" name="graduation_year" class="form-control" min="2000" max="2040" placeholder="e.g. 2026"
                               value="<?= e((string)($_POST['graduation_year'] ?? ($existingProfile['graduation_year'] ?? ''))) ?>" required>
                        <?php if (isset($errors['graduation_year'])): ?>
                            <span style="color:#DC2626; font-size:12px;"><?= e($errors['graduation_year']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- STEP 3: CAREER GOALS -->
            <section class="wizard-step-pane" id="pane-step-3" aria-labelledby="step3-title">
                <h2 class="step-section-title" id="step3-title">Step 3 — Target Career Goals</h2>
                <p class="step-section-desc">Select your target career. CareerAct uses this to analyze skill gaps and recommend tailored extracurriculars.</p>

                <!-- Search Filter -->
                <div style="margin-bottom: 16px;">
                    <input type="text" id="careerSearch" class="form-control" placeholder="🔍 Search available career paths (e.g. Data Scientist, Security Analyst, Developer)...">
                </div>

                <div class="form-label">Select Primary Career Goal <span class="req">*</span></div>
                <?php if (isset($errors['primary_career_id'])): ?>
                    <div style="color:#DC2626; font-size:12.5px; margin-bottom: 8px;"><?= e($errors['primary_career_id']) ?></div>
                <?php endif; ?>

                <div class="catalog-grid" id="careerPrimaryGrid" style="margin-bottom: 24px;">
                    <?php foreach ($careers as $car): ?>
                        <?php 
                            $isPrim = (isset($_POST['primary_career_id']) && (int)$_POST['primary_career_id'] === (int)$car['id'])
                                || (!isset($_POST['primary_career_id']) && $primaryCareerId === (int)$car['id']);
                        ?>
                        <label class="select-card <?= $isPrim ? 'selected' : '' ?>" data-title="<?= strtolower(e($car['title'])) ?>">
                            <input type="radio" name="primary_career_id" value="<?= (int)$car['id'] ?>" <?= $isPrim ? 'checked' : '' ?> required>
                            <div class="card-content">
                                <h4><?= e($car['title']) ?></h4>
                                <p><?= e($car['description']) ?></p>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="form-label" style="margin-top: 20px;">Secondary / Additional Career Interests (Optional)</div>
                <div class="catalog-grid" id="careerSecondaryGrid" style="max-height: 240px; margin-bottom: 24px;">
                    <?php foreach ($careers as $car): ?>
                        <?php 
                            $isSec = in_array((int)$car['id'], $secondaryCareerIds, true);
                        ?>
                        <label class="select-card <?= $isSec ? 'selected' : '' ?>" data-title="<?= strtolower(e($car['title'])) ?>">
                            <input type="checkbox" name="secondary_career_ids[]" value="<?= (int)$car['id'] ?>" <?= $isSec ? 'checked' : '' ?>>
                            <div class="card-content">
                                <h4><?= e($car['title']) ?></h4>
                                <p><?= e(substr((string)$car['description'], 0, 100)) ?>...</p>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div>
                    <label for="resume_summary" class="form-label">Why this career goal? (Statement of Purpose / Career Objective)</label>
                    <textarea id="resume_summary" name="resume_summary" rows="3" class="form-textarea" placeholder="Describe what motivates your interest in this career and what you wish to accomplish..."><?= e($_POST['resume_summary'] ?? ($existingProfile['resume_summary'] ?? '')) ?></textarea>
                </div>
            </section>

            <!-- STEP 4: INTERESTS -->
            <section class="wizard-step-pane" id="pane-step-4" aria-labelledby="step4-title">
                <h2 class="step-section-title" id="step4-title">Step 4 — Activity & Field Interests</h2>
                <p class="step-section-desc">Select the industry domains and functional fields you find engaging.</p>

                <?php if (isset($errors['interest_fields'])): ?>
                    <div style="color:#DC2626; font-size:12.5px; margin-bottom: 12px;"><?= e($errors['interest_fields']) ?></div>
                <?php endif; ?>

                <div class="catalog-grid" style="max-height: 480px;">
                    <?php foreach ($fields as $f): ?>
                        <?php 
                            $isSelected = in_array((int)$f['id'], $existingInterests, true)
                                || (isset($_POST['interest_fields']) && in_array((int)$f['id'], array_map('intval', $_POST['interest_fields']), true));
                        ?>
                        <label class="select-card <?= $isSelected ? 'selected' : '' ?>">
                            <input type="checkbox" name="interest_fields[]" value="<?= (int)$f['id'] ?>" <?= $isSelected ? 'checked' : '' ?>>
                            <div class="card-content">
                                <h4><?= e($f['name']) ?></h4>
                                <p><?= e($f['description']) ?></p>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- STEP 5: EXISTING SKILLS -->
            <section class="wizard-step-pane" id="pane-step-5" aria-labelledby="step5-title">
                <h2 class="step-section-title" id="step5-title">Step 5 — Current Self-Reported Skills</h2>
                <p class="step-section-desc">Select the skills you currently possess and declare your practical proficiency level.</p>

                <?php if (isset($errors['skills'])): ?>
                    <div style="color:#DC2626; font-size:12.5px; margin-bottom: 12px;"><?= e($errors['skills']) ?></div>
                <?php endif; ?>

                <div class="skills-picker-list">
                    <?php foreach ($skillsByCategory as $categoryName => $catSkills): ?>
                        <div style="font-size: 13px; font-weight: 700; color: #163A70; margin: 12px 0 6px; border-bottom: 1px solid #E2E8F0; padding-bottom: 4px;">
                            <?= e($categoryName) ?>
                        </div>
                        <?php foreach ($catSkills as $sk): ?>
                            <?php 
                                $sId = (int)$sk['id'];
                                $hasSkill = isset($existingSkillsMap[$sId]);
                                $currentLvl = $existingSkillsMap[$sId] ?? 2;
                            ?>
                            <div class="skill-pick-item <?= $hasSkill ? 'selected' : '' ?>" id="skill-row-<?= $sId ?>">
                                <div class="skill-left">
                                    <input type="checkbox" id="skill-check-<?= $sId ?>" class="skill-toggle-chk" data-target="<?= $sId ?>" <?= $hasSkill ? 'checked' : '' ?>>
                                    <div>
                                        <div class="skill-name-txt"><?= e($sk['name']) ?></div>
                                        <div style="font-size: 11.5px; color: #64748B;"><?= e($sk['description']) ?></div>
                                    </div>
                                </div>
                                <div>
                                    <select name="skills[<?= $sId ?>]" class="form-select skill-level-select" style="min-width: 140px;" <?= $hasSkill ? '' : 'disabled' ?>>
                                        <?php foreach ($skillLevels as $lvl): ?>
                                            <option value="<?= (int)$lvl['level'] ?>" <?= $currentLvl === (int)$lvl['level'] ? 'selected' : '' ?>>
                                                Level <?= (int)$lvl['level'] ?> (<?= e($lvl['label']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- STEP 6: CURRENT EXTRACURRICULAR ACTIVITIES -->
            <section class="wizard-step-pane" id="pane-step-6" aria-labelledby="step6-title">
                <h2 class="step-section-title" id="step6-title">Step 6 — Extracurricular Activity Participation</h2>
                <p class="step-section-desc">Record any hackathons, clubs, workshops, leadership positions, or personal projects you have participated in.</p>

                <div id="activitiesContainer">
                    <?php if (!empty($existingActivities)): ?>
                        <?php foreach ($existingActivities as $idx => $act): ?>
                            <div class="activity-entry-card" id="act-card-<?= $idx ?>">
                                <button type="button" class="btn-remove-act" onclick="removeActivityRow(<?= $idx ?>)">✕ Remove</button>
                                <div class="form-grid-2">
                                    <div style="margin-bottom: 12px;">
                                        <label class="form-label">Activity Title / Name <span class="req">*</span></label>
                                        <input type="text" name="activity_title[]" class="form-control" value="<?= e($act['title']) ?>" placeholder="e.g. Smart India Hackathon 2024" required>
                                    </div>
                                    <div style="margin-bottom: 12px;">
                                        <label class="form-label">Related Catalog Activity (Optional)</label>
                                        <select name="activity_catalog_id[]" class="form-select">
                                            <option value="">Custom / Non-catalog Activity</option>
                                            <?php foreach ($activityCatalog as $acItem): ?>
                                                <option value="<?= (int)$acItem['id'] ?>" <?= (int)($act['activity_id'] ?? 0) === (int)$acItem['id'] ? 'selected' : '' ?>>
                                                    <?= e($acItem['title']) ?> (<?= e($acItem['category_name']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div style="margin-bottom: 12px;">
                                        <label class="form-label">Your Role or Responsibility</label>
                                        <input type="text" name="activity_role[]" class="form-control" value="<?= e($act['role'] ?? '') ?>" placeholder="e.g. Team Lead, Frontend Developer, Member">
                                    </div>
                                    <div style="margin-bottom: 12px;">
                                        <div style="display: flex; gap: 10px;">
                                            <div style="flex: 1;">
                                                <label class="form-label">Start Date</label>
                                                <input type="date" name="activity_start[]" class="form-control" value="<?= e($act['start_date'] ?? '') ?>">
                                            </div>
                                            <div style="flex: 1;">
                                                <label class="form-label">End Date</label>
                                                <input type="date" name="activity_end[]" class="form-control" value="<?= e($act['end_date'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label class="form-label">Description & Outcomes</label>
                                    <textarea name="activity_desc[]" rows="2" class="form-textarea" placeholder="Briefly describe what you did, the skills you applied, and any awards or recognition won..."><?= e($act['description'] ?? '') ?></textarea>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- Default First Activity Row -->
                        <div class="activity-entry-card" id="act-card-0">
                            <button type="button" class="btn-remove-act" onclick="removeActivityRow(0)">✕ Remove</button>
                            <div class="form-grid-2">
                                <div style="margin-bottom: 12px;">
                                    <label class="form-label">Activity Title / Name <span class="req">*</span></label>
                                    <input type="text" name="activity_title[]" class="form-control" placeholder="e.g. Smart India Hackathon 2024">
                                </div>
                                <div style="margin-bottom: 12px;">
                                    <label class="form-label">Related Catalog Activity (Optional)</label>
                                    <select name="activity_catalog_id[]" class="form-select">
                                        <option value="">Custom / Non-catalog Activity</option>
                                        <?php foreach ($activityCatalog as $acItem): ?>
                                            <option value="<?= (int)$acItem['id'] ?>"><?= e($acItem['title']) ?> (<?= e($acItem['category_name']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div style="margin-bottom: 12px;">
                                    <label class="form-label">Your Role or Responsibility</label>
                                    <input type="text" name="activity_role[]" class="form-control" placeholder="e.g. Team Lead, Member, Participant">
                                </div>
                                <div style="margin-bottom: 12px;">
                                    <div style="display: flex; gap: 10px;">
                                        <div style="flex: 1;">
                                            <label class="form-label">Start Date</label>
                                            <input type="date" name="activity_start[]" class="form-control">
                                        </div>
                                        <div style="flex: 1;">
                                            <label class="form-label">End Date</label>
                                            <input type="date" name="activity_end[]" class="form-control">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="form-label">Description & Outcomes</label>
                                <textarea name="activity_desc[]" rows="2" class="form-textarea" placeholder="Briefly describe what you did, the skills you applied, and any outcomes..."></textarea>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <button type="button" class="btn-add-act" id="btnAddActivity">
                    <span>+ Add Another Extracurricular Activity</span>
                </button>
            </section>

            <!-- STEP 7: REVIEW AND SUBMIT -->
            <section class="wizard-step-pane" id="pane-step-7" aria-labelledby="step7-title">
                <h2 class="step-section-title" id="step7-title">Step 7 — Review & Complete Profile</h2>
                <p class="step-section-desc">Review your entered information before finalizing. You can edit your profile at any time from your account.</p>

                <!-- Scrollable Review Cards Area -->
                <div class="review-cards-scroll-area" id="reviewCardsScrollArea">
                    <!-- Personal Info Review -->
                    <div class="review-card">
                        <div class="review-header">
                            <span class="review-title">1. Personal & Contact Information</span>
                            <button type="button" class="review-edit-btn" onclick="goToStep(1)">Edit</button>
                        </div>
                        <div class="review-grid">
                            <div>
                                <div class="review-field-lbl">Full Name</div>
                                <div class="review-field-val" id="rev_name">—</div>
                            </div>
                            <div>
                                <div class="review-field-lbl">Email Address</div>
                                <div class="review-field-val"><?= e($currentUser['email']) ?></div>
                            </div>
                            <div>
                                <div class="review-field-lbl">Location</div>
                                <div class="review-field-val" id="rev_location">—</div>
                            </div>
                            <div>
                                <div class="review-field-lbl">Preferred Mode</div>
                                <div class="review-field-val" id="rev_mode">—</div>
                            </div>
                        </div>
                    </div>

                    <!-- Education Review -->
                    <div class="review-card">
                        <div class="review-header">
                            <span class="review-title">2. Educational Background</span>
                            <button type="button" class="review-edit-btn" onclick="goToStep(2)">Edit</button>
                        </div>
                        <div class="review-grid">
                            <div>
                                <div class="review-field-lbl">Institution</div>
                                <div class="review-field-val" id="rev_inst">—</div>
                            </div>
                            <div>
                                <div class="review-field-lbl">Degree / Field</div>
                                <div class="review-field-val" id="rev_degree">—</div>
                            </div>
                            <div>
                                <div class="review-field-lbl">Graduation Year</div>
                                <div class="review-field-val" id="rev_grad">—</div>
                            </div>
                        </div>
                    </div>

                    <!-- Career Goals Review -->
                    <div class="review-card">
                        <div class="review-header">
                            <span class="review-title">3. Target Career Goals</span>
                            <button type="button" class="review-edit-btn" onclick="goToStep(3)">Edit</button>
                        </div>
                        <div>
                            <div class="review-field-lbl">Primary Career Target</div>
                            <div class="review-field-val" style="color: #1769E8; font-size: 15px; margin-bottom: 8px;" id="rev_primary_career">—</div>
                            <div class="review-field-lbl">Secondary Goals</div>
                            <div class="review-field-val" id="rev_secondary_careers">—</div>
                        </div>
                    </div>

                    <!-- Interests & Skills Review -->
                    <div class="review-card">
                        <div class="review-header">
                            <span class="review-title">4 & 5. Interests & Declared Skills</span>
                            <button type="button" class="review-edit-btn" onclick="goToStep(4)">Edit</button>
                        </div>
                        <div>
                            <div class="review-field-lbl">Selected Interests</div>
                            <div class="review-field-val" style="margin-bottom: 10px;" id="rev_interests">—</div>
                            <div class="review-field-lbl">Self-Reported Skills</div>
                            <div class="review-field-val" id="rev_skills">—</div>
                        </div>
                    </div>

                    <!-- Activities Summary -->
                    <div class="review-card">
                        <div class="review-header">
                            <span class="review-title">6. Extracurricular Activities</span>
                            <button type="button" class="review-edit-btn" onclick="goToStep(6)">Edit</button>
                        </div>
                        <div id="rev_activities" class="review-field-val">No activities entered yet.</div>
                    </div>
                </div>

                <div style="background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 8px; padding: 14px 18px; color: #065F46; font-size: 13.5px; margin-top: 10px;">
                    ✓ By clicking "Complete Profile & Unlock Dashboard", your profile status will be updated to Completed in the <code>carrearact2</code> database, granting full access to your personalized student dashboard.
                </div>
            </section>

        </main>

        <!-- Wizard Navigation Footer -->
        <footer class="wizard-footer">
            <button type="button" class="btn-prev" id="btnPrev" style="visibility: hidden;">
                ← Back
            </button>
            <div style="display: flex; gap: 12px;">
                <button type="button" class="btn-next" id="btnNext">
                    <span>Continue →</span>
                </button>
                <button type="submit" class="btn-submit-wizard" id="btnSubmit" style="display: none;">
                    <span>✓ Complete Profile & Unlock Dashboard</span>
                </button>
            </div>
        </footer>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let currentStep = 1;
    const totalSteps = 7;

    const btnPrev = document.getElementById('btnPrev');
    const btnNext = document.getElementById('btnNext');
    const btnSubmit = document.getElementById('btnSubmit');
    const stepIndicators = document.querySelectorAll('.step-item');
    const stepPanes = document.querySelectorAll('.wizard-step-pane');

    window.goToStep = function(step) {
        if (step < 1 || step > totalSteps) return;

        // Update step indicators
        stepIndicators.forEach((btn, idx) => {
            const sNum = idx + 1;
            btn.classList.remove('active');
            if (sNum === step) {
                btn.classList.add('active');
            } else if (sNum < step) {
                btn.classList.add('completed');
            } else {
                btn.classList.remove('completed');
            }
        });

        // Toggle panes
        stepPanes.forEach((pane, idx) => {
            if (idx + 1 === step) {
                pane.classList.add('active');
            } else {
                pane.classList.remove('active');
            }
        });

        currentStep = step;

        // Button states
        btnPrev.style.visibility = (currentStep === 1) ? 'hidden' : 'visible';
        if (currentStep === totalSteps) {
            btnNext.style.display = 'none';
            btnSubmit.style.display = 'inline-flex';
            populateReviewSummary();
        } else {
            btnNext.style.display = 'inline-flex';
            btnSubmit.style.display = 'none';
        }

        const container = document.querySelector('.onboarding-container');
        if (container) {
            container.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    // Step Indicator Clicks
    stepIndicators.forEach(btn => {
        btn.addEventListener('click', function() {
            const targetStep = parseInt(this.getAttribute('data-step'), 10);
            goToStep(targetStep);
        });
    });

    btnNext.addEventListener('click', function() {
        if (validateStep(currentStep)) {
            goToStep(currentStep + 1);
        }
    });

    btnPrev.addEventListener('click', function() {
        goToStep(currentStep - 1);
    });

    // Step Validation
    function validateStep(step) {
        if (step === 1) {
            const name = document.getElementById('full_name').value.trim();
            if (!name) {
                alert('Please enter your Full Name.');
                document.getElementById('full_name').focus();
                return false;
            }
        } else if (step === 2) {
            const inst = document.getElementById('institution').value.trim();
            const branch = document.getElementById('field_of_study').value.trim();
            const grad = document.getElementById('graduation_year').value.trim();
            if (!inst) {
                alert('Please enter your College / University name.');
                document.getElementById('institution').focus();
                return false;
            }
            if (!branch) {
                alert('Please enter your Field of Study / Branch.');
                document.getElementById('field_of_study').focus();
                return false;
            }
            if (!grad || parseInt(grad, 10) < 1980) {
                alert('Please enter a valid Expected Graduation Year (e.g. 2026).');
                document.getElementById('graduation_year').focus();
                return false;
            }
        } else if (step === 3) {
            const primCareer = document.querySelector('input[name="primary_career_id"]:checked');
            if (!primCareer) {
                alert('Please select your Primary Career Goal.');
                return false;
            }
        } else if (step === 4) {
            const interests = document.querySelectorAll('input[name="interest_fields[]"]:checked');
            if (interests.length === 0) {
                alert('Please select at least one field of interest.');
                return false;
            }
        } else if (step === 5) {
            const skills = document.querySelectorAll('.skill-toggle-chk:checked');
            if (skills.length === 0) {
                alert('Please check at least one skill and select your proficiency level.');
                return false;
            }
        }
        return true;
    }

    // Card Selection Highlights (Radio and Checkboxes)
    document.querySelectorAll('.select-card input').forEach(input => {
        input.addEventListener('change', function() {
            if (this.type === 'radio') {
                const name = this.name;
                document.querySelectorAll(`input[name="${name}"]`).forEach(r => {
                    r.closest('.select-card').classList.remove('selected');
                });
                if (this.checked) {
                    this.closest('.select-card').classList.add('selected');
                }
            } else if (this.type === 'checkbox') {
                if (this.checked) {
                    this.closest('.select-card').classList.add('selected');
                } else {
                    this.closest('.select-card').classList.remove('selected');
                }
            }
        });
    });

    // Skill Pick Items Toggle
    document.querySelectorAll('.skill-toggle-chk').forEach(chk => {
        chk.addEventListener('change', function() {
            const targetId = this.getAttribute('data-target');
            const row = document.getElementById(`skill-row-${targetId}`);
            const select = row.querySelector('.skill-level-select');
            if (this.checked) {
                row.classList.add('selected');
                select.disabled = false;
            } else {
                row.classList.remove('selected');
                select.disabled = true;
            }
        });
    });

    // Search filter for Career Goals
    const careerSearch = document.getElementById('careerSearch');
    if (careerSearch) {
        careerSearch.addEventListener('input', function() {
            const term = this.value.toLowerCase().trim();
            document.querySelectorAll('#careerPrimaryGrid .select-card, #careerSecondaryGrid .select-card').forEach(card => {
                const text = card.getAttribute('data-title') || '';
                if (text.includes(term)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    }

    // Dynamic Extracurricular Activities Rows
    let actIndex = <?= count($existingActivities) > 0 ? count($existingActivities) : 1 ?>;
    const btnAddActivity = document.getElementById('btnAddActivity');
    const activitiesContainer = document.getElementById('activitiesContainer');

    if (btnAddActivity && activitiesContainer) {
        btnAddActivity.addEventListener('click', function() {
            const newCard = document.createElement('div');
            newCard.className = 'activity-entry-card';
            newCard.id = `act-card-${actIndex}`;
            newCard.innerHTML = `
                <button type="button" class="btn-remove-act" onclick="removeActivityRow(${actIndex})">✕ Remove</button>
                <div class="form-grid-2">
                    <div style="margin-bottom: 12px;">
                        <label class="form-label">Activity Title / Name <span class="req">*</span></label>
                        <input type="text" name="activity_title[]" class="form-control" placeholder="e.g. Web Development Workshop, Hackathon" required>
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label class="form-label">Related Catalog Activity (Optional)</label>
                        <select name="activity_catalog_id[]" class="form-select">
                            <option value="">Custom / Non-catalog Activity</option>
                            <?php foreach ($activityCatalog as $acItem): ?>
                                <option value="<?= (int)$acItem['id'] ?>"><?= e($acItem['title']) ?> (<?= e($acItem['category_name']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label class="form-label">Your Role or Responsibility</label>
                        <input type="text" name="activity_role[]" class="form-control" placeholder="e.g. Participant, Lead, Organizer">
                    </div>
                    <div style="margin-bottom: 12px;">
                        <div style="display: flex; gap: 10px;">
                            <div style="flex: 1;">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="activity_start[]" class="form-control">
                            </div>
                            <div style="flex: 1;">
                                <label class="form-label">End Date</label>
                                <input type="date" name="activity_end[]" class="form-control">
                            </div>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="form-label">Description & Outcomes</label>
                    <textarea name="activity_desc[]" rows="2" class="form-textarea" placeholder="Describe achievements, skills, or key contributions..."></textarea>
                </div>
            `;
            activitiesContainer.appendChild(newCard);
            actIndex++;
        });
    }

    window.removeActivityRow = function(id) {
        const row = document.getElementById(`act-card-${id}`);
        if (row) {
            row.remove();
        }
    };

    // Review Summary Generator
    function populateReviewSummary() {
        try {
            const nameEl = document.getElementById('full_name');
            const revName = document.getElementById('rev_name');
            if (revName && nameEl) {
                revName.textContent = nameEl.value.trim() || '—';
            }
            
            const cityEl = document.getElementById('city');
            const city = cityEl ? cityEl.value.trim() : '';
            const countrySelect = document.getElementById('country_id');
            const countryText = (countrySelect && countrySelect.selectedIndex >= 0 && countrySelect.options[countrySelect.selectedIndex]) 
                ? countrySelect.options[countrySelect.selectedIndex].text.trim() 
                : '';
            const revLoc = document.getElementById('rev_location');
            if (revLoc) {
                revLoc.textContent = (city ? city + ', ' : '') + countryText;
            }

            const modeSelect = document.getElementById('preferred_mode');
            const revMode = document.getElementById('rev_mode');
            if (revMode && modeSelect) {
                revMode.textContent = (modeSelect.selectedIndex >= 0 && modeSelect.options[modeSelect.selectedIndex])
                    ? modeSelect.options[modeSelect.selectedIndex].text.trim()
                    : 'Any';
            }

            const instEl = document.getElementById('institution');
            const revInst = document.getElementById('rev_inst');
            if (revInst && instEl) {
                revInst.textContent = instEl.value.trim() || '—';
            }

            const branchEl = document.getElementById('field_of_study');
            const revDegree = document.getElementById('rev_degree');
            if (revDegree && branchEl) {
                revDegree.textContent = branchEl.value.trim() || '—';
            }

            const gradEl = document.getElementById('graduation_year');
            const revGrad = document.getElementById('rev_grad');
            if (revGrad && gradEl) {
                revGrad.textContent = gradEl.value.trim() || '—';
            }

            // Primary Career
            const primChecked = document.querySelector('input[name="primary_career_id"]:checked');
            const revPrim = document.getElementById('rev_primary_career');
            if (revPrim) {
                if (primChecked) {
                    const card = primChecked.closest('.select-card');
                    const h4 = card ? card.querySelector('h4') : null;
                    revPrim.textContent = h4 ? h4.textContent.trim() : 'Selected';
                } else {
                    revPrim.textContent = 'None Selected';
                }
            }

            // Secondary Careers
            const secChecked = document.querySelectorAll('input[name="secondary_career_ids[]"]:checked');
            const revSec = document.getElementById('rev_secondary_careers');
            if (revSec) {
                const secTitles = [];
                secChecked.forEach(c => {
                    const card = c.closest('.select-card');
                    const h4 = card ? card.querySelector('h4') : null;
                    if (h4) secTitles.push(h4.textContent.trim());
                });
                revSec.textContent = secTitles.length > 0 ? secTitles.join(', ') : 'None';
            }

            // Interests
            const intChecked = document.querySelectorAll('input[name="interest_fields[]"]:checked');
            const revInt = document.getElementById('rev_interests');
            if (revInt) {
                const intTitles = [];
                intChecked.forEach(c => {
                    const card = c.closest('.select-card');
                    const h4 = card ? card.querySelector('h4') : null;
                    if (h4) intTitles.push(h4.textContent.trim());
                });
                revInt.textContent = intTitles.length > 0 ? intTitles.join(', ') : 'None';
            }

            // Skills
            const skillsChecked = document.querySelectorAll('.skill-toggle-chk:checked');
            const revSkills = document.getElementById('rev_skills');
            if (revSkills) {
                const skillSummaries = [];
                skillsChecked.forEach(c => {
                    const row = c.closest('.skill-pick-item');
                    if (row) {
                        const nameEl = row.querySelector('.skill-name-txt');
                        const name = nameEl ? nameEl.textContent.trim() : 'Skill';
                        const lvlSelect = row.querySelector('.skill-level-select');
                        const lvlText = (lvlSelect && lvlSelect.selectedIndex >= 0 && lvlSelect.options[lvlSelect.selectedIndex])
                            ? lvlSelect.options[lvlSelect.selectedIndex].text.trim()
                            : 'Proficient';
                        skillSummaries.push(`${name} (${lvlText})`);
                    }
                });
                revSkills.textContent = skillSummaries.length > 0 ? skillSummaries.join(' • ') : 'None';
            }

            // Activities
            const actTitles = document.querySelectorAll('input[name="activity_title[]"]');
            const revAct = document.getElementById('rev_activities');
            if (revAct) {
                const actList = [];
                actTitles.forEach(input => {
                    if (input && input.value.trim()) {
                        actList.push(input.value.trim());
                    }
                });
                revAct.textContent = actList.length > 0 ? actList.join(', ') : 'No activities specified';
            }

            // Reset scroll on review scroll area
            const reviewScroll = document.getElementById('reviewCardsScrollArea');
            if (reviewScroll) {
                reviewScroll.scrollTop = 0;
            }
        } catch (err) {
            console.error('Review generation error:', err);
        }
    }
});
</script>
</body>
</html>
