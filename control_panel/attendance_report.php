<?php
require_once __DIR__ . '/../config/init.php';
requirePermission('attendance_report');

$pageTitle = 'Central Attendance Reports & Shift Stay Duration | Pulse Mark Admin';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

$pdo = getDB();

// Handle Filter parameters
$dateFilter = sanitize($_GET['date_filter'] ?? 'all');
$dateFrom = sanitize($_GET['date_from'] ?? '');
$dateTo = sanitize($_GET['date_to'] ?? '');
$selectedDept = sanitize($_GET['department'] ?? '');
$selectedMethod = sanitize($_GET['method'] ?? '');
$selectedAgent = sanitize($_GET['agent_id'] ?? '');
$activeTab = sanitize($_GET['tab'] ?? 'daily_summary');

$whereClauses = ["1=1"];
$params = [];

if ($dateFilter === 'today') {
    $whereClauses[] = "DATE(a.punch_time) = CURDATE()";
} elseif ($dateFilter === 'yesterday') {
    $whereClauses[] = "DATE(a.punch_time) = CURDATE() - INTERVAL 1 DAY";
} elseif ($dateFilter === 'this_week') {
    $whereClauses[] = "YEARWEEK(a.punch_time, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($dateFilter === 'this_month') {
    $whereClauses[] = "YEAR(a.punch_time) = YEAR(CURDATE()) AND MONTH(a.punch_time) = MONTH(CURDATE())";
} elseif (!empty($dateFrom) && !empty($dateTo)) {
    $whereClauses[] = "DATE(a.punch_time) BETWEEN ? AND ?";
    $params[] = $dateFrom;
    $params[] = $dateTo;
}

if (!empty($selectedDept)) {
    $whereClauses[] = "e.department = ?";
    $params[] = $selectedDept;
}

if (!empty($selectedMethod)) {
    $whereClauses[] = "a.punch_type = ?";
    $params[] = $selectedMethod;
}

if (!empty($selectedAgent)) {
    $whereClauses[] = "a.agent_id = ?";
    $params[] = $selectedAgent;
}

$whereSql = implode(" AND ", $whereClauses);

// Query Attendance Records
$attendanceLogs = [];
$totalPunches = 0;
$uniqueEmployees = 0;
$biometricCount = 0;
$faceCount = 0;
$lateCount = 0;
$earlyExitCount = 0;
$departments = [];
$agentsList = [];
$dailySummaries = [];

if ($pdo) {
    try {
        $deptStmt = $pdo->query("SELECT DISTINCT `department` FROM `employees` WHERE `department` IS NOT NULL AND `department` != '' ORDER BY `department` ASC");
        $departments = $deptStmt ? $deptStmt->fetchAll(PDO::FETCH_COLUMN) : [];

        $agStmt = $pdo->query("SELECT `id`, `name`, `username`, `location` FROM `users` WHERE `role` = 'agent' ORDER BY `name` ASC");
        $agentsList = $agStmt ? $agStmt->fetchAll() : [];

        $sql = "SELECT a.*, e.name as emp_name, e.department, e.designation, e.company, e.location,
                       e.shift_name, e.shift_start, e.shift_end, e.is_attendance_blocked,
                       u.name as agent_name, u.location as agent_location 
                FROM `attendance_logs` a 
                LEFT JOIN `employees` e ON a.emp_id = e.emp_id 
                LEFT JOIN `users` u ON a.agent_id = u.id 
                WHERE {$whereSql} 
                ORDER BY a.punch_time ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rawLogs = $stmt->fetchAll();

        // Process logs into daily timesheet summaries
        $empDayGroups = [];
        foreach ($rawLogs as $log) {
            $dayKey = date('Y-m-d', strtotime($log['punch_time']));
            $empKey = $log['emp_id'] . '_' . $dayKey;

            if (!isset($empDayGroups[$empKey])) {
                $empDayGroups[$empKey] = [
                    'date' => $dayKey,
                    'emp_id' => $log['emp_id'],
                    'emp_name' => $log['emp_name'] ?? 'Registered User',
                    'department' => $log['department'] ?? 'General',
                    'designation' => $log['designation'] ?? 'Staff',
                    'company' => $log['company'] ?? 'PulseMark HQ',
                    'location' => $log['location'] ?? 'HQ',
                    'shift_name' => $log['shift_name'] ?? 'General Shift',
                    'shift_start' => $log['shift_start'] ?? '09:00:00',
                    'shift_end' => $log['shift_end'] ?? '18:00:00',
                    'is_attendance_blocked' => (int)($log['is_attendance_blocked'] ?? 0),
                    'agent_name' => $log['agent_name'] ?? ('Agent #' . ($log['agent_id'] ?? '1')),
                    'agent_location' => $log['agent_location'] ?? '',
                    'punches' => []
                ];
            }
            $empDayGroups[$empKey]['punches'][] = $log;
        }

        // Calculate Timesheet metrics for each day
        $todayStr = date('Y-m-d');
        foreach ($empDayGroups as $key => $item) {
            $punches = $item['punches'];
            $firstPunch = $punches[0]['punch_time'];
            $lastPunch = $punches[count($punches) - 1]['punch_time'];
            $shiftStart = $item['shift_start'];
            $shiftEnd = $item['shift_end'];
            $isToday = ($item['date'] === $todayStr);

            // 1. Office Duration calculation
            $firstTs = strtotime($firstPunch);
            $lastTs = strtotime($lastPunch);
            $staySeconds = 0;
            $isLiveInOffice = false;

            if (count($punches) > 1 && $lastTs > $firstTs) {
                $staySeconds = $lastTs - $firstTs;
            } elseif ($isToday) {
                $staySeconds = max(0, time() - $firstTs);
                $isLiveInOffice = true;
            }

            $stayHrs = floor($staySeconds / 3600);
            $stayMins = floor(($staySeconds % 3600) / 60);
            $stayFormatted = sprintf("%dh %02dm", $stayHrs, $stayMins);

            // 2. Late Arrival Calculation (compare first punch time with shift_start)
            $firstPunchTimeOnly = date('H:i:s', $firstTs);
            $shiftStartTs = strtotime($item['date'] . ' ' . $shiftStart);
            $lateMinutes = 0;
            $lateStatus = 'On Time';

            if ($firstTs > $shiftStartTs) {
                $lateSeconds = $firstTs - $shiftStartTs;
                $lateMinutes = round($lateSeconds / 60);
                if ($lateMinutes > 0) {
                    $lateHrs = floor($lateMinutes / 60);
                    $remMins = $lateMinutes % 60;
                    $lateStatus = $lateHrs > 0 ? "Late by {$lateHrs}h {$remMins}m" : "Late by {$lateMinutes} mins";
                    $lateCount++;
                }
            }

            // 3. Early Exit Calculation (compare last punch time with shift_end)
            $shiftEndTs = strtotime($item['date'] . ' ' . $shiftEnd);
            $earlyExitMinutes = 0;
            $earlyStatus = 'Normal';

            if (count($punches) > 1 || !$isToday) {
                if ($lastTs < $shiftEndTs) {
                    $earlySeconds = $shiftEndTs - $lastTs;
                    $earlyExitMinutes = round($earlySeconds / 60);
                    if ($earlyExitMinutes > 0) {
                        $eHrs = floor($earlyExitMinutes / 60);
                        $eRemMins = $earlyExitMinutes % 60;
                        $earlyStatus = $eHrs > 0 ? "Early Exit by {$eHrs}h {$eRemMins}m" : "Early Exit by {$earlyExitMinutes} mins";
                        $earlyExitCount++;
                    }
                } else {
                    $earlyStatus = 'Full Shift Met';
                }
            } else {
                $earlyStatus = 'Shift in Progress';
            }

            $dailySummaries[] = [
                'date' => $item['date'],
                'emp_id' => $item['emp_id'],
                'emp_name' => $item['emp_name'],
                'department' => $item['department'],
                'designation' => $item['designation'],
                'company' => $item['company'],
                'location' => $item['location'],
                'shift_name' => $item['shift_name'],
                'shift_start' => $shiftStart,
                'shift_end' => $shiftEnd,
                'is_attendance_blocked' => $item['is_attendance_blocked'],
                'agent_name' => $item['agent_name'],
                'agent_location' => $item['agent_location'],
                'first_in' => $firstPunch,
                'last_out' => $lastPunch,
                'punch_count' => count($punches),
                'stay_formatted' => $stayFormatted,
                'stay_seconds' => $staySeconds,
                'is_live' => $isLiveInOffice,
                'late_status' => $lateStatus,
                'late_minutes' => $lateMinutes,
                'early_status' => $earlyStatus,
                'early_minutes' => $earlyExitMinutes,
                'last_method' => $punches[count($punches) - 1]['punch_type'] ?? 'Biometric'
            ];
        }

        // Reverse for newest day first
        $dailySummaries = array_reverse($dailySummaries);
        $attendanceLogs = array_reverse($rawLogs);

        $totalPunches = count($attendanceLogs);
        $uniqueEmpList = [];

        foreach ($attendanceLogs as $log) {
            $uniqueEmpList[$log['emp_id']] = true;
            if ($log['punch_type'] === 'Biometric') {
                $biometricCount++;
            } elseif ($log['punch_type'] === 'Face') {
                $faceCount++;
            }
        }
        $uniqueEmployees = count($uniqueEmpList);
    } catch (Exception $e) {
        $attendanceLogs = [];
        $dailySummaries = [];
    }
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=PulseMark_Central_Attendance_Report_' . date('Y-m-d_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Employee ID', 'Employee Name', 'Department', 'Designation', 'Duty Shift', 'Shift Start', 'Shift End', 'Agent Point', 'First Punch (In)', 'Last Punch (Out)', 'Total Office Stay', 'Late Arrival Status', 'Early Exit Status', 'Punch Count']);
    
    foreach ($dailySummaries as $row) {
        fputcsv($output, [
            $row['date'],
            $row['emp_id'],
            $row['emp_name'],
            $row['department'],
            $row['designation'],
            $row['shift_name'],
            date('h:i A', strtotime($row['shift_start'])),
            date('h:i A', strtotime($row['shift_end'])),
            $row['agent_name'],
            date('h:i:s A', strtotime($row['first_in'])),
            date('h:i:s A', strtotime($row['last_out'])),
            $row['stay_formatted'],
            $row['late_status'],
            $row['early_status'],
            $row['punch_count']
        ]);
    }
    fclose($output);
    exit;
}

$flash = getFlash();
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
                    <i class="fa-solid fa-chart-column" style="color: #38bdf8;"></i>
                    <span>Central Attendance Reports & Shift Tracker</span>
                </h1>
                <p class="page-subtitle" style="color: var(--text-muted); font-size: 0.95rem; margin-top: 4px;">
                    Enterprise-wide biometric and facial recognition attendance logs, office stay duration, late arrivals, and early exits.
                </p>
            </div>
            <div style="display: flex; gap: 12px;">
                <button type="button" onclick="window.print()" class="btn btn-secondary" style="border: 1px solid rgba(255, 255, 255, 0.2); font-weight: 600;">
                    <i class="fa-solid fa-print"></i>
                    <span>Print Report</span>
                </button>
                <a href="<?= $_SERVER['REQUEST_URI'] . (strpos($_SERVER['REQUEST_URI'], '?') !== false ? '&' : '?') ?>export=csv" class="btn btn-primary" style="background: linear-gradient(135deg, #10b981, #06b6d4); font-weight: 700;">
                    <i class="fa-solid fa-file-csv"></i>
                    <span>Export Timesheet CSV</span>
                </a>
            </div>
        </div>

        <!-- Metric Summary Cards -->
        <div class="metric-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 20px; margin-bottom: 28px;">
            <div class="glass-panel metric-card" style="padding: 20px; border-radius: 16px; border-left: 4px solid #38bdf8;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="color: var(--text-muted); font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Total Punches</div>
                        <div style="font-size: 2rem; font-weight: 800; color: var(--text-main); margin-top: 4px; font-family: 'Outfit', sans-serif;"><?= $totalPunches ?></div>
                    </div>
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(56, 189, 248, 0.15); color: #38bdf8; display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">
                        <i class="fa-solid fa-clipboard-check"></i>
                    </div>
                </div>
            </div>

            <div class="glass-panel metric-card" style="padding: 20px; border-radius: 16px; border-left: 4px solid #10b981;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="color: var(--text-muted); font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Present Staff</div>
                        <div style="font-size: 2rem; font-weight: 800; color: #10b981; margin-top: 4px; font-family: 'Outfit', sans-serif;"><?= $uniqueEmployees ?></div>
                    </div>
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(16, 185, 129, 0.15); color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">
                        <i class="fa-solid fa-users"></i>
                    </div>
                </div>
            </div>

            <div class="glass-panel metric-card" style="padding: 20px; border-radius: 16px; border-left: 4px solid #f59e0b;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="color: var(--text-muted); font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Late Arrivals</div>
                        <div style="font-size: 2rem; font-weight: 800; color: #f59e0b; margin-top: 4px; font-family: 'Outfit', sans-serif;"><?= $lateCount ?></div>
                    </div>
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(245, 158, 11, 0.15); color: #f59e0b; display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                </div>
            </div>

            <div class="glass-panel metric-card" style="padding: 20px; border-radius: 16px; border-left: 4px solid #ec4899;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="color: var(--text-muted); font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Early Exits</div>
                        <div style="font-size: 2rem; font-weight: 800; color: #ec4899; margin-top: 4px; font-family: 'Outfit', sans-serif;"><?= $earlyExitCount ?></div>
                    </div>
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(236, 72, 153, 0.15); color: #ec4899; display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">
                        <i class="fa-solid fa-person-walking-arrow-right"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="glass-panel" style="padding: 20px; border-radius: 16px; margin-bottom: 24px;">
            <form method="GET" action="attendance_report.php" style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end;">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">

                <div style="flex: 1; min-width: 160px;">
                    <label style="font-size: 0.82rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 6px;">Date Filter</label>
                    <select name="date_filter" id="dateFilterSelect" onchange="toggleCustomDates()" class="form-control">
                        <option value="all" <?= $dateFilter === 'all' ? 'selected' : '' ?>>All Time</option>
                        <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="yesterday" <?= $dateFilter === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                        <option value="this_week" <?= $dateFilter === 'this_week' ? 'selected' : '' ?>>This Week</option>
                        <option value="this_month" <?= $dateFilter === 'this_month' ? 'selected' : '' ?>>This Month</option>
                        <option value="custom" <?= $dateFilter === 'custom' || (!empty($dateFrom) && !empty($dateTo)) ? 'selected' : '' ?>>Custom Range</option>
                    </select>
                </div>

                <div id="customDateWrap" style="display: <?= ($dateFilter === 'custom' || (!empty($dateFrom) && !empty($dateTo))) ? 'flex' : 'none' ?>; gap: 10px;">
                    <div>
                        <label style="font-size: 0.82rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 6px;">From Date</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="form-control">
                    </div>
                    <div>
                        <label style="font-size: 0.82rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 6px;">To Date</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="form-control">
                    </div>
                </div>

                <div style="flex: 1; min-width: 160px;">
                    <label style="font-size: 0.82rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 6px;">Department</label>
                    <select name="department" class="form-control">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= htmlspecialchars($dept) ?>" <?= $selectedDept === $dept ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="flex: 1; min-width: 170px;">
                    <label style="font-size: 0.82rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 6px;">Agent Terminal</label>
                    <select name="agent_id" class="form-control">
                        <option value="">All Agents / Points</option>
                        <?php foreach ($agentsList as $ag): ?>
                            <option value="<?= htmlspecialchars($ag['id']) ?>" <?= (string)$selectedAgent === (string)$ag['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ag['name']) ?><?= !empty($ag['location']) ? ' (' . htmlspecialchars($ag['location']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="flex: 1; min-width: 150px;">
                    <label style="font-size: 0.82rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 6px;">Punch Method</label>
                    <select name="method" class="form-control">
                        <option value="">All Methods</option>
                        <option value="Biometric" <?= $selectedMethod === 'Biometric' ? 'selected' : '' ?>>Biometric (ZKTeco)</option>
                        <option value="Face" <?= $selectedMethod === 'Face' ? 'selected' : '' ?>>Face Recognition</option>
                    </select>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #10b981, #06b6d4); font-weight: 700; padding: 10px 20px;">
                        <i class="fa-solid fa-filter"></i> Apply Filters
                    </button>
                    <a href="attendance_report.php" class="btn btn-secondary" style="margin-left: 6px; padding: 10px 16px;">
                        Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- View Tabs -->
        <div style="display: flex; gap: 12px; margin-bottom: 20px;">
            <button type="button" class="tab-btn <?= $activeTab === 'daily_summary' ? 'active' : '' ?>" onclick="switchView('daily_summary')" id="tabBtnSummary">
                <i class="fa-solid fa-user-clock"></i> Daily Shift & Stay Timesheet (<?= count($dailySummaries) ?>)
            </button>
            <button type="button" class="tab-btn <?= $activeTab === 'raw_logs' ? 'active' : '' ?>" onclick="switchView('raw_logs')" id="tabBtnLogs">
                <i class="fa-solid fa-list-check"></i> Central Raw Logs (<?= count($attendanceLogs) ?>)
            </button>
        </div>

        <!-- ================= 1. DAILY SHIFT & STAY TIMESHEET VIEW ================= -->
        <div id="viewSummary" class="glass-panel" style="padding: 24px; border-radius: 18px; display: <?= $activeTab === 'daily_summary' ? 'block' : 'none' ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 20px;">
                <div style="position: relative; min-width: 280px; flex: 1; max-width: 420px;">
                    <input type="text" id="summarySearch" onkeyup="filterSummaryTable()" class="form-control" placeholder="Search by name, ID, department, agent..." style="padding-left: 38px;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #64748b;"></i>
                </div>
                <div style="font-size: 0.85rem; color: var(--text-muted);">
                    Showing <strong style="color: var(--text-main);"><?= count($dailySummaries) ?></strong> daily employee stay records
                </div>
            </div>

            <div class="table-container">
                <table class="custom-table" id="summaryTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Employee ID</th>
                            <th>Employee Details</th>
                            <th>Duty Shift</th>
                            <th>First Punch (In)</th>
                            <th>Last Punch (Out)</th>
                            <th>Office Stay Duration</th>
                            <th>Late Arrival</th>
                            <th>Early Departure</th>
                            <th>Agent Point</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($dailySummaries)): ?>
                            <?php foreach ($dailySummaries as $row): 
                                $shiftStartFmt = date('h:i A', strtotime($row['shift_start']));
                                $shiftEndFmt = date('h:i A', strtotime($row['shift_end']));
                                $isLate = $row['late_minutes'] > 0;
                                $isEarly = $row['early_minutes'] > 0;
                            ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 700; font-size: 0.9rem;"><?= date('M d, Y', strtotime($row['date'])) ?></div>
                                        <div style="font-size: 0.74rem; color: var(--text-muted);"><?= date('l', strtotime($row['date'])) ?></div>
                                    </td>
                                    <td>
                                        <strong style="color: var(--primary); font-family: monospace; font-size: 0.95rem;">
                                             <?= htmlspecialchars($row['emp_id']) ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="width: 34px; height: 34px; border-radius: 8px; background: linear-gradient(135deg, #10b981, #06b6d4); color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: 0.9rem;">
                                                <?= strtoupper(substr($row['emp_name'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 700;"><?= htmlspecialchars($row['emp_name']) ?></div>
                                                <div style="font-size: 0.76rem; color: var(--primary);"><?= htmlspecialchars($row['department']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <!-- Duty Shift Column -->
                                    <td>
                                        <div style="font-weight: 700; color: #38bdf8; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 5px;">
                                            <i class="fa-solid fa-clock"></i> <?= $shiftStartFmt ?> - <?= $shiftEndFmt ?>
                                        </div>
                                        <div style="font-size: 0.74rem; color: var(--text-muted);"><?= htmlspecialchars($row['shift_name']) ?></div>
                                    </td>
                                    <!-- First In Punch -->
                                    <td>
                                        <div style="font-weight: 700; color: #10b981; font-size: 0.9rem;">
                                            <i class="fa-solid fa-arrow-right-to-bracket fa-xs"></i> <?= date('h:i:s A', strtotime($row['first_in'])) ?>
                                        </div>
                                    </td>
                                    <!-- Last Out Punch -->
                                    <td>
                                        <?php if ($row['first_in'] !== $row['last_out']): ?>
                                            <div style="font-weight: 700; color: #f59e0b; font-size: 0.9rem;">
                                                <i class="fa-solid fa-arrow-right-from-bracket fa-xs"></i> <?= date('h:i:s A', strtotime($row['last_out'])) ?>
                                            </div>
                                        <?php elseif ($row['is_live']): ?>
                                            <span class="badge badge-normal" style="display: inline-flex; align-items: center; gap: 5px;">
                                                <i class="fa-solid fa-spinner fa-spin fa-xs"></i> In Office (Active)
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-size: 0.82rem;">1 Punch Logged</span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Office Stay Duration -->
                                    <td>
                                        <div style="font-weight: 800; font-size: 0.95rem; font-family: monospace;">
                                            <?= $row['stay_formatted'] ?>
                                        </div>
                                        <?php if ($row['is_live']): ?>
                                            <div style="font-size: 0.72rem; color: #10b981;"><i class="fa-solid fa-bolt"></i> Running Time</div>
                                        <?php else: ?>
                                            <div style="font-size: 0.72rem; color: var(--text-muted);"><?= $row['punch_count'] ?> punch(es)</div>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Late Status -->
                                    <td>
                                        <?php if ($isLate): ?>
                                            <span class="badge badge-attention" style="font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
                                                <i class="fa-solid fa-triangle-exclamation"></i> <?= $row['late_status'] ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-normal" style="font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
                                                <i class="fa-solid fa-circle-check"></i> On Time
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Early Exit Status -->
                                    <td>
                                        <?php if ($isEarly): ?>
                                            <span class="badge badge-attention" style="font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
                                                <i class="fa-solid fa-person-walking-arrow-right"></i> <?= $row['early_status'] ?>
                                            </span>
                                        <?php elseif ($row['early_status'] === 'Full Shift Met'): ?>
                                            <span class="badge badge-normal" style="font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
                                                <i class="fa-solid fa-check-double"></i> Full Shift Met
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-size: 0.8rem;">In Progress</span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Agent Point -->
                                    <td>
                                        <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
                                            <i class="fa-solid fa-tower-broadcast"></i> <?= htmlspecialchars($row['agent_name'] ?? 'HQ') ?>
                                        </span>
                                    </td>
                                    <!-- Status -->
                                    <td>
                                        <?php if ($row['is_attendance_blocked'] === 1): ?>
                                            <span class="badge badge-attention" style="border: 1px solid #ef4444; color: #ef4444;">
                                                <i class="fa-solid fa-ban"></i> BLOCKED
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-normal">
                                                <i class="fa-solid fa-circle-check"></i> Present
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" style="text-align: center; padding: 36px; color: var(--text-muted);">
                                    No attendance stay records found matching your selected filters.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ================= 2. RAW PUNCH AUDIT LOGS VIEW ================= -->
        <div id="viewLogs" class="glass-panel" style="padding: 24px; border-radius: 18px; display: <?= $activeTab === 'raw_logs' ? 'block' : 'none' ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 20px;">
                <div style="position: relative; min-width: 280px; flex: 1; max-width: 420px;">
                    <input type="text" id="reportSearch" onkeyup="filterReportTable()" class="form-control" placeholder="Search by name, ID, department, agent..." style="padding-left: 38px;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #64748b;"></i>
                </div>
                <div style="font-size: 0.85rem; color: var(--text-muted);">
                    Showing <strong style="color: var(--text-main);"><?= count($attendanceLogs) ?></strong> central punches
                </div>
            </div>

            <div class="table-container">
                <table class="custom-table" id="reportTable">
                    <thead>
                        <tr>
                            <th>Log ID</th>
                            <th>Employee ID</th>
                            <th>Employee Details</th>
                            <th>Duty Shift</th>
                            <th>Department & Role</th>
                            <th>Location / Company</th>
                            <th>Agent Terminal</th>
                            <th>Punch Method</th>
                            <th>Match Score</th>
                            <th>Punch Time & Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($attendanceLogs)): ?>
                            <?php foreach ($attendanceLogs as $log): 
                                $shiftStartFmt = !empty($log['shift_start']) ? date('h:i A', strtotime($log['shift_start'])) : '09:00 AM';
                                $shiftEndFmt = !empty($log['shift_end']) ? date('h:i A', strtotime($log['shift_end'])) : '06:00 PM';
                            ?>
                                <tr>
                                    <td>
                                        <span style="font-weight: 700; color: var(--text-muted); font-family: monospace;">
                                            #LOG-<?= str_pad($log['id'], 5, '0', STR_PAD_LEFT) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong style="color: var(--primary); font-family: monospace; font-size: 0.95rem;">
                                            <?= htmlspecialchars($log['emp_id']) ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="width: 34px; height: 34px; border-radius: 8px; background: linear-gradient(135deg, #10b981, #06b6d4); color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: 0.9rem;">
                                                <?= strtoupper(substr($log['emp_name'] ?? 'U', 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 700;"><?= htmlspecialchars($log['emp_name'] ?? 'Registered User') ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <!-- Duty Shift Column -->
                                    <td>
                                        <div style="font-weight: 700; color: #38bdf8; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 5px;">
                                            <i class="fa-solid fa-clock"></i> <?= $shiftStartFmt ?> - <?= $shiftEndFmt ?>
                                        </div>
                                        <div style="font-size: 0.74rem; color: var(--text-muted);"><?= htmlspecialchars($log['shift_name'] ?? 'General Shift') ?></div>
                                    </td>
                                    <td>
                                        <div style="color: var(--primary); font-weight: 600;"><?= htmlspecialchars($log['department'] ?? 'General') ?></div>
                                        <div style="font-size: 0.78rem; color: var(--text-muted);"><?= htmlspecialchars($log['designation'] ?? 'Staff') ?></div>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($log['location'] ?? 'HQ') ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($log['company'] ?? 'PulseMark HQ') ?></div>
                                    </td>
                                    <!-- Agent Terminal Column -->
                                    <td>
                                        <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
                                            <i class="fa-solid fa-tower-broadcast"></i> <?= htmlspecialchars($log['agent_name'] ?? ('Agent #' . ($log['agent_id'] ?? 'HQ'))) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($log['punch_type'] === 'Biometric'): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 6px; color: #10b981; font-weight: 600;">
                                                <i class="fa-solid fa-fingerprint"></i> Biometric (ZK)
                                            </span>
                                        <?php else: ?>
                                            <span style="display: inline-flex; align-items: center; gap: 6px; color: #8b5cf6; font-weight: 600;">
                                                <i class="fa-solid fa-camera"></i> Face Scan
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-normal">
                                            <?= htmlspecialchars($log['quality_score'] ?? '99.8%') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-weight: 700;"><?= date('h:i:s A', strtotime($log['punch_time'])) ?></div>
                                        <div style="font-size: 0.78rem; color: var(--text-muted);"><?= date('M d, Y', strtotime($log['punch_time'])) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge badge-normal" style="display: inline-flex; align-items: center; gap: 5px;">
                                            <i class="fa-solid fa-circle-check"></i> Logged
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" style="text-align: center; padding: 36px; color: var(--text-muted);">
                                    No attendance records found matching your selected filters.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script>
function toggleCustomDates() {
    const val = document.getElementById('dateFilterSelect').value;
    const wrap = document.getElementById('customDateWrap');
    if (val === 'custom') {
        wrap.style.display = 'flex';
    } else {
        wrap.style.display = 'none';
    }
}

function switchView(tab) {
    const viewSummary = document.getElementById('viewSummary');
    const viewLogs = document.getElementById('viewLogs');
    const tabBtnSummary = document.getElementById('tabBtnSummary');
    const tabBtnLogs = document.getElementById('tabBtnLogs');

    if (tab === 'daily_summary') {
        viewSummary.style.display = 'block';
        viewLogs.style.display = 'none';
        tabBtnSummary.classList.add('active');
        tabBtnLogs.classList.remove('active');
    } else {
        viewSummary.style.display = 'none';
        viewLogs.style.display = 'block';
        tabBtnSummary.classList.remove('active');
        tabBtnLogs.classList.add('active');
    }
}

function filterSummaryTable() {
    const input = document.getElementById('summarySearch').value.toLowerCase();
    const table = document.getElementById('summaryTable');
    const tr = table.getElementsByTagName('tr');

    for (let i = 1; i < tr.length; i++) {
        const rowText = tr[i].innerText.toLowerCase();
        if (rowText.includes(input)) {
            tr[i].style.display = '';
        } else {
            tr[i].style.display = 'none';
        }
    }
}

function filterReportTable() {
    const input = document.getElementById('reportSearch').value.toLowerCase();
    const table = document.getElementById('reportTable');
    const tr = table.getElementsByTagName('tr');

    for (let i = 1; i < tr.length; i++) {
        const rowText = tr[i].innerText.toLowerCase();
        if (rowText.includes(input)) {
            tr[i].style.display = '';
        } else {
            tr[i].style.display = 'none';
        }
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
