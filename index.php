<?php
/**
 * CareerAct - Entry Point
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

initSession();

if (isUserLoggedIn()) {
    if (($_SESSION['role_code'] ?? '') === 'admin') {
        header('Location: admin/dashboard.php');
    } else {
        header('Location: dashboard.php');
    }
} else {
    header('Location: login.php');
}
exit;
