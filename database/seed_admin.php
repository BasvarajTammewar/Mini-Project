<?php
/**
 * CareerAct — Seed Administrator Account
 * Safely creates an authorized admin user if one does not exist.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();

$adminEmail = 'admin@careeract.edu';
$adminPass  = 'Admin@123';

$stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$adminEmail]);

if (!$stmt->fetch()) {
    $hash = password_hash($adminPass, PASSWORD_DEFAULT);
    $ins = $db->prepare('
        INSERT INTO users (name, email, password_hash, role, account_status, is_demo, created_at, updated_at) 
        VALUES (?, ?, ?, \'admin\', \'active\', 0, NOW(), NOW())
    ');
    $ins->execute(['System Administrator', $adminEmail, $hash]);
    echo "Admin account [{$adminEmail}] created successfully!\n";
} else {
    echo "Admin account [{$adminEmail}] already exists.\n";
}
