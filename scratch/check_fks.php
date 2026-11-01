<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();
$fks = $db->query("
    SELECT 
        TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'opportunities'
")->fetchAll(PDO::FETCH_ASSOC);
print_r($fks);

