<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();
print_r($db->query("SELECT id, name FROM data_sources")->fetchAll(PDO::FETCH_ASSOC));

