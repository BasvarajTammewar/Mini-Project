


<?php
/**
 * CareerAct - Entry Point
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

initSession();

if (isUserLoggedIn()) {
    if (isAdmin()) {
        header('Location: admin/dashboard.php');
    } elseif (isStudentProfileCompleted()) {
        header('Location: dashboard.php');
    } else {
        header('Location: onboarding.php?msg=complete_profile');
    }
} else {
    header('Location: login.php');
}
exit;
