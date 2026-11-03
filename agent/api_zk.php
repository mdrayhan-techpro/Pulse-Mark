<?php
require_once __DIR__ . '/../config/init.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!isLoggedIn()) {
    echo json_encode(['status' => 'offline', 'connected' => false, 'message' => 'Authentication required']);
    exit;
}
session_write_close();

$action = $_GET['action'] ?? 'status';
$mode = $_GET['mode'] ?? '';
$targetEmpId = $_GET['target_emp_id'] ?? ($_GET['emp_id'] ?? '');

$queryParams = [];
if ($mode !== '') $queryParams['mode'] = $mode;
if ($targetEmpId !== '') $queryParams['target_emp_id'] = $targetEmpId;
$queryString = !empty($queryParams) ? '?' . http_build_query($queryParams) : '';

$captureUrl = 'http://127.0.0.1:9000/capture' . $queryString;

$endpoints = [
    'status' => 'http://127.0.0.1:9000/status',
    'capture' => $captureUrl,
    'reset' => 'http://127.0.0.1:9000/reset',
];

$url = $endpoints[$action] ?? $endpoints['status'];

$ctx = stream_context_create([
    'http' => [
        'timeout' => 0.8,
        'ignore_errors' => true
    ]
]);

$response = @file_get_contents($url, false, $ctx);

if ($response !== false) {
    echo $response;
} else {
    echo json_encode([
        'status' => 'offline',
        'connected' => false,
        'message' => 'Hardware bridge not reachable on port 9000'
    ]);
}
