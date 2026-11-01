<?php
/**
 * CareerAct — Student & User Login Page
 * Module 1: Authentication and User Management
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';

initSession();

// If already logged in, redirect directly to appropriate dashboard or profile completion
if (isUserLoggedIn()) {
    if (isAdmin()) {
        header('Location: admin/dashboard.php');
    } else {
        if (isStudentProfileCompleted()) {
            header('Location: dashboard.php');
        } else {
            header('Location: onboarding.php?msg=complete_profile');
        }
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
    } elseif ($_GET['msg'] === 'complete_profile') {
        $flashMessage = 'Please complete your student profile before accessing the dashboard.';
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

                // 3. Rate-limiting check using existing login_attempts table (5 attempts within 15 mins)
                $attemptStmt = $db->prepare('
                    SELECT COUNT(*) 
                    FROM login_attempts 
                    WHERE (email = :email OR ip = :ip) 
                      AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                ');
                $attemptStmt->execute([
                    ':email' => $email,
                    ':ip'    => $clientIp,
                ]);
                $recentAttempts = (int)$attemptStmt->fetchColumn();

                if ($recentAttempts >= 5) {
                    $error = 'Too many failed login attempts. For security reasons, please wait 15 minutes before trying again.';
                } else {
                    // 4. Find user record in users table
                    $stmt = $db->prepare('
                        SELECT id, name, email, password_hash, role, account_status
                        FROM users
                        WHERE email = :email
                        LIMIT 1
                    ');
                    $stmt->execute([':email' => $email]);
                    $user = $stmt->fetch();

                    if (!$user) {
                        // Email not found - log attempt
                        $logStmt = $db->prepare('
                            INSERT INTO login_attempts (email, ip, attempted_at)
                            VALUES (:email, :ip, NOW())
                        ');
                        $logStmt->execute([':email' => $email, ':ip' => $clientIp]);

                        $error = 'Invalid email address or password.';
                    } elseif ($user['account_status'] === 'suspended') {
                        $error = 'Your account has been suspended. Please contact the administrator.';
                    } elseif ($user['account_status'] !== 'active') {
                        $error = 'Your account is inactive. Please contact support.';
                    } else {
                        // 5. Verify password using password_verify()
                        if (password_verify($password, $user['password_hash'])) {
                            // Successful authentication
                            
                            // Clear failed attempts for this email and IP
                            $clearAttempts = $db->prepare('DELETE FROM login_attempts WHERE email = :email OR ip = :ip');
                            $clearAttempts->execute([':email' => $email, ':ip' => $clientIp]);

                            // Update last login timestamp
                            $updateStmt = $db->prepare('UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id');
                            $updateStmt->execute([':id' => $user['id']]);

                            // Regenerate session ID to prevent fixation attacks
                            session_regenerate_id(true);

                            // Set Remember Me cookie if requested
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
                            $_SESSION['user_id']      = (int)$user['id'];
                            $_SESSION['email']        = $user['email'];
                            $_SESSION['name']         = $user['name'];
                            $_SESSION['full_name']    = $user['name'];
                            $_SESSION['role']         = $user['role'];
                            $_SESSION['role_code']    = $user['role'];
                            $_SESSION['logged_in_at'] = time();

                            // 6. Role-based and Profile Completion Redirection
                            if ($user['role'] === 'admin') {
                                header('Location: admin/dashboard.php');
                                exit;
                            }

                            // Student Role: Check if profile is complete
                            if (isStudentProfileCompleted((int)$user['id'])) {
                                header('Location: dashboard.php');
                            } else {
                                header('Location: onboarding.php?msg=complete_profile');
                            }
                            exit;
                        } else {
                            // Password mismatch - log failed attempt
                            $logStmt = $db->prepare('
                                INSERT INTO login_attempts (email, ip, attempted_at)
                                VALUES (:email, :ip, NOW())
                            ');
                            $logStmt->execute([':email' => $email, ':ip' => $clientIp]);

                            $error = 'Invalid email address or password.';
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('Login Error: ' . $e->getMessage());
                $error = 'Service temporarily unavailable. Please try again.';
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
    <meta name="description" content="Sign in to CareerAct — Student Extracurricular Activity-Based Career Recommendation System.">
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
                <h2 class="brand-headline">Turn campus activities into <span>career superpowers.</span></h2>
                <p class="brand-description">
                    Access your personalized extracurricular activity roadmap, evaluate skill readiness, and prepare for industry recruitment.
                </p>

                <ul class="brand-features">
                    <li class="brand-feature-item">
                        <div class="feature-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <span>Extracurricular Activity Analysis</span>
                    </li>
                    <li class="brand-feature-item">
                        <div class="feature-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </div>
                        <span>Skill Gap Identification & Recommendations</span>
                    </li>
                    <li class="brand-feature-item">
                        <div class="feature-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                        </div>
                        <span>Career Readiness & Roadmap Planning</span>
                    </li>
                </ul>
            </div>

            <div class="brand-footer-card">
                <p class="brand-footer-text">
                    "CareerAct bridged the gap between what I did outside class and what tech recruiters actually cared about."
                </p>
                <div class="brand-footer-author">— Student, Computer Science</div>
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
                <h1 class="form-title">Welcome Back</h1>
                <p class="form-subtitle">Sign in to your CareerAct account to continue.</p>
            </div>

            <!-- Flash Notification -->
            <?php if (!empty($flashMessage)): ?>
                <div class="alert alert-<?= $flashType ?>" role="status" id="flash-alert">
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

            <!-- Error Notification -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-error" role="alert" id="error-alert">
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

                <!-- Email Input -->
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
                            class="form-input <?= (!empty($error) && empty($emailInput)) ? 'is-invalid' : '' ?>" 
                            placeholder="name@college.edu"
                            value="<?= e($emailInput) ?>"
                            required
                            autocomplete="email"
                            autofocus
                        >
                    </div>
                </div>

                <!-- Password Input -->
                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label for="password" class="form-label" style="margin-bottom: 0;">
                            Password <span class="req">*</span>
                        </label>
                    </div>
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

                <!-- Remember Me -->
                <div class="form-group" style="display: flex; align-items: center; justify-content: space-between;">
                    <label class="checkbox-container">
                        <input type="checkbox" name="remember_me" value="1" id="rememberMe">
                        <span class="checkbox-label">Keep me signed in</span>
                    </label>
                </div>

                <button type="submit" class="btn-primary" id="loginSubmitBtn">
                    <span>Sign In</span>
                    <svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </button>
            </form>

            <footer class="form-footer">
                Don't have an account yet? <a href="signup.php" id="signupRedirectLink">Create student account</a>
            </footer>
        </main>
    </div>
</div>

<script src="assets/js/auth.js"></script>
</body>
</html>
