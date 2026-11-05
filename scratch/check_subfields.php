<?php
require_once __DIR__ . '/../config/db.php';
$db = getDbConnection();
$subfields = $db->query("SELECT cs.*, cf.name as field_name FROM career_subfields cs JOIN career_fields cf ON cs.field_id = cf.id ORDER BY cf.id, cs.id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($subfields as $sf) {
    echo "Field: {$sf['field_name']} (ID: {$sf['field_id']}) -> Subfield: {$sf['name']} (ID: {$sf['id']})\n";
}
