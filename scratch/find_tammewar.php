<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();
$stmt = $db->query("SELECT id, name, email, role FROM users WHERE name LIKE '%Tammewar%' OR email LIKE '%tammewar%'");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);

