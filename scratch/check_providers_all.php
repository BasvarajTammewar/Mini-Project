<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();
print_r($db->query("SELECT * FROM providers")->fetchAll(PDO::FETCH_ASSOC));

