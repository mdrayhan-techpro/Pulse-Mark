<?php
require_once __DIR__ . '/../config/init.php';
requirePermission('employee');
$pdo = getDB();

// 1. Handle Sample CSV Download Template
if (isset($_GET['download_sample']) && $_GET['download_sample'] == '1') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=PulseMark_Employee_Import_Template.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($output, ['emp_id', 'name', 'designation', 'department', 'section', 'email', 'cell', 'company', 'location', 'floor', 'status', 'shift_name', 'shift_start', 'shift_end', 'attendance_permission']);
    fputcsv($output, ['EMP-2001', 'Mahade Hasan', 'Staff', 'Information Technology', 'Software', 'mahade.hasan@remarkhb.com', '01711000001', 'Remark HB', 'Corporate Office', '12th', 'Active', 'General (09:00 AM - 06:00 PM)', '09:00:00', '18:00:00', 'none']);
    fputcsv($output, ['EMP-2002', 'Towfiq Ahmed', 'Sr. Officer', 'Information Technology', 'Network', 'towfiq@remarkhb.com', '01332563216', 'Remark HB', 'Corporate Office', '12th', 'Active', 'General (09:00 AM - 06:00 PM)', '09:00:00', '18:00:00', 'none']);
    fclose($output);
    exit;
}

// 2. Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=PulseMark_Employees_' . date('Y-m-d_His') . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($output, [
        'Employee ID',
        'Full Name',
        'Designation',
        'Department',
        'Section',
        'Email',
        'Cell Phone',
        'Company',
        'Location',
        'Floor',
        'Duty Shift Name',
        'Shift Start',
        'Shift End',
        'Attendance Permission',
        'Status',
        'Assigned Agents'
    ]);

    if ($pdo) {
        $exportEmps = $pdo->query("SELECT * FROM `employees` ORDER BY `id` DESC")->fetchAll();
        $allPerms = $pdo->query("SELECT p.*, u.name as agent_name FROM `employee_agent_permissions` p JOIN `users` u ON p.agent_id = u.id WHERE p.status = 'active'")->fetchAll();
        $permMap = [];
        foreach ($allPerms as $ap) {
            $permMap[$ap['emp_id']][] = $ap['agent_name'] . ($ap['access_type'] === 'temporary' ? ' (Temp)' : ' (Perm)');
        }

        foreach ($exportEmps as $row) {
            $blockType = $row['attendance_block_type'] ?? ((int)($row['is_attendance_blocked'] ?? 0) === 1 ? 'both' : 'none');
            $permLabel = 'Allowed (Normal)';
            if ($blockType === 'fingerprint') $permLabel = 'Block Fingerprint Only';
            elseif ($blockType === 'face') $permLabel = 'Block Face Only';
            elseif ($blockType === 'both') $permLabel = 'Block Both';

            $agentsStr = !empty($permMap[$row['emp_id']]) ? implode('; ', $permMap[$row['emp_id']]) : 'None';
            $shiftStart = !empty($row['shift_start']) ? date('h:i A', strtotime($row['shift_start'])) : '09:00 AM';
            $shiftEnd = !empty($row['shift_end']) ? date('h:i A', strtotime($row['shift_end'])) : '06:00 PM';

            fputcsv($output, [
                $row['emp_id'],
                $row['name'],
                $row['designation'] ?? '',
                $row['department'] ?? '',
                $row['section'] ?? '',
                $row['email'] ?? '',
                $row['cell'] ?? '',
                $row['company'] ?? '',
                $row['location'] ?? '',
                $row['floor'] ?? '',
                $row['shift_name'] ?? 'General Shift',
                $shiftStart,
                $shiftEnd,
                $permLabel,
                $row['status'] ?? 'Active',
                $agentsStr
            ]);
        }
    }
    fclose($output);
    exit;
}

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

// Fetch all available agents for assignment
$allAgents = [];
if ($pdo) {
    try {
        $allAgents = $pdo->query("SELECT id, name, username, department FROM `users` WHERE `role` = 'agent' AND `status` = 'active' ORDER BY name ASC")->fetchAll();
    } catch (Exception $e) {
        $allAgents = [];
    }
}

// Handle Create / Update / Delete / Block Employee
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Security validation failed.');
        header('Location: employee.php');
        exit;
    }

    $action = $_POST['action'];

    // 1. Create Employee
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

        $assigned_agents = $_POST['assigned_agents'] ?? [];
        $primary_agent_id = !empty($assigned_agents) ? (int)$assigned_agents[0] : null;

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
            setFlash('danger', "Duplicate Blocked! Employee Name '{$name}' (ID: {$dupName['emp_id']}) is already registered under {$existingAgent}. You cannot create a duplicate record!");
        } elseif ($dupCell) {
            $existingAgent = !empty($dupCell['agent_name']) ? $dupCell['agent_name'] : ("Agent #" . $dupCell['agent_id']);
            setFlash('danger', "Duplicate Blocked! Phone number '{$cell}' is already registered to employee '{$dupCell['name']}' (ID: {$dupCell['emp_id']}) under {$existingAgent}!");
        } elseif ($dupEmail) {
            $existingAgent = !empty($dupEmail['agent_name']) ? $dupEmail['agent_name'] : ("Agent #" . $dupEmail['agent_id']);
            setFlash('danger', "Duplicate Blocked! Email '{$email}' is already registered to employee '{$dupEmail['name']}' (ID: {$dupEmail['emp_id']}) under {$existingAgent}!");
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO `employees` (`emp_id`, `agent_id`, `name`, `designation`, `department`, `section`, `email`, `cell`, `company`, `location`, `floor`, `status`, `shift_name`, `shift_start`, `shift_end`, `is_attendance_blocked`, `attendance_block_type`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$emp_id, $primary_agent_id, $name, $designation, $department, $section, $email, $cell, $company, $location, $floor, $status, $shift_name, $shift_start, $shift_end, $is_attendance_blocked, $attendance_block_type]);

                // Sync Assigned Agents & Permissions
                syncEmployeeAgentPermissions(
                    $pdo, 
                    $emp_id, 
                    $assigned_agents, 
                    $_POST['agent_access_type'] ?? [], 
                    $_POST['agent_valid_from'] ?? [], 
                    $_POST['agent_valid_to'] ?? []
                );

                logAudit($pdo, 'EMPLOYEE_CREATED', 'Employee', "Created new employee '{$name}' (Emp ID: {$emp_id}) in {$department} / {$designation} with shift {$shift_name}.");
                setFlash('success', "Employee '{$name}' added successfully with assigned Agent permissions!");
            } catch (Exception $e) {
                setFlash('danger', 'Failed to save employee: ' . $e->getMessage());
            }
        }
        header('Location: employee.php');
        exit;
    }

    // 2. Update Employee
    if ($action === 'update_employee' && $pdo) {
        $id = (int)($_POST['id'] ?? 0);
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

        $assigned_agents = $_POST['assigned_agents'] ?? [];
        $primary_agent_id = !empty($assigned_agents) ? (int)$assigned_agents[0] : null;

        // Check if emp_id taken by another record
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM `employees` WHERE `emp_id` = ? AND `id` != ?");
        $checkStmt->execute([$emp_id, $id]);
        if ($checkStmt->fetchColumn() > 0) {
            setFlash('danger', "Employee ID '{$emp_id}' is already assigned to another employee.");
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE `employees` SET `emp_id` = ?, `agent_id` = ?, `name` = ?, `designation` = ?, `department` = ?, `section` = ?, `email` = ?, `cell` = ?, `company` = ?, `location` = ?, `floor` = ?, `status` = ?, `shift_name` = ?, `shift_start` = ?, `shift_end` = ?, `is_attendance_blocked` = ?, `attendance_block_type` = ? WHERE `id` = ?");
                $stmt->execute([$emp_id, $primary_agent_id, $name, $designation, $department, $section, $email, $cell, $company, $location, $floor, $status, $shift_name, $shift_start, $shift_end, $is_attendance_blocked, $attendance_block_type, $id]);

                // Sync Assigned Agents & Permissions
                syncEmployeeAgentPermissions(
                    $pdo, 
                    $emp_id, 
                    $assigned_agents, 
                    $_POST['agent_access_type'] ?? [], 
                    $_POST['agent_valid_from'] ?? [], 
                    $_POST['agent_valid_to'] ?? []
                );

                logAudit($pdo, 'EMPLOYEE_UPDATED', 'Employee', "Updated profile details and shift settings for employee '{$name}' (Emp ID: {$emp_id}).");
                setFlash('success', "Employee '{$name}' updated successfully with assigned Agent permissions!");
            } catch (Exception $e) {
                setFlash('danger', 'Failed to update employee: ' . $e->getMessage());
            }
        }
        header('Location: employee.php');
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
            logAudit($pdo, 'ATTENDANCE_PERMISSION_CHANGED', 'Employee', "Changed attendance permission for employee ({$emp_id}) to {$statusText}.", null, null, null, $target_block_type === 'none' ? 'Success' : 'Warning');
            setFlash($msgType, "Attendance for Employee ({$emp_id}) is now set to: {$statusText}.");
        } catch (Exception $e) {
            setFlash('danger', 'Failed to change attendance permission: ' . $e->getMessage());
        }
        header('Location: employee.php');
        exit;
    }

    // 4. Delete Employee
    if ($action === 'delete_employee' && $pdo) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $empIdQuery = $pdo->prepare("SELECT emp_id FROM `employees` WHERE `id` = ?");
            $empIdQuery->execute([$id]);
            $delEmpId = $empIdQuery->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM `employees` WHERE `id` = ?");
            $stmt->execute([$id]);

            if ($delEmpId) {
                $delPerm = $pdo->prepare("DELETE FROM `employee_agent_permissions` WHERE `emp_id` = ?");
                $delPerm->execute([$delEmpId]);
            }

            logAudit($pdo, 'EMPLOYEE_DELETED', 'Employee', "Deleted employee record (Emp ID: {$delEmpId}) and associated agent permissions.", null, null, null, 'Warning');
            setFlash('success', 'Employee deleted successfully.');
        }
        header('Location: employee.php');
        exit;
    }

    // 5. Import Employees via CSV
    if ($action === 'import_employees' && $pdo) {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            setFlash('danger', 'Please choose a valid CSV file to upload.');
            header('Location: employee.php');
            exit;
        }

        $fileTmpPath = $_FILES['csv_file']['tmp_name'];
        $fileName = $_FILES['csv_file']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($fileExtension, ['csv', 'txt'])) {
            setFlash('danger', 'Invalid file format. Please upload a valid .csv file.');
            header('Location: employee.php');
            exit;
        }

        $handle = fopen($fileTmpPath, 'r');
        if ($handle === false) {
            setFlash('danger', 'Could not open uploaded CSV file.');
            header('Location: employee.php');
            exit;
        }

        $duplicateAction = sanitize($_POST['duplicate_action'] ?? 'skip'); // 'update' or 'skip'
        $assigned_agents = $_POST['assigned_agents'] ?? [];
        $access_types = $_POST['agent_access_type'] ?? [];
        $valid_froms = $_POST['agent_valid_from'] ?? [];
        $valid_tos = $_POST['agent_valid_to'] ?? [];

        $primary_agent_id = !empty($assigned_agents) ? (int)$assigned_agents[0] : null;

        // Read header row
        $rawHeader = fgetcsv($handle);
        if (!$rawHeader) {
            fclose($handle);
            setFlash('danger', 'The uploaded CSV file is empty.');
            header('Location: employee.php');
            exit;
        }

        // Strip BOM if present in first column header
        $rawHeader[0] = preg_replace('/^\xEF\xBB\xBF/', '', $rawHeader[0]);

        // Map column index to standard field names
        $colMap = [];
        foreach ($rawHeader as $idx => $headerName) {
            $clean = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace([' ', '-'], '_', $headerName))));
            
            if (in_array($clean, ['empid', 'emp_id', 'employee_id', 'id', 'id_no', 'card_no', 'employeeid'])) {
                $colMap['emp_id'] = $idx;
            } elseif (in_array($clean, ['name', 'full_name', 'fullname', 'employee_name', 'emp_name'])) {
                $colMap['name'] = $idx;
            } elseif (in_array($clean, ['designation', 'designation_title', 'title', 'position', 'role', 'job_title'])) {
                $colMap['designation'] = $idx;
            } elseif (in_array($clean, ['department', 'dept', 'department_name', 'dept_name'])) {
                $colMap['department'] = $idx;
            } elseif (in_array($clean, ['section', 'sec', 'section_name'])) {
                $colMap['section'] = $idx;
            } elseif (in_array($clean, ['email', 'e_mail', 'email_address', 'mail'])) {
                $colMap['email'] = $idx;
            } elseif (in_array($clean, ['cell', 'phone', 'mobile', 'contact', 'phone_number', 'cell_phone', 'mobile_no', 'phone_no'])) {
                $colMap['cell'] = $idx;
            } elseif (in_array($clean, ['company', 'company_name', 'organization', 'org'])) {
                $colMap['company'] = $idx;
            } elseif (in_array($clean, ['location', 'office_location', 'branch', 'branch_name'])) {
                $colMap['location'] = $idx;
            } elseif (in_array($clean, ['floor', 'floor_no', 'floornumber'])) {
                $colMap['floor'] = $idx;
            } elseif (in_array($clean, ['status', 'emp_status', 'employee_status'])) {
                $colMap['status'] = $idx;
            } elseif (in_array($clean, ['shift_name', 'shift', 'duty_shift', 'duty_shift_name'])) {
                $colMap['shift_name'] = $idx;
            } elseif (in_array($clean, ['shift_start', 'shift_start_time', 'start_time', 'in_time'])) {
                $colMap['shift_start'] = $idx;
            } elseif (in_array($clean, ['shift_end', 'shift_end_time', 'end_time', 'out_time'])) {
                $colMap['shift_end'] = $idx;
            } elseif (in_array($clean, ['attendance_permission', 'attendance_block_type', 'block_type', 'permission', 'attendance_block', 'is_attendance_blocked'])) {
                $colMap['attendance_block_type'] = $idx;
            }
        }

        if (!isset($colMap['emp_id']) || !isset($colMap['name'])) {
            fclose($handle);
            setFlash('danger', "Invalid CSV Header format! Columns 'emp_id' and 'name' are required. Please download our sample CSV template.");
            header('Location: employee.php');
            exit;
        }

        $insertedCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;

        $checkStmt = $pdo->prepare("SELECT id, emp_id, name FROM `employees` WHERE LOWER(TRIM(emp_id)) = LOWER(TRIM(?))");
        
        $insertStmt = $pdo->prepare("INSERT INTO `employees` 
            (`emp_id`, `agent_id`, `name`, `designation`, `department`, `section`, `email`, `cell`, `company`, `location`, `floor`, `status`, `shift_name`, `shift_start`, `shift_end`, `is_attendance_blocked`, `attendance_block_type`) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $updateStmt = $pdo->prepare("UPDATE `employees` SET 
            `agent_id` = COALESCE(?, `agent_id`), 
            `name` = ?, 
            `designation` = ?, 
            `department` = ?, 
            `section` = ?, 
            `email` = ?, 
            `cell` = ?, 
            `company` = ?, 
            `location` = ?, 
            `floor` = ?, 
            `status` = ?, 
            `shift_name` = ?, 
            `shift_start` = ?, 
            `shift_end` = ?, 
            `is_attendance_blocked` = ?, 
            `attendance_block_type` = ? 
            WHERE `id` = ?");

        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row, 'trim'))) continue;

            $emp_id = sanitize(trim($row[$colMap['emp_id']] ?? ''));
            $name = sanitize(trim($row[$colMap['name']] ?? ''));

            if (empty($emp_id) || empty($name)) {
                $skippedCount++;
                continue;
            }

            $designation = isset($colMap['designation']) ? sanitize(trim($row[$colMap['designation']] ?? '')) : 'Staff';
            $department = isset($colMap['department']) ? sanitize(trim($row[$colMap['department']] ?? '')) : 'General';
            $section = isset($colMap['section']) ? sanitize(trim($row[$colMap['section']] ?? '')) : '';
            $email = isset($colMap['email']) ? sanitize(trim($row[$colMap['email']] ?? '')) : '';
            $cell = isset($colMap['cell']) ? sanitize(trim($row[$colMap['cell']] ?? '')) : '';
            $company = isset($colMap['company']) ? sanitize(trim($row[$colMap['company']] ?? '')) : '';
            $location = isset($colMap['location']) ? sanitize(trim($row[$colMap['location']] ?? '')) : '';
            $floor = isset($colMap['floor']) ? sanitize(trim($row[$colMap['floor']] ?? '')) : '';
            
            $statusRaw = isset($colMap['status']) ? sanitize(trim($row[$colMap['status']] ?? 'Active')) : 'Active';
            $status = (strtolower($statusRaw) === 'inactive') ? 'Inactive' : 'Active';

            $shift_name = isset($colMap['shift_name']) ? sanitize(trim($row[$colMap['shift_name']] ?? '')) : '';
            if (empty($shift_name)) $shift_name = 'General (09:00 AM - 06:00 PM)';

            $shift_start_raw = isset($colMap['shift_start']) ? trim($row[$colMap['shift_start']] ?? '') : '';
            $shift_start = '09:00:00';
            if (!empty($shift_start_raw)) {
                $timeParsed = strtotime($shift_start_raw);
                if ($timeParsed !== false) $shift_start = date('H:i:00', $timeParsed);
            }

            $shift_end_raw = isset($colMap['shift_end']) ? trim($row[$colMap['shift_end']] ?? '') : '';
            $shift_end = '18:00:00';
            if (!empty($shift_end_raw)) {
                $timeParsed = strtotime($shift_end_raw);
                if ($timeParsed !== false) $shift_end = date('H:i:00', $timeParsed);
            }

            $blockTypeRaw = isset($colMap['attendance_block_type']) ? strtolower(trim($row[$colMap['attendance_block_type']] ?? 'none')) : 'none';
            if (in_array($blockTypeRaw, ['fingerprint', 'finger', 'block_finger'])) {
                $attendance_block_type = 'fingerprint';
            } elseif (in_array($blockTypeRaw, ['face', 'block_face'])) {
                $attendance_block_type = 'face';
            } elseif (in_array($blockTypeRaw, ['both', 'all', 'blocked', 'block_both', '1'])) {
                $attendance_block_type = 'both';
            } else {
                $attendance_block_type = 'none';
            }
            $is_attendance_blocked = ($attendance_block_type !== 'none') ? 1 : 0;

            // Check if employee ID exists
            $checkStmt->execute([$emp_id]);
            $existing = $checkStmt->fetch();

            if ($existing) {
                if ($duplicateAction === 'update') {
                    $updateStmt->execute([
                        $primary_agent_id,
                        $name,
                        $designation,
                        $department,
                        $section,
                        $email,
                        $cell,
                        $company,
                        $location,
                        $floor,
                        $status,
                        $shift_name,
                        $shift_start,
                        $shift_end,
                        $is_attendance_blocked,
                        $attendance_block_type,
                        $existing['id']
                    ]);

                    if (!empty($assigned_agents)) {
                        syncEmployeeAgentPermissions($pdo, $emp_id, $assigned_agents, $access_types, $valid_froms, $valid_tos);
                    }
                    $updatedCount++;
                } else {
                    $skippedCount++;
                }
            } else {
                // Check if duplicate cell or email exists
                $isConflict = false;
                if (!empty($cell)) {
                    $cellChk = $pdo->prepare("SELECT COUNT(*) FROM `employees` WHERE TRIM(cell) = TRIM(?) AND cell != ''");
                    $cellChk->execute([$cell]);
                    if ($cellChk->fetchColumn() > 0) $isConflict = true;
                }
                if (!$isConflict && !empty($email)) {
                    $emailChk = $pdo->prepare("SELECT COUNT(*) FROM `employees` WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) AND email != ''");
                    $emailChk->execute([$email]);
                    if ($emailChk->fetchColumn() > 0) $isConflict = true;
                }

                if ($isConflict && $duplicateAction === 'skip') {
                    $skippedCount++;
                    continue;
                }

                try {
                    $insertStmt->execute([
                        $emp_id,
                        $primary_agent_id,
                        $name,
                        $designation,
                        $department,
                        $section,
                        $email,
                        $cell,
                        $company,
                        $location,
                        $floor,
                        $status,
                        $shift_name,
                        $shift_start,
                        $shift_end,
                        $is_attendance_blocked,
                        $attendance_block_type
                    ]);

                    if (!empty($assigned_agents)) {
                        syncEmployeeAgentPermissions($pdo, $emp_id, $assigned_agents, $access_types, $valid_froms, $valid_tos);
                    }
                    $insertedCount++;
                } catch (Exception $e) {
                    $skippedCount++;
                }
            }
        }

        fclose($handle);

        $msg = "CSV Import Complete: <strong>{$insertedCount}</strong> employee(s) added, <strong>{$updatedCount}</strong> updated";
        if ($skippedCount > 0) {
            $msg .= ", <strong>{$skippedCount}</strong> duplicate/invalid record(s) skipped.";
        } else {
            $msg .= ".";
        }

        $msgType = ($insertedCount > 0 || $updatedCount > 0) ? 'success' : ($skippedCount > 0 ? 'warning' : 'danger');
        setFlash($msgType, $msg);
        header('Location: employee.php');
        exit;
    }
}

// Fetch all employees and their assigned agents
$employees = [];
$agentPermsByEmp = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM `employees` ORDER BY `id` DESC");
        $employees = $stmt->fetchAll();

        $permRows = $pdo->query("SELECT p.*, u.name as agent_name, u.username as agent_username 
                                 FROM `employee_agent_permissions` p 
                                 JOIN `users` u ON p.agent_id = u.id 
                                 WHERE p.status = 'active'")->fetchAll();
        foreach ($permRows as $pr) {
            $agentPermsByEmp[$pr['emp_id']][] = $pr;
        }
    } catch (Exception $e) {
        $employees = [];
    }
}

$flash = getFlash();

$pageTitle = 'Employee Management';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<main class="main-content">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-body">
        <?php if ($flash): ?>
            <div class="alert-box alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation' ?>"></i>
                <span><?= htmlspecialchars($flash['message']) ?></span>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Employee & Shift Management</h1>
                <p class="page-subtitle">View and manage company employee records, duty shifts, multi-agent assignments, and permissions</p>
            </div>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <a href="employee.php?export=csv" class="btn btn-secondary" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.35); text-decoration: none;" title="Export all employee records to CSV file">
                    <i class="fa-solid fa-file-export"></i>
                    <span>Export CSV</span>
                </a>
                <button type="button" class="btn btn-secondary" data-modal-target="importEmployeeModal" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.35);" title="Bulk import employee records from CSV file">
                    <i class="fa-solid fa-file-import"></i>
                    <span>Import CSV</span>
                </button>
                <button class="btn btn-primary" data-modal-target="addEmployeeModal">
                    <i class="fa-solid fa-user-plus"></i>
                    <span>Add New Employee</span>
                </button>
            </div>
        </div>

        <!-- Employee Table -->
        <div class="glass-panel" style="padding: 24px;">
            <div class="card-title-bar">
                <div>
                    <h3 class="card-title">Employee List</h3>
                    <p style="font-size: 0.82rem; color: var(--text-muted);">Total: <?= count($employees) ?> employee records</p>
                </div>
                <div class="nav-search" style="width: 260px; padding: 6px 12px;">
                    <i class="fa-solid fa-magnifying-glass fa-sm"></i>
                    <input type="text" class="table-search-input" data-target-table="employeeTable" placeholder="Search by name, ID, dept...">
                </div>
            </div>

            <div class="table-container">
                <table class="custom-table" id="employeeTable">
                    <thead>
                        <tr>
                            <th>Emp ID</th>
                            <th>Name</th>
                            <th>Designation & Dept</th>
                            <th>Duty Shift</th>
                            <th>Assigned Agents & Validity</th>
                            <th>Contact</th>
                            <th>Location / Floor</th>
                            <th>Attendance Permission</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($employees)): ?>
                            <?php foreach ($employees as $emp): 
                                $blockType = $emp['attendance_block_type'] ?? ((int)($emp['is_attendance_blocked'] ?? 0) === 1 ? 'both' : 'none');
                                if (empty($blockType)) $blockType = 'none';
                                
                                $shiftStartFmt = !empty($emp['shift_start']) ? date('h:i A', strtotime($emp['shift_start'])) : '09:00 AM';
                                $shiftEndFmt = !empty($emp['shift_end']) ? date('h:i A', strtotime($emp['shift_end'])) : '06:00 PM';
                                $shiftName = $emp['shift_name'] ?? 'General Shift';

                                $myPerms = $agentPermsByEmp[$emp['emp_id']] ?? [];
                                $empWithPerms = $emp;
                                $empWithPerms['assigned_perms'] = $myPerms;
                            ?>
                                <tr>
                                    <td><strong style="color: var(--primary);"><?= htmlspecialchars($emp['emp_id']) ?></strong></td>
                                    <td style="font-weight: 600;"><?= htmlspecialchars($emp['name']) ?></td>
                                    <td>
                                        <div style="font-weight: 600;"><?= htmlspecialchars($emp['designation'] ?: 'Staff') ?></div>
                                        <div style="font-size: 0.78rem; color: var(--primary);"><?= htmlspecialchars($emp['department'] ?: 'General') ?></div>
                                    </td>
                                    <!-- Duty Shift Column -->
                                    <td>
                                        <div style="font-weight: 700; color: #38bdf8; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 5px;">
                                            <i class="fa-solid fa-clock"></i> <?= $shiftStartFmt ?> - <?= $shiftEndFmt ?>
                                        </div>
                                        <div style="font-size: 0.74rem; color: var(--text-muted);"><?= htmlspecialchars($shiftName) ?></div>
                                    </td>
                                    <!-- Assigned Agents & Validity Column -->
                                    <td>
                                        <?php if (!empty($myPerms)): ?>
                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                <?php foreach ($myPerms as $ap): 
                                                    $isTemp = $ap['access_type'] === 'temporary';
                                                    $now = date('Y-m-d H:i:s');
                                                    $isExpired = $isTemp && !empty($ap['valid_to']) && $now > $ap['valid_to'];
                                                ?>
                                                    <div style="display: inline-flex; align-items: center; gap: 6px;">
                                                        <?php if ($isExpired): ?>
                                                            <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); font-size: 0.72rem; padding: 2px 6px;">
                                                                <i class="fa-solid fa-clock-rotate-left"></i> <?= htmlspecialchars($ap['agent_name']) ?> (Expired)
                                                            </span>
                                                        <?php elseif ($isTemp && !empty($ap['valid_to'])): ?>
                                                            <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.35); font-size: 0.72rem; padding: 2px 6px;">
                                                                <i class="fa-solid fa-stopwatch"></i> <?= htmlspecialchars($ap['agent_name']) ?> (Till <?= date('d M', strtotime($ap['valid_to'])) ?>)
                                                            </span>
                                                        <?php elseif ($isTemp): ?>
                                                            <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.35); font-size: 0.72rem; padding: 2px 6px;">
                                                                <i class="fa-solid fa-stopwatch"></i> <?= htmlspecialchars($ap['agent_name']) ?> (Temp)
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); font-size: 0.72rem; padding: 2px 6px;">
                                                                <i class="fa-solid fa-headset"></i> <?= htmlspecialchars($ap['agent_name']) ?> (Perm)
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #64748b; font-size: 0.78rem;">No agent assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($emp['cell'] ?: '-') ?></div>
                                        <small style="color: var(--text-muted); font-size: 0.75rem;"><?= htmlspecialchars($emp['email'] ?: '') ?></small>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($emp['location'] ?: '-') ?></div>
                                        <small style="color: var(--text-muted); font-size: 0.75rem;"><?= htmlspecialchars($emp['floor'] ?: '-') ?></small>
                                    </td>
                                    <!-- Attendance Permission Column (Quick Granular Selector) -->
                                    <td>
                                        <form method="POST" action="employee.php" style="margin: 0; display: inline-block;">
                                            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
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
                                    <td>
                                        <span class="badge <?= strtolower($emp['status']) === 'active' ? 'badge-normal' : 'badge-attention' ?>">
                                            <i class="fa-solid fa-circle fa-xs"></i> <?= htmlspecialchars($emp['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: inline-flex; gap: 6px; align-items: center;">
                                            <!-- Edit Button -->
                                            <button type="button" class="btn btn-secondary btn-sm edit-emp-btn" 
                                                data-emp='<?= json_encode($empWithPerms, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>' 
                                                style="color: var(--primary);" 
                                                title="Edit Employee & Agent Assignments">
                                                <i class="fa-regular fa-pen-to-square"></i>
                                            </button>

                                            <!-- Delete Button -->
                                            <form method="POST" action="employee.php" style="display: inline; margin: 0;">
                                                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                                <input type="hidden" name="action" value="delete_employee">
                                                <input type="hidden" name="id" value="<?= $emp['id'] ?>">
                                                <button type="button" class="btn btn-secondary btn-sm" onclick="confirmDeleteEmployee(event, this.form, '<?= htmlspecialchars(addslashes($emp['name'])) ?>')" style="color: var(--danger);" title="Delete">
                                                    <i class="fa-regular fa-trash-can"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" style="text-align: center; color: var(--text-muted); padding: 35px;">
                                    No employees found. Click "Add New Employee" to add the first record.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Add Employee Centered Modal Pop-up -->
<div class="modal-backdrop" id="addEmployeeModal">
    <div class="modal-box" style="max-width: 760px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
            <h3 class="modal-title" style="font-size: 1.2rem; font-weight: 700;">Add New Employee</h3>
            <button type="button" class="close-modal" data-modal-close>&times;</button>
        </div>
        <form method="POST" action="employee.php">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="create_employee">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div class="form-group">
                        <label class="form-label">Emp ID *</label>
                        <input type="text" name="emp_id" class="form-control" placeholder="EMP-1001" required autofocus>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Name *</label>
                        <input type="text" name="name" class="form-control" placeholder="Full Name" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div class="form-group">
                        <label class="form-label">Designation</label>
                        <input type="text" name="designation" class="form-control" placeholder="Senior Officer">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <input type="text" name="department" class="form-control" placeholder="Accounts / Production">
                    </div>
                </div>

                <!-- Shift Settings with Select Dropdowns (No Manual Typing) -->
                <div style="padding: 14px; border-radius: 10px; background: rgba(56, 189, 248, 0.05); border: 1px solid rgba(56, 189, 248, 0.2); margin-bottom: 14px;">
                    <div style="font-weight: 700; color: var(--primary); font-size: 0.88rem; margin-bottom: 10px;">
                        <i class="fa-solid fa-clock"></i> Duty Shift & Working Hours (Select Time Dropdown)
                    </div>
                    <div style="display: grid; grid-template-columns: 1.1fr 1fr 1fr; gap: 10px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 0.8rem;">Shift Name</label>
                            <input type="text" name="shift_name" value="General (09:00 AM - 06:00 PM)" class="form-control">
                        </div>

                        <!-- Shift Start Dropdown -->
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 0.8rem;">Shift Start (In Time)</label>
                            <div style="display: flex; gap: 4px; align-items: center;">
                                <select name="shift_start_hour" id="add_admin_start_hour" onchange="syncAdminTime('add_admin_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; flex: 1;">
                                    <?php for ($h = 1; $h <= 12; $h++): 
                                        $hVal = str_pad($h, 2, '0', STR_PAD_LEFT);
                                    ?>
                                        <option value="<?= $hVal ?>" <?= $hVal === '09' ? 'selected' : '' ?>><?= $hVal ?></option>
                                    <?php endfor; ?>
                                </select>
                                <span style="font-weight: 800; color: var(--primary);">:</span>
                                <select name="shift_start_min" id="add_admin_start_min" onchange="syncAdminTime('add_admin_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; flex: 1;">
                                    <?php for ($m = 0; $m < 60; $m += 5): 
                                        $mVal = str_pad($m, 2, '0', STR_PAD_LEFT);
                                    ?>
                                        <option value="<?= $mVal ?>" <?= $mVal === '00' ? 'selected' : '' ?>><?= $mVal ?></option>
                                    <?php endfor; ?>
                                </select>
                                <select name="shift_start_ampm" id="add_admin_start_ampm" onchange="syncAdminTime('add_admin_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; width: 66px; color: var(--primary);">
                                    <option value="AM" selected>AM</option>
                                    <option value="PM">PM</option>
                                </select>
                            </div>
                            <input type="hidden" name="shift_start" id="add_admin_shift_start" value="09:00:00">
                        </div>

                        <!-- Shift End Dropdown -->
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 0.8rem;">Shift End (Out Time)</label>
                            <div style="display: flex; gap: 4px; align-items: center;">
                                <select name="shift_end_hour" id="add_admin_end_hour" onchange="syncAdminTime('add_admin_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; flex: 1;">
                                    <?php for ($h = 1; $h <= 12; $h++): 
                                        $hVal = str_pad($h, 2, '0', STR_PAD_LEFT);
                                    ?>
                                        <option value="<?= $hVal ?>" <?= $hVal === '06' ? 'selected' : '' ?>><?= $hVal ?></option>
                                    <?php endfor; ?>
                                </select>
                                <span style="font-weight: 800; color: var(--primary);">:</span>
                                <select name="shift_end_min" id="add_admin_end_min" onchange="syncAdminTime('add_admin_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; flex: 1;">
                                    <?php for ($m = 0; $m < 60; $m += 5): 
                                        $mVal = str_pad($m, 2, '0', STR_PAD_LEFT);
                                    ?>
                                        <option value="<?= $mVal ?>" <?= $mVal === '00' ? 'selected' : '' ?>><?= $mVal ?></option>
                                    <?php endfor; ?>
                                </select>
                                <select name="shift_end_ampm" id="add_admin_end_ampm" onchange="syncAdminTime('add_admin_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; width: 66px; color: var(--primary);">
                                    <option value="AM">AM</option>
                                    <option value="PM" selected>PM</option>
                                </select>
                            </div>
                            <input type="hidden" name="shift_end" id="add_admin_shift_end" value="18:00:00">
                        </div>
                    </div>
                </div>

                <!-- Multi-Agent Assignment & Attendance Validity Section -->
                <div style="padding: 14px; border-radius: 10px; background: rgba(56, 189, 248, 0.05); border: 1.5px solid rgba(56, 189, 248, 0.25); margin-bottom: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <div style="font-weight: 700; color: var(--primary); font-size: 0.88rem; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-user-shield"></i> Assigned Agents & Attendance Validity
                        </div>
                        <span style="font-size: 0.75rem; color: var(--text-muted);">Assign multiple agents (Permanent or Time-bound Range)</span>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <?php foreach ($allAgents as $ag): ?>
                            <div class="agent-item-card" style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 8px; padding: 10px 12px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0; font-size: 0.86rem; font-weight: 600; color: #f8fafc;">
                                        <input type="checkbox" name="assigned_agents[]" value="<?= $ag['id'] ?>" onchange="toggleAgentControls(this, 'add_ag_<?= $ag['id'] ?>')">
                                        <i class="fa-solid fa-headset" style="color: #38bdf8;"></i>
                                        <span><?= htmlspecialchars($ag['name']) ?> (<?= htmlspecialchars($ag['username']) ?>)</span>
                                    </label>
                                    <div id="add_ag_<?= $ag['id'] ?>_controls" style="display: none; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        <select name="agent_access_type[<?= $ag['id'] ?>]" onchange="toggleAgentDateInputs(this, 'add_ag_<?= $ag['id'] ?>_dates')" class="form-control form-control-sm" style="width: auto; padding: 3px 8px; font-size: 0.78rem;">
                                            <option value="permanent">Permanent Access</option>
                                            <option value="temporary">Temporary (Date-Time Range)</option>
                                        </select>
                                        <div id="add_ag_<?= $ag['id'] ?>_dates" style="display: none; align-items: center; gap: 4px;">
                                            <span style="font-size: 0.72rem; color: #94a3b8;">From:</span>
                                            <input type="datetime-local" name="agent_valid_from[<?= $ag['id'] ?>]" class="form-control form-control-sm" style="padding: 3px 6px; font-size: 0.75rem; width: auto;">
                                            <span style="font-size: 0.72rem; color: #94a3b8;">To:</span>
                                            <input type="datetime-local" name="agent_valid_to[<?= $ag['id'] ?>]" class="form-control form-control-sm" style="padding: 3px 6px; font-size: 0.75rem; width: auto;">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div class="form-group">
                        <label class="form-label">Section</label>
                        <input type="text" name="section" class="form-control" placeholder="Sewing / Quality">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" placeholder="employee@company.com">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div class="form-group">
                        <label class="form-label">Cell</label>
                        <input type="text" name="cell" class="form-control" placeholder="017xxxxxxxx">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Company</label>
                        <input type="text" name="company" class="form-control" placeholder="Company Name">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1.3fr; gap: 14px;">
                    <div class="form-group">
                        <label class="form-label">Location</label>
                        <input type="text" name="location" class="form-control" placeholder="Dhaka">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Floor</label>
                        <input type="text" name="floor" class="form-control" placeholder="3rd Floor">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Attendance Permission</label>
                        <select name="attendance_block_type" class="form-control" style="font-weight: 600;">
                            <option value="none">Allowed (Normal Attendance)</option>
                            <option value="fingerprint">Block Fingerprint Only</option>
                            <option value="face">Block Face Only</option>
                            <option value="both">Block Both (Finger & Face)</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-save"></i>
                    <span>Save Employee</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Employee Centered Modal Pop-up -->
<div class="modal-backdrop" id="editEmployeeModal">
    <div class="modal-box" style="max-width: 760px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
            <h3 class="modal-title" style="font-size: 1.2rem; font-weight: 700;">Edit Employee</h3>
            <button type="button" class="close-modal" data-modal-close>&times;</button>
        </div>
        <form method="POST" action="employee.php">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="update_employee">
                <input type="hidden" name="id" id="edit_emp_db_id">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div class="form-group">
                        <label class="form-label">Emp ID *</label>
                        <input type="text" name="emp_id" id="edit_emp_id" class="form-control" placeholder="EMP-1001" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Name *</label>
                        <input type="text" name="name" id="edit_emp_name" class="form-control" placeholder="Full Name" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div class="form-group">
                        <label class="form-label">Designation</label>
                        <input type="text" name="designation" id="edit_emp_designation" class="form-control" placeholder="Senior Officer">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <input type="text" name="department" id="edit_emp_department" class="form-control" placeholder="Accounts / Production">
                    </div>
                </div>

                <!-- Shift Settings with Select Dropdowns (No Manual Typing) -->
                <div style="padding: 14px; border-radius: 10px; background: rgba(56, 189, 248, 0.05); border: 1px solid rgba(56, 189, 248, 0.2); margin-bottom: 14px;">
                    <div style="font-weight: 700; color: var(--primary); font-size: 0.88rem; margin-bottom: 10px;">
                        <i class="fa-solid fa-clock"></i> Duty Shift & Working Hours (Select Time Dropdown)
                    </div>
                    <div style="display: grid; grid-template-columns: 1.1fr 1fr 1fr; gap: 10px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 0.8rem;">Shift Name</label>
                            <input type="text" name="shift_name" id="edit_emp_shift_name" class="form-control">
                        </div>

                        <!-- Shift Start Dropdown -->
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 0.8rem;">Shift Start (In Time)</label>
                            <div style="display: flex; gap: 4px; align-items: center;">
                                <select name="shift_start_hour" id="edit_admin_start_hour" onchange="syncAdminTime('edit_admin_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; flex: 1;">
                                    <?php for ($h = 1; $h <= 12; $h++): 
                                        $hVal = str_pad($h, 2, '0', STR_PAD_LEFT);
                                    ?>
                                        <option value="<?= $hVal ?>" <?= $hVal === '09' ? 'selected' : '' ?>><?= $hVal ?></option>
                                    <?php endfor; ?>
                                </select>
                                <span style="font-weight: 800; color: var(--primary);">:</span>
                                <select name="shift_start_min" id="edit_admin_start_min" onchange="syncAdminTime('edit_admin_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; flex: 1;">
                                    <?php for ($m = 0; $m < 60; $m += 5): 
                                        $mVal = str_pad($m, 2, '0', STR_PAD_LEFT);
                                    ?>
                                        <option value="<?= $mVal ?>" <?= $mVal === '00' ? 'selected' : '' ?>><?= $mVal ?></option>
                                    <?php endfor; ?>
                                </select>
                                <select name="shift_start_ampm" id="edit_admin_start_ampm" onchange="syncAdminTime('edit_admin_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; width: 66px; color: var(--primary);">
                                    <option value="AM" selected>AM</option>
                                    <option value="PM">PM</option>
                                </select>
                            </div>
                            <input type="hidden" name="shift_start" id="edit_admin_shift_start" value="09:00:00">
                        </div>

                        <!-- Shift End Dropdown -->
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 0.8rem;">Shift End (Out Time)</label>
                            <div style="display: flex; gap: 4px; align-items: center;">
                                <select name="shift_end_hour" id="edit_admin_end_hour" onchange="syncAdminTime('edit_admin_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; flex: 1;">
                                    <?php for ($h = 1; $h <= 12; $h++): 
                                        $hVal = str_pad($h, 2, '0', STR_PAD_LEFT);
                                    ?>
                                        <option value="<?= $hVal ?>" <?= $hVal === '06' ? 'selected' : '' ?>><?= $hVal ?></option>
                                    <?php endfor; ?>
                                </select>
                                <span style="font-weight: 800; color: var(--primary);">:</span>
                                <select name="shift_end_min" id="edit_admin_end_min" onchange="syncAdminTime('edit_admin_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; flex: 1;">
                                    <?php for ($m = 0; $m < 60; $m += 5): 
                                        $mVal = str_pad($m, 2, '0', STR_PAD_LEFT);
                                    ?>
                                        <option value="<?= $mVal ?>" <?= $mVal === '00' ? 'selected' : '' ?>><?= $mVal ?></option>
                                    <?php endfor; ?>
                                </select>
                                <select name="shift_end_ampm" id="edit_admin_end_ampm" onchange="syncAdminTime('edit_admin_')" class="form-control" style="padding: 6px 4px; font-weight: 700; text-align: center; width: 66px; color: var(--primary);">
                                    <option value="AM">AM</option>
                                    <option value="PM" selected>PM</option>
                                </select>
                            </div>
                            <input type="hidden" name="shift_end" id="edit_admin_shift_end" value="18:00:00">
                        </div>
                    </div>
                </div>

                <!-- Multi-Agent Assignment & Attendance Validity Section -->
                <div style="padding: 14px; border-radius: 10px; background: rgba(56, 189, 248, 0.05); border: 1.5px solid rgba(56, 189, 248, 0.25); margin-bottom: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <div style="font-weight: 700; color: var(--primary); font-size: 0.88rem; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-user-shield"></i> Assigned Agents & Attendance Validity
                        </div>
                        <span style="font-size: 0.75rem; color: var(--text-muted);">Assign multiple agents (Permanent or Time-bound Range)</span>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <?php foreach ($allAgents as $ag): ?>
                            <div class="agent-item-card" style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 8px; padding: 10px 12px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0; font-size: 0.86rem; font-weight: 600; color: #f8fafc;">
                                        <input type="checkbox" name="assigned_agents[]" id="edit_ag_chk_<?= $ag['id'] ?>" value="<?= $ag['id'] ?>" onchange="toggleAgentControls(this, 'edit_ag_<?= $ag['id'] ?>')">
                                        <i class="fa-solid fa-headset" style="color: #38bdf8;"></i>
                                        <span><?= htmlspecialchars($ag['name']) ?> (<?= htmlspecialchars($ag['username']) ?>)</span>
                                    </label>
                                    <div id="edit_ag_<?= $ag['id'] ?>_controls" style="display: none; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        <select name="agent_access_type[<?= $ag['id'] ?>]" id="edit_ag_type_<?= $ag['id'] ?>" onchange="toggleAgentDateInputs(this, 'edit_ag_<?= $ag['id'] ?>_dates')" class="form-control form-control-sm" style="width: auto; padding: 3px 8px; font-size: 0.78rem;">
                                            <option value="permanent">Permanent Access</option>
                                            <option value="temporary">Temporary (Date-Time Range)</option>
                                        </select>
                                        <div id="edit_ag_<?= $ag['id'] ?>_dates" style="display: none; align-items: center; gap: 4px;">
                                            <span style="font-size: 0.72rem; color: #94a3b8;">From:</span>
                                            <input type="datetime-local" name="agent_valid_from[<?= $ag['id'] ?>]" id="edit_ag_from_<?= $ag['id'] ?>" class="form-control form-control-sm" style="padding: 3px 6px; font-size: 0.75rem; width: auto;">
                                            <span style="font-size: 0.72rem; color: #94a3b8;">To:</span>
                                            <input type="datetime-local" name="agent_valid_to[<?= $ag['id'] ?>]" id="edit_ag_to_<?= $ag['id'] ?>" class="form-control form-control-sm" style="padding: 3px 6px; font-size: 0.75rem; width: auto;">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div class="form-group">
                        <label class="form-label">Section</label>
                        <input type="text" name="section" id="edit_emp_section" class="form-control" placeholder="Sewing / Quality">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="edit_emp_email" class="form-control" placeholder="employee@company.com">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div class="form-group">
                        <label class="form-label">Cell</label>
                        <input type="text" name="cell" id="edit_emp_cell" class="form-control" placeholder="017xxxxxxxx">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Company</label>
                        <input type="text" name="company" id="edit_emp_company" class="form-control" placeholder="Company Name">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1.3fr; gap: 14px;">
                    <div class="form-group">
                        <label class="form-label">Location</label>
                        <input type="text" name="location" id="edit_emp_location" class="form-control" placeholder="Dhaka">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Floor</label>
                        <input type="text" name="floor" id="edit_emp_floor" class="form-control" placeholder="3rd Floor">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" id="edit_emp_status" class="form-control">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Attendance Permission</label>
                        <select name="attendance_block_type" id="edit_emp_attendance_block_type" class="form-control" style="font-weight: 600;">
                            <option value="none">Allowed (Normal Attendance)</option>
                            <option value="fingerprint">Block Fingerprint Only</option>
                            <option value="face">Block Face Only</option>
                            <option value="both">Block Both (Finger & Face)</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check"></i>
                    <span>Update Employee</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Import Employee Centered Modal Pop-up -->
<div class="modal-backdrop" id="importEmployeeModal">
    <div class="modal-box" style="max-width: 680px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 38px; height: 38px; border-radius: 8px; background: rgba(56, 189, 248, 0.15); display: flex; align-items: center; justify-content: center; color: #38bdf8; font-size: 1.1rem;">
                    <i class="fa-solid fa-file-import"></i>
                </div>
                <div>
                    <h3 class="modal-title" style="font-size: 1.15rem; font-weight: 700; margin: 0;">Import Employees via CSV</h3>
                    <p style="font-size: 0.78rem; color: var(--text-muted); margin: 0;">Bulk upload employee records, shifts, and agent terminal permissions</p>
                </div>
            </div>
            <button type="button" class="close-modal" data-modal-close>&times;</button>
        </div>

        <form method="POST" action="employee.php" enctype="multipart/form-data">
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 16px;">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="import_employees">

                <!-- Template Download Card -->
                <div style="background: rgba(56, 189, 248, 0.08); border: 1px dashed rgba(56, 189, 248, 0.35); border-radius: 10px; padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-file-csv" style="font-size: 1.6rem; color: #38bdf8;"></i>
                        <div>
                            <div style="font-size: 0.88rem; font-weight: 600; color: #f8fafc;">Standard CSV Template</div>
                            <div style="font-size: 0.76rem; color: var(--text-muted);">Use our pre-formatted column headers to prevent upload errors</div>
                        </div>
                    </div>
                    <a href="employee.php?download_sample=1" class="btn btn-secondary btn-sm" style="background: rgba(56, 189, 248, 0.2); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.4); text-decoration: none; white-space: nowrap;">
                        <i class="fa-solid fa-download"></i>
                        <span>Download Sample</span>
                    </a>
                </div>

                <!-- CSV File Upload Area -->
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" style="font-weight: 600;">Select CSV File *</label>
                    <div class="file-dropzone" style="border: 2px dashed rgba(255, 255, 255, 0.18); border-radius: 12px; padding: 22px; text-align: center; background: rgba(15, 23, 42, 0.5); cursor: pointer; transition: all 0.2s;" onclick="document.getElementById('csvFileInput').click();">
                        <input type="file" id="csvFileInput" name="csv_file" accept=".csv, text/csv" required style="display: none;" onchange="handleCsvFileSelect(this)">
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
                            <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(56, 189, 248, 0.12); display: flex; align-items: center; justify-content: center; color: #38bdf8; font-size: 1.3rem;">
                                <i class="fa-solid fa-cloud-arrow-up"></i>
                            </div>
                            <div id="csvFileNameText" style="font-size: 0.9rem; font-weight: 600; color: #f8fafc;">Click to browse or drag & drop .CSV file here</div>
                            <div style="font-size: 0.76rem; color: var(--text-muted);">Supports standard CSV files exported from Excel, HRMS, or Google Sheets</div>
                        </div>
                    </div>
                </div>

                <!-- Duplicate Handling Option -->
                <div style="background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 10px; padding: 14px;">
                    <label class="form-label" style="font-weight: 600; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-clone" style="color: #f59e0b;"></i> Duplicate Handling Policy
                    </label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <label style="display: flex; align-items: flex-start; gap: 8px; background: rgba(15, 23, 42, 0.6); padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.08); cursor: pointer; font-size: 0.82rem;">
                            <input type="radio" name="duplicate_action" value="skip" checked style="margin-top: 3px;">
                            <div>
                                <strong style="color: #f8fafc; display: block;">Skip Duplicates</strong>
                                <span style="color: var(--text-muted); font-size: 0.74rem;">Keep existing records untouched if Emp ID already exists</span>
                            </div>
                        </label>
                        <label style="display: flex; align-items: flex-start; gap: 8px; background: rgba(15, 23, 42, 0.6); padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.08); cursor: pointer; font-size: 0.82rem;">
                            <input type="radio" name="duplicate_action" value="update" style="margin-top: 3px;">
                            <div>
                                <strong style="color: #38bdf8; display: block;">Update Existing</strong>
                                <span style="color: var(--text-muted); font-size: 0.74rem;">Overwrite employee details & shifts with new CSV values</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Bulk Assign to Agent Terminals (Optional) -->
                <div style="padding: 14px; border-radius: 10px; background: rgba(56, 189, 248, 0.05); border: 1.5px solid rgba(56, 189, 248, 0.25);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <div style="font-weight: 700; color: var(--primary); font-size: 0.88rem; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-user-shield"></i> Auto-Assign Imported Employees to Agent(s)
                        </div>
                        <span style="font-size: 0.74rem; color: var(--text-muted);">Optional assignment</span>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <?php foreach ($allAgents as $ag): ?>
                            <div class="agent-item-card" style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 8px; padding: 10px 12px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0; font-size: 0.86rem; font-weight: 600; color: #f8fafc;">
                                        <input type="checkbox" name="assigned_agents[]" value="<?= $ag['id'] ?>" onchange="toggleAgentControls(this, 'import_ag_<?= $ag['id'] ?>')">
                                        <i class="fa-solid fa-headset" style="color: #38bdf8;"></i>
                                        <span><?= htmlspecialchars($ag['name']) ?> (<?= htmlspecialchars($ag['username']) ?>)</span>
                                    </label>
                                    <div id="import_ag_<?= $ag['id'] ?>_controls" style="display: none; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        <select name="agent_access_type[<?= $ag['id'] ?>]" onchange="toggleAgentDateInputs(this, 'import_ag_<?= $ag['id'] ?>_dates')" class="form-control form-control-sm" style="width: auto; padding: 3px 8px; font-size: 0.78rem;">
                                            <option value="permanent">Permanent Access</option>
                                            <option value="temporary">Temporary (Date-Time Range)</option>
                                        </select>
                                        <div id="import_ag_<?= $ag['id'] ?>_dates" style="display: none; align-items: center; gap: 4px;">
                                            <span style="font-size: 0.72rem; color: #94a3b8;">From:</span>
                                            <input type="datetime-local" name="agent_valid_from[<?= $ag['id'] ?>]" class="form-control form-control-sm" style="padding: 3px 6px; font-size: 0.75rem; width: auto;">
                                            <span style="font-size: 0.72rem; color: #94a3b8;">To:</span>
                                            <input type="datetime-local" name="agent_valid_to[<?= $ag['id'] ?>]" class="form-control form-control-sm" style="padding: 3px 6px; font-size: 0.75rem; width: auto;">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                    <span>Upload & Import Records</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function handleCsvFileSelect(input) {
    const textEl = document.getElementById('csvFileNameText');
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const sizeKB = Math.round(file.size / 1024);
        textEl.innerHTML = `<span style="color: #38bdf8;"><i class="fa-solid fa-file-csv fa-lg"></i> Selected: <strong>${file.name}</strong> (${sizeKB} KB)</span>`;
    } else {
        textEl.innerHTML = 'Click to browse or drag & drop .CSV file here';
    }
}

function to24HAdmin(hour, min, ampm) {
    let h = parseInt(hour, 10) || 9;
    if (ampm === 'PM' && h < 12) h += 12;
    if (ampm === 'AM' && h === 12) h = 0;
    const hStr = h < 10 ? '0' + h : '' + h;
    const mStr = min.toString().padStart(2, '0');
    return hStr + ':' + mStr + ':00';
}

function parseTimeToPartsAdmin(timeStr) {
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

function setAdminTimeDropdowns(prefix, type, timeStr) {
    const parts = parseTimeToPartsAdmin(timeStr);
    const hEl = document.getElementById(prefix + type + '_hour');
    const mEl = document.getElementById(prefix + type + '_min');
    const apEl = document.getElementById(prefix + type + '_ampm');
    
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

function syncAdminTime(prefix) {
    const sH = document.getElementById(prefix + 'start_hour')?.value || '09';
    const sM = document.getElementById(prefix + 'start_min')?.value || '00';
    const sAP = document.getElementById(prefix + 'start_ampm')?.value || 'AM';

    const eH = document.getElementById(prefix + 'end_hour')?.value || '06';
    const eM = document.getElementById(prefix + 'end_min')?.value || '00';
    const eAP = document.getElementById(prefix + 'end_ampm')?.value || 'PM';

    const sTime = to24HAdmin(sH, sM, sAP);
    const eTime = to24HAdmin(eH, eM, eAP);

    const sInput = document.getElementById(prefix + 'shift_start');
    const eInput = document.getElementById(prefix + 'shift_end');
    if (sInput) sInput.value = sTime;
    if (eInput) eInput.value = eTime;
}

function toggleAgentControls(checkbox, prefix) {
    const controls = document.getElementById(prefix + '_controls');
    if (controls) {
        controls.style.display = checkbox.checked ? 'flex' : 'none';
    }
}

function toggleAgentDateInputs(selectElem, datesContainerId) {
    const container = document.getElementById(datesContainerId);
    if (container) {
        container.style.display = (selectElem.value === 'temporary') ? 'flex' : 'none';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.edit-emp-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            try {
                const emp = JSON.parse(this.getAttribute('data-emp'));
                document.getElementById('edit_emp_db_id').value = emp.id || '';
                document.getElementById('edit_emp_id').value = emp.emp_id || '';
                document.getElementById('edit_emp_name').value = emp.name || '';
                document.getElementById('edit_emp_designation').value = emp.designation || '';
                document.getElementById('edit_emp_department').value = emp.department || '';
                document.getElementById('edit_emp_section').value = emp.section || '';
                document.getElementById('edit_emp_email').value = emp.email || '';
                document.getElementById('edit_emp_cell').value = emp.cell || '';
                document.getElementById('edit_emp_company').value = emp.company || '';
                document.getElementById('edit_emp_location').value = emp.location || '';
                document.getElementById('edit_emp_floor').value = emp.floor || '';
                document.getElementById('edit_emp_status').value = emp.status || 'Active';
                
                document.getElementById('edit_emp_shift_name').value = emp.shift_name || 'General Shift';
                
                // Set In/Out Time dropdowns
                setAdminTimeDropdowns('edit_admin_', 'start', emp.shift_start || '09:00:00');
                setAdminTimeDropdowns('edit_admin_', 'end', emp.shift_end || '18:00:00');
                syncAdminTime('edit_admin_');

                const blockType = emp.attendance_block_type || (emp.is_attendance_blocked == 1 ? 'both' : 'none');
                document.getElementById('edit_emp_attendance_block_type').value = blockType;

                // Reset all agent checkboxes in edit modal first
                document.querySelectorAll('#editEmployeeModal input[type="checkbox"][name="assigned_agents[]"]').forEach(chk => {
                    chk.checked = false;
                    const agId = chk.value;
                    const controls = document.getElementById('edit_ag_' + agId + '_controls');
                    if (controls) controls.style.display = 'none';
                    const dates = document.getElementById('edit_ag_' + agId + '_dates');
                    if (dates) dates.style.display = 'none';
                });

                // Populate existing assigned permissions
                const perms = emp.assigned_perms || [];
                perms.forEach(p => {
                    const agId = p.agent_id;
                    const chk = document.getElementById('edit_ag_chk_' + agId);
                    if (chk) {
                        chk.checked = true;
                        const controls = document.getElementById('edit_ag_' + agId + '_controls');
                        if (controls) controls.style.display = 'flex';

                        const typeSel = document.getElementById('edit_ag_type_' + agId);
                        if (typeSel) {
                            typeSel.value = p.access_type || 'permanent';
                            const dates = document.getElementById('edit_ag_' + agId + '_dates');
                            if (dates) {
                                dates.style.display = (p.access_type === 'temporary') ? 'flex' : 'none';
                            }
                        }

                        if (p.valid_from) {
                            const fromEl = document.getElementById('edit_ag_from_' + agId);
                            if (fromEl) fromEl.value = p.valid_from.replace(' ', 'T').substring(0, 16);
                        }
                        if (p.valid_to) {
                            const toEl = document.getElementById('edit_ag_to_' + agId);
                            if (toEl) toEl.value = p.valid_to.replace(' ', 'T').substring(0, 16);
                        }
                    }
                });
                
                const modal = document.getElementById('editEmployeeModal');
                if (modal) modal.classList.add('active');
            } catch (err) {
                console.error('Error loading employee data:', err);
            }
        });
    });
});

async function confirmDeleteEmployee(e, form, name) {
    e.preventDefault();
    const confirmed = await showPulseConfirm({
        title: 'Delete Employee?',
        message: 'Are you sure you want to delete employee <strong>' + name + '</strong>? This action will remove all enrolled templates and history.',
        confirmText: 'Yes, Delete',
        type: 'error'
    });
    if (confirmed) {
        form.submit();
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
