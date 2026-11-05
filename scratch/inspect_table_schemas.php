<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();

$tablesToInspect = [
    'career_fields', 'career_subfields', 'careers', 'career_skills',
    'skills', 'skill_categories', 'activities', 'activity_skills',
    'student_education', 'student_profiles'
];

foreach ($tablesToInspect as $t) {
    echo "=== SCHEMA OF `$t` ===\n";
    $cols = $db->query("SHOW FULL COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['Field']} - {$c['Type']} - NULL:{$c['Null']} - DEF:{$c['Default']} - KEY:{$c['Key']}\n";
    }
    echo "\n";
}
