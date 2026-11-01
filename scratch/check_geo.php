<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();
print_r($db->query("DESCRIBE countries")->fetchAll(PDO::FETCH_ASSOC));
print_r($db->query("DESCRIBE cities")->fetchAll(PDO::FETCH_ASSOC));
print_r($db->query("SELECT * FROM countries WHERE name LIKE '%India%'")->fetchAll(PDO::FETCH_ASSOC));
print_r($db->query("SELECT * FROM cities WHERE name LIKE '%Pune%' OR name LIKE '%Mumbai%' OR name LIKE '%Bengaluru%' OR name LIKE '%Bangalore%' OR name LIKE '%Delhi%' OR name LIKE '%Hyderabad%'")->fetchAll(PDO::FETCH_ASSOC));

