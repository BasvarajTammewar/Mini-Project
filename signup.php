<?php
/**
 * CareerAct — Student Sign-Up Page & Registration Handler
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';

initSession();

// If already logged in, redirect directly to dashboard
if (isUserLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$formData = [
    'full_name'      => '',
    'email'          => '',
    'college_name'   => '',
    'degree_name'    => '',
    'branch_name'    => '',
    'academic_year'  => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Validate CSRF Token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $errors['general'] = 'Your session expired or invalid form submission. Please try again.';
    } else {
        // 2. Collect and sanitize input
        $fullName     = trim((string)($_POST['full_name'] ?? ''));
        $email        = strtolower(trim((string)($_POST['email'] ?? '')));
        $collegeName  = trim((string)($_POST['college_name'] ?? ''));
        $degreeName   = trim((string)($_POST['degree_name'] ?? ''));
        $branchName   = trim((string)($_POST['branch_name'] ?? ''));
        $academicYear = trim((string)($_POST['academic_year'] ?? ''));
        $password     = (string)($_POST['password'] ?? '');
        $confirmPass  = (string)($_POST['confirm_password'] ?? '');
        $termsAgreed  = isset($_POST['terms']);

        // Preserve form values (excluding passwords)
        $formData['full_name']     = $fullName;
        $formData['email']         = $email;
        $formData['college_name']  = $collegeName;
        $formData['degree_name']   = $degreeName;
        $formData['branch_name']   = $branchName;
        $formData['academic_year'] = $academicYear;

        // 3. Validation Rules
        if (empty($fullName)) {
            $errors['full_name'] = 'Full Name is required.';
        } elseif (mb_strlen($fullName) > 150) {
            $errors['full_name'] = 'Full Name cannot exceed 150 characters.';
        }

        if (empty($email)) {
            $errors['email'] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        } elseif (mb_strlen($email) > 255) {
            $errors['email'] = 'Email cannot exceed 255 characters.';
        }

        if (empty($collegeName)) {
            $errors['college_name'] = 'College / University is required.';
        } elseif (mb_strlen($collegeName) > 200) {
            $errors['college_name'] = 'College name cannot exceed 200 characters.';
        }

        if (empty($degreeName)) {
            $errors['degree_name'] = 'Degree / Program is required.';
        }

        if (empty($branchName)) {
            $errors['branch_name'] = 'Course / Branch is required.';
        }

        $academicYearInt = null;
        if ($academicYear !== '') {
            $academicYearInt = (int)$academicYear;
            if ($academicYearInt < 1 || $academicYearInt > 10) {
                $errors['academic_year'] = 'Please select a valid study year.';
            }
        }

        if (empty($password)) {
            $errors['password'] = 'Password is required.';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }

        if (empty($confirmPass)) {
            $errors['confirm_password'] = 'Please confirm your password.';
        } elseif ($password !== $confirmPass) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }

        if (!$termsAgreed) {
            $errors['terms'] = 'You must agree to the Terms of Service to create an account.';
        }

        // 4. If no validation errors, proceed with DB check & transaction
        if (empty($errors)) {
            try {
                $db = getDbConnection();

                // Check for duplicate email
                $stmtCheck = $db->prepare("SELECT user_id FROM users WHERE email = :email LIMIT 1");
                $stmtCheck->execute([':email' => $email]);
                if ($stmtCheck->fetch()) {
                    $errors['email'] = 'An account with this email address already exists. Please sign in.';
                } else {
                    // Look up role_id for 'student' (never hardcode)
                    $stmtRole = $db->prepare("SELECT role_id FROM roles WHERE role_code = 'student' LIMIT 1");
                    $stmtRole->execute();
                    $roleId = $stmtRole->fetchColumn();

                    if (!$roleId) {
                        throw new RuntimeException("Student role definition not found in database.");
                    }

                    // Look up account_status_id for 'active' (never hardcode)
                    $stmtStatus = $db->prepare("SELECT account_status_id FROM account_statuses WHERE status_code = 'active' LIMIT 1");
                    $stmtStatus->execute();
                    $statusId = $stmtStatus->fetchColumn();

                    if (!$statusId) {
                        throw new RuntimeException("Active account status definition not found in database.");
                    }

                    // Hash password securely
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                    // Begin Transaction
                    $db->beginTransaction();

                    // Insert into users
                    $stmtUser = $db->prepare("
                        INSERT INTO users (
                            role_id, account_status_id, email, password_hash, email_verified_at, created_at
                        ) VALUES (
                            :role_id, :account_status_id, :email, :password_hash, NOW(), NOW()
                        )
                    ");
                    $stmtUser->execute([
                        ':role_id'           => $roleId,
                        ':account_status_id' => $statusId,
                        ':email'             => $email,
                        ':password_hash'     => $passwordHash
                    ]);

                    $newUserId = (int)$db->lastInsertId();

                    // Insert into student_profiles
                    $stmtProfile = $db->prepare("
                        INSERT INTO student_profiles (
                            user_id, full_name, college_name, degree_name, course_name, branch_name, academic_year, profile_visibility, created_at
                        ) VALUES (
                            :user_id, :full_name, :college_name, :degree_name, :course_name, :branch_name, :academic_year, 'private', NOW()
                        )
                    ");
                    $stmtProfile->execute([
                        ':user_id'       => $newUserId,
                        ':full_name'     => $fullName,
                        ':college_name'  => $collegeName,
                        ':degree_name'   => $degreeName,
                        ':course_name'   => $branchName, // Course/Branch mapped
                        ':branch_name'   => $branchName,
                        ':academic_year' => $academicYearInt
                    ]);

                    $newStudentId = (int)$db->lastInsertId();

                    $db->commit();

                    // Automatically establish authenticated session and route to guided onboarding
                    session_regenerate_id(true);
                    $_SESSION['user_id']       = $newUserId;
                    $_SESSION['email']         = $email;
                    $_SESSION['role_code']     = 'student';
                    $_SESSION['role_name']     = 'Student';
                    $_SESSION['student_id']    = $newStudentId;
                    $_SESSION['full_name']     = $fullName;
                    $_SESSION['college_name']  = $collegeName;
                    $_SESSION['degree_name']   = $degreeName;
                    $_SESSION['branch_name']   = $branchName;
                    $_SESSION['academic_year'] = $academicYearInt;
                    $_SESSION['logged_in_at']  = time();

                    header('Location: onboarding.php?step=1');
                    exit;
                }
            } catch (Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Registration Error: ' . $e->getMessage());
                $errors['general'] = 'Registration could not be completed right now. Please try again.';
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
    <title>Create Your Account — CareerAct</title>
    <meta name="description" content="Sign up for CareerAct — Student Career & Extracurricular Activity Recommendation System.">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
<div class="auth-page">
    <div class="auth-container signup-mode">
        
        <!-- Left Brand Showcase Panel -->
        <aside class="brand-panel" aria-label="CareerAct Information">
            <div class="brand-logo-area">
                <div class="brand-icon-wrapper" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path>
                    </svg>
                </div>
                <a href="index.php" class="brand-title">Career<span>Act</span></a>
            </div>

            <div class="brand-content">
                <h2 class="brand-headline">Turn campus activities into <span>career superpowers.</span></h2>
                <p class="brand-description">
                    Join thousands of ambitious students tracking hackathons, workshops, leadership roles, and research projects to unlock data-backed career roadmaps.
                </p>

                <ul class="brand-features">
                    <li class="brand-feature-item">
                        <div class="feature-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <span>Personalized Extracurricular Recommendations</span>
                    </li>
                    <li class="brand-feature-item">
                        <div class="feature-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </div>
                        <span>Automated Skill & Achievement Mapping</span>
                    </li>
                    <li class="brand-feature-item">
                        <div class="feature-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                        </div>
                        <span>Verified Portfolios & ATS Resume Ready</span>
                    </li>
                </ul>
            </div>

            <div class="brand-footer-card">
                <p class="brand-footer-text">
                    "CareerAct bridged the gap between what I did outside class and what tech recruiters actually cared about."
                </p>
                <div class="brand-footer-author">— B.Tech Final Year Student, CSE</div>
            </div>
        </aside>

        <!-- Right Form Panel -->
        <main class="form-panel" id="main-content">
            <!-- Mobile Brand Header -->
            <div class="mobile-brand-header">
                <div class="mobile-brand-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path>
                    </svg>
                </div>
                <div class="mobile-brand-title">CareerAct</div>
            </div>

            <div class="form-header">
                <h1 class="form-title">Create Your CareerAct Account</h1>
                <p class="form-subtitle">Start connecting your activities with your career goals.</p>
            </div>

            <!-- General Error Alert -->
            <?php if (!empty($errors['general'])): ?>
                <div class="alert alert-error" role="alert" id="error-alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span><?= e($errors['general']) ?></span>
                </div>
            <?php endif; ?>

            <form action="signup.php" method="POST" class="auth-form" id="signupForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                <!-- Row 1: Full Name & Email -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="full_name" class="form-label">
                            Full Name <span class="req">*</span>
                        </label>
                        <div class="input-wrapper">
                            <span class="input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            </span>
                            <input 
                                type="text" 
                                id="full_name" 
                                name="full_name" 
                                class="form-input <?= isset($errors['full_name']) ? 'is-invalid' : '' ?>" 
                                placeholder="e.g. Rahul Sharma"
                                value="<?= e($formData['full_name']) ?>"
                                required
                                autocomplete="name"
                            >
                        </div>
                        <?php if (isset($errors['full_name'])): ?>
                            <span class="field-error"><?= e($errors['full_name']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="email" class="form-label">
                            Email Address <span class="req">*</span>
                        </label>
                        <div class="input-wrapper">
                            <span class="input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                            </span>
                            <input 
                                type="email" 
                                id="email" 
                                name="email" 
                                class="form-input <?= isset($errors['email']) ? 'is-invalid' : '' ?>" 
                                placeholder="name@college.edu"
                                value="<?= e($formData['email']) ?>"
                                required
                                autocomplete="email"
                            >
                        </div>
                        <?php if (isset($errors['email'])): ?>
                            <span class="field-error"><?= e($errors['email']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Row 2: College & Degree -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="college_name" class="form-label">
                            College / University <span class="req">*</span>
                        </label>
                        <div class="input-wrapper">
                            <span class="input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M3 21h18M3 7v14M21 7v14M6 11h2M6 15h2M16 11h2M16 15h2M10 21V11h4v10M2 7l10-5 10 5"></path></svg>
                            </span>
                            <input 
                                type="text" 
                                id="college_name" 
                                name="college_name" 
                                class="form-input <?= isset($errors['college_name']) ? 'is-invalid' : '' ?>" 
                                placeholder="e.g. Pune Institute of Computer Technology"
                                value="<?= e($formData['college_name']) ?>"
                                required
                            >
                        </div>
                        <?php if (isset($errors['college_name'])): ?>
                            <span class="field-error"><?= e($errors['college_name']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="degree_name" class="form-label">
                            Degree / Program <span class="req">*</span>
                        </label>
                        <div class="input-wrapper">
                            <span class="input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>
                            </span>
                            <input 
                                type="text" 
                                id="degree_name" 
                                name="degree_name" 
                                class="form-input <?= isset($errors['degree_name']) ? 'is-invalid' : '' ?>" 
                                placeholder="e.g. B.Tech / B.E. / BCA / B.Sc"
                                value="<?= e($formData['degree_name']) ?>"
                                required
                            >
                        </div>
                        <?php if (isset($errors['degree_name'])): ?>
                            <span class="field-error"><?= e($errors['degree_name']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Row 3: Course/Branch & Academic Year -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="branch_name" class="form-label">
                            Course / Branch <span class="req">*</span>
                        </label>
                        <div class="input-wrapper">
                            <span class="input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                            </span>
                            <input 
                                type="text" 
                                id="branch_name" 
                                name="branch_name" 
                                class="form-input <?= isset($errors['branch_name']) ? 'is-invalid' : '' ?>" 
                                placeholder="e.g. Computer Science & Engineering"
                                value="<?= e($formData['branch_name']) ?>"
                                required
                            >
                        </div>
                        <?php if (isset($errors['branch_name'])): ?>
                            <span class="field-error"><?= e($errors['branch_name']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="academic_year" class="form-label">
                            Academic / Study Year <span class="req">*</span>
                        </label>
                        <div class="input-wrapper">
                            <span class="input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            </span>
                            <select 
                                id="academic_year" 
                                name="academic_year" 
                                class="form-select <?= isset($errors['academic_year']) ? 'is-invalid' : '' ?>"
                                required
                            >
                                <option value="">Select current year</option>
                                <option value="1" <?= $formData['academic_year'] === '1' ? 'selected' : '' ?>>1st Year (Freshman)</option>
                                <option value="2" <?= $formData['academic_year'] === '2' ? 'selected' : '' ?>>2nd Year (Sophomore)</option>
                                <option value="3" <?= $formData['academic_year'] === '3' ? 'selected' : '' ?>>3rd Year (Junior)</option>
                                <option value="4" <?= $formData['academic_year'] === '4' ? 'selected' : '' ?>>4th Year (Senior)</option>
                                <option value="5" <?= $formData['academic_year'] === '5' ? 'selected' : '' ?>>5th Year / Postgraduate</option>
                            </select>
                        </div>
                        <?php if (isset($errors['academic_year'])): ?>
                            <span class="field-error"><?= e($errors['academic_year']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Row 4: Password & Confirm Password -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="password" class="form-label">
                            Password <span class="req">*</span>
                        </label>
                        <div class="input-wrapper">
                            <span class="input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                            </span>
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                class="form-input <?= isset($errors['password']) ? 'is-invalid' : '' ?>" 
                                placeholder="At least 8 characters"
                                required
                                autocomplete="new-password"
                            >
                            <button type="button" class="password-toggle-btn" data-target="password" aria-label="Show password">
                                <svg class="icon-eye" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                <svg class="icon-eye-off" viewBox="0 0 24 24" style="display: none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                            </button>
                        </div>
                        <?php if (isset($errors['password'])): ?>
                            <span class="field-error"><?= e($errors['password']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="form-label">
                            Confirm Password <span class="req">*</span>
                        </label>
                        <div class="input-wrapper">
                            <span class="input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                            </span>
                            <input 
                                type="password" 
                                id="confirm_password" 
                                name="confirm_password" 
                                class="form-input <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" 
                                placeholder="Re-enter your password"
                                required
                                autocomplete="new-password"
                            >
                            <button type="button" class="password-toggle-btn" data-target="confirm_password" aria-label="Show password">
                                <svg class="icon-eye" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                <svg class="icon-eye-off" viewBox="0 0 24 24" style="display: none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                            </button>
                        </div>
                        <span class="field-error" id="confirm_password_error" style="<?= isset($errors['confirm_password']) ? '' : 'display:none;' ?>">
                            <?= e($errors['confirm_password'] ?? '') ?>
                        </span>
                    </div>
                </div>

                <!-- Terms & Conditions -->
                <div class="form-group" style="margin-top: 4px;">
                    <label class="checkbox-container">
                        <input type="checkbox" name="terms" value="1" required id="termsCheckbox" <?= isset($_POST['terms']) ? 'checked' : '' ?>>
                        <span class="checkbox-label">
                            I agree to the CareerAct <a href="#" onclick="event.preventDefault(); alert('By signing up, you agree to record authentic extracurricular activities and maintain academic integrity.');">Terms of Service</a> & <a href="#" onclick="event.preventDefault(); alert('Your profile and assessment details remain confidential according to your privacy settings.');">Privacy Policy</a>.
                        </span>
                    </label>
                    <?php if (isset($errors['terms'])): ?>
                        <span class="field-error"><?= e($errors['terms']) ?></span>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn-primary" id="signupSubmitBtn">
                    <span>Create Account</span>
                    <svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </button>
            </form>

            <footer class="form-footer">
                Already have an account? <a href="login.php" id="loginRedirectLink">Sign in.</a>
            </footer>
        </main>
    </div>
</div>

<script src="assets/js/auth.js"></script>
</body>
</html>
