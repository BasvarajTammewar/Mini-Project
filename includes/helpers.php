<?php
/**
 * CareerAct - Helper Functions & Security Utilities
 */

declare(strict_types=1);

/**
 * Initializes a hardened PHP session
 */
function initSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        );

        if (!headers_sent()) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            session_start();
        } else {
            @session_start();
        }
    }
}

/**
 * Generates or retrieves the current CSRF token
 */
function getCsrfToken(): string {
    initSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validates a submitted CSRF token
 */
function verifyCsrfToken(?string $token): bool {
    initSession();
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Escapes strings safely for HTML output
 */
function e(?string $string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Checks whether user is currently authenticated
 */
function isUserLoggedIn(): bool {
    initSession();
    return !empty($_SESSION['user_id']);
}

/**
 * Retrieves the client's IP address
 */
function getClientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if ($ip === '::1') {
        $ip = '127.0.0.1';
    }
    return substr($ip, 0, 45);
}

/**
 * Returns the current authenticated user ID, or null if guest
 */
function getCurrentUserId(): ?int {
    initSession();
    return !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * Returns the current user's role ('student', 'admin', or null)
 */
function getCurrentUserRole(): ?string {
    initSession();
    return $_SESSION['role'] ?? $_SESSION['role_code'] ?? null;
}

/**
 * Checks if current user has the 'student' role
 */
function isStudent(): bool {
    return getCurrentUserRole() === 'student';
}

/**
 * Checks if current user has the 'admin' role
 */
function isAdmin(): bool {
    return getCurrentUserRole() === 'admin';
}

/**
 * Enforces that user is authenticated; redirects to login if not
 */
function requireAuth(string $redirect = 'login.php?msg=session_required'): void {
    if (!isUserLoggedIn()) {
        header("Location: {$redirect}");
        exit;
    }
}

/**
 * Enforces that user has the Student role
 */
function requireStudent(string $redirect = 'admin/dashboard.php'): void {
    requireAuth();
    if (!isStudent()) {
        header("Location: {$redirect}");
        exit;
    }
}

/**
 * Enforces that user has the Admin role; returns 403 or redirects
 */
function requireAdmin(): void {
    requireAuth();
    if (!isAdmin()) {
        http_response_code(403);
        include __DIR__ . '/../admin/unauthorized.php';
        exit;
    }
}

/**
 * Checks whether a student has completed their profile
 * Uses the existing database structure: student_profiles.profile_completed
 */
function isStudentProfileCompleted(?int $userId = null): bool {
    if ($userId === null) {
        $userId = getCurrentUserId();
    }
    if (!$userId) {
        return false;
    }

    require_once __DIR__ . '/../config/db.php';
    try {
        $db = getDbConnection();
        $stmt = $db->prepare('SELECT profile_completed FROM student_profiles WHERE user_id = :user_id LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();
        if ($row && !empty($row['profile_completed'])) {
            return true;
        }
        return false;
    } catch (Throwable $e) {
        error_log('Error checking profile completion: ' . $e->getMessage());
        return false;
    }
}

/**
 * Ensures student has completed their profile before accessing dashboard
 * Redirects incomplete students to the onboarding wizard
 */
function requireCompletedProfile(string $redirect = 'onboarding.php?msg=complete_profile'): void {
    requireAuth();
    if (isStudent() && !isStudentProfileCompleted()) {
        header("Location: {$redirect}");
        exit;
    }
}
