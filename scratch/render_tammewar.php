<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();

session_start();
$_SESSION['user_id'] = 9;
$_SESSION['user_name'] = 'Basvaraj Tammewar';
$_SESSION['user_email'] = 'tammewarbasvraj@gmail.com';
$_SESSION['user_role'] = 'student';
$_SESSION['profile_completed'] = 1;

ob_start();
include __DIR__ . '/../dashboard.php';
$html = ob_get_clean();

file_put_contents(__DIR__ . '/tammewar_dashboard.html', $html);
echo "Generated tammewar_dashboard.html (" . strlen($html) . " bytes)\n";

