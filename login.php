<?php
/**
 * CareerAct — Student & User Login Page
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';

initSession();

// If already logged in, redirect directly to appropriate dashboard
if (isUserLoggedIn()) {
    if (($_SESSION['role_code'] ?? '') === 'admin') {
        header('Location: admin/dashboard.php');
    } else {
        header('Location: dashboard.php');
    }
    exit;
}

$error = '';
$emailInput = '';
$clientIp = getClientIp();

// Flash message banners
$flashMessage = '';
$flashType = 'info';

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'registered') {
        $flashMessage = 'Account created successfully! You can now sign in with your credentials.';
        $flashType = 'success';
    } elseif ($_GET['msg'] === 'logged_out') {
        $flashMessage = 'You have been securely logged out.';
        $flashType = 'info';
    } elseif ($_GET['msg'] === 'session_required') {
        $flashMessage = 'Please sign in to access the requested page.';
        $flashType = 'info';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify CSRF Token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $error = 'Your session expired or form token was invalid. Please try again.';
    } else {
        $email      = strtolower(trim((string)($_POST['email'] ?? '')));
        $password   = (string)($_POST['password'] ?? '');
        $rememberMe = isset($_POST['remember_me']);

        $emailInput = $email;

        // 2. Validate input format
        if (empty($email) || empty($password)) {
            $error = 'Please enter both your email address and password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address or password.';
        } else {
            try {
                $db = getDbConnection();

                // 3. Find user record with role, account status, and student profile
                $stmt = $db->prepare("
                    SELECT 
                        u.user_id,
                        u.role_id,
                        u.account_status_id,
                        u.email,
                        u.password_hash,
                        u.failed_login_count,
                        u.locked_until,
                        r.role_code,
                        r.role_name,
                        s.status_code,
                        s.status_name,
                        sp.student_id,
                        sp.full_name,
                        sp.college_name,
                        sp.degree_name,
                        sp.branch_name,
                        sp.academic_year,
                        sp.profile_completed_at
                    FROM users u
                    JOIN roles r ON u.role_id = r.role_id
                    JOIN account_statuses s ON u.account_status_id = s.account_status_id
                    LEFT JOIN student_profiles sp ON u.user_id = sp.user_id
                    WHERE u.email = :email
                    LIMIT 1
                ");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch();

                // 4. Rate-limiting & Temporary Lockout Check
                if ($user) {
                    $isLocked = false;
                    if (!empty($user['locked_until'])) {
                        $lockExpiration = strtotime($user['locked_until']);
                        if ($lockExpiration > time()) {
                            $isLocked = true;
                            $minutesLeft = max(1, (int)ceil(($lockExpiration - time()) / 60));
                            $error = "Account is temporarily locked due to multiple failed login attempts. Please try again in {$minutesLeft} minute(s).";
                        }
                    }

                    if ($isLocked) {
                        // Log locked attempt
                        $logStmt = $db->prepare("
                            INSERT INTO login_attempts (user_id, email_tried, ip_address, was_success, attempted_at)
                            VALUES (:user_id, :email, :ip, 0, NOW())
                        ");
                        $logStmt->execute([
                            ':user_id' => $user['user_id'],
                            ':email'   => $email,
                            ':ip'      => $clientIp
                        ]);
                    } elseif ($user['status_code'] === 'suspended') {
                        $error = 'Your account has been suspended. Please contact support or your institution administrator.';
                    } elseif ($user['status_code'] === 'locked') {
                        $error = 'Your account is locked. Please contact the administrator to unlock your profile.';
                    } elseif ($user['status_code'] === 'deleted') {
                        $error = 'This account has been deactivated or removed.';
                    } elseif ($user['status_code'] === 'pending_verification') {
                        $error = 'Your account is pending email verification. Please verify your email before signing in.';
                    } else {
                        // 5. Verify Password
                        if (password_verify($password, $user['password_hash'])) {
                            // Successful authentication
                            
                            // Reset failed login counter and update last login timestamp
                            $updateStmt = $db->prepare("
                                UPDATE users
                                SET failed_login_count = 0,
                                    locked_until = NULL,
                                    last_login_at = NOW()
                                WHERE user_id = :user_id
                            ");
                            $updateStmt->execute([':user_id' => $user['user_id']]);

                            // Log successful attempt
                            $logStmt = $db->prepare("
                                INSERT INTO login_attempts (user_id, email_tried, ip_address, was_success, attempted_at)
                                VALUES (:user_id, :email, :ip, 1, NOW())
                            ");
                            $logStmt->execute([
                                ':user_id' => $user['user_id'],
                                ':email'   => $email,
                                ':ip'      => $clientIp
                            ]);

                            // Regenerate session ID to prevent fixation
                            session_regenerate_id(true);

                            // Set Remember Me cookie lifetime if requested
                            if ($rememberMe) {
                                $cookieLifetime = 30 * 24 * 3600; // 30 days
                                $params = session_get_cookie_params();
                                setcookie(
                                    session_name(),
                                    session_id(),
                                    time() + $cookieLifetime,
                                    $params['path'],
                                    $params['domain'],
                                    $params['secure'],
                                    $params['httponly']
                                );
                            }

                            // Store only safe session state
                            $_SESSION['user_id']       = (int)$user['user_id'];
                            $_SESSION['email']         = $user['email'];
                            $_SESSION['role_code']     = $user['role_code'];
                            $_SESSION['role_name']     = $user['role_name'];
                            $_SESSION['student_id']    = $user['student_id'] ? (int)$user['student_id'] : null;
                            $_SESSION['full_name']     = $user['full_name'] ?? 'Student';
                            $_SESSION['college_name']  = $user['college_name'] ?? '';
                            $_SESSION['degree_name']   = $user['degree_name'] ?? '';
                            $_SESSION['branch_name']   = $user['branch_name'] ?? '';
                            $_SESSION['academic_year'] = $user['academic_year'] ?? '';
                            $_SESSION['logged_in_at']  = time();

                            // Redirect to destination: Admin -> Admin Dashboard, Incomplete Student -> Onboarding, Completed Student -> Dashboard
                            if ($user['role_code'] === 'admin') {
                                header('Location: admin/dashboard.php');
                                exit;
                            }

                            if (empty($user['profile_completed_at'])) {
                                header('Location: onboarding.php?step=1');
                                exit;
                            }

                            header('Location: dashboard.php');
                            exit;
                        } else {
                            // Incorrect password - Increment failure count
                            $newFailCount = (int)$user['failed_login_count'] + 1;
                            $lockUntil = null;
                            
                            // If >= 5 consecutive failures, lock for 15 minutes
                            if ($newFailCount >= 5) {
                                $lockUntil = date('Y-m-d H:i:s', time() + 15 * 60);
                            }

                            $updateStmt = $db->prepare("
                                UPDATE users
                                SET failed_login_count = :count,
                                    locked_until = :locked_until
                                WHERE user_id = :user_id
                            ");
                            $updateStmt->execute([
                                ':count'        => $newFailCount,
                                ':locked_until' => $lockUntil,
                                ':user_id'      => $user['user_id']
                            ]);

                            // Log failed attempt
                            $logStmt = $db->prepare("
                                INSERT INTO login_attempts (user_id, email_tried, ip_address, was_success, attempted_at)
                                VALUES (:user_id, :email, :ip, 0, NOW())
                            ");
                            $logStmt->execute([
                                ':user_id' => $user['user_id'],
                                ':email'   => $email,
                                ':ip'      => $clientIp
                            ]);

                            // Generic error message for security
                            $error = 'Invalid email address or password.';
                        }
                    }
                } else {
                    // Unknown email - Log failed attempt without revealing user non-existence
                    $logStmt = $db->prepare("
                        INSERT INTO login_attempts (user_id, email_tried, ip_address, was_success, attempted_at)
                        VALUES (NULL, :email, :ip, 0, NOW())
                    ");
                    $logStmt->execute([
                        ':email' => $email,
                        ':ip'    => $clientIp
                    ]);

                    $error = 'Invalid email address or password.';
                }
            } catch (Exception $e) {
                error_log('Login Error: ' . $e->getMessage());
                $error = 'Unable to complete sign-in right now. Please try again.';
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
    <title>Sign In — CareerAct</title>
    <meta name="description" content="Sign in to CareerAct — Student Career & Extracurricular Activity Recommendation System.">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
<div class="auth-page">
    <div class="auth-container">
        
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
                <h2 class="brand-headline">Your journey to a <span>stellar career</span> starts here.</h2>
                <p class="brand-description">
                    Discover handpicked extracurricular activities, align skill benchmarks, and build a competitive profile designed for top campus placements.
                </p>

                <ul class="brand-features">
                    <li class="brand-feature-item">
                        <div class="feature-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        </div>
                        <span>Targeted Career Trajectory Recommendations</span>
                    </li>
                    <li class="brand-feature-item">
                        <div class="feature-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        </div>
                        <span>Verified Activity & Extracurricular Records</span>
                    </li>
                    <li class="brand-feature-item">
                        <div class="feature-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                        </div>
                        <span>Real-Time Skill Gap Assessments</span>
                    </li>
                </ul>
            </div>

            <div class="brand-footer-card">
                <p class="brand-footer-text">
                    "Setting my career target in CareerAct showed me exactly which hackathons and certifications to pursue this semester."
                </p>
                <div class="brand-footer-author">— Student Ambassador, CareerAct</div>
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
                <h1 class="form-title">Welcome Back!</h1>
                <p class="form-subtitle">Continue building your career, one activity at a time.</p>
            </div>

            <!-- Flash Alert (Registration Success / Logout) -->
            <?php if (!empty($flashMessage)): ?>
                <div class="alert alert-<?= e($flashType) ?>" role="status" id="flash-alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <?php if ($flashType === 'success'): ?>
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        <?php else: ?>
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        <?php endif; ?>
                    </svg>
                    <span><?= e($flashMessage) ?></span>
                </div>
            <?php endif; ?>

            <!-- Error Alert -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-error" role="alert" id="login-error-alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="auth-form" id="loginForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                <!-- Email Field -->
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
                            class="form-input" 
                            placeholder="Enter your registered email"
                            value="<?= e($emailInput) ?>"
                            required 
                            autocomplete="email"
                            autofocus
                        >
                    </div>
                </div>

                <!-- Password Field -->
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
                            class="form-input" 
                            placeholder="Enter your password"
                            required
                            autocomplete="current-password"
                        >
                        <button type="button" class="password-toggle-btn" data-target="password" aria-label="Show password">
                            <svg class="icon-eye" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            <svg class="icon-eye-off" viewBox="0 0 24 24" style="display: none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                        </button>
                    </div>
                </div>

                <!-- Remember Me Option -->
                <div class="form-options">
                    <label class="checkbox-container">
                        <input type="checkbox" name="remember_me" value="1" id="rememberMeCheckbox">
                        <span class="checkbox-label">Remember me on this device</span>
                    </label>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-primary" id="loginSubmitBtn">
                    <span>Sign In</span>
                    <svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </button>
            </form>

            <footer class="form-footer">
                Don't have an account? <a href="signup.php" id="signupRedirectLink">Create an account.</a>
            </footer>
        </main>
    </div>
</div>

<script src="assets/js/auth.js"></script>
</body>
</html>
