<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();

$hasCities = $db->query("SHOW TABLES LIKE 'cities'")->rowCount() > 0;
echo "Has cities table: " . ($hasCities ? "YES" : "NO") . "\n";
if ($hasCities) {
    $cities = $db->query("SELECT id, name FROM cities LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    print_r($cities);
}

$oppCount = $db->query("SELECT COUNT(*) FROM opportunities")->fetchColumn();
echo "Total opportunities: $oppCount\n";
if ($oppCount > 0) {
    $sampleOpp = $db->query("SELECT id, title, delivery_mode, city_id, status, registration_deadline, registration_url FROM opportunities LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    print_r($sampleOpp);
}

