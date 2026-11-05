<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();

echo "=== TABLES IN CARREARACT2 ===\n";
$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    $cnt = $db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo "- $t ($cnt rows)\n";
}

echo "\n=== CAREER FIELDS ===\n";
$fields = $db->query("SELECT * FROM career_fields")->fetchAll(PDO::FETCH_ASSOC);
print_r($fields);

echo "\n=== CAREER SUBFIELDS ===\n";
$subfields = $db->query("SELECT * FROM career_subfields")->fetchAll(PDO::FETCH_ASSOC);
print_r($subfields);

echo "\n=== SKILL CATEGORIES ===\n";
$skillCats = $db->query("SELECT * FROM skill_categories")->fetchAll(PDO::FETCH_ASSOC);
print_r($skillCats);

echo "\n=== ACTIVITY CATEGORIES ===\n";
$actCats = $db->query("SELECT * FROM activity_categories")->fetchAll(PDO::FETCH_ASSOC);
print_r($actCats);
