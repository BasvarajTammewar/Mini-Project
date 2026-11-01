<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();
$acts = $db->query("SELECT id, category_id, title, delivery_mode, difficulty FROM activities ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($acts as $a) {
    echo "ID {$a['id']} [Cat:{$a['category_id']}]: {$a['title']} ({$a['delivery_mode']})\n";
}

