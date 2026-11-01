<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();

$stmt = $db->query("SELECT id, name, country_id FROM cities WHERE name IN ('Pune', 'Mumbai', 'Bengaluru', 'Bangalore', 'Delhi', 'New Delhi', 'Hyderabad') OR country_id = (SELECT id FROM countries WHERE code = 'IND' OR name LIKE '%India%' LIMIT 1)");
$cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($cities);

