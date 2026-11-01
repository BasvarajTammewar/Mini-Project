<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();
$stmt = $db->query("SELECT id, name, email FROM users WHERE email LIKE '%priya%' OR role = 'student' LIMIT 1");
$u = $stmt->fetch();
if (!$u) {
    die("No user found\n");
}

session_start();
$_SESSION['user_id'] = $u['id'];
$_SESSION['user_name'] = $u['name'];
$_SESSION['user_email'] = $u['email'];
$_SESSION['user_role'] = 'student';
$_SESSION['profile_completed'] = 1;

ob_start();
include __DIR__ . '/../dashboard.php';
$html = ob_get_clean();

echo "User: " . $u['name'] . " (ID: " . $u['id'] . ")\n";
echo "HTML length: " . strlen($html) . " bytes\n";

// Let's check where the dropdowns and SVGs are
file_put_contents(__DIR__ . '/rendered_dashboard.html', $html);
echo "Rendered HTML saved to scratch/rendered_dashboard.html\n";

