<?php
/**
 * CareerAct - Authentication Guard
 * Include this at the top of any page that requires a logged-in user.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

initSession();

if (!isUserLoggedIn()) {
    // Redirect to login page
    $currentUrl = $_SERVER['REQUEST_URI'] ?? 'index.php';
    header('Location: login.php?msg=session_required');
    exit;
}
