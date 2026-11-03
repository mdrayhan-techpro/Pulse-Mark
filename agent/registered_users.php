<?php
require_once __DIR__ . '/../config/init.php';
$isGuestMode = isAgentGuestMode();

if ($isGuestMode) {
    $currentAgent = [
        'id' => 1,
        'name' => 'Guest Terminal',
        'username' => 'guest',
        'email' => 'guest@pulsemark.com',
        'dept' => 'Guest Preview Mode',
        'is_guest' => true
    ];
} else {
    $currentAgent = [
        'id' => $_SESSION['agent_id'] ?? ($_SESSION['user_id'] ?? 1),
        'name' => $_SESSION['agent_name'] ?? ($_SESSION['user_name'] ?? 'Agent User'),
        'username' => $_SESSION['agent_username'] ?? ($_SESSION['user_username'] ?? 'agent'),
        'email' => $_SESSION['agent_email'] ?? ($_SESSION['user_email'] ?? 'agent@pulsemark.com'),
        'dept' => $_SESSION['agent_dept'] ?? 'Field Operations',
        'is_guest' => false
    ];
}

$pdo = getDB();
$agentId = $currentAgent['id'] ?? 1;

if (!function_exists('formatTime12to24')) {
    function formatTime12to24($hour, $min, $ampm) {
        $h = (int)$hour;
        $ampm = strtoupper(trim($ampm));
        if ($ampm === 'PM' && $h < 12) {
            $h += 12;
        } elseif ($ampm === 'AM' && $h === 12) {
            $h = 0;
        }
        $m = str_pad((int)$min, 2, '0', STR_PAD_LEFT);
        return str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . $m . ':00';
    }
}

// Handle Add / Edit / Block Actions from Agent Portal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($isGuestMode) {
        setFlash('danger', 'Action Denied: You are in Guest Preview Mode. Please sign in to create or edit employees.');
        header('Location: registered_users.php');
        exit;
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Security validation failed.');
        header('Location: registered_users.php');
        exit;
    }

    $action = $_POST['action'];

    // 1. Create New Employee (Auto-assigned to this agent with permanent access)
    if ($action === 'create_employee' && $pdo) {
        $emp_id = sanitize($_POST['emp_id'] ?? '');
        $name = sanitize($_POST['name'] ?? '');
        $designation = sanitize($_POST['designation'] ?? '');
        $department = sanitize($_POST['department'] ?? '');
        $section = sanitize($_POST['section'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $cell = sanitize($_POST['cell'] ?? '');
        $company = sanitize($_POST['company'] ?? '');
        $location = sanitize($_POST['location'] ?? '');
        $floor = sanitize($_POST['floor'] ?? '');
        $status = sanitize($_POST['status'] ?? 'Active');
        $shift_name = sanitize($_POST['shift_name'] ?? 'General Shift');

        $shift_start_hour = sanitize($_POST['shift_start_hour'] ?? '');
        $shift_start_min = sanitize($_POST['shift_start_min'] ?? '');
        $shift_start_ampm = sanitize($_POST['shift_start_ampm'] ?? '');
        if (!empty($shift_start_hour) && $shift_start_min !== '' && !empty($shift_start_ampm)) {
            $shift_start = formatTime12to24($shift_start_hour, $shift_start_min, $shift_start_ampm);
        } else {
            $shift_start = sanitize($_POST['shift_start'] ?? '09:00:00');
        }

        $shift_end_hour = sanitize($_POST['shift_end_hour'] ?? '');
        $shift_end_min = sanitize($_POST['shift_end_min'] ?? '');
        $shift_end_ampm = sanitize($_POST['shift_end_ampm'] ?? '');
        if (!empty($shift_end_hour) && $shift_end_min !== '' && !empty($shift_end_ampm)) {
            $shift_end = formatTime12to24($shift_end_hour, $shift_end_min, $shift_end_ampm);
        } else {
            $shift_end = sanitize($_POST['shift_end'] ?? '18:00:00');
        }

        $attendance_block_type = sanitize($_POST['attendance_block_type'] ?? 'none');
        $is_attendance_blocked = ($attendance_block_type !== 'none') ? 1 : 0;

        // 1. Check duplicate ID
        $checkId = $pdo->prepare("SELECT e.*, u.name as agent_name FROM `employees` e LEFT JOIN `users` u ON e.agent_id = u.id WHERE LOWER(TRIM(e.emp_id)) = LOWER(TRIM(?))");
        $checkId->execute([$emp_id]);
        $dupId = $checkId->fetch();

        // 2. Check duplicate Name
        $checkName = $pdo->prepare("SELECT e.*, u.name as agent_name FROM `employees` e LEFT JOIN `users` u ON e.agent_id = u.id WHERE LOWER(TRIM(e.name)) = LOWER(TRIM(?))");
        $checkName->execute([$name]);
        $dupName = $checkName->fetch();

        // 3. Check duplicate Cell / Phone
        $dupCell = null;
        if (!empty($cell)) {
            $checkCell = $pdo->prepare("SELECT e.*, u.name as agent_name FROM `employees` e LEFT JOIN `users` u ON e.agent_id = u.id WHERE TRIM(e.cell) = TRIM(?) AND e.cell != ''");
            $checkCell->execute([$cell]);
            $dupCell = $checkCell->fetch();
        }

        // 4. Check duplicate Email
        $dupEmail = null;
        if (!empty($email)) {
            $checkEmail = $pdo->prepare("SELECT e.*, u.name as agent_name FROM `employees` e LEFT JOIN `users` u ON e.agent_id = u.id WHERE LOWER(TRIM(e.email)) = LOWER(TRIM(?)) AND e.email != ''");
            $checkEmail->execute([$email]);
            $dupEmail = $checkEmail->fetch();
        }

        if ($dupId) {
            $existingAgent = !empty($dupId['agent_name']) ? $dupId['agent_name'] : ("Agent #" . $dupId['agent_id']);
            setFlash('danger', "Duplicate Blocked! Employee ID '{$emp_id}' is already registered to '{$dupId['name']}' under {$existingAgent}. Same employee cannot be added multiple times!");
        } elseif ($dupName) {
            $existingAgent = !empty($dupName['agent_name']) ? $dupName['agent_name'] : ("Agent #" . $dupName['agent_id']);
            setFlash('danger', "Duplicate Blocked! Employee Name '{$name}' (ID: {$dupName['emp_id']}) is already registered under {$existingAgent}. The same employee cannot be added across different agents!");
        } elseif ($dupCell) {
            $existingAgent = !empty($dupCell['agent_name']) ? $dupCell['agent_name'] : ("Agent #" . $dupCell['agent_id']);
            setFlash('danger', "Duplicate Blocked! Phone number '{$cell}' is already registered to employee '{$dupCell['name']}' (ID: {$dupCell['emp_id']}) under {$existingAgent}!");
        } elseif ($dupEmail) {
            $existingAgent = !empty($dupEmail['agent_name']) ? $dupEmail['agent_name'] : ("Agent #" . $dupEmail['agent_id']);
            setFlash('danger', "Duplicate Blocked! Email '{$email}' is already registered to employee '{$dupEmail['name']}' (ID: {$dupEmail['emp_id']}) under {$existingAgent}!");
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO `employees` (`emp_id`, `agent_id`, `name`, `designation`, `department`, `section`, `email`, `cell`, `company`, `location`, `floor`, `status`, `shift_name`, `shift_start`, `shift_end`, `is_attendance_blocked`, `attendance_block_type`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$emp_id, $agentId, $name, $designation, $department, $section, $email, $cell, $company, $location, $floor, $status, $shift_name, $shift_start, $shift_end, $is_attendance_blocked, $attendance_block_type]);

                // Auto assign permanent permission for this agent
                $permStmt = $pdo->prepare("INSERT INTO `employee_agent_permissions` (`emp_id`, `agent_id`, `access_type`, `valid_from`, `status`) VALUES (?, ?, 'permanent', NOW(), 'active') ON DUPLICATE KEY UPDATE `access_type` = 'permanent', `status` = 'active'");
                $permStmt->execute([$emp_id, $agentId]);

                setFlash('success', "Registered User '{$name}' ({$emp_id}) added successfully with Shift [{$shift_name}] and auto-assigned to your agent terminal!");
            } catch (Exception $e) {
                setFlash('danger', 'Failed to register user: ' . $e->getMessage());
            }
        }
        header('Location: registered_users.php');
        exit;
    }

    // 2. Update Employee Shift & Attendance Permission (Core Profile is Protected / Admin Controlled)
    if ($action === 'update_employee' && $pdo) {
        $id = (int)($_POST['id'] ?? 0);
        $shift_name = sanitize($_POST['shift_name'] ?? 'General Shift');

        $shift_start_hour = sanitize($_POST['shift_start_hour'] ?? '');
        $shift_start_min = sanitize($_POST['shift_start_min'] ?? '');
        $shift_start_ampm = sanitize($_POST['shift_start_ampm'] ?? '');
        if (!empty($shift_start_hour) && $shift_start_min !== '' && !empty($shift_start_ampm)) {
            $shift_start = formatTime12to24($shift_start_hour, $shift_start_min, $shift_start_ampm);
        } else {
            $shift_start = sanitize($_POST['shift_start'] ?? '09:00:00');
        }

        $shift_end_hour = sanitize($_POST['shift_end_hour'] ?? '');
        $shift_end_min = sanitize($_POST['shift_end_min'] ?? '');
        $shift_end_ampm = sanitize($_POST['shift_end_ampm'] ?? '');
        if (!empty($shift_end_hour) && $shift_end_min !== '' && !empty($shift_end_ampm)) {
            $shift_end = formatTime12to24($shift_end_hour, $shift_end_min, $shift_end_ampm);
        } else {
            $shift_end = sanitize($_POST['shift_end'] ?? '18:00:00');
        }

        $attendance_block_type = sanitize($_POST['attendance_block_type'] ?? 'none');
        $is_attendance_blocked = ($attendance_block_type !== 'none') ? 1 : 0;

        try {
            // Strictly update operational shift & attendance permission fields; core employee profile is admin protected
            $stmt = $pdo->prepare("UPDATE `employees` SET `shift_name` = ?, `shift_start` = ?, `shift_end` = ?, `is_attendance_blocked` = ?, `attendance_block_type` = ? WHERE `id` = ?");
            $stmt->execute([$shift_name, $shift_start, $shift_end, $is_attendance_blocked, $attendance_block_type, $id]);
            setFlash('success', "Duty shift & attendance permission updated successfully!");
        } catch (Exception $e) {
            setFlash('danger', 'Failed to update shift settings: ' . $e->getMessage());
        }
        header('Location: registered_users.php');
        exit;
    }

    // 3. Quick Toggle Attendance Block
    if ($action === 'toggle_attendance_block' && $pdo) {
        $emp_id = sanitize($_POST['emp_id'] ?? '');
        $target_block_type = sanitize($_POST['target_block_type'] ?? 'none');
        $target_block = ($target_block_type !== 'none') ? 1 : 0;

        try {
            $stmt = $pdo->prepare("UPDATE `employees` SET `attendance_block_type` = ?, `is_attendance_blocked` = ? WHERE `emp_id` = ?");
            $stmt->execute([$target_block_type, $target_block, $emp_id]);

            $statusLabels = [
                'none' => 'Allowed (Normal Attendance)',
                'fingerprint' => 'Fingerprint Punch BLOCKED',
                'face' => 'Face Recognition BLOCKED',
                'both' => 'Both Biometric & Face BLOCKED'
            ];
            $statusText = $statusLabels[$target_block_type] ?? 'Updated';
            $msgType = $target_block_type === 'none' ? 'success' : 'danger';
            setFlash($msgType, "Attendance for Employee ({$emp_id}) is now set to: {$statusText}.");
        } catch (Exception $e) {
            setFlash('danger', 'Failed to change attendance permission: ' . $e->getMessage());
        }
        header('Location: registered_users.php');
        exit;
    }
}

// Fetch employees strictly authorized/assigned to this logged-in agent (Permanent or within Active Temporary Date Range)
$employees = [];
$totalUsers = 0;
$totalBiometricEnrolled = 0;
$totalFaceEnrolled = 0;
$totalBlocked = 0;

if (!empty($isGuestMode)) {
    // In Guest Mode: Do not expose employee database
    $employees = [];
    $totalUsers = 0;
    $totalBiometricEnrolled = 0;
    $totalFaceEnrolled = 0;
    $totalBlocked = 0;
} elseif ($pdo) {
    try {
        if (isAdminLoggedIn()) {
            $q = $pdo->prepare("SELECT e.*, 
                'permanent' as my_access_type,
                NULL as my_valid_from,
                NULL as my_valid_to,
                (SELECT COUNT(*) FROM `biometric_entries` b WHERE b.emp_id = e.emp_id) as bio_count,
                (SELECT COUNT(*) FROM `face_entries` f WHERE f.emp_id = e.emp_id AND f.status = 'Active') as face_count 
                FROM `employees` e 
                GROUP BY e.id
                ORDER BY e.id DESC");
            $q->execute();
        } else {
            $q = $pdo->prepare("SELECT e.*, 
                p.access_type as my_access_type,
                p.valid_from as my_valid_from,
                p.valid_to as my_valid_to,
                (SELECT COUNT(*) FROM `biometric_entries` b WHERE b.emp_id = e.emp_id) as bio_count,
                (SELECT COUNT(*) FROM `face_entries` f WHERE f.emp_id = e.emp_id AND f.status = 'Active') as face_count 
                FROM `employees` e 
                LEFT JOIN `employee_agent_permissions` p ON p.emp_id = e.emp_id AND p.agent_id = :aid1 AND p.status = 'active'
                WHERE (e.agent_id = :aid2 OR p.agent_id = :aid3)
                  AND (
                      p.access_type = 'permanent' 
                      OR (p.access_type = 'temporary' AND (p.valid_from IS NULL OR p.valid_from <= NOW()) AND (p.valid_to IS NULL OR p.valid_to >= NOW()))
                      OR (e.agent_id = :aid4 AND p.agent_id IS NULL)
                  )
                GROUP BY e.id
                ORDER BY e.id DESC");
            $q->execute([
                ':aid1' => $agentId, 
                ':aid2' => $agentId, 
                ':aid3' => $agentId, 
                ':aid4' => $agentId
            ]);
        }
        $employees = $q->fetchAll();
        $totalUsers = count($employees);
        
        foreach ($employees as $e) {
            if (($e['bio_count'] ?? 0) > 0) {
                $totalBiometricEnrolled++;
            }
            if (($e['face_count'] ?? 0) > 0) {
                $totalFaceEnrolled++;
            }
            $bType = $e['attendance_block_type'] ?? ((int)($e['is_attendance_blocked'] ?? 0) === 1 ? 'both' : 'none');
            if ($bType !== 'none' || (int)($e['is_attendance_blocked'] ?? 0) === 1) {
                $totalBlocked++;
            }
        }
    } catch (Exception $e) {
        $employees = [];
    }
}

$flash = getFlash();

$pageTitle = 'Registered Users & Employees | Pulse Mark';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<main class="main-content">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-body">
        <?php if ($flash): ?>
            <div class="alert-box alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom: 24px;">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation' ?>"></i>
                <span><?= htmlspecialchars($flash['message']) ?></span>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
            <div>
                <h1 class="page-title" style="font-size: 1.85rem; font-weight: 800; font-family: 'Outfit', sans-serif; display: flex; align-items: center; gap: 12px;">
                    <i class="fa-solid fa-users-gear" style="color: #38bdf8;"></i>
                    <span>Registered Users & Shift Management</span>
                </h1>
                <p class="page-subtitle" style="color: #94a3b8; font-size: 0.95rem; margin-top: 4px;">
                    Manage employee directory, duty shift schedules, biometric & face enrollment, and granular attendance permissions.
                </p>
            </div>
            <div style="display: flex; gap: 12px;">
                <button type="button" class="btn btn-primary" onclick="openAddModal()" style="background: linear-gradient(135deg, #10b981, #06b6d4); font-weight: 700; padding: 10px 20px;">
                    <i class="fa-solid fa-user-plus"></i>
                    <span>Register New User</span>
                </button>
            </div>
        </div>

        <!-- Metrics Overview -->
        <div class="metric-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 20px; margin-bottom: 28px;">
            <div class="glass-panel metric-card" style="padding: 20px; border-radius: 16px; border-left: 4px solid #38bdf8;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="color: #94a3b8; font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Total Assigned Users</div>
                        <div style="font-size: 1.9rem; font-weight: 800; color: #f8fafc; margin-top: 4px; font-family: 'Outfit', sans-serif;"><?= $totalUsers ?></div>
                    </div>
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(56, 189, 248, 0.15); color: #38bdf8; display: flex; align-items: center; justify-content: center; font-size: 1.35rem;">
                        <i class="fa-solid fa-users"></i>
                    </div>
                </div>
            </div>

            <div class="glass-panel metric-card" style="padding: 20px; border-radius: 16px; border-left: 4px solid #10b981;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="color: #94a3b8; font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Biometric Enrolled</div>
                        <div style="font-size: 1.9rem; font-weight: 800; color: #10b981; margin-top: 4px; font-family: 'Outfit', sans-serif;"><?= $totalBiometricEnrolled ?></div>
                    </div>
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(16, 185, 129, 0.15); color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 1.35rem;">
                        <i class="fa-solid fa-fingerprint"></i>
                    </div>
                </div>
            </div>

            <div class="glass-panel metric-card" style="padding: 20px; border-radius: 16px; border-left: 4px solid #06b6d4;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="color: #94a3b8; font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Face Enrolled</div>
                        <div style="font-size: 1.9rem; font-weight: 800; color: #06b6d4; margin-top: 4px; font-family: 'Outfit', sans-serif;"><?= $totalFaceEnrolled ?></div>
                    </div>
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(6, 182, 212, 0.15); color: #06b6d4; display: flex; align-items: center; justify-content: center; font-size: 1.35rem;">
                        <i class="fa-solid fa-camera"></i>
                    </div>
                </div>
            </div>

            <div class="glass-panel metric-card" style="padding: 20px; border-radius: 16px; border-left: 4px solid #ef4444;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="color: #94a3b8; font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Attendance Blocked</div>
                        <div style="font-size: 1.9rem; font-weight: 800; color: #ef4444; margin-top: 4px; font-family: 'Outfit', sans-serif;"><?= $totalBlocked ?></div>
                    </div>
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(239, 68, 68, 0.15); color: #ef4444; display: flex; align-items: center; justify-content: center; font-size: 1.35rem;">
                        <i class="fa-solid fa-ban"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Registered Users Table Panel -->
        <div class="glass-panel" style="padding: 24px; border-radius: 18px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 20px;">
                <div style="position: relative; min-width: 280px; flex: 1; max-width: 400px;">
                    <input type="text" id="userSearchInput" onkeyup="filterUsers()" class="form-control" placeholder="Search by name, ID, department, shift..." style="padding-left: 38px;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-dim);"></i>
                </div>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <select id="deptFilter" onchange="filterUsers()" class="form-control" style="width: auto;">
                        <option value="">All Departments</option>
                        <?php 
                        $depts = array_unique(array_filter(array_column($employees, 'department')));
                        foreach ($depts as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="blockFilter" onchange="filterUsers()" class="form-control" style="width: auto;">
                        <option value="">All Attendance Permissions</option>
                        <option value="none">Allowed / Normal</option>
                        <option value="fingerprint">Fingerprint Blocked</option>
                        <option value="face">Face Recognition Blocked</option>
                        <option value="both">Both Blocked</option>
                    </select>
                </div>
            </div>

            <div class="table-responsive">
                <table class="data-table" id="usersTable" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Employee ID</th>
                            <th>User Name</th>
                            <th>Designation & Dept</th>
                            <th>Duty Shift Time</th>
                            <th>Agent Access Validity</th>
                            <th>Contact</th>
                            <th>Biometric</th>
                            <th>Face Status</th>
                            <th>Attendance Permission</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($employees)): ?>
                            <?php foreach ($employees as $emp): 
                                $isBioEnrolled = ($emp['bio_count'] ?? 0) > 0;
                                $isFaceEnrolled = ($emp['face_count'] ?? 0) > 0;
                                
                                $blockType = $emp['attendance_block_type'] ?? ((int)($emp['is_attendance_blocked'] ?? 0) === 1 ? 'both' : 'none');
                                if (empty($blockType)) $blockType = 'none';
                                
                                $shiftStartFmt = !empty($emp['shift_start']) ? date('h:i A', strtotime($emp['shift_start'])) : '09:00 AM';
                                $shiftEndFmt = !empty($emp['shift_end']) ? date('h:i A', strtotime($emp['shift_end'])) : '06:00 PM';
                                $shiftName = $emp['shift_name'] ?? 'General Shift';

                                $isTemp = ($emp['my_access_type'] ?? '') === 'temporary';
                                $validTo = $emp['my_valid_to'] ?? '';
                            ?>
                                <tr data-blocked="<?= htmlspecialchars($blockType) ?>">
                                    <td>
                                        <span style="font-weight: 800; color: #38bdf8; font-family: monospace; font-size: 0.95rem;">
                                            <?= htmlspecialchars($emp['emp_id']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div style="width: 36px; height: 36px; border-radius: 8px; background: <?= $blockType !== 'none' ? 'linear-gradient(135deg, #ef4444, #b91c1c)' : 'linear-gradient(135deg, #10b981, #06b6d4)' ?>; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #fff; font-size: 0.95rem;">
                                                <?= strtoupper(substr($emp['name'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($emp['name']) ?></div>
                                                <div style="font-size: 0.78rem; color: var(--text-muted);"><?= htmlspecialchars($emp['company'] ?? 'PulseMark HQ') ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($emp['designation'] ?? 'Staff') ?></div>
                                        <div style="font-size: 0.8rem; color: var(--primary);"><?= htmlspecialchars($emp['department'] ?? 'General') ?></div>
                                    </td>
                                    <!-- Duty Shift Column -->
                                    <td>
                                        <div style="font-weight: 700; color: #38bdf8; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 5px;">
                                            <i class="fa-solid fa-clock"></i> <?= $shiftStartFmt ?> - <?= $shiftEndFmt ?>
                                        </div>
                                        <div style="font-size: 0.74rem; color: var(--text-muted);"><?= htmlspecialchars($shiftName) ?></div>
                                    </td>
                                    <!-- Agent Access Validity -->
                                    <td>
                                        <?php if ($isTemp && !empty($validTo)): ?>
                                            <span class="badge" style="background: rgba(245, 158, 11, 0.18); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.35); font-size: 0.74rem; padding: 4px 8px; display: inline-flex; align-items: center; gap: 5px;">
                                                <i class="fa-solid fa-stopwatch"></i> Till <?= date('d M, h:i A', strtotime($validTo)) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); font-size: 0.74rem; padding: 4px 8px; display: inline-flex; align-items: center; gap: 5px;">
                                                <i class="fa-solid fa-shield-halved"></i> Permanent Access
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.85rem; color: var(--text-main);"><?= htmlspecialchars($emp['cell'] ?? 'N/A') ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($emp['email'] ?? 'N/A') ?></div>
                                    </td>
                                    <!-- Biometric Status Column -->
                                    <td>
                                        <?php if ($isBioEnrolled): ?>
                                            <span class="badge" style="background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.4); display: inline-flex; align-items: center; gap: 5px; padding: 4px 8px; font-size: 0.78rem;">
                                                <i class="fa-solid fa-circle-check"></i> Enrolled (<?= $emp['bio_count'] ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); display: inline-flex; align-items: center; gap: 5px; padding: 4px 8px; font-size: 0.78rem;">
                                                <i class="fa-solid fa-circle-xmark"></i> Not Enrolled
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Face Status Column -->
                                    <td>
                                        <?php if ($isFaceEnrolled): ?>
                                            <span class="badge" style="background: rgba(56, 189, 248, 0.2); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.4); display: inline-flex; align-items: center; gap: 5px; padding: 4px 8px; font-size: 0.78rem;">
                                                <i class="fa-solid fa-circle-check"></i> Enrolled (<?= $emp['face_count'] ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); display: inline-flex; align-items: center; gap: 5px; padding: 4px 8px; font-size: 0.78rem;">
                                                <i class="fa-solid fa-circle-xmark"></i> Not Enrolled
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Attendance Permission Column (Quick Granular Selector) -->
                                    <td>
                                        <form method="POST" action="registered_users.php" style="margin: 0; display: inline-block;">
                                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                            <input type="hidden" name="action" value="toggle_attendance_block">
                                            <input type="hidden" name="emp_id" value="<?= htmlspecialchars($emp['emp_id']) ?>">
                                            <select name="target_block_type" onchange="this.form.submit()" class="form-control" title="Change attendance permission" style="padding: 4px 8px; font-size: 0.76rem; font-weight: 700; border-radius: 8px; cursor: pointer; width: auto; background: <?= $blockType === 'none' ? 'rgba(16, 185, 129, 0.15)' : ($blockType === 'fingerprint' ? 'rgba(245, 158, 11, 0.18)' : ($blockType === 'face' ? 'rgba(168, 85, 247, 0.18)' : 'rgba(239, 68, 68, 0.2)')) ?>; color: <?= $blockType === 'none' ? '#10b981' : ($blockType === 'fingerprint' ? '#f59e0b' : ($blockType === 'face' ? '#c084fc' : '#ef4444')) ?>; border: 1px solid <?= $blockType === 'none' ? 'rgba(16, 185, 129, 0.4)' : ($blockType === 'fingerprint' ? 'rgba(245, 158, 11, 0.5)' : ($blockType === 'face' ? 'rgba(168, 85, 247, 0.5)' : '#ef4444')) ?>;">
                                                <option value="none" <?= $blockType === 'none' ? 'selected' : '' ?>>✓ Allowed (Normal)</option>
                                                <option value="fingerprint" <?= $blockType === 'fingerprint' ? 'selected' : '' ?>>⛔ Block Finger Only</option>
                                                <option value="face" <?= $blockType === 'face' ? 'selected' : '' ?>>⛔ Block Face Only</option>
                                                <option value="both" <?= $blockType === 'both' ? 'selected' : '' ?>>🚫 Block Both (All)</option>
                                            </select>
                                        </form>
                                    </td>
                                    <!-- Account Status -->
                                    <td>
                                        <span class="badge" style="background: <?= strtolower($emp['status'] ?? 'Active') === 'active' ? 'rgba(56, 189, 248, 0.15)' : 'rgba(239, 68, 68, 0.15)' ?>; color: <?= strtolower($emp['status'] ?? 'Active') === 'active' ? '#38bdf8' : '#ef4444' ?>; border: 1px solid <?= strtolower($emp['status'] ?? 'Active') === 'active' ? 'rgba(56, 189, 248, 0.3)' : 'rgba(239, 68, 68, 0.3)' ?>;">
                                            <?= htmlspecialchars($emp['status'] ?? 'Active') ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; gap: 6px;">
                                            <button type="button" class="btn btn-sm" onclick='openEditModal(<?= json_encode($emp) ?>)' title="Configure Duty Shift & Attendance Access" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); padding: 6px 10px; border-radius: 8px;">
                                                <i class="fa-solid fa-clock-rotate-left"></i>
                                            </button>
                                            <a href="biometric_entry.php?emp_id=<?= urlencode($emp['emp_id']) ?>" class="btn btn-sm" title="Register ZKTeco Fingerprint" style="background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.4); padding: 6px 10px; font-weight: 600; border-radius: 8px;">
                                                <i class="fa-solid fa-fingerprint"></i>
                                            </a>
                                            <a href="face_entry.php?emp_id=<?= urlencode($emp['emp_id']) ?>" class="btn btn-sm" title="Register Face" style="background: rgba(56, 189, 248, 0.2); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.4); padding: 6px 10px; font-weight: 600; border-radius: 8px;">
                                                <i class="fa-solid fa-camera"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" style="text-align: center; padding: 36px 20px; color: #94a3b8;">
                                    <?php if (!empty($isGuestMode)): ?>
                                        <div style="font-weight: 700; color: #94a3b8; margin-bottom: 6px; font-size: 0.95rem;">
                                            <i class="fa-solid fa-lock" style="color: #38bdf8; margin-right: 6px;"></i> Employee database records are protected in Guest Mode.
                                        </div>
                                        <div style="font-size: 0.84rem; color: var(--text-dim);">
                                            Please <a href="#" onclick="openLoginModal(); return false;" style="color: #38bdf8; font-weight: 700; text-decoration: underline;">Sign In</a> with an Agent or Administrator account to view registered personnel.
                                        </div>
                                    <?php else: ?>
                                        No registered users found in your assigned terminal.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Add Employee Modal -->
<div id="addModal" class="modal-backdrop" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(6px); z-index: 9999; align-items: center; justify-content: center;">
    <div class="glass-panel" style="width: 100%; max-width: 720px; margin: 20px; border-radius: 20px; border: 1.5px solid rgba(56, 189, 248, 0.35); background: rgba(15, 23, 42, 0.98); max-height: 90vh; overflow-y: auto;">
        <div style="padding: 20px 24px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 1.25rem; font-weight: 800; color: #f8fafc; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-user-plus" style="color: #10b981;"></i>
                <span>Register New User / Employee</span>
            </h3>
            <button type="button" onclick="closeAddModal()" style="background: none; border: none; color: #94a3b8; font-size: 1.2rem; cursor: pointer;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="registered_users.php" style="padding: 24px;">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="create_employee">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div class="form-group">
                    <label class="form-label">Employee ID *</label>
                    <input type="text" name="emp_id" required class="form-control" placeholder="e.g. M4205" style="background: rgba(30, 41, 59, 0.8);">
                </div>
                <div class="form-group">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="name" required class="form-control" placeholder="e.g. Shakil Ahmed" style="background: rgba(30, 41, 59, 0.8);">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div class="form-group">
                    <label class="form-label">Department *</label>
                    <input type="text" name="department" required class="form-control" placeholder="e.g. Information Technology" style="background: rgba(30, 41, 59, 0.8);">
                </div>
                <div class="form-group">
                    <label class="form-label">Designation</label>
                    <input type="text" name="designation" class="form-control" placeholder="e.g. Software Engineer" style="background: rgba(30, 41, 59, 0.8);">
                </div>
            </div>

            <!-- Shift & Duty Hours Dropdown Settings (No Manual Typing) -->
            <div style="padding: 16px; border-radius: 12px; background: rgba(30, 41, 59, 0.4); border: 1px solid rgba(56, 189, 248, 0.2); margin-bottom: 16px;">
                <div style="font-weight: 700; color: #38bdf8; font-size: 0.92rem; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-business-time"></i> Duty Shift & Working Hours (Select Time Dropdown)
                </div>
                <div style="display: grid; grid-template-columns: 1.1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.8rem; color: #94a3b8;">Shift Preset</label>
                        <select name="shift_name" id="add_shift_name" class="form-control" onchange="applyShiftPreset(this, 'add')" style="background: rgba(15, 23, 42, 0.9);">
                            <option value="General (09:00 AM - 06:00 PM)">General (09:00 AM - 06:00 PM)</option>
                            <option value="Morning (08:00 AM - 05:00 PM)">Morning (08:00 AM - 05:00 PM)</option>
                            <option value="Evening (02:00 PM - 11:00 PM)">Evening (02:00 PM - 11:00 PM)</option>
                            <option value="Night (10:00 PM - 07:00 AM)">Night (10:00 PM - 07:00 AM)</option>
                            <option value="Custom Shift">Custom Shift</option>
                        </select>
                    </div>

                    <!-- Shift Start (In Time) Selectors -->
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.8rem; color: #94a3b8;">Shift Start (In Time)</label>
                        <div style="display: flex; gap: 4px; align-items: center;">
                            <select name="shift_start_hour" id="add_shift_start_hour" onchange="syncHiddenTime('add_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; background: rgba(15, 23, 42, 0.9); flex: 1;">
                                <?php for ($h = 1; $h <= 12; $h++): 
                                    $hVal = str_pad($h, 2, '0', STR_PAD_LEFT);
                                ?>
                                    <option value="<?= $hVal ?>" <?= $hVal === '09' ? 'selected' : '' ?>><?= $hVal ?></option>
                                <?php endfor; ?>
                            </select>
                            <span style="font-weight: 800; color: #38bdf8;">:</span>
                            <select name="shift_start_min" id="add_shift_start_min" onchange="syncHiddenTime('add_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; background: rgba(15, 23, 42, 0.9); flex: 1;">
                                <?php for ($m = 0; $m < 60; $m += 5): 
                                    $mVal = str_pad($m, 2, '0', STR_PAD_LEFT);
                                ?>
                                    <option value="<?= $mVal ?>" <?= $mVal === '00' ? 'selected' : '' ?>><?= $mVal ?></option>
                                <?php endfor; ?>
                            </select>
                            <select name="shift_start_ampm" id="add_shift_start_ampm" onchange="syncHiddenTime('add_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; width: 66px; background: rgba(15, 23, 42, 0.9); color: #38bdf8;">
                                <option value="AM" selected>AM</option>
                                <option value="PM">PM</option>
                            </select>
                        </div>
                        <input type="hidden" name="shift_start" id="add_shift_start" value="09:00:00">
                    </div>

                    <!-- Shift End (Out Time) Selectors -->
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.8rem; color: #94a3b8;">Shift End (Out Time)</label>
                        <div style="display: flex; gap: 4px; align-items: center;">
                            <select name="shift_end_hour" id="add_shift_end_hour" onchange="syncHiddenTime('add_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; background: rgba(15, 23, 42, 0.9); flex: 1;">
                                <?php for ($h = 1; $h <= 12; $h++): 
                                    $hVal = str_pad($h, 2, '0', STR_PAD_LEFT);
                                ?>
                                    <option value="<?= $hVal ?>" <?= $hVal === '06' ? 'selected' : '' ?>><?= $hVal ?></option>
                                <?php endfor; ?>
                            </select>
                            <span style="font-weight: 800; color: #38bdf8;">:</span>
                            <select name="shift_end_min" id="add_shift_end_min" onchange="syncHiddenTime('add_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; background: rgba(15, 23, 42, 0.9); flex: 1;">
                                <?php for ($m = 0; $m < 60; $m += 5): 
                                    $mVal = str_pad($m, 2, '0', STR_PAD_LEFT);
                                ?>
                                    <option value="<?= $mVal ?>" <?= $mVal === '00' ? 'selected' : '' ?>><?= $mVal ?></option>
                                <?php endfor; ?>
                            </select>
                            <select name="shift_end_ampm" id="add_shift_end_ampm" onchange="syncHiddenTime('add_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; width: 66px; background: rgba(15, 23, 42, 0.9); color: #38bdf8;">
                                <option value="AM">AM</option>
                                <option value="PM" selected>PM</option>
                            </select>
                        </div>
                        <input type="hidden" name="shift_end" id="add_shift_end" value="18:00:00">
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div class="form-group">
                    <label class="form-label">Cell / Mobile</label>
                    <input type="text" name="cell" class="form-control" placeholder="e.g. 01700000000" style="background: rgba(30, 41, 59, 0.8);">
                </div>
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" placeholder="e.g. user@example.com" style="background: rgba(30, 41, 59, 0.8);">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1.3fr; gap: 16px; margin-bottom: 24px;">
                <div class="form-group">
                    <label class="form-label">Location</label>
                    <input type="text" name="location" class="form-control" placeholder="e.g. Corporate Office" style="background: rgba(30, 41, 59, 0.8);">
                </div>
                <div class="form-group">
                    <label class="form-label">Account Status</label>
                    <select name="status" class="form-control" style="background: rgba(30, 41, 59, 0.8);">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Attendance Permission</label>
                    <select name="attendance_block_type" class="form-control" style="background: rgba(30, 41, 59, 0.8); font-weight: 600;">
                        <option value="none">Allowed (Normal Attendance)</option>
                        <option value="fingerprint">Block Fingerprint Only (Face Allowed)</option>
                        <option value="face">Block Face Only (Fingerprint Allowed)</option>
                        <option value="both">Block Both (Fingerprint & Face)</option>
                    </select>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" onclick="closeAddModal()" class="btn btn-secondary" style="padding: 10px 20px;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #10b981, #06b6d4); font-weight: 700; padding: 10px 24px;">Save & Register</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Employee Shift & Attendance Modal (Core Identity is Read-Only / Control Panel Managed) -->
<div id="editModal" class="modal-backdrop" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(6px); z-index: 9999; align-items: center; justify-content: center;">
    <div class="glass-panel" style="width: 100%; max-width: 740px; margin: 20px; border-radius: 20px; border: 1.5px solid rgba(56, 189, 248, 0.35); background: rgba(15, 23, 42, 0.98); max-height: 90vh; overflow-y: auto;">
        <div style="padding: 20px 24px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 1.25rem; font-weight: 800; color: #f8fafc; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-clock-rotate-left" style="color: #38bdf8;"></i>
                <span>Configure Duty Shift & Attendance Access</span>
            </h3>
            <button type="button" onclick="closeEditModal()" style="background: none; border: none; color: #94a3b8; font-size: 1.2rem; cursor: pointer;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="registered_users.php" style="padding: 24px;">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="update_employee">
            <input type="hidden" name="id" id="edit_id">

            <!-- Master Profile Summary (Read-Only) -->
            <div style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 14px; padding: 16px; margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                    <span style="font-size: 0.82rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-id-card-clip" style="color: #38bdf8;"></i> Employee Master Profile
                    </span>
                    <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); font-size: 0.72rem; padding: 3px 8px;">
                        <i class="fa-solid fa-lock"></i> Locked (Control Panel Managed)
                    </span>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 12px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.78rem; color: #64748b;">
                            Employee ID <i class="fa-solid fa-lock" style="font-size: 0.7rem; color: #475569; margin-left: 4px;"></i>
                        </label>
                        <input type="text" id="edit_emp_id" readonly class="form-control" style="background: rgba(15, 23, 42, 0.9); color: #38bdf8; font-family: monospace; font-weight: 700; cursor: not-allowed; border-color: rgba(255, 255, 255, 0.08);">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.78rem; color: #64748b;">
                            Full Name <i class="fa-solid fa-lock" style="font-size: 0.7rem; color: #475569; margin-left: 4px;"></i>
                        </label>
                        <input type="text" id="edit_name" readonly class="form-control" style="background: rgba(15, 23, 42, 0.9); color: #f8fafc; font-weight: 600; cursor: not-allowed; border-color: rgba(255, 255, 255, 0.08);">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 12px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.78rem; color: #64748b;">
                            Department <i class="fa-solid fa-lock" style="font-size: 0.7rem; color: #475569; margin-left: 4px;"></i>
                        </label>
                        <input type="text" id="edit_department" readonly class="form-control" style="background: rgba(15, 23, 42, 0.9); color: #cbd5e1; cursor: not-allowed; border-color: rgba(255, 255, 255, 0.08);">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.78rem; color: #64748b;">
                            Designation <i class="fa-solid fa-lock" style="font-size: 0.7rem; color: #475569; margin-left: 4px;"></i>
                        </label>
                        <input type="text" id="edit_designation" readonly class="form-control" style="background: rgba(15, 23, 42, 0.9); color: #cbd5e1; cursor: not-allowed; border-color: rgba(255, 255, 255, 0.08);">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 12px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.78rem; color: #64748b;">
                            Cell / Mobile <i class="fa-solid fa-lock" style="font-size: 0.7rem; color: #475569; margin-left: 4px;"></i>
                        </label>
                        <input type="text" id="edit_cell" readonly class="form-control" style="background: rgba(15, 23, 42, 0.9); color: #cbd5e1; cursor: not-allowed; border-color: rgba(255, 255, 255, 0.08);">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.78rem; color: #64748b;">
                            Email Address <i class="fa-solid fa-lock" style="font-size: 0.7rem; color: #475569; margin-left: 4px;"></i>
                        </label>
                        <input type="email" id="edit_email" readonly class="form-control" style="background: rgba(15, 23, 42, 0.9); color: #cbd5e1; cursor: not-allowed; border-color: rgba(255, 255, 255, 0.08);">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.78rem; color: #64748b;">
                            Location <i class="fa-solid fa-lock" style="font-size: 0.7rem; color: #475569; margin-left: 4px;"></i>
                        </label>
                        <input type="text" id="edit_location" readonly class="form-control" style="background: rgba(15, 23, 42, 0.9); color: #cbd5e1; cursor: not-allowed; border-color: rgba(255, 255, 255, 0.08);">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.78rem; color: #64748b;">
                            Account Status <i class="fa-solid fa-lock" style="font-size: 0.7rem; color: #475569; margin-left: 4px;"></i>
                        </label>
                        <input type="text" id="edit_status" readonly class="form-control" style="background: rgba(15, 23, 42, 0.9); color: #38bdf8; font-weight: 700; cursor: not-allowed; border-color: rgba(255, 255, 255, 0.08);">
                    </div>
                </div>
            </div>

            <!-- Operational Settings Configurable by Agent: Duty Shift & Attendance Access -->
            <div style="padding: 16px; border-radius: 14px; background: rgba(30, 41, 59, 0.4); border: 1.5px solid rgba(56, 189, 248, 0.35); margin-bottom: 16px;">
                <div style="font-weight: 700; color: #38bdf8; font-size: 0.92rem; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-business-time"></i> Duty Shift & Working Hours (Editable by Agent)
                </div>
                <div style="display: grid; grid-template-columns: 1.1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.8rem; color: #94a3b8;">Shift Name</label>
                        <input type="text" name="shift_name" id="edit_shift_name" class="form-control" style="background: rgba(15, 23, 42, 0.9);">
                    </div>

                    <!-- Shift Start (In Time) Selectors -->
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.8rem; color: #94a3b8;">Shift Start (In Time)</label>
                        <div style="display: flex; gap: 4px; align-items: center;">
                            <select name="shift_start_hour" id="edit_shift_start_hour" onchange="syncHiddenTime('edit_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; background: rgba(15, 23, 42, 0.9); flex: 1;">
                                <?php for ($h = 1; $h <= 12; $h++): 
                                    $hVal = str_pad($h, 2, '0', STR_PAD_LEFT);
                                ?>
                                    <option value="<?= $hVal ?>" <?= $hVal === '09' ? 'selected' : '' ?>><?= $hVal ?></option>
                                <?php endfor; ?>
                            </select>
                            <span style="font-weight: 800; color: #38bdf8;">:</span>
                            <select name="shift_start_min" id="edit_shift_start_min" onchange="syncHiddenTime('edit_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; background: rgba(15, 23, 42, 0.9); flex: 1;">
                                <?php for ($m = 0; $m < 60; $m += 5): 
                                    $mVal = str_pad($m, 2, '0', STR_PAD_LEFT);
                                ?>
                                    <option value="<?= $mVal ?>" <?= $mVal === '00' ? 'selected' : '' ?>><?= $mVal ?></option>
                                <?php endfor; ?>
                            </select>
                            <select name="shift_start_ampm" id="edit_shift_start_ampm" onchange="syncHiddenTime('edit_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; width: 66px; background: rgba(15, 23, 42, 0.9); color: #38bdf8;">
                                <option value="AM" selected>AM</option>
                                <option value="PM">PM</option>
                            </select>
                        </div>
                        <input type="hidden" name="shift_start" id="edit_shift_start" value="09:00:00">
                    </div>

                    <!-- Shift End (Out Time) Selectors -->
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.8rem; color: #94a3b8;">Shift End (Out Time)</label>
                        <div style="display: flex; gap: 4px; align-items: center;">
                            <select name="shift_end_hour" id="edit_shift_end_hour" onchange="syncHiddenTime('edit_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; background: rgba(15, 23, 42, 0.9); flex: 1;">
                                <?php for ($h = 1; $h <= 12; $h++): 
                                    $hVal = str_pad($h, 2, '0', STR_PAD_LEFT);
                                ?>
                                    <option value="<?= $hVal ?>" <?= $hVal === '06' ? 'selected' : '' ?>><?= $hVal ?></option>
                                <?php endfor; ?>
                            </select>
                            <span style="font-weight: 800; color: #38bdf8;">:</span>
                            <select name="shift_end_min" id="edit_shift_end_min" onchange="syncHiddenTime('edit_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; background: rgba(15, 23, 42, 0.9); flex: 1;">
                                <?php for ($m = 0; $m < 60; $m += 5): 
                                    $mVal = str_pad($m, 2, '0', STR_PAD_LEFT);
                                ?>
                                    <option value="<?= $mVal ?>" <?= $mVal === '00' ? 'selected' : '' ?>><?= $mVal ?></option>
                                <?php endfor; ?>
                            </select>
                            <select name="shift_end_ampm" id="edit_shift_end_ampm" onchange="syncHiddenTime('edit_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; width: 66px; background: rgba(15, 23, 42, 0.9); color: #38bdf8;">
                                <option value="AM">AM</option>
                                <option value="PM" selected>PM</option>
                            </select>
                        </div>
                        <input type="hidden" name="shift_end" id="edit_shift_end" value="18:00:00">
                    </div>
                </div>
            </div>

            <!-- Attendance Permission Configurable by Agent -->
            <div style="margin-bottom: 24px;">
                <label class="form-label" style="font-weight: 700; color: #f8fafc;">
                    Attendance Permission & Biometric Access
                </label>
                <select name="attendance_block_type" id="edit_attendance_block_type" class="form-control" style="background: rgba(30, 41, 59, 0.9); font-weight: 700; padding: 10px 14px; border: 1.5px solid rgba(56, 189, 248, 0.35);">
                    <option value="none">✓ Allowed (Normal Attendance - Both Finger & Face)</option>
                    <option value="fingerprint">⛔ Block Fingerprint Only (Face Allowed)</option>
                    <option value="face">⛔ Block Face Only (Fingerprint Allowed)</option>
                    <option value="both">🚫 Block Both (Fingerprint & Face Blocked)</option>
                </select>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" onclick="closeEditModal()" class="btn btn-secondary" style="padding: 10px 20px;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #38bdf8, #6366f1); font-weight: 700; padding: 10px 24px;">
                    <i class="fa-solid fa-floppy-disk"></i> Update Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function to24H(hour, min, ampm) {
    let h = parseInt(hour, 10) || 9;
    if (ampm === 'PM' && h < 12) h += 12;
    if (ampm === 'AM' && h === 12) h = 0;
    const hStr = h < 10 ? '0' + h : '' + h;
    const mStr = min.toString().padStart(2, '0');
    return hStr + ':' + mStr + ':00';
}

function parseTimeToParts(timeStr) {
    if (!timeStr) return { hour: '09', min: '00', ampm: 'AM' };
    const parts = timeStr.split(':');
    let h = parseInt(parts[0] || '9', 10);
    let m = (parts[1] || '00').substring(0, 2);
    if (m.length === 1) m = '0' + m;
    let ampm = 'AM';
    if (h >= 12) {
        ampm = 'PM';
        if (h > 12) h -= 12;
    } else if (h === 0) {
        h = 12;
    }
    const hStr = h < 10 ? '0' + h : '' + h;
    return { hour: hStr, min: m, ampm: ampm };
}

function setTimeDropdowns(prefix, type, timeStr) {
    const parts = parseTimeToParts(timeStr);
    const hEl = document.getElementById(prefix + 'shift_' + type + '_hour');
    const mEl = document.getElementById(prefix + 'shift_' + type + '_min');
    const apEl = document.getElementById(prefix + 'shift_' + type + '_ampm');
    
    if (hEl) hEl.value = parts.hour;
    if (mEl) {
        let exists = false;
        for (let i = 0; i < mEl.options.length; i++) {
            if (mEl.options[i].value === parts.min) {
                exists = true;
                break;
            }
        }
        if (!exists) {
            const opt = new Option(parts.min, parts.min, true, true);
            mEl.add(opt);
        }
        mEl.value = parts.min;
    }
    if (apEl) apEl.value = parts.ampm;
}

function syncHiddenTime(prefix) {
    const sH = document.getElementById(prefix + 'shift_start_hour')?.value || '09';
    const sM = document.getElementById(prefix + 'shift_start_min')?.value || '00';
    const sAP = document.getElementById(prefix + 'shift_start_ampm')?.value || 'AM';

    const eH = document.getElementById(prefix + 'shift_end_hour')?.value || '06';
    const eM = document.getElementById(prefix + 'shift_end_min')?.value || '00';
    const eAP = document.getElementById(prefix + 'shift_end_ampm')?.value || 'PM';

    const sTime = to24H(sH, sM, sAP);
    const eTime = to24H(eH, eM, eAP);

    const sInput = document.getElementById(prefix + 'shift_start');
    const eInput = document.getElementById(prefix + 'shift_end');
    if (sInput) sInput.value = sTime;
    if (eInput) eInput.value = eTime;
}

function openAddModal() {
    if (typeof checkGuestAction === 'function' && !checkGuestAction('Register New Employee')) return;
    const modal = document.getElementById('addModal');
    modal.style.display = 'flex';
    syncHiddenTime('add_');
}

function closeAddModal() {
    const modal = document.getElementById('addModal');
    modal.style.display = 'none';
}

function openEditModal(emp) {
    if (typeof checkGuestAction === 'function' && !checkGuestAction('Edit Employee Shift & Attendance')) return;
    document.getElementById('edit_id').value = emp.id;
    document.getElementById('edit_emp_id').value = emp.emp_id || '';
    document.getElementById('edit_name').value = emp.name || '';
    document.getElementById('edit_department').value = emp.department || '';
    document.getElementById('edit_designation').value = emp.designation || 'Staff';
    document.getElementById('edit_cell').value = emp.cell || 'N/A';
    document.getElementById('edit_email').value = emp.email || 'N/A';
    document.getElementById('edit_location').value = emp.location || 'Corporate Office';
    document.getElementById('edit_status').value = emp.status || 'Active';
    
    document.getElementById('edit_shift_name').value = emp.shift_name || 'General Shift';
    
    // Set In Time dropdowns
    setTimeDropdowns('edit_', 'start', emp.shift_start || '09:00:00');
    // Set Out Time dropdowns
    setTimeDropdowns('edit_', 'end', emp.shift_end || '18:00:00');
    syncHiddenTime('edit_');

    // Attendance Permission
    const blockType = emp.attendance_block_type || (emp.is_attendance_blocked == 1 ? 'both' : 'none');
    document.getElementById('edit_attendance_block_type').value = blockType;

    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

function applyShiftPreset(selectElem, mode) {
    const val = selectElem.value;
    let sH = '09', sM = '00', sAP = 'AM';
    let eH = '06', eM = '00', eAP = 'PM';

    if (val.includes('08:00 AM') || val.includes('Morning')) {
        sH = '08'; sM = '00'; sAP = 'AM';
        eH = '05'; eM = '00'; eAP = 'PM';
    } else if (val.includes('02:00 PM') || val.includes('Evening')) {
        sH = '02'; sM = '00'; sAP = 'PM';
        eH = '11'; eM = '00'; eAP = 'PM';
    } else if (val.includes('10:00 PM') || val.includes('Night')) {
        sH = '10'; sM = '00'; sAP = 'PM';
        eH = '07'; eM = '00'; eAP = 'AM';
    } else if (val.includes('General')) {
        sH = '09'; sM = '00'; sAP = 'AM';
        eH = '06'; eM = '00'; eAP = 'PM';
    }

    const prefix = mode === 'add' ? 'add_' : 'edit_';
    const sHourEl = document.getElementById(prefix + 'shift_start_hour');
    const sMinEl = document.getElementById(prefix + 'shift_start_min');
    const sAmpmEl = document.getElementById(prefix + 'shift_start_ampm');
    const eHourEl = document.getElementById(prefix + 'shift_end_hour');
    const eMinEl = document.getElementById(prefix + 'shift_end_min');
    const eAmpmEl = document.getElementById(prefix + 'shift_end_ampm');

    if (sHourEl) sHourEl.value = sH;
    if (sMinEl) sMinEl.value = sM;
    if (sAmpmEl) sAmpmEl.value = sAP;
    if (eHourEl) eHourEl.value = eH;
    if (eMinEl) eMinEl.value = eM;
    if (eAmpmEl) eAmpmEl.value = eAP;

    syncHiddenTime(prefix);
}

function filterUsers() {
    const input = document.getElementById('userSearchInput').value.toLowerCase();
    const dept = document.getElementById('deptFilter').value.toLowerCase();
    const blockFilter = document.getElementById('blockFilter').value.toLowerCase();
    const table = document.getElementById('usersTable');
    const tr = table.getElementsByTagName('tr');

    for (let i = 1; i < tr.length; i++) {
        const row = tr[i];
        const rowText = row.innerText.toLowerCase();
        const rowBlocked = (row.getAttribute('data-blocked') || '').toLowerCase();

        const matchesSearch = rowText.includes(input);
        const matchesDept = !dept || rowText.includes(dept);
        const matchesBlock = !blockFilter || rowBlocked === blockFilter;

        if (matchesSearch && matchesDept && matchesBlock) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
