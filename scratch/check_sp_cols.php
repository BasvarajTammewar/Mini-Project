<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();
print_r($db->query("DESCRIBE student_profiles")->fetchAll(PDO::FETCH_ASSOC));

