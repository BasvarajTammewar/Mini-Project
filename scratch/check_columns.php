<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();

$tables = ['opportunities', 'activities', 'activity_categories', 'career_activities', 'enrollments'];
foreach ($tables as $table) {
    echo "=== Table: $table ===\n";
    $cols = $db->query("DESCRIBE $table")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['Field']} - {$c['Type']} - Null:{$c['Null']} - Key:{$c['Key']} - Def:{$c['Default']}\n";
    }
}

echo "=== View: v_active_opportunities ===\n";
$create = $db->query("SHOW CREATE VIEW v_active_opportunities")->fetch(PDO::FETCH_ASSOC);
print_r($create);

