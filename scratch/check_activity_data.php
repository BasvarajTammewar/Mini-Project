<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();

echo "Activity Categories:\n";
$cats = $db->query("SELECT id, code, name FROM activity_categories")->fetchAll(PDO::FETCH_ASSOC);
print_r($cats);

echo "Activities count: " . $db->query("SELECT COUNT(*) FROM activities")->fetchColumn() . "\n";
$acts = $db->query("SELECT a.id, a.category_id, a.title, c.name as category_name FROM activities a LEFT JOIN activity_categories c ON a.category_id = c.id LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
print_r($acts);

echo "Career Activities count: " . $db->query("SELECT COUNT(*) FROM career_activities")->fetchColumn() . "\n";
$ca = $db->query("SELECT ca.career_id, c.title as career_title, a.title as activity_title, ca.relevance FROM career_activities ca JOIN careers c ON ca.career_id = c.id JOIN activities a ON ca.activity_id = a.id LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
print_r($ca);

