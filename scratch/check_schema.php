<?php
require_once __DIR__ . '/../config/db.php';
$pdo = getDbConnection();
$tables = ['careers', 'career_fields', 'career_subfields', 'activities', 'activity_categories', 'career_skills', 'career_activities', 'activity_skills'];
foreach ($tables as $t) {
    echo "=== TABLE $t ===\n";
    $cols = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "{$c['Field']} ({$c['Type']})\n";
    }
}
