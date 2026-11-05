<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();
$careers = $db->query("SELECT c.id, c.title, cs.name as subfield_name, cf.name as field_name FROM careers c JOIN career_subfields cs ON c.subfield_id = cs.id JOIN career_fields cf ON cs.field_id = cf.id ORDER BY cf.id, c.id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($careers as $c) {
    echo "ID: {$c['id']} | {$c['title']} (Field: {$c['field_name']} / {$c['subfield_name']})\n";
}
