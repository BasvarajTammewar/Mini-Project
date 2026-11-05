<?php
require_once __DIR__ . '/../config/db.php';
try {
    $db = getDbConnection();
    echo "DB CONNECTED: " . $db->query('SELECT DATABASE()')->fetchColumn() . "\n";
} catch (Exception $e) {
    echo "DB ERROR: " . $e->getMessage() . "\n";
}
