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
if (($_SESSION['role_code'] ?? '') !== 'admin') {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>403 Forbidden — Admin Access Required | CareerAct</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            body { font-family: 'Inter', sans-serif; background: #F3F6FA; color: #17243B; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .card { background: #fff; padding: 40px; border-radius: 14px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); text-align: center; max-width: 480px; }
            h1 { font-size: 24px; color: #D92D20; margin-bottom: 12px; }
            p { color: #667085; line-height: 1.5; margin-bottom: 24px; }
            .btn { display: inline-block; background: #1769E8; color: #fff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>403 — Unauthorized Access</h1>
            <p>You do not have administrative privileges to view this section. Please return to your student dashboard or sign in with an administrator account.</p>
            <a href="../dashboard.php" class="btn">Return to Student Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}
