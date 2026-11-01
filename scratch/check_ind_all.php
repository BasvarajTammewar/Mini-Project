<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();
$allIndianCities = $db->query("SELECT * FROM cities WHERE country_id = 37")->fetchAll(PDO::FETCH_ASSOC);
print_r($allIndianCities);

