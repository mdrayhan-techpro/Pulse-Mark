<?php
require_once __DIR__ . '/config/db.php';
$pdo = getDB();
if (!$pdo) {
    echo "No DB\n";
    exit;
}
echo "--- BIOMETRIC ENTRIES ---\n";
$stmt = $pdo->query("SELECT id, emp_id, finger_type, quality_score, status, created_at FROM `biometric_entries`");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- EMPLOYEES ---\n";
$stmt2 = $pdo->query("SELECT id, emp_id, name FROM `employees`");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- ENROLLED TEMPLATES JSON KEYS ---\n";
$json = json_decode(file_get_contents(__DIR__ . '/enrolled_templates.json'), true);
print_r(array_keys($json ?: []));
