<?php
/**
 * CareerAct — Admin Authentication & Authorization Guard
 * Ensures only users with role_code = 'admin' can access admin management pages.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

initSession();

// 1. Must be logged in
if (!isUserLoggedIn()) {
    header('Location: ../login.php?msg=session_required');
    exit;
}

// 2. Must possess admin role
if (!isAdmin()) {
    http_response_code(403);
    require __DIR__ . '/unauthorized.php';
    exit;
}
