<?php
/**
 * CareerAct - Logout Processor
 * Clears and terminates authenticated session securely
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

initSession();

// Unset all session keys
$_SESSION = [];

// Delete session cookie from browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy session on server
session_destroy();

// Redirect to login screen
header('Location: login.php?msg=logged_out');
exit;
