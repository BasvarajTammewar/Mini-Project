<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();

$sql = file_get_contents(__DIR__ . '/../database/migrations_v13_biotechnology.sql');
$db->exec($sql);
echo "Migration V13 applied successfully to carrearact2!\n";

$tables = ['biotechnology_education_pathways', 'biotech_skill_details', 'biotech_career_details', 'biotech_activity_details'];
foreach ($tables as $t) {
    $exists = $db->query("SHOW TABLES LIKE '$t'")->fetch();
    echo "- Table $t: " . ($exists ? "EXISTS" : "MISSING") . "\n";
}
