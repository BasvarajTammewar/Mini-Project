<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();

$hasProviders = $db->query("SHOW TABLES LIKE 'providers'")->rowCount() > 0;
echo "Has providers: " . ($hasProviders ? "YES" : "NO") . "\n";
if ($hasProviders) {
    print_r($db->query("SELECT id, name FROM providers LIMIT 10")->fetchAll(PDO::FETCH_ASSOC));
}

$hasSources = $db->query("SHOW TABLES LIKE 'sources'")->rowCount() > 0;
echo "Has sources: " . ($hasSources ? "YES" : "NO") . "\n";
if ($hasSources) {
    print_r($db->query("SELECT id, name FROM sources LIMIT 10")->fetchAll(PDO::FETCH_ASSOC));
}

