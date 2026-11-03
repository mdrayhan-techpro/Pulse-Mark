<?php
require_once __DIR__ . '/../config/init.php';
requirePermission('audit_logs');
$pdo = getDB();

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=PulseMark_Audit_Logs_' . date('Y-m-d_His') . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    fputcsv($output, [
        'Log ID',
        'Timestamp',
        'User / Actor',
        'User Role',
        'Category',
        'Action Event',
        'Details & Description',
        'IP Address',
        'Status'
    ]);

    if ($pdo) {
        $exportLogs = $pdo->query("SELECT * FROM `audit_logs` ORDER BY `id` DESC")->fetchAll();
        foreach ($exportLogs as $row) {
            fputcsv($output, [
                '#' . $row['id'],
                date('Y-m-d h:i:s A', strtotime($row['created_at'])),
                $row['user_name'] ?: 'System',
                strtoupper($row['user_role'] ?: 'admin'),
                $row['category'],
                $row['action'],
                $row['details'] ?? '',
                $row['ip_address'] ?? '127.0.0.1',
                $row['status'] ?? 'Success'
            ]);
        }
    }
    fclose($output);
    exit;
}

// Handle Clear Old Logs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Security token mismatch.');
        header('Location: audit_logs.php');
        exit;
    }

    $action = $_POST['action'];
    if ($action === 'clear_logs' && $pdo) {
        $retentionDays = (int)($_POST['retention_days'] ?? 30);
        try {
            if ($retentionDays === 0) {
                // Clear all logs
                $pdo->exec("TRUNCATE TABLE `audit_logs`");
                logAudit($pdo, 'LOGS_CLEARED', 'System', 'All historical audit log records were cleared by Administrator.');
                setFlash('success', 'All system audit logs have been successfully cleared.');
            } else {
                $stmt = $pdo->prepare("DELETE FROM `audit_logs` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL ? DAY)");
                $stmt->execute([$retentionDays]);
                $deletedCount = $stmt->rowCount();
                logAudit($pdo, 'LOGS_PURGED', 'System', "Purged {$deletedCount} audit logs older than {$retentionDays} days.");
                setFlash('success', "Purged {$deletedCount} audit logs older than {$retentionDays} days.");
            }
        } catch (Exception $e) {
            setFlash('danger', 'Failed to clear logs: ' . $e->getMessage());
        }
        header('Location: audit_logs.php');
        exit;
    }
}

// Filter Parameters
$filterCategory = sanitize($_GET['category'] ?? 'all');
$filterStatus = sanitize($_GET['status'] ?? 'all');
$filterRole = sanitize($_GET['role'] ?? 'all');
$dateFilter = sanitize($_GET['date_filter'] ?? 'all');
$dateFrom = sanitize($_GET['date_from'] ?? '');
$dateTo = sanitize($_GET['date_to'] ?? '');

$where = ["1=1"];
$params = [];

if ($filterCategory !== 'all' && !empty($filterCategory)) {
    $where[] = "`category` = ?";
    $params[] = $filterCategory;
}

if ($filterStatus !== 'all' && !empty($filterStatus)) {
    $where[] = "`status` = ?";
    $params[] = $filterStatus;
}

if ($filterRole !== 'all' && !empty($filterRole)) {
    $where[] = "`user_role` = ?";
    $params[] = $filterRole;
}

if ($dateFilter === 'today') {
    $where[] = "DATE(`created_at`) = CURDATE()";
} elseif ($dateFilter === 'yesterday') {
    $where[] = "DATE(`created_at`) = CURDATE() - INTERVAL 1 DAY";
} elseif ($dateFilter === 'last_7_days') {
    $where[] = "`created_at` >= CURDATE() - INTERVAL 7 DAY";
} elseif ($dateFilter === 'this_month') {
    $where[] = "MONTH(`created_at`) = MONTH(CURDATE()) AND YEAR(`created_at`) = YEAR(CURDATE())";
} elseif ($dateFilter === 'custom') {
    if (!empty($dateFrom)) {
        $where[] = "DATE(`created_at`) >= ?";
        $params[] = $dateFrom;
    }
    if (!empty($dateTo)) {
        $where[] = "DATE(`created_at`) <= ?";
        $params[] = $dateTo;
    }
}

$whereSql = implode(" AND ", $where);

// Fetch Stats & Logs
$logs = [];
$totalCount = 0;
$todayCount = 0;
$bioFaceCount = 0;
$warningCount = 0;
$categories = [];

if ($pdo) {
    try {
        // Stats
        $totalCount = $pdo->query("SELECT COUNT(*) FROM `audit_logs`")->fetchColumn() ?: 0;
        $todayCount = $pdo->query("SELECT COUNT(*) FROM `audit_logs` WHERE DATE(`created_at`) = CURDATE()")->fetchColumn() ?: 0;
        $bioFaceCount = $pdo->query("SELECT COUNT(*) FROM `audit_logs` WHERE `category` IN ('Biometric', 'Face Recognition')")->fetchColumn() ?: 0;
        $warningCount = $pdo->query("SELECT COUNT(*) FROM `audit_logs` WHERE `status` IN ('Warning', 'Failed')")->fetchColumn() ?: 0;
        
        $catRows = $pdo->query("SELECT DISTINCT `category` FROM `audit_logs` WHERE `category` != '' ORDER BY `category` ASC")->fetchAll(PDO::FETCH_COLUMN);
        $categories = $catRows ?: [];

        // Filtered logs
        $stmt = $pdo->prepare("SELECT * FROM `audit_logs` WHERE {$whereSql} ORDER BY `id` DESC LIMIT 500");
        $stmt->execute($params);
        $logs = $stmt->fetchAll();
    } catch (Exception $e) {
        $logs = [];
    }
}

$pageTitle = 'Audit Logs';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
$flash = getFlash();

function timeElapsedString($datetime) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->d >= 1) return $diff->d . 'd ago';
    if ($diff->h >= 1) return $diff->h . 'h ago';
    if ($diff->i >= 1) return $diff->i . 'm ago';
    return 'Just now';
}
?>

<main class="main-content">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-body">
        <?php if ($flash): ?>
            <div class="alert-box alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-check-circle' : ($flash['type'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-exclamation') ?>"></i>
                <span><?= htmlspecialchars($flash['message']) ?></span>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">System Audit Logs & Security Trails</h1>
                <p class="page-subtitle">Track, monitor, and audit administrative actions, biometric entries, attendance events, and system changes</p>
            </div>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <a href="audit_logs.php?export=csv" class="btn btn-secondary" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.35); text-decoration: none;" title="Export filtered audit logs to CSV spreadsheet">
                    <i class="fa-solid fa-file-export"></i>
                    <span>Export CSV</span>
                </a>
                <button type="button" class="btn btn-secondary" data-modal-target="clearLogsModal" style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.35);" title="Manage & Purge Logs">
                    <i class="fa-solid fa-trash-can"></i>
                    <span>Purge Logs</span>
                </button>
            </div>
        </div>

        <!-- KPI Metric Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 20px;">
            <!-- Card 1: Total Audit Events -->
            <div class="glass-panel" style="padding: 18px 20px; display: flex; align-items: center; gap: 16px; border-left: 4px solid #38bdf8;">
                <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(56, 189, 248, 0.15); display: flex; align-items: center; justify-content: center; color: #38bdf8; font-size: 1.4rem;">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #f8fafc; font-family: 'Outfit', sans-serif;"><?= number_format($totalCount) ?></div>
                    <div style="font-size: 0.8rem; color: var(--text-muted); font-weight: 500;">Total Audit Trails</div>
                </div>
            </div>

            <!-- Card 2: Today's Activity -->
            <div class="glass-panel" style="padding: 18px 20px; display: flex; align-items: center; gap: 16px; border-left: 4px solid #10b981;">
                <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(16, 185, 129, 0.15); display: flex; align-items: center; justify-content: center; color: #10b981; font-size: 1.4rem;">
                    <i class="fa-solid fa-calendar-day"></i>
                </div>
                <div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #f8fafc; font-family: 'Outfit', sans-serif;"><?= number_format($todayCount) ?></div>
                    <div style="font-size: 0.8rem; color: var(--text-muted); font-weight: 500;">Today's Activities</div>
                </div>
            </div>

            <!-- Card 3: Biometric & AI Face Sync -->
            <div class="glass-panel" style="padding: 18px 20px; display: flex; align-items: center; gap: 16px; border-left: 4px solid #a855f7;">
                <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(168, 85, 247, 0.15); display: flex; align-items: center; justify-content: center; color: #c084fc; font-size: 1.4rem;">
                    <i class="fa-solid fa-fingerprint"></i>
                </div>
                <div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #f8fafc; font-family: 'Outfit', sans-serif;"><?= number_format($bioFaceCount) ?></div>
                    <div style="font-size: 0.8rem; color: var(--text-muted); font-weight: 500;">Biometric & Face Sync</div>
                </div>
            </div>

            <!-- Card 4: Security Warnings -->
            <div class="glass-panel" style="padding: 18px 20px; display: flex; align-items: center; gap: 16px; border-left: 4px solid #f59e0b;">
                <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(245, 158, 11, 0.15); display: flex; align-items: center; justify-content: center; color: #f59e0b; font-size: 1.4rem;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #f8fafc; font-family: 'Outfit', sans-serif;"><?= number_format($warningCount) ?></div>
                    <div style="font-size: 0.8rem; color: var(--text-muted); font-weight: 500;">Security Warnings</div>
                </div>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="glass-panel" style="padding: 18px; margin-bottom: 20px;">
            <form method="GET" action="audit_logs.php" id="filterForm" style="margin: 0;">
                <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
                    
                    <!-- Category Filter -->
                    <div style="flex: 1; min-width: 150px;">
                        <label class="form-label" style="font-size: 0.78rem; font-weight: 600; margin-bottom: 4px;">Category</label>
                        <select name="category" class="form-control" onchange="document.getElementById('filterForm').submit()" style="padding: 7px 10px; font-size: 0.84rem;">
                            <option value="all" <?= $filterCategory === 'all' ? 'selected' : '' ?>>All Categories</option>
                            <option value="Authentication" <?= $filterCategory === 'Authentication' ? 'selected' : '' ?>>Authentication & Login</option>
                            <option value="Employee" <?= $filterCategory === 'Employee' ? 'selected' : '' ?>>Employee Management</option>
                            <option value="Biometric" <?= $filterCategory === 'Biometric' ? 'selected' : '' ?>>Biometrics (ZK Finger)</option>
                            <option value="Face Recognition" <?= $filterCategory === 'Face Recognition' ? 'selected' : '' ?>>Face Recognition (AI)</option>
                            <option value="Attendance" <?= $filterCategory === 'Attendance' ? 'selected' : '' ?>>Attendance & Punch</option>
                            <option value="Agent Terminals" <?= $filterCategory === 'Agent Terminals' ? 'selected' : '' ?>>Agent Terminals</option>
                            <option value="Data Export/Import" <?= $filterCategory === 'Data Export/Import' ? 'selected' : '' ?>>Data Export / Import</option>
                            <option value="System" <?= $filterCategory === 'System' ? 'selected' : '' ?>>System & Settings</option>
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div style="flex: 1; min-width: 130px;">
                        <label class="form-label" style="font-size: 0.78rem; font-weight: 600; margin-bottom: 4px;">Status</label>
                        <select name="status" class="form-control" onchange="document.getElementById('filterForm').submit()" style="padding: 7px 10px; font-size: 0.84rem;">
                            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="Success" <?= $filterStatus === 'Success' ? 'selected' : '' ?>>✓ Success</option>
                            <option value="Warning" <?= $filterStatus === 'Warning' ? 'selected' : '' ?>>⚠ Warning</option>
                            <option value="Failed" <?= $filterStatus === 'Failed' ? 'selected' : '' ?>>✕ Failed</option>
                        </select>
                    </div>

                    <!-- Role Filter -->
                    <div style="flex: 1; min-width: 130px;">
                        <label class="form-label" style="font-size: 0.78rem; font-weight: 600; margin-bottom: 4px;">Actor Role</label>
                        <select name="role" class="form-control" onchange="document.getElementById('filterForm').submit()" style="padding: 7px 10px; font-size: 0.84rem;">
                            <option value="all" <?= $filterRole === 'all' ? 'selected' : '' ?>>All Roles</option>
                            <option value="admin" <?= $filterRole === 'admin' ? 'selected' : '' ?>>Admin Accounts</option>
                            <option value="agent" <?= $filterRole === 'agent' ? 'selected' : '' ?>>Agent Terminals</option>
                        </select>
                    </div>

                    <!-- Date Range Filter -->
                    <div style="flex: 1.2; min-width: 160px;">
                        <label class="form-label" style="font-size: 0.78rem; font-weight: 600; margin-bottom: 4px;">Date Range</label>
                        <select name="date_filter" id="dateFilterSelect" class="form-control" onchange="toggleCustomDates(this.value); document.getElementById('filterForm').submit();" style="padding: 7px 10px; font-size: 0.84rem;">
                            <option value="all" <?= $dateFilter === 'all' ? 'selected' : '' ?>>All Time</option>
                            <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
                            <option value="yesterday" <?= $dateFilter === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                            <option value="last_7_days" <?= $dateFilter === 'last_7_days' ? 'selected' : '' ?>>Last 7 Days</option>
                            <option value="this_month" <?= $dateFilter === 'this_month' ? 'selected' : '' ?>>This Month</option>
                            <option value="custom" <?= $dateFilter === 'custom' ? 'selected' : '' ?>>Custom Range...</option>
                        </select>
                    </div>

                    <!-- Custom Date From/To -->
                    <div id="customDateBox" style="display: <?= $dateFilter === 'custom' ? 'flex' : 'none' ?>; gap: 8px; align-items: flex-end;">
                        <div>
                            <label class="form-label" style="font-size: 0.74rem; margin-bottom: 4px;">From</label>
                            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="form-control" style="padding: 6px 10px; font-size: 0.82rem;">
                        </div>
                        <div>
                            <label class="form-label" style="font-size: 0.74rem; margin-bottom: 4px;">To</label>
                            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="form-control" style="padding: 6px 10px; font-size: 0.82rem;">
                        </div>
                        <button type="submit" class="btn btn-secondary btn-sm" style="padding: 8px 14px; font-weight: 600;">Apply</button>
                    </div>

                    <!-- Reset Button -->
                    <?php if ($filterCategory !== 'all' || $filterStatus !== 'all' || $filterRole !== 'all' || $dateFilter !== 'all'): ?>
                        <div>
                            <a href="audit_logs.php" class="btn btn-secondary btn-sm" style="padding: 8px 12px; color: var(--text-muted);" title="Clear all filters">
                                <i class="fa-solid fa-rotate-left"></i> Reset
                            </a>
                        </div>
                    <?php endif; ?>

                    <!-- Realtime Search Input -->
                    <div class="nav-search" style="margin-left: auto; width: 280px; padding: 7px 14px;">
                        <i class="fa-solid fa-magnifying-glass fa-sm"></i>
                        <input type="text" class="table-search-input" data-target-table="auditLogTable" placeholder="Search event, actor, IP...">
                    </div>
                </div>
            </form>
        </div>

        <!-- Audit Log Table -->
        <div class="glass-panel" style="padding: 24px;">
            <div class="card-title-bar">
                <div>
                    <h3 class="card-title">Activity Trail Records</h3>
                    <p style="font-size: 0.82rem; color: var(--text-muted); margin-top: 2px;">
                        Showing <?= count($logs) ?> logged security and operational event(s)
                    </p>
                </div>
            </div>

            <div class="table-container">
                <table class="custom-table" id="auditLogTable">
                    <thead>
                        <tr>
                            <th style="width: 155px;">Timestamp</th>
                            <th>Actor / User</th>
                            <th>Category</th>
                            <th>Action Event</th>
                            <th>Description / Details</th>
                            <th>IP Address</th>
                            <th>Status</th>
                            <th style="width: 65px; text-align: center;">View</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($logs)): ?>
                            <?php foreach ($logs as $log): 
                                $catColors = [
                                    'Authentication' => ['bg' => 'rgba(56, 189, 248, 0.15)', 'color' => '#38bdf8', 'border' => 'rgba(56, 189, 248, 0.35)', 'icon' => 'fa-key'],
                                    'Employee' => ['bg' => 'rgba(16, 185, 129, 0.15)', 'color' => '#10b981', 'border' => 'rgba(16, 185, 129, 0.35)', 'icon' => 'fa-id-badge'],
                                    'Biometric' => ['bg' => 'rgba(168, 85, 247, 0.15)', 'color' => '#c084fc', 'border' => 'rgba(168, 85, 247, 0.35)', 'icon' => 'fa-fingerprint'],
                                    'Face Recognition' => ['bg' => 'rgba(236, 72, 153, 0.15)', 'color' => '#f472b6', 'border' => 'rgba(236, 72, 153, 0.35)', 'icon' => 'fa-camera'],
                                    'Attendance' => ['bg' => 'rgba(14, 165, 233, 0.15)', 'color' => '#38bdf8', 'border' => 'rgba(14, 165, 233, 0.35)', 'icon' => 'fa-clipboard-user'],
                                    'Agent Terminals' => ['bg' => 'rgba(245, 158, 11, 0.15)', 'color' => '#f59e0b', 'border' => 'rgba(245, 158, 11, 0.35)', 'icon' => 'fa-headset'],
                                    'Data Export/Import' => ['bg' => 'rgba(20, 184, 166, 0.15)', 'color' => '#2dd4bf', 'border' => 'rgba(20, 184, 166, 0.35)', 'icon' => 'fa-file-import'],
                                    'System' => ['bg' => 'rgba(148, 163, 184, 0.15)', 'color' => '#cbd5e1', 'border' => 'rgba(148, 163, 184, 0.35)', 'icon' => 'fa-gear']
                                ];
                                $catStyle = $catColors[$log['category']] ?? ['bg' => 'rgba(148, 163, 184, 0.15)', 'color' => '#cbd5e1', 'border' => 'rgba(148, 163, 184, 0.35)', 'icon' => 'fa-circle-info'];

                                $statusStyle = ['bg' => 'rgba(16, 185, 129, 0.15)', 'color' => '#10b981', 'border' => 'rgba(16, 185, 129, 0.35)'];
                                if ($log['status'] === 'Warning') {
                                    $statusStyle = ['bg' => 'rgba(245, 158, 11, 0.15)', 'color' => '#f59e0b', 'border' => 'rgba(245, 158, 11, 0.35)'];
                                } elseif ($log['status'] === 'Failed') {
                                    $statusStyle = ['bg' => 'rgba(239, 68, 68, 0.15)', 'color' => '#ef4444', 'border' => 'rgba(239, 68, 68, 0.35)'];
                                }
                            ?>
                                <tr>
                                    <!-- Timestamp Column -->
                                    <td>
                                        <div style="font-weight: 600; font-size: 0.82rem; color: #f8fafc;">
                                            <?= date('d M Y, h:i A', strtotime($log['created_at'])) ?>
                                        </div>
                                        <div style="font-size: 0.74rem; color: var(--text-muted);">
                                            <i class="fa-regular fa-clock fa-xs"></i> <?= timeElapsedString($log['created_at']) ?>
                                        </div>
                                    </td>

                                    <!-- Actor / User Column -->
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <div class="avatar" style="width: 30px; height: 30px; font-size: 0.76rem; background: <?= $log['user_role'] === 'agent' ? 'linear-gradient(135deg, #0284c7, #0369a1)' : 'linear-gradient(135deg, #6366f1, #4f46e5)' ?>;">
                                                <?= strtoupper(substr($log['user_name'] ?: 'S', 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 600; font-size: 0.84rem; color: #f8fafc;"><?= htmlspecialchars($log['user_name'] ?: 'System') ?></div>
                                                <span class="badge" style="font-size: 0.68rem; padding: 1px 6px; background: rgba(255, 255, 255, 0.06); color: var(--text-muted); border: 1px solid rgba(255, 255, 255, 0.1);">
                                                    <?= strtoupper(htmlspecialchars($log['user_role'] ?: 'admin')) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Category Column -->
                                    <td>
                                        <span class="badge" style="background: <?= $catStyle['bg'] ?>; color: <?= $catStyle['color'] ?>; border: 1px solid <?= $catStyle['border'] ?>; font-size: 0.75rem; padding: 3px 8px; display: inline-flex; align-items: center; gap: 5px;">
                                            <i class="fa-solid <?= $catStyle['icon'] ?> fa-xs"></i>
                                            <span><?= htmlspecialchars($log['category']) ?></span>
                                        </span>
                                    </td>

                                    <!-- Action Name Column -->
                                    <td>
                                        <strong style="font-size: 0.82rem; color: #38bdf8; font-family: monospace; letter-spacing: 0.02em;">
                                            <?= htmlspecialchars($log['action']) ?>
                                        </strong>
                                    </td>

                                    <!-- Details Column -->
                                    <td>
                                        <div style="font-size: 0.83rem; color: #cbd5e1; max-width: 420px; line-height: 1.35;">
                                            <?= htmlspecialchars($log['details']) ?>
                                        </div>
                                    </td>

                                    <!-- IP Address Column -->
                                    <td>
                                        <span style="font-size: 0.76rem; font-family: monospace; color: var(--text-muted); background: rgba(0,0,0,0.25); padding: 2px 6px; border-radius: 4px;">
                                            <?= htmlspecialchars($log['ip_address'] ?: '127.0.0.1') ?>
                                        </span>
                                    </td>

                                    <!-- Status Column -->
                                    <td>
                                        <span class="badge" style="background: <?= $statusStyle['bg'] ?>; color: <?= $statusStyle['color'] ?>; border: 1px solid <?= $statusStyle['border'] ?>; font-size: 0.75rem; padding: 3px 8px;">
                                            <i class="fa-solid <?= $log['status'] === 'Success' ? 'fa-circle-check' : ($log['status'] === 'Warning' ? 'fa-triangle-exclamation' : 'fa-circle-xmark') ?> fa-xs"></i>
                                            <?= htmlspecialchars($log['status']) ?>
                                        </span>
                                    </td>

                                    <!-- View Detail Button -->
                                    <td style="text-align: center;">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick='openLogDetailModal(<?= json_encode($log, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' style="padding: 4px 8px; color: var(--primary);" title="Inspect full audit record">
                                            <i class="fa-regular fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 45px 20px;">
                                    <div style="display: flex; flex-direction: column; align-items: center; gap: 10px;">
                                        <i class="fa-solid fa-clipboard-list" style="font-size: 2.2rem; color: #64748b;"></i>
                                        <div style="font-weight: 600; font-size: 1rem; color: #94a3b8;">No Audit Logs Found</div>
                                        <p style="font-size: 0.8rem; color: var(--text-muted); max-width: 360px; margin: 0;">No security or system activity logs match the selected filter parameters.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Clear / Purge Logs Modal -->
<div class="modal-backdrop" id="clearLogsModal">
    <div class="modal-box" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="modal-title" style="font-size: 1.15rem; font-weight: 700; color: #ef4444; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-trash-can"></i> Purge Audit Logs
            </h3>
            <button type="button" class="close-modal" data-modal-close>&times;</button>
        </div>
        <form method="POST" action="audit_logs.php">
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="clear_logs">

                <p style="font-size: 0.86rem; color: var(--text-muted); margin: 0; line-height: 1.4;">
                    Audit log maintenance helps reduce database footprint. Choose which historical logs you would like to purge:
                </p>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Purge Criteria</label>
                    <select name="retention_days" class="form-control" style="font-weight: 600;">
                        <option value="90">Logs older than 90 days</option>
                        <option value="60">Logs older than 60 days</option>
                        <option value="30" selected>Logs older than 30 days (Recommended)</option>
                        <option value="7">Logs older than 7 days</option>
                        <option value="0" style="color: #ef4444;">⚠️ Clear ALL Audit Logs (Truncate)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #ef4444; border-color: #ef4444;">
                    <i class="fa-solid fa-trash-can"></i>
                    <span>Confirm Purge</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Log Inspector Pop-up Modal -->
<div class="modal-backdrop" id="logDetailModal">
    <div class="modal-box" style="max-width: 580px;">
        <div class="modal-header">
            <h3 class="modal-title" style="font-size: 1.15rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-shield-halved" style="color: #38bdf8;"></i> Audit Event Inspector
            </h3>
            <button type="button" class="close-modal" data-modal-close>&times;</button>
        </div>
        <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div style="background: rgba(15, 23, 42, 0.6); padding: 10px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                    <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 600;">LOG ID & TIMESTAMP</div>
                    <div id="modal_log_id" style="font-weight: 700; color: #f8fafc; font-size: 0.88rem; margin-top: 2px;"></div>
                </div>
                <div style="background: rgba(15, 23, 42, 0.6); padding: 10px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                    <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 600;">ACTOR / USER</div>
                    <div id="modal_log_user" style="font-weight: 700; color: #38bdf8; font-size: 0.88rem; margin-top: 2px;"></div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div style="background: rgba(15, 23, 42, 0.6); padding: 10px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                    <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 600;">CATEGORY & ACTION</div>
                    <div id="modal_log_action" style="font-weight: 700; color: #f8fafc; font-size: 0.86rem; margin-top: 2px;"></div>
                </div>
                <div style="background: rgba(15, 23, 42, 0.6); padding: 10px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                    <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 600;">STATUS & IP ADDRESS</div>
                    <div id="modal_log_status" style="font-weight: 700; color: #10b981; font-size: 0.86rem; margin-top: 2px;"></div>
                </div>
            </div>

            <div>
                <label class="form-label" style="font-size: 0.78rem; font-weight: 600;">Full Event Description & Payload</label>
                <div id="modal_log_details" style="background: rgba(15, 23, 42, 0.8); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 14px; font-size: 0.85rem; color: #cbd5e1; line-height: 1.45; word-break: break-word;">
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-modal-close>Close</button>
        </div>
    </div>
</div>

<script>
function toggleCustomDates(val) {
    const box = document.getElementById('customDateBox');
    if (box) {
        box.style.display = (val === 'custom') ? 'flex' : 'none';
    }
}

function openLogDetailModal(log) {
    if (!log) return;
    document.getElementById('modal_log_id').innerText = '#' + log.id + ' • ' + log.created_at;
    document.getElementById('modal_log_user').innerText = (log.user_name || 'System') + ' (' + (log.user_role || 'admin').toUpperCase() + ')';
    document.getElementById('modal_log_action').innerText = log.category + ' → ' + log.action;
    document.getElementById('modal_log_status').innerText = log.status + ' • ' + (log.ip_address || '127.0.0.1');
    document.getElementById('modal_log_details').innerText = log.details || 'No additional payload';

    const modal = document.getElementById('logDetailModal');
    if (modal) modal.classList.add('active');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
