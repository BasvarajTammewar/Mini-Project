<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();
$existingSkills = $db->query("SELECT id, category_id, code, name FROM skills ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($existingSkills as $s) {
    echo "ID: {$s['id']} | Code: {$s['code']} | Name: {$s['name']} (Category ID: {$s['category_id']})\n";
}
