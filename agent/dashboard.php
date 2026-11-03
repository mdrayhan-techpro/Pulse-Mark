<?php
$pageTitle = 'Agent Workspace Dashboard';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

$pdo = getDB();
$agentId = (int)($currentAgent['id'] ?? 0);
$today = date('Y-m-d');

// Agent point information & modes
$agentInfo = null;
$totalAssignedEmployees = 0;
$todayPunchesCount = 0;
$todayUniquePresent = 0;
$todayLateCount = 0;
$recentLogs = [];

if (!empty($isGuestMode)) {
    // In Guest Mode: Do not expose database records or statistics
    $totalAssignedEmployees = 0;
    $todayPunchesCount = 0;
    $todayUniquePresent = 0;
    $todayLateCount = 0;
    $recentLogs = [];
} elseif ($pdo) {
    try {
        if (isAdminLoggedIn()) {
            $totalAssignedEmployees = (int)($pdo->query("SELECT COUNT(*) FROM `employees` WHERE `status` = 'Active' OR `status` IS NULL")->fetchColumn() ?: 0);
            
            $pStmt = $pdo->prepare("SELECT COUNT(*) FROM `attendance_logs` WHERE DATE(`punch_time`) = ?");
            $pStmt->execute([$today]);
            $todayPunchesCount = (int)($pStmt->fetchColumn() ?: 0);

            $uPresentStmt = $pdo->prepare("SELECT COUNT(DISTINCT `emp_id`) FROM `attendance_logs` WHERE DATE(`punch_time`) = ?");
            $uPresentStmt->execute([$today]);
            $todayUniquePresent = (int)($uPresentStmt->fetchColumn() ?: 0);

            $logStmt = $pdo->query("
                SELECT l.*, e.name as emp_name, e.department, e.designation, e.emp_id as emp_code
                FROM `attendance_logs` l
                LEFT JOIN `employees` e ON l.emp_id = e.emp_id
                ORDER BY l.id DESC
                LIMIT 7
            ");
            $recentLogs = $logStmt ? $logStmt->fetchAll() : [];
        } else {
            // Logged-in agent
            $uStmt = $pdo->prepare("SELECT * FROM `users` WHERE `id` = ?");
            $uStmt->execute([$agentId]);
            $agentInfo = $uStmt->fetch() ?: $currentAgent;

            $totalAssignedEmployees = count(getAgentAuthorizedEmployees($pdo, $agentId, true));

            $pStmt = $pdo->prepare("SELECT COUNT(*) FROM `attendance_logs` WHERE `agent_id` = ? AND DATE(`punch_time`) = ?");
            $pStmt->execute([$agentId, $today]);
            $todayPunchesCount = (int)($pStmt->fetchColumn() ?: 0);

            $uPresentStmt = $pdo->prepare("SELECT COUNT(DISTINCT `emp_id`) FROM `attendance_logs` WHERE `agent_id` = ? AND DATE(`punch_time`) = ?");
            $uPresentStmt->execute([$agentId, $today]);
            $todayUniquePresent = (int)($uPresentStmt->fetchColumn() ?: 0);

            $logStmt = $pdo->prepare("
                SELECT l.*, e.name as emp_name, e.department, e.designation, e.emp_id as emp_code
                FROM `attendance_logs` l
                LEFT JOIN `employees` e ON l.emp_id = e.emp_id
                WHERE l.agent_id = ?
                ORDER BY l.id DESC
                LIMIT 7
            ");
            $logStmt->execute([$agentId]);
            $recentLogs = $logStmt->fetchAll();
        }
    } catch (Exception $e) {
        $recentLogs = [];
    }
}

$bioMode = $agentInfo['biometric_mode'] ?? 'always_on';
$faceMode = $agentInfo['face_mode'] ?? 'always_on';
$bioStart = $agentInfo['biometric_start'] ?? '09:00:00';
$bioEnd = $agentInfo['biometric_end'] ?? '18:00:00';
$faceStart = $agentInfo['face_start'] ?? '09:00:00';
$faceEnd = $agentInfo['face_end'] ?? '18:00:00';

$flash = getFlash();
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

        <!-- Terminal Welcome & Mode Banner -->
        <div class="glass-panel" style="padding: 26px 30px; margin-bottom: 26px; background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(6, 182, 212, 0.06)); border: 1.5px solid rgba(16, 185, 129, 0.25); border-radius: 18px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
                <div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 0.8rem; color: #10b981; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Attendance Terminal Point</span>
                        <span style="background: rgba(16, 185, 129, 0.2); color: #34d399; font-size: 0.75rem; font-weight: 700; padding: 2px 8px; border-radius: 12px; border: 1px solid rgba(16, 185, 129, 0.4);">
                            ID: #<?= $agentId ?>
                        </span>
                    </div>
                    <h1 style="font-size: 1.85rem; font-weight: 800; font-family: 'Outfit', sans-serif; margin-top: 4px; color: #ffffff;">
                        <?= htmlspecialchars($agentInfo['name'] ?? $currentAgent['name'] ?? 'Agent Terminal') ?>
                    </h1>
                    <div style="font-size: 0.88rem; color: #94a3b8; display: flex; align-items: center; gap: 16px; margin-top: 4px;">
                        <span><i class="fa-solid fa-location-dot" style="color: #f97316;"></i> <?= htmlspecialchars($agentInfo['location'] ?? 'Main Office') ?></span>
                        <span><i class="fa-regular fa-calendar" style="color: #38bdf8;"></i> <?= date('l, F j, Y') ?></span>
                    </div>
                </div>

                <!-- Live Service Operating Badges -->
                <div style="display: flex; gap: 14px; flex-wrap: wrap; align-items: center;">
                    <!-- Biometric Service Badge -->
                    <div style="background: rgba(15, 23, 42, 0.6); padding: 10px 16px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.08); display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-fingerprint" style="color: #10b981; font-size: 1.2rem;"></i>
                        <div>
                            <div style="font-size: 0.72rem; color: #94a3b8; text-transform: uppercase; font-weight: 600;">Biometric Service</div>
                            <div style="font-size: 0.85rem; font-weight: 700; color: #ffffff;">
                                <?php if ($bioMode === 'always_on'): ?>
                                    <span style="color: #34d399;"><i class="fa-solid fa-circle fa-2xs"></i> 24/7 Active</span>
                                <?php elseif ($bioMode === 'disabled'): ?>
                                    <span style="color: #f87171;"><i class="fa-solid fa-ban fa-2xs"></i> Disabled</span>
                                <?php else: ?>
                                    <span style="color: #38bdf8;"><i class="fa-solid fa-clock fa-2xs"></i> <?= date('h:i A', strtotime($bioStart)) ?> - <?= date('h:i A', strtotime($bioEnd)) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Face Service Badge -->
                    <div style="background: rgba(15, 23, 42, 0.6); padding: 10px 16px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.08); display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-camera" style="color: #38bdf8; font-size: 1.2rem;"></i>
                        <div>
                            <div style="font-size: 0.72rem; color: #94a3b8; text-transform: uppercase; font-weight: 600;">Face AI Service</div>
                            <div style="font-size: 0.85rem; font-weight: 700; color: #ffffff;">
                                <?php if ($faceMode === 'always_on'): ?>
                                    <span style="color: #34d399;"><i class="fa-solid fa-circle fa-2xs"></i> 24/7 Active</span>
                                <?php elseif ($faceMode === 'disabled'): ?>
                                    <span style="color: #f87171;"><i class="fa-solid fa-ban fa-2xs"></i> Disabled</span>
                                <?php else: ?>
                                    <span style="color: #38bdf8;"><i class="fa-solid fa-clock fa-2xs"></i> <?= date('h:i A', strtotime($faceStart)) ?> - <?= date('h:i A', strtotime($faceEnd)) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Large Attendance Action Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 28px;">
            <!-- 1. Face Attendance Big Button -->
            <a href="face_attendance.php" class="glass-panel" style="min-height: 125px; padding: 26px 28px; text-decoration: none; display: flex; align-items: center; gap: 20px; border: 1.5px solid rgba(14, 165, 233, 0.4); border-radius: 16px; transition: all 0.25s ease;">
                <div style="width: 64px; height: 64px; border-radius: 16px; background: linear-gradient(135deg, #0ea5e9, #6366f1); display: flex; align-items: center; justify-content: center; font-size: 1.85rem; color: #ffffff; flex-shrink: 0; box-shadow: 0 0 20px rgba(14, 165, 233, 0.45);">
                    <i class="fa-solid fa-camera"></i>
                </div>
                <div style="flex: 1;">
                    <div style="font-size: 1.35rem; font-weight: 800; font-family: 'Outfit', sans-serif; color: var(--text-main); line-height: 1.2;">
                        Face Attendance
                    </div>
                    <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 4px;">
                        Quick Facial Recognition Clock-in & Verification
                    </div>
                </div>
                <div style="color: #38bdf8; font-size: 1.35rem; flex-shrink: 0;">
                    <i class="fa-solid fa-arrow-right"></i>
                </div>
            </a>

            <!-- 2. Biometric Attendance Big Button -->
            <a href="biometric_attendance.php" class="glass-panel" style="min-height: 125px; padding: 26px 28px; text-decoration: none; display: flex; align-items: center; gap: 20px; border: 1.5px solid rgba(16, 185, 129, 0.4); border-radius: 16px; transition: all 0.25s ease;">
                <div style="width: 64px; height: 64px; border-radius: 16px; background: linear-gradient(135deg, #10b981, #06b6d4); display: flex; align-items: center; justify-content: center; font-size: 1.85rem; color: #ffffff; flex-shrink: 0; box-shadow: 0 0 20px rgba(16, 185, 129, 0.45);">
                    <i class="fa-solid fa-fingerprint"></i>
                </div>
                <div style="flex: 1;">
                    <div style="font-size: 1.35rem; font-weight: 800; font-family: 'Outfit', sans-serif; color: var(--text-main); line-height: 1.2;">
                        Biometric Attendance
                    </div>
                    <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 4px;">
                        ZK-4500 Fingerprint Sensor Attendance Punch
                    </div>
                </div>
                <div style="color: #34d399; font-size: 1.35rem; flex-shrink: 0;">
                    <i class="fa-solid fa-arrow-right"></i>
                </div>
            </a>
        </div>

        <!-- Metric Cards Grid -->
        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom: 28px;">
            <!-- Assigned Staff -->
            <div class="glass-panel stat-card">
                <div class="stat-info">
                    <div class="stat-label">Assigned Staff</div>
                    <div class="stat-value"><?= number_format($totalAssignedEmployees) ?></div>
                    <div class="stat-trend trend-up">
                        <i class="fa-solid fa-users"></i>
                        <span>Permitted for this point</span>
                    </div>
                </div>
                <div class="stat-icon blue">
                    <i class="fa-solid fa-id-badge"></i>
                </div>
            </div>

            <!-- Today's Unique Present -->
            <div class="glass-panel stat-card">
                <div class="stat-info">
                    <div class="stat-label">Employees Present Today</div>
                    <div class="stat-value" style="color: #10b981;"><?= number_format($todayUniquePresent) ?></div>
                    <div class="stat-trend trend-up">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Punched at this terminal</span>
                    </div>
                </div>
                <div class="stat-icon green">
                    <i class="fa-solid fa-user-check"></i>
                </div>
            </div>

            <!-- Total Punches Today -->
            <div class="glass-panel stat-card">
                <div class="stat-info">
                    <div class="stat-label">Total Punches Today</div>
                    <div class="stat-value" style="color: #38bdf8;"><?= number_format($todayPunchesCount) ?></div>
                    <div class="stat-trend trend-up">
                        <i class="fa-solid fa-arrow-right-arrow-left"></i>
                        <span>In / Out Records</span>
                    </div>
                </div>
                <div class="stat-icon" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8;">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
            </div>

            <!-- Late Punches -->
            <div class="glass-panel stat-card">
                <div class="stat-info">
                    <div class="stat-label">Late Punches Today</div>
                    <div class="stat-value" style="color: <?= $todayLateCount > 0 ? '#f59e0b' : 'var(--text-main)' ?>;"><?= number_format($todayLateCount) ?></div>
                    <div class="stat-trend" style="color: <?= $todayLateCount > 0 ? '#f59e0b' : 'var(--text-muted)' ?>;">
                        <i class="fa-solid fa-clock"></i>
                        <span>Delayed check-ins</span>
                    </div>
                </div>
                <div class="stat-icon purple" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
                    <i class="fa-solid fa-business-time"></i>
                </div>
            </div>
        </div>

        <!-- Quick Enrollment & Recent Punches Grid -->
        <div style="display: grid; grid-template-columns: 1fr 340px; gap: 24px; margin-bottom: 28px;">
            <!-- Left: Recent Punches Table -->
            <div class="glass-panel" style="padding: 24px;">
                <div class="card-title-bar">
                    <div>
                        <h3 class="card-title" style="display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-bolt" style="color: #f59e0b;"></i>
                            <span>Recent Punches (This Terminal)</span>
                        </h3>
                        <p style="font-size: 0.82rem; color: var(--text-muted);">Real-time check-ins and check-outs recorded at this point</p>
                    </div>
                    <a href="attendance_report.php" class="btn btn-secondary btn-sm">View Report</a>
                </div>

                <div class="table-container">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Time</th>
                                <th>Channel</th>
                                <th>Type</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recentLogs)): ?>
                                <?php foreach ($recentLogs as $log): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($log['emp_name'] ?? $log['employee_name'] ?? 'Authorized Staff') ?></div>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                ID: <strong style="color: #38bdf8;"><?= htmlspecialchars($log['emp_code'] ?? $log['emp_id'] ?? 'EMP-'.$log['id']) ?></strong>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 700; color: var(--text-main); font-family: 'Outfit', monospace;">
                                                <?= date('h:i:s A', strtotime($log['punch_time'] ?? $log['timestamp'] ?? $log['time'])) ?>
                                            </div>
                                            <div style="font-size: 0.72rem; color: var(--text-muted);">
                                                <?= date('M d, Y', strtotime($log['punch_time'] ?? $log['timestamp'] ?? $log['date'])) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (stripos($log['punch_type'] ?? '', 'face') !== false): ?>
                                                <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3);">
                                                    <i class="fa-solid fa-camera"></i> Face AI
                                                </span>
                                            <?php else: ?>
                                                <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);">
                                                    <i class="fa-solid fa-fingerprint"></i> Biometric ZK
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);">
                                                <?= htmlspecialchars($log['punch_type'] ?? 'IN') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-normal"><i class="fa-solid fa-check"></i> Verified</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 36px 20px;">
                                        <?php if (!empty($isGuestMode)): ?>
                                            <div style="font-weight: 700; color: #94a3b8; margin-bottom: 6px; font-size: 0.92rem;">
                                                <i class="fa-solid fa-lock" style="color: #38bdf8; margin-right: 6px;"></i> Live attendance stream is hidden in Guest Preview Mode.
                                            </div>
                                            <div style="font-size: 0.82rem; color: var(--text-dim);">
                                                Please <a href="#" onclick="openLoginModal(); return false;" style="color: #38bdf8; font-weight: 700; text-decoration: underline;">Sign In</a> with an authorized account to view attendance logs.
                                            </div>
                                        <?php else: ?>
                                            No attendance logs recorded yet for this terminal today.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right: Quick Enrollment Cards -->
            <div style="display: flex; flex-direction: column; gap: 16px;">
                <div class="glass-panel" style="padding: 22px;">
                    <h3 class="card-title" style="margin-bottom: 14px; font-size: 1.05rem;">
                        <i class="fa-solid fa-user-plus" style="color: #38bdf8;"></i> Staff Enrollment
                    </h3>
                    <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 16px;">
                        Enroll new biometric or facial credentials for authorized employees.
                    </p>

                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <a href="biometric_entry.php" class="btn btn-secondary" style="width: 100%; justify-content: flex-start; gap: 12px; padding: 14px 16px;">
                            <i class="fa-solid fa-fingerprint" style="color: #10b981; font-size: 1.15rem;"></i>
                            <div style="text-align: left;">
                                <div style="font-weight: 700; font-size: 0.9rem; color: #ffffff;">New Biometric Entry</div>
                                <div style="font-size: 0.72rem; color: var(--text-muted);">Register Fingerprint (ZK-4500)</div>
                            </div>
                        </a>

                        <a href="face_entry.php" class="btn btn-secondary" style="width: 100%; justify-content: flex-start; gap: 12px; padding: 14px 16px;">
                            <i class="fa-solid fa-camera" style="color: #38bdf8; font-size: 1.15rem;"></i>
                            <div style="text-align: left;">
                                <div style="font-weight: 700; font-size: 0.9rem; color: #ffffff;">New Face Entry</div>
                                <div style="font-size: 0.72rem; color: var(--text-muted);">Enroll AI Face Vector</div>
                            </div>
                        </a>
                    </div>
                </div>

                <div class="glass-panel" style="padding: 22px;">
                    <h3 class="card-title" style="margin-bottom: 12px; font-size: 1.05rem;">
                        <i class="fa-solid fa-users-gear" style="color: #10b981;"></i> Terminal Roster
                    </h3>
                    <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 14px;">
                        View all permitted employees assigned to this terminal point.
                    </p>
                    <a href="registered_users.php" class="btn btn-primary" style="width: 100%; justify-content: center;">
                        <span>View Registered Employees</span>
                        <i class="fa-solid fa-arrow-right fa-xs"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
