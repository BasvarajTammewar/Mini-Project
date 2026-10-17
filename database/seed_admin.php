<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();

$stmt = $db->prepare('SELECT user_id FROM users WHERE email = ?');
$stmt->execute(['admin@careeract.edu']);
if (!$stmt->fetch()) {
    $roleId = $db->query("SELECT role_id FROM roles WHERE role_code = 'admin'")->fetchColumn();
    $statusId = $db->query("SELECT account_status_id FROM account_statuses WHERE status_code = 'active'")->fetchColumn();
    $hash = password_hash('Admin@123', PASSWORD_DEFAULT);
    $ins = $db->prepare('INSERT INTO users (role_id, account_status_id, email, password_hash, email_verified_at, created_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
    $ins->execute([$roleId, $statusId, 'admin@careeract.edu', $hash]);
    echo "Admin created successfully!\n";
} else {
    echo "Admin already exists!\n";
}
