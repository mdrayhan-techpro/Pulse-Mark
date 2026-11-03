<?php
require_once __DIR__ . '/config/db.php';
$pdo = getDB();
if (!$pdo) {
    echo "DB connection failed\n";
    exit(1);
}

// Fetch all registered biometric entries for ACTIVE employees only
$stmt = $pdo->query("SELECT b.emp_id, b.template 
                     FROM `biometric_entries` b 
                     JOIN `employees` e ON b.emp_id = e.emp_id 
                     WHERE b.template IS NOT NULL AND b.template != '' 
                     AND (e.status = 'Active' OR e.status IS NULL)");
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$jsonFile = __DIR__ . '/enrolled_templates.json';
$newData = [];
foreach ($entries as $row) {
    $emp_id = $row['emp_id'];
    $tmpl = $row['template'];
    if (!isset($newData[$emp_id])) {
        $newData[$emp_id] = [];
    }
    if (!in_array($tmpl, $newData[$emp_id])) {
        $newData[$emp_id][] = $tmpl;
    }
}

file_put_contents($jsonFile, json_encode($newData, JSON_PRETTY_PRINT));
echo "Enrolled templates synchronized successfully! Active keys: " . implode(', ', array_keys($newData)) . "\n";

// Trigger bridge reload
$ctx = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
$res = @file_get_contents('http://127.0.0.1:9000/sync_templates', false, $ctx);
echo "Bridge reload response: " . ($res ?: 'offline') . "\n";
