<?php
require_once __DIR__ . '/../config/init.php';
requirePermission('dashboard');

$pageTitle = 'Control Panel Dashboard';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

$pdo = getDB();
$today = date('Y-m-d');

// Real-time Attendance Metrics
$totalEmployees = 0;
$totalAgents = 0;
$activeAgents = 0;
$todayPresentCount = 0;
$todayLateCount = 0;
$todayOnTimeCount = 0;
$todayAbsentCount = 0;
$todayBioPunches = 0;
$todayFacePunches = 0;
$recentLogs = [];
$weeklyLabels = [];
$weeklyPresentData = [];

if ($pdo) {
    try {
        // Total Employees
        $totalEmployees = (int)($pdo->query("SELECT COUNT(*) FROM `employees`")->fetchColumn() ?: 0);

        // Agents
        $totalAgents = (int)($pdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'agent'")->fetchColumn() ?: 0);
        $activeAgents = (int)($pdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'agent' AND `status` = 'active'")->fetchColumn() ?: 0);

        // Today's Unique Present Employees
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT `employee_id`) FROM `attendance_logs` WHERE `date` = ?");
        $stmt->execute([$today]);
        $todayPresentCount = (int)($stmt->fetchColumn() ?: 0);

        // Today's Late Employees
        $stmtLate = $pdo->prepare("SELECT COUNT(DISTINCT `employee_id`) FROM `attendance_logs` WHERE `date` = ? AND `late_status` = 'Late'");
        $stmtLate->execute([$today]);
        $todayLateCount = (int)($stmtLate->fetchColumn() ?: 0);

        $todayOnTimeCount = max(0, $todayPresentCount - $todayLateCount);
        $todayAbsentCount = max(0, $totalEmployees - $todayPresentCount);

        // Today's Punch Types (Bio vs Face)
        $stmtBio = $pdo->prepare("SELECT COUNT(*) FROM `attendance_logs` WHERE `date` = ? AND (`attendance_type` LIKE '%biometric%' OR `attendance_type` LIKE '%finger%')");
        $stmtBio->execute([$today]);
        $todayBioPunches = (int)($stmtBio->fetchColumn() ?: 0);

        $stmtFace = $pdo->prepare("SELECT COUNT(*) FROM `attendance_logs` WHERE `date` = ? AND `attendance_type` LIKE '%face%'");
        $stmtFace->execute([$today]);
        $todayFacePunches = (int)($stmtFace->fetchColumn() ?: 0);

        // Recent Attendance Logs
        $logStmt = $pdo->query("
            SELECT l.*, u.name as agent_name, u.location as agent_location, e.department, e.designation, e.employee_code as emp_code
            FROM `attendance_logs` l
            LEFT JOIN `users` u ON l.agent_id = u.id
            LEFT JOIN `employees` e ON l.employee_id = e.id
            ORDER BY l.id DESC
            LIMIT 8
        ");
        $recentLogs = $logStmt->fetchAll();

        // 7-Day Trend
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $dLabel = date('D (M j)', strtotime($d));
            $wStmt = $pdo->prepare("SELECT COUNT(DISTINCT `employee_id`) FROM `attendance_logs` WHERE `date` = ?");
            $wStmt->execute([$d]);
            $cnt = (int)($wStmt->fetchColumn() ?: 0);

            $weeklyLabels[] = $dLabel;
            $weeklyPresentData[] = $cnt;
        }

    } catch (Exception $e) {
        // Fallbacks
    }
}

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

        <!-- Page Header -->
        <div class="page-header" style="margin-bottom: 24px;">
            <div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <h1 class="page-title" style="margin: 0;">Central Attendance Dashboard</h1>
                    <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); font-size: 0.78rem; font-weight: 700; padding: 4px 10px; border-radius: 20px;">
                        <i class="fa-solid fa-circle fa-2xs" style="animation: pulse 1.5s infinite;"></i> Live System
                    </span>
                </div>
                <p class="page-subtitle" style="margin-top: 4px;">Welcome back, <?= htmlspecialchars($currentUser['name'] ?? 'Admin') ?>. Today is <?= date('l, F j, Y') ?>.</p>
            </div>
        </div>

        <!-- KPI Stats Cards -->
        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom: 28px;">
            <!-- Total Employees -->
            <div class="glass-panel stat-card">
                <div class="stat-info">
                    <div class="stat-label">Total Registered Staff</div>
                    <div class="stat-value"><?= number_format($totalEmployees) ?></div>
                    <div class="stat-trend trend-up">
                        <i class="fa-solid fa-id-card"></i>
                        <span>Enrolled in system</span>
                    </div>
                </div>
                <div class="stat-icon blue">
                    <i class="fa-solid fa-users"></i>
                </div>
            </div>

            <!-- Today Present -->
            <div class="glass-panel stat-card">
                <div class="stat-info">
                    <div class="stat-label">Present Today</div>
                    <div class="stat-value" style="color: #10b981;"><?= number_format($todayPresentCount) ?></div>
                    <div class="stat-trend trend-up">
                        <i class="fa-solid fa-circle-check"></i>
                        <span><?= $totalEmployees > 0 ? round(($todayPresentCount / $totalEmployees) * 100) : 0 ?>% Turnout Rate</span>
                    </div>
                </div>
                <div class="stat-icon green">
                    <i class="fa-solid fa-user-check"></i>
                </div>
            </div>

            <!-- Late In Today -->
            <div class="glass-panel stat-card">
                <div class="stat-info">
                    <div class="stat-label">Late Arrivals Today</div>
                    <div class="stat-value" style="color: <?= $todayLateCount > 0 ? '#f59e0b' : 'var(--text-main)' ?>;"><?= number_format($todayLateCount) ?></div>
                    <div class="stat-trend" style="color: <?= $todayLateCount > 0 ? '#f59e0b' : 'var(--text-muted)' ?>;">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                        <span>Past shift duty start</span>
                    </div>
                </div>
                <div class="stat-icon purple" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
                    <i class="fa-solid fa-business-time"></i>
                </div>
            </div>

            <!-- Absent Today -->
            <div class="glass-panel stat-card">
                <div class="stat-info">
                    <div class="stat-label">Absent Today</div>
                    <div class="stat-value" style="color: <?= $todayAbsentCount > 0 ? '#f43f5e' : 'var(--text-main)' ?>;"><?= number_format($todayAbsentCount) ?></div>
                    <div class="stat-trend trend-down">
                        <i class="fa-solid fa-user-xmark"></i>
                        <span>No punch recorded</span>
                    </div>
                </div>
                <div class="stat-icon rose">
                    <i class="fa-solid fa-user-slash"></i>
                </div>
            </div>

            <!-- Active Points -->
            <div class="glass-panel stat-card">
                <div class="stat-info">
                    <div class="stat-label">Agent Terminals</div>
                    <div class="stat-value" style="color: #06b6d4;"><?= number_format($activeAgents) ?> <span style="font-size: 1rem; color: var(--text-muted); font-weight: 400;">/ <?= $totalAgents ?></span></div>
                    <div class="stat-trend trend-up">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span>Operating Points</span>
                    </div>
                </div>
                <div class="stat-icon" style="background: rgba(6, 182, 212, 0.15); color: #06b6d4;">
                    <i class="fa-solid fa-tower-broadcast"></i>
                </div>
            </div>
        </div>

        <!-- Analytics & Charts Grid -->
        <div class="dashboard-grid" style="margin-bottom: 28px;">
            <!-- 7-Day Attendance Trend Line Chart -->
            <div class="glass-panel chart-card">
                <div class="card-title-bar">
                    <div>
                        <h3 class="card-title">7-Day Attendance Trend</h3>
                        <p style="font-size: 0.82rem; color: var(--text-muted);">Daily employee attendance turnout across all agent points</p>
                    </div>
                    <span class="badge badge-normal"><i class="fa-solid fa-chart-line"></i> Last 7 Days</span>
                </div>
                <div style="height: 270px; position: relative;">
                    <canvas id="attendanceTrendChart"></canvas>
                </div>
            </div>

            <!-- Punch Method Breakdown Chart -->
            <div class="glass-panel chart-card">
                <div class="card-title-bar">
                    <div>
                        <h3 class="card-title">Today's Punch Channels</h3>
                        <p style="font-size: 0.82rem; color: var(--text-muted);">Biometric Fingerprint vs AI Face</p>
                    </div>
                    <i class="fa-solid fa-pie-chart" style="color: var(--text-muted);"></i>
                </div>
                <div style="height: 200px; position: relative; display: flex; align-items: center; justify-content: center;">
                    <canvas id="punchMethodChart"></canvas>
                </div>
                <div style="display: flex; justify-content: space-around; margin-top: 18px; font-size: 0.85rem; font-weight: 600;">
                    <div style="color: #10b981;"><i class="fa-solid fa-fingerprint"></i> Biometric (<?= $todayBioPunches ?>)</div>
                    <div style="color: #38bdf8;"><i class="fa-solid fa-camera"></i> Face AI (<?= $todayFacePunches ?>)</div>
                </div>
            </div>
        </div>

        <!-- Recent Real-Time Attendance Punches Table -->
        <div class="glass-panel" style="padding: 24px;">
            <div class="card-title-bar">
                <div>
                    <h3 class="card-title" style="display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-bolt" style="color: #f59e0b;"></i>
                        <span>Live Attendance Stream</span>
                    </h3>
                    <p style="font-size: 0.82rem; color: var(--text-muted);">Latest check-in and check-out records across all terminals</p>
                </div>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <a href="attendance_report.php" class="btn btn-secondary btn-sm">
                        <span>View All Logs</span>
                        <i class="fa-solid fa-arrow-right fa-xs"></i>
                    </a>
                </div>
            </div>

            <div class="table-container">
                <table class="custom-table" id="recentAttendanceTable">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Agent / Point</th>
                            <th>Time & Date</th>
                            <th>Method</th>
                            <th>Type</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentLogs)): ?>
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(56, 189, 248, 0.15); color: #38bdf8; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem;">
                                                <?= strtoupper(substr($log['employee_name'] ?? 'E', 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($log['employee_name'] ?? 'Unknown') ?></div>
                                                <div style="font-size: 0.78rem; color: var(--text-muted);">
                                                    ID: <strong style="color: #38bdf8;"><?= htmlspecialchars($log['emp_code'] ?? $log['employee_code'] ?? 'EMP-'.$log['employee_id']) ?></strong> 
                                                    <?= !empty($log['designation']) ? '• ' . htmlspecialchars($log['designation']) : '' ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: var(--text-main);">
                                            <i class="fa-solid fa-location-dot" style="color: #f97316; font-size: 0.85rem;"></i>
                                            <?= htmlspecialchars($log['agent_name'] ?? ('Agent #' . ($log['agent_id'] ?? 1))) ?>
                                        </div>
                                        <?php if (!empty($log['agent_location'])): ?>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($log['agent_location']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 700; color: var(--text-main); font-family: 'Outfit', monospace;">
                                            <?= date('h:i:s A', strtotime($log['timestamp'] ?? $log['time'])) ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">
                                            <?= date('M d, Y', strtotime($log['timestamp'] ?? $log['date'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (stripos($log['attendance_type'], 'face') !== false): ?>
                                            <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3);">
                                                <i class="fa-solid fa-camera"></i> Face AI
                                            </span>
                                        <?php else: ?>
                                            <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);">
                                                <i class="fa-solid fa-fingerprint"></i> Biometric
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $pType = strtoupper($log['punch_type'] ?? 'IN');
                                        if ($pType === 'OUT'): ?>
                                            <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3);">
                                                <i class="fa-solid fa-arrow-right-from-bracket"></i> Punch Out
                                            </span>
                                        <?php else: ?>
                                            <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);">
                                                <i class="fa-solid fa-arrow-right-to-bracket"></i> Punch In
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (($log['late_status'] ?? '') === 'Late'): ?>
                                            <span class="badge badge-attention">
                                                <i class="fa-solid fa-clock-rotate-left"></i> Late (<?= (int)$log['late_minutes'] ?>m)
                                            </span>
                                        <?php elseif (($log['early_status'] ?? '') === 'Early Leave'): ?>
                                            <span class="badge badge-attention" style="background: rgba(249, 115, 22, 0.15); color: #fb923c;">
                                                <i class="fa-solid fa-person-walking-arrow-right"></i> Early (<?= (int)$log['early_leave_minutes'] ?>m)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-normal">
                                                <i class="fa-solid fa-check"></i> On Time
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 40px;">
                                    <div style="font-size: 2rem; margin-bottom: 8px;"><i class="fa-solid fa-clock-rotate-left" style="opacity: 0.4;"></i></div>
                                    No attendance records logged yet today.
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
document.addEventListener('DOMContentLoaded', () => {
    // 1. 7-Day Attendance Trend Chart
    const trendCtx = document.getElementById('attendanceTrendChart');
    if (trendCtx) {
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($weeklyLabels) ?>,
                datasets: [{
                    label: 'Present Employees',
                    data: <?= json_encode($weeklyPresentData) ?>,
                    borderColor: '#38bdf8',
                    backgroundColor: 'rgba(56, 189, 248, 0.15)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#38bdf8',
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: { color: '#94a3b8', font: { family: 'Inter', size: 12 } }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, color: '#94a3b8' },
                        grid: { color: 'rgba(255, 255, 255, 0.05)' }
                    },
                    x: {
                        ticks: { color: '#94a3b8' },
                        grid: { color: 'rgba(255, 255, 255, 0.05)' }
                    }
                }
            }
        });
    }

    // 2. Punch Method Doughnut Chart
    const punchCtx = document.getElementById('punchMethodChart');
    if (punchCtx) {
        const bioCount = <?= (int)$todayBioPunches ?>;
        const faceCount = <?= (int)$todayFacePunches ?>;
        const hasData = (bioCount + faceCount) > 0;

        new Chart(punchCtx, {
            type: 'doughnut',
            data: {
                labels: ['Biometric Fingerprint', 'AI Face Recognition'],
                datasets: [{
                    data: hasData ? [bioCount, faceCount] : [1, 1],
                    backgroundColor: hasData ? ['#10b981', '#38bdf8'] : ['rgba(255,255,255,0.05)', 'rgba(255,255,255,0.08)'],
                    borderWidth: 0,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
