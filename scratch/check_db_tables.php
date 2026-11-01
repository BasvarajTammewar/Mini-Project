<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();
$tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "Matching tables in database:\n";
foreach ($tables as $t) {
    if (preg_match('/activ|event|opp|city|locat|enroll|career/i', $t)) {
        echo " - $t\n";
    }
}

