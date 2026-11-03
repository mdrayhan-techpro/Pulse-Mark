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
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'get_enrolled_faces') {
    if (!$pdo) {
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
        exit;
    }

    try {
        // 0. Terminal Point Face Service & Operating Schedule Check
        $termCheck = isAgentTerminalServiceActive($pdo, $agentId, 'face');
        if (!$termCheck['active']) {
            echo json_encode([
                'status' => 'terminal_disabled',
                'faces' => [],
                'message' => $termCheck['reason'] ?? "Face Recognition attendance is not currently active for this terminal point."
            ]);
            exit;
        }

        // Only return enrolled faces of ACTIVE and NON-BLOCKED employees authorized for this agent
        $stmt = $pdo->prepare("SELECT DISTINCT f.emp_id, f.face_descriptor, f.quality_score, e.name, e.department, e.designation, e.location, e.company, e.shift_name, e.shift_start, e.shift_end, e.is_attendance_blocked, e.attendance_block_type 
                             FROM `face_entries` f 
                             JOIN `employees` e ON f.emp_id = e.emp_id 
                             LEFT JOIN `employee_agent_permissions` p ON p.emp_id = e.emp_id AND p.agent_id = ? AND p.status = 'active'
                             WHERE f.status = 'Active' 
                               AND (e.status = 'Active' OR e.status IS NULL) 
                               AND (e.attendance_block_type NOT IN ('face', 'both') OR e.attendance_block_type IS NULL)
                               AND (
                                   p.access_type = 'permanent'
                                   OR (p.access_type = 'temporary' AND (p.valid_from IS NULL OR p.valid_from <= NOW()) AND (p.valid_to IS NULL OR p.valid_to >= NOW()))
                                   OR (e.agent_id = ? AND p.agent_id IS NULL)
                               )
                             ORDER BY f.id DESC");
        $stmt->execute([$agentId, $agentId]);
        $faces = $stmt ? $stmt->fetchAll() : [];

        $list = [];
        foreach ($faces as $f) {
            $list[] = [
                'emp_id' => $f['emp_id'],
                'name' => $f['name'] ?? 'Employee',
                'department' => $f['department'] ?? 'General',
                'designation' => $f['designation'] ?? 'Staff',
                'location' => $f['location'] ?? 'HQ',
                'company' => $f['company'] ?? 'PulseMark HQ',
                'descriptor' => json_decode($f['face_descriptor'], true) ?: $f['face_descriptor'],
                'quality' => $f['quality_score']
            ];
        }

        echo json_encode(['status' => 'success', 'faces' => $list]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

if ($action === 'get_all_enrolled_faces') {
    if (!$pdo) {
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
        exit;
    }
    try {
        $stmt = $pdo->query("SELECT f.emp_id, f.face_descriptor, e.name, e.department, e.designation 
                             FROM `face_entries` f 
                             JOIN `employees` e ON f.emp_id = e.emp_id 
                             WHERE f.status = 'Active' AND (e.status = 'Active' OR e.status IS NULL)
                             ORDER BY f.id DESC");
        $faces = $stmt ? $stmt->fetchAll() : [];
        $list = [];
        foreach ($faces as $f) {
            $list[] = [
                'emp_id' => $f['emp_id'],
                'name' => $f['name'] ?? 'Employee',
                'department' => $f['department'] ?? 'General',
                'designation' => $f['designation'] ?? 'Staff',
                'descriptor' => json_decode($f['face_descriptor'], true) ?: $f['face_descriptor']
            ];
        }
        echo json_encode(['status' => 'success', 'faces' => $list]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// Biometric Vector Comparison Helper Function (Cosine Similarity & Euclidean Distance)
function compareBiometricFaceVectors($vecA, $vecB) {
    if (!is_array($vecA) || !is_array($vecB) || count($vecA) === 0 || count($vecA) !== count($vecB)) {
        return ['sim' => 0.0, 'dist' => 999.0];
    }
    $dot = 0.0; $normA = 0.0; $normB = 0.0; $sumSq = 0.0;
    $count = count($vecA);
    for ($k = 0; $k < $count; $k++) {
        $a = (float)$vecA[$k];
        $b = (float)$vecB[$k];
        $dot += $a * $b;
        $normA += $a * $a;
        $normB += $b * $b;
        $diff = $a - $b;
        $sumSq += $diff * $diff;
    }
    $denom = sqrt($normA) * sqrt($normB);
    $sim = $denom > 0 ? ($dot / $denom) : 0.0;
    $dist = sqrt($sumSq);
    return ['sim' => $sim, 'dist' => $dist];
}

if ($action === 'save_face' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $emp_id = sanitize($_POST['emp_id'] ?? '');
    $descriptor = $_POST['descriptor'] ?? '';
    $image = $_POST['image'] ?? '';
    $quality = sanitize($_POST['quality'] ?? '99.5%');

    if (empty($emp_id) || empty($descriptor)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing employee ID or face biometric descriptor']);
        exit;
    }

    try {
        // 1. Check if employee is authorized for this agent
        $auth = checkAgentAttendanceAuthorization($pdo, $emp_id, $agentId);
        if (!$auth['authorized']) {
            echo json_encode(['status' => 'error', 'message' => $auth['reason'] ?? 'Employee is not assigned to this agent terminal']);
            exit;
        }

        $chk = $pdo->prepare("SELECT status, name FROM `employees` WHERE `emp_id` = ? LIMIT 1");
        $chk->execute([$emp_id]);
        $empRow = $chk->fetch();

        if (!$empRow) {
            echo json_encode(['status' => 'error', 'message' => 'Employee ID not found in directory']);
            exit;
        }

        if (strcasecmp($empRow['status'] ?? 'Active', 'Active') !== 0) {
            echo json_encode([
                'status' => 'error', 
                'message' => "Employee ({$empRow['name']} - {$emp_id}) is INACTIVE! Face enrollment is blocked."
            ]);
            exit;
        }

        // 2. DUPLICATE FACE CHECK ACROSS ALL OTHER REGISTERED EMPLOYEES
        $existingFacesStmt = $pdo->prepare("
            SELECT f.emp_id, f.face_descriptor, e.name 
            FROM `face_entries` f 
            JOIN `employees` e ON f.emp_id = e.emp_id 
            WHERE f.emp_id != ? AND f.status = 'Active'
        ");
        $existingFacesStmt->execute([$emp_id]);
        $otherFaces = $existingFacesStmt->fetchAll();

        $newDescriptor = json_decode($descriptor, true);
        if (is_array($newDescriptor) && !empty($newDescriptor)) {
            foreach ($otherFaces as $other) {
                $otherDesc = json_decode($other['face_descriptor'], true);
                if (is_array($otherDesc) && count($otherDesc) === count($newDescriptor)) {
                    $metrics = compareBiometricFaceVectors($newDescriptor, $otherDesc);
                    $sim = $metrics['sim'];
                    $dist = $metrics['dist'];

                    // Cosine similarity >= 0.65 or Euclidean distance <= 0.84 indicates identical human face match
                    if ($sim >= 0.65 || $dist <= 0.84) {
                        $matchPercent = round(min(99.9, max(85.0, $sim * 100)), 1) . '%';
                        echo json_encode([
                            'status' => 'duplicate_face',
                            'matched_emp' => $other['emp_id'],
                            'matched_name' => $other['name'],
                            'similarity' => $matchPercent,
                            'message' => "Duplicate Face Blocked! This face is ALREADY registered to Employee: {$other['name']} (ID: #{$other['emp_id']}) with {$matchPercent} biometric match.<br>The same face cannot be registered under a different employee name!"
                        ]);
                        exit;
                    }
                }
            }
        }

        // Remove existing face entry for this employee to keep 1 active master face template
        $del = $pdo->prepare("DELETE FROM `face_entries` WHERE `emp_id` = ?");
        $del->execute([$emp_id]);

        $ins = $pdo->prepare("INSERT INTO `face_entries` (`emp_id`, `agent_id`, `face_descriptor`, `face_image`, `quality_score`, `status`) VALUES (?, ?, ?, ?, ?, 'Active')");
        $ins->execute([$emp_id, $agentId, $descriptor, $image, $quality]);

        echo json_encode([
            'status' => 'success',
            'message' => "Face biometric template successfully registered for Employee ({$emp_id})!"
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

if ($action === 'mark_face_attendance') {
    $emp_id = sanitize($_POST['emp_id'] ?? ($_GET['emp_id'] ?? ''));
    $score = sanitize($_POST['score'] ?? ($_GET['score'] ?? '99.2%'));

    if (empty($emp_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing employee ID']);
        exit;
    }

    try {
        // 0. Terminal Point Face Service & Operating Schedule Check
        $termCheck = isAgentTerminalServiceActive($pdo, $agentId, 'face');
        if (!$termCheck['active']) {
            echo json_encode([
                'status' => 'terminal_disabled',
                'found' => false,
                'message' => $termCheck['reason'] ?? "Face Recognition attendance is not currently active for this terminal point."
            ]);
            exit;
        }

        // 1. Strict Multi-Agent Attendance Authorization Check (Permanent or Temporary Date-Time Range)
        $auth = checkAgentAttendanceAuthorization($pdo, $emp_id, $agentId);
        if (!$auth['authorized']) {
            echo json_encode([
                'status' => 'unauthorized',
                'found' => false,
                'message' => $auth['reason'] ?? "Employee ({$emp_id}) is not authorized for attendance at this agent terminal."
            ]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM `employees` WHERE `emp_id` = ? LIMIT 1");
        $stmt->execute([$emp_id]);
        $emp = $stmt->fetch();

        if (!$emp) {
            echo json_encode(['status' => 'not_found', 'message' => 'Employee ID not found in directory']);
            exit;
        }

        // Check if employee is Inactive
        if (strcasecmp($emp['status'] ?? 'Active', 'Active') !== 0) {
            echo json_encode([
                'status' => 'inactive',
                'found' => false,
                'employee' => [
                    'emp_id' => $emp['emp_id'],
                    'name' => $emp['name'],
                    'status' => $emp['status']
                ],
                'message' => "Employee ({$emp['name']} - {$emp_id}) is INACTIVE! Face attendance is blocked."
            ]);
            exit;
        }

        // Strict Attendance Block Check: Check if Face or Both is blocked
        $blockType = $emp['attendance_block_type'] ?? ((int)($emp['is_attendance_blocked'] ?? 0) === 1 ? 'both' : 'none');
        if ($blockType === 'face' || $blockType === 'both' || ((int)($emp['is_attendance_blocked'] ?? 0) === 1 && $blockType !== 'fingerprint')) {
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
                'message' => "Live Face Recognition Attendance is BLOCKED for Employee ({$emp['name']} - {$emp_id})!"
            ]);
            exit;
        }

        $punchTime = date('Y-m-d H:i:s');
        $qualityFormatted = (is_numeric($score) ? $score . '%' : $score);

        $ins = $pdo->prepare("INSERT INTO `attendance_logs` (`emp_id`, `agent_id`, `punch_type`, `verification_mode`, `quality_score`, `punch_time`) VALUES (?, ?, 'Face', 'Live AI Optical Recognition', ?, ?)");
        $ins->execute([$emp['emp_id'], $agentId, $qualityFormatted, $punchTime]);

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
            'message' => 'Face Attendance Verified & Logged Successfully!'
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

echo json_encode(['status' => 'invalid_action']);
