<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();
$cols = $db->query("SHOW FULL COLUMNS FROM `career_activities`")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "  {$c['Field']} - {$c['Type']}\n";
}
