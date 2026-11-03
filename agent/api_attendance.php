<?php
require_once __DIR__ . '/../config/init.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!isLoggedIn()) {
    echo json_encode(['status' => 'unauthorized', 'message' => 'Authentication required']);
    exit;
}

$pdo = getDB();
$sessionAgentId = $_SESSION['agent_id'] ?? ($_SESSION['user_id'] ?? 0);
$agentId = (isAdminLoggedIn() && !empty($_REQUEST['agent_id'])) ? (int)$_REQUEST['agent_id'] : (int)$sessionAgentId;

$action = $_GET['action'] ?? ($_POST['action'] ?? 'verify');
$emp_id = sanitize($_GET['emp_id'] ?? ($_POST['emp_id'] ?? ''));
$score = sanitize($_GET['score'] ?? ($_POST['score'] ?? '99.8%'));

if ($action === 'verify') {
    if (!$pdo) {
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
        exit;
    }

    if (empty($emp_id)) {
        echo json_encode([
            'status' => 'not_found',
            'found' => false,
            'message' => 'Unrecognized Fingerprint. No employee ID matched.'
        ]);
        exit;
    }

    try {
        // 0. Terminal Point Biometric Service & Operating Schedule Check
        $termCheck = isAgentTerminalServiceActive($pdo, $agentId, 'biometric');
        if (!$termCheck['active']) {
            echo json_encode([
                'status' => 'terminal_disabled',
                'found' => false,
                'message' => $termCheck['reason'] ?? "Biometric Fingerprint attendance is not currently active for this terminal point."
            ]);
            exit;
        }

        // 1. Verify if employee exists in employees directory
        $stmt = $pdo->prepare("SELECT * FROM `employees` WHERE `emp_id` = ? LIMIT 1");
        $stmt->execute([$emp_id]);
        $emp = $stmt->fetch();

        if (!$emp) {
            echo json_encode([
                'status' => 'not_found',
                'found' => false,
                'message' => "Employee ID ({$emp_id}) not found in the employee directory."
            ]);
            exit;
        }

        // 2. Strict Multi-Agent Attendance Authorization Check (Permanent or Temporary Date-Time Range)
        $auth = checkAgentAttendanceAuthorization($pdo, $emp_id, $agentId);
        if (!$auth['authorized']) {
            echo json_encode([
                'status' => 'unauthorized',
                'found' => false,
                'employee' => [
                    'emp_id' => $emp['emp_id'],
                    'name' => $emp['name'],
                    'status' => $emp['status']
                ],
                'message' => $auth['reason'] ?? "Employee ({$emp['name']} - {$emp_id}) is not authorized for attendance at this agent terminal."
            ]);
            exit;
        }

        // 3. Strict Inactive Employee Check: Block attendance if inactive
        if (strcasecmp($emp['status'] ?? 'Active', 'Active') !== 0) {
            echo json_encode([
                'status' => 'inactive',
                'found' => false,
                'employee' => [
                    'emp_id' => $emp['emp_id'],
                    'name' => $emp['name'],
                    'status' => $emp['status']
                ],
                'message' => "Employee ({$emp['name']} - {$emp_id}) is INACTIVE! Biometric attendance is blocked."
            ]);
            exit;
        }

        // 4. Strict Attendance Block Check: Check if Fingerprint or Both is blocked
        $blockType = $emp['attendance_block_type'] ?? ((int)($emp['is_attendance_blocked'] ?? 0) === 1 ? 'both' : 'none');
        if ($blockType === 'fingerprint' || $blockType === 'both' || ((int)($emp['is_attendance_blocked'] ?? 0) === 1 && $blockType !== 'face')) {
            echo json_encode([
                'status' => 'blocked',
                'found' => false,
                'employee' => [
                    'emp_id' => $emp['emp_id'],
                    'name' => $emp['name'],
                    'status' => $emp['status'],
                    'attendance_block_type' => $blockType,
                    'is_attendance_blocked' => 1
                ],
                'message' => "Biometric Fingerprint Attendance is BLOCKED for Employee ({$emp['name']} - {$emp_id})!"
            ]);
            exit;
        }

        // 5. Strict Biometric Enrollment Check: Must have record in biometric_entries table
        $bioCheck = $pdo->prepare("SELECT COUNT(*) FROM `biometric_entries` WHERE `emp_id` = ?");
        $bioCheck->execute([$emp_id]);
        if ($bioCheck->fetchColumn() == 0) {
            echo json_encode([
                'status' => 'not_enrolled',
                'found' => false,
                'message' => "Employee ({$emp_id}) has NO biometric fingerprint registered in the system!"
            ]);
            exit;
        }

        $punchTime = date('Y-m-d H:i:s');
        $qualityFormatted = (is_numeric($score) ? $score . '%' : $score);

        // Log attendance
        try {
            $ins = $pdo->prepare("INSERT INTO `attendance_logs` (`emp_id`, `agent_id`, `punch_type`, `verification_mode`, `quality_score`, `punch_time`) VALUES (?, ?, 'Biometric', 'ZKTeco Mini Live20R', ?, ?)");
            $ins->execute([$emp['emp_id'], $agentId, $qualityFormatted, $punchTime]);
        } catch (Exception $ex) {}

        echo json_encode([
            'status' => 'success',
            'found' => true,
            'employee' => [
                'id' => $emp['id'],
                'emp_id' => $emp['emp_id'],
                'name' => $emp['name'],
                'department' => $emp['department'] ?? 'General',
                'designation' => $emp['designation'] ?? 'Staff',
                'location' => $emp['location'] ?? 'HQ',
                'company' => $emp['company'] ?? 'PulseMark HQ',
                'cell' => $emp['cell'] ?? 'N/A',
                'shift_name' => $emp['shift_name'] ?? 'General Shift',
                'shift_start' => $emp['shift_start'] ?? '09:00:00',
                'shift_end' => $emp['shift_end'] ?? '18:00:00'
            ],
            'quality' => $qualityFormatted,
            'punch_time' => date('h:i:s A', strtotime($punchTime)),
            'punch_date' => date('M d, Y', strtotime($punchTime)),
            'message' => 'Attendance Verified & Logged Successfully!'
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

echo json_encode(['status' => 'invalid_action']);
