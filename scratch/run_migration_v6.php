<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();

$sql = file_get_contents(__DIR__ . '/../database/migrations_v6_events.sql');
$db->exec($sql);
echo "Migration v6 executed successfully!\n";

// Verify
$count = $db->query("SELECT COUNT(*) FROM opportunities WHERE status = 'published'")->fetchColumn();
echo "Active published opportunities in database: $count\n";

$cities = $db->query("SELECT id, name FROM cities WHERE name IN ('Pune', 'Mumbai', 'Bengaluru', 'New Delhi', 'Hyderabad')")->fetchAll(PDO::FETCH_ASSOC);
echo "Cities in database:\n";
print_r($cities);

