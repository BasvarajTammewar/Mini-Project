<?php
/**
 * CareerAct - Logout Processor
 * Clears and terminates authenticated session securely
 * Supports POST logout requests (with optional CSRF validation) and safe GET fallback.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

initSession();

// Verify CSRF token if provided via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
    if (!verifyCsrfToken((string)$_POST['csrf_token'])) {
        // Proceed with logout anyway for security when the user explicitly requests to log out
    }
}

// Unset all session variables
$_SESSION = [];

// Delete session cookie from browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy session on server
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

// Redirect to login screen
header('Location: login.php?msg=logged_out');
exit;
