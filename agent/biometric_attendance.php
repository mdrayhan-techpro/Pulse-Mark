<?php
$pageTitle = 'Biometric Attendance | Pulse Mark';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

$pdo = getDB();
$agentId = $currentAgent['id'] ?? 1;

// Fetch today's attendance logs for this agent
$todayLogs = [];
$termBioStatus = isAgentTerminalServiceActive($pdo, $agentId, 'biometric');

if (!empty($isGuestMode)) {
    // In Guest Mode: Do not expose biometric logs from database
    $termBioStatus = ['active' => true, 'reason' => 'Guest Preview Mode (Read-Only)'];
    $todayLogs = [];
} elseif ($pdo) {
    try {
        $q = $pdo->prepare("SELECT a.*, e.name as emp_name, e.department, e.designation, e.company 
                            FROM `attendance_logs` a 
                            LEFT JOIN `employees` e ON a.emp_id = e.emp_id 
                            WHERE a.agent_id = ?
                            ORDER BY a.id DESC LIMIT 10");
        $q->execute([$agentId]);
        $todayLogs = $q->fetchAll();
    } catch (Exception $e) {
        $todayLogs = [];
    }
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

        <?php if (!$termBioStatus['active']): ?>
            <div class="alert-box alert-danger" style="margin-bottom: 24px; border: 1.5px solid rgba(239, 68, 68, 0.4); background: rgba(239, 68, 68, 0.12); display: flex; align-items: center; gap: 12px;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.4rem; color: #f87171;"></i>
                <div>
                    <strong style="color: #f87171; display: block; font-size: 0.98rem;">Terminal Point Biometric Attendance Inactive</strong>
                    <span style="color: #cbd5e1; font-size: 0.88rem;"><?= htmlspecialchars($termBioStatus['reason']) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
            <div>
                <h1 class="page-title" style="font-size: 1.85rem; font-weight: 800; font-family: 'Outfit', sans-serif; display: flex; align-items: center; gap: 12px;">
                    <i class="fa-solid fa-fingerprint" style="color: #10b981;"></i>
                    <span>Live Biometric Attendance</span>
                </h1>
                <p class="page-subtitle" style="color: #94a3b8; font-size: 0.95rem; margin-top: 4px;">
                    Touch ZKTeco USB sensor. Registered employee identity and attendance will be identified automatically.
                </p>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-secondary" style="border: 1px solid rgba(255, 255, 255, 0.2); padding: 10px 18px; font-weight: 600;">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span>Back to Dashboard</span>
                </a>
            </div>
        </div>

        <!-- ================= MAIN TWO-COLUMN LAYOUT ================= -->
        <div style="display: grid; grid-template-columns: 1.1fr 1fr; gap: 28px; align-items: start; margin-bottom: 32px;">

            <!-- ================= LEFT SIDE: ZKTeco LIVE SENSOR DISPLAY ================= -->
            <div class="glass-panel" style="padding: 24px; border-radius: 18px; border: 1.5px solid rgba(16, 185, 129, 0.35); background: rgba(10, 16, 30, 0.9);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.08);">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="width: 12px; height: 12px; border-radius: 50%; background: #10b981; box-shadow: 0 0 10px #10b981;"></span>
                        <h2 style="font-size: 1.15rem; font-weight: 700; color: #f8fafc; margin: 0;">Biometric Live Display</h2>
                    </div>
                    <span id="zkStatusBadge" style="font-size: 0.78rem; font-weight: 700; padding: 4px 12px; border-radius: 20px; background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4);">
                        <i class="fa-solid fa-plug-circle-check"></i> SENSOR READY
                    </span>
                </div>

                <!-- THE BLACK SURFACE SCANNER WINDOW -->
                <div id="blackSurface" style="position: relative; width: 100%; height: 380px; background: #000000; border-radius: 14px; border: 2.5px solid #10b981; display: flex; flex-direction: column; align-items: center; justify-content: center; overflow: hidden; box-shadow: inset 0 0 35px rgba(0,0,0,0.9), 0 0 25px rgba(16, 185, 129, 0.3); transition: all 0.35s ease; cursor: pointer;" onclick="manualTriggerScan()">
                    
                    <!-- HUD info -->
                    <div style="position: absolute; top: 12px; left: 16px; right: 16px; display: flex; justify-content: space-between; align-items: center; font-family: monospace; font-size: 0.74rem; color: #94a3b8; z-index: 5;">
                        <span>DEVICE: ZK-LIVE20R</span>
                        <span id="liveListenerStatus" style="color: #10b981; font-weight: 700;">LISTENING FOR TOUCH...</span>
                    </div>

                    <!-- Ambient Grid overlay -->
                    <div style="position: absolute; inset: 0; background-image: linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px); background-size: 24px 24px; pointer-events: none;"></div>

                    <!-- Scanner Laser Sweep -->
                    <div id="zkLaser" style="position: absolute; width: 85%; height: 3px; background: #10b981; box-shadow: 0 0 18px #10b981, 0 0 8px #06b6d4; top: 15%; z-index: 6; animation: zkLaserAnim 1.6s infinite alternate ease-in-out;"></div>

                    <!-- Fingerprint Graphic Container -->
                    <div id="fpGraphicWrap" style="position: relative; width: 200px; height: 260px; display: flex; align-items: center; justify-content: center; z-index: 4;">
                        
                        <!-- Standby SVG -->
                        <svg id="fpSvgStandby" viewBox="0 0 100 130" style="width: 170px; height: 220px; opacity: 0.35; transition: all 0.4s ease;">
                            <path d="M50 15 C30 15 15 32 15 55 C15 78 28 95 35 115" stroke="#10b981" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                            <path d="M50 25 C36 25 25 38 25 57 C25 80 40 92 45 110" stroke="#10b981" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                            <path d="M50 35 C42 35 35 44 35 58 C35 75 48 88 50 105" stroke="#10b981" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                            <path d="M50 45 C46 45 42 50 42 60 C42 70 48 80 50 95" stroke="#10b981" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                            <path d="M50 15 C70 15 85 32 85 55 C85 78 72 95 65 115" stroke="#10b981" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                            <path d="M50 25 C64 25 75 38 75 57 C75 80 60 92 55 110" stroke="#10b981" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                            <path d="M50 35 C58 35 65 44 65 58 C65 75 52 88 50 105" stroke="#10b981" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                            <path d="M50 45 C54 45 58 50 58 60 C58 70 52 80 50 95" stroke="#10b981" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                            <path d="M50 55 C50 65 50 75 50 85" stroke="#10b981" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                        </svg>

                        <!-- Real Live Hardware Image Capture -->
                        <div id="fpRealImage" style="display: none; width: 100%; height: 100%; align-items: center; justify-content: center;">
                            <img id="liveSensorImg" src="" alt="Captured Fingerprint" style="width: 170px; height: 220px; object-fit: cover; border-radius: 10px; filter: drop-shadow(0 0 15px #10b981); border: 1.5px solid #10b981;">
                        </div>
                    </div>

                    <!-- Bottom Status Text inside Black Surface -->
                    <div id="surfaceMsg" style="position: absolute; bottom: 12px; left: 16px; right: 16px; text-align: center; font-size: 0.85rem; font-weight: 700; z-index: 5;">
                        <span style="color: #34d399;"><i class="fa-solid fa-hand"></i> Sensor Active: Place finger on ZKTeco scanner to mark attendance...</span>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 14px; font-size: 0.82rem; color: #94a3b8;">
                    <span><i class="fa-solid fa-bolt" style="color: #10b981;"></i> Auto-detects physical USB touch</span>
                    <button type="button" onclick="manualTriggerScan()" class="btn btn-sm" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); font-weight: 600;">
                        <i class="fa-solid fa-fingerprint"></i> Test Tap Scan
                    </button>
                </div>
            </div>

            <!-- ================= RIGHT SIDE: DYNAMIC USER RECOGNITION (Initially Empty) ================= -->
            <div class="glass-panel" style="padding: 24px; border-radius: 18px; min-height: 480px; display: flex; flex-direction: column; justify-content: center; position: relative;">
                
                <!-- 1. INITIAL EMPTY STATE -->
                <div id="rightSideEmpty" style="text-align: center; padding: 40px 20px;">
                    <div style="width: 100px; height: 100px; border-radius: 50%; background: rgba(30, 41, 59, 0.6); border: 2px dashed rgba(56, 189, 248, 0.3); margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 3rem; animation: emptyPulse 2.5s infinite ease-in-out;">
                        <i class="fa-solid fa-id-card"></i>
                    </div>
                    <h3 style="font-size: 1.3rem; font-weight: 800; color: #f8fafc; margin-bottom: 8px; font-family: 'Outfit', sans-serif;">
                        Waiting for Fingerprint...
                    </h3>
                    <p style="color: #94a3b8; font-size: 0.9rem; max-width: 320px; margin: 0 auto; line-height: 1.6;">
                        Place finger on the ZKTeco scanner on the left. The registered employee's profile and attendance will appear here automatically.
                    </p>
                </div>

                <!-- 2. SCANNING / MATCHING STATE -->
                <div id="rightSideLoading" style="display: none; text-align: center; padding: 50px 20px;">
                    <div style="width: 80px; height: 80px; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; font-size: 3rem; color: #38bdf8;">
                        <i class="fa-solid fa-spinner fa-spin"></i>
                    </div>
                    <h4 style="font-size: 1.2rem; font-weight: 700; color: #f8fafc; margin-bottom: 6px;">
                        Matching Biometric Template...
                    </h4>
                    <p style="color: #38bdf8; font-size: 0.85rem;">Searching registered database</p>
                </div>

                <!-- 3. SUCCESS STATE: EMPLOYEE FOUND & ATTENDANCE MARKED -->
                <div id="rightSideSuccess" style="display: none;">
                    
                    <!-- Verified Header Banner -->
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-radius: 12px; background: rgba(16, 185, 129, 0.15); border: 1.5px solid #10b981; margin-bottom: 20px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-circle-check" style="font-size: 1.5rem; color: #10b981;"></i>
                            <div>
                                <div style="font-weight: 800; color: #10b981; font-size: 1.05rem;">ATTENDANCE VERIFIED</div>
                                <div id="resPunchTime" style="font-size: 0.78rem; color: #a7f3d0;">Today at 00:00:00</div>
                            </div>
                        </div>
                        <span id="resMatchScore" style="font-size: 0.8rem; font-weight: 800; padding: 4px 10px; border-radius: 20px; background: #10b981; color: #022c22;">
                            MATCH: 99.8%
                        </span>
                    </div>

                    <!-- Big Employee Profile Card -->
                    <div style="padding: 24px; border-radius: 16px; background: rgba(15, 23, 42, 0.8); border: 1px solid rgba(56, 189, 248, 0.35); text-align: center; margin-bottom: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
                        
                        <!-- Avatar -->
                        <div id="resAvatar" style="width: 84px; height: 84px; border-radius: 50%; background: linear-gradient(135deg, #10b981, #06b6d4); color: #fff; font-size: 2.2rem; font-weight: 800; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; border: 3px solid rgba(255,255,255,0.2); box-shadow: 0 0 20px rgba(16, 185, 129, 0.5);">
                            --
                        </div>

                        <!-- Name & ID -->
                        <h2 id="resEmpName" style="font-size: 1.5rem; font-weight: 800; color: #ffffff; margin: 0 0 6px 0; font-family: 'Outfit', sans-serif;">
                            Verified Personnel
                        </h2>
                        <div style="margin-bottom: 16px;">
                            <span id="resEmpId" style="font-family: monospace; font-size: 1rem; font-weight: 800; color: #38bdf8; background: rgba(56, 189, 248, 0.15); padding: 4px 12px; border-radius: 20px; border: 1px solid rgba(56, 189, 248, 0.3);">
                                EMP-ID
                            </span>
                        </div>

                        <!-- Details Grid -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; text-align: left; background: rgba(30, 41, 59, 0.6); padding: 14px; border-radius: 12px; font-size: 0.85rem; border: 1px solid rgba(255, 255, 255, 0.05);">
                            <div>
                                <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase;">Department</div>
                                <div id="resDept" style="font-weight: 700; color: #f1f5f9;">Information Technology</div>
                            </div>
                            <div>
                                <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase;">Designation</div>
                                <div id="resDesig" style="font-weight: 700; color: #f1f5f9;">Sr. Executive</div>
                            </div>
                            <div>
                                <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase;">Location</div>
                                <div id="resLoc" style="font-weight: 700; color: #f1f5f9;">Corporate Office</div>
                            </div>
                            <div>
                                <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase;">Status</div>
                                <div style="font-weight: 700; color: #10b981;"><i class="fa-solid fa-circle-check"></i> Present / Logged</div>
                            </div>
                        </div>

                    </div>

                    <!-- Ready next scan hint -->
                    <div style="text-align: center; font-size: 0.82rem; color: #94a3b8;">
                        <i class="fa-solid fa-rotate" style="color: #38bdf8;"></i> Next person can place finger on the scanner anytime.
                    </div>
                </div>

                <!-- 4. NOT FOUND / UNREGISTERED STATE -->
                <div id="rightSideNotFound" style="display: none; text-align: center; padding: 40px 20px;">
                    <div style="width: 80px; height: 80px; border-radius: 50%; background: rgba(239, 68, 68, 0.15); color: #f87171; border: 2px solid #ef4444; margin: 0 auto 18px; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; box-shadow: 0 0 25px rgba(239, 68, 68, 0.4);">
                        <i class="fa-solid fa-user-xmark"></i>
                    </div>
                    <h3 style="font-size: 1.3rem; font-weight: 800; color: #ef4444; margin-bottom: 8px;">
                        Fingerprint Not Registered!
                    </h3>
                    <p style="color: #94a3b8; font-size: 0.9rem; max-width: 320px; margin: 0 auto 20px; line-height: 1.6;">
                        This fingerprint template is not registered to any employee in the system.
                    </p>
                    <a href="biometric_entry.php" class="btn btn-primary" style="background: linear-gradient(135deg, #10b981, #06b6d4); font-weight: 700; padding: 10px 20px;">
                        <i class="fa-solid fa-fingerprint"></i>
                        <span>Enroll Fingerprint Now</span>
                    </a>
                </div>

                <!-- 5. ATTENDANCE BLOCKED / INACTIVE STATE -->
                <div id="rightSideBlocked" style="display: none; text-align: center; padding: 40px 20px;">
                    <div style="width: 80px; height: 80px; border-radius: 50%; background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 2px solid #ef4444; margin: 0 auto 18px; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; box-shadow: 0 0 30px rgba(239, 68, 68, 0.6);">
                        <i class="fa-solid fa-ban"></i>
                    </div>
                    <h3 style="font-size: 1.35rem; font-weight: 800; color: #ef4444; margin-bottom: 8px;">
                        Attendance is BLOCKED!
                    </h3>
                    <p id="blockedMsgText" style="color: #f87171; font-weight: 600; font-size: 0.92rem; max-width: 340px; margin: 0 auto 20px; line-height: 1.6;">
                        Attendance marking for this employee is currently blocked by Administrator.
                    </p>
                    <a href="registered_users.php" class="btn btn-secondary" style="font-weight: 700; padding: 10px 20px;">
                        <i class="fa-solid fa-users-gear"></i>
                        <span>Manage Employee Permissions</span>
                    </a>
                </div>

            </div>

        </div>

        <!-- ================= BOTTOM: Recent Attendance Logs ================= -->
        <div class="glass-panel" style="padding: 24px; border-radius: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                <h3 style="font-size: 1.15rem; font-weight: 700; color: #f8fafc; margin: 0; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-clock-rotate-left" style="color: #38bdf8;"></i>
                    <span>Today's Realtime Biometric Attendance Log</span>
                </h3>
                <span style="font-size: 0.82rem; color: #10b981; font-weight: 700;">
                    <i class="fa-solid fa-wifi"></i> Live Telemetry Synced
                </span>
            </div>

            <div class="table-responsive">
                <table class="data-table" id="attendanceTable" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Log ID</th>
                            <th>Employee ID</th>
                            <th>Employee Name</th>
                            <th>Department</th>
                            <th>Designation</th>
                            <th>Verification Mode</th>
                            <th>Match Score</th>
                            <th>Punch Time</th>
                        </tr>
                    </thead>
                    <tbody id="attendanceTbody">
                        <?php if (!empty($todayLogs)): ?>
                            <?php foreach ($todayLogs as $log): ?>
                                <tr>
                                    <td><strong>#LOG-<?= str_pad($log['id'], 4, '0', STR_PAD_LEFT) ?></strong></td>
                                    <td><span style="font-weight: 800; color: #38bdf8; font-family: monospace;"><?= htmlspecialchars($log['emp_id']) ?></span></td>
                                    <td>
                                        <div style="font-weight: 700; color: #f8fafc;"><?= htmlspecialchars($log['emp_name'] ?? 'Registered User') ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($log['department'] ?? 'General') ?></td>
                                    <td><?= htmlspecialchars($log['designation'] ?? 'Staff') ?></td>
                                    <td>
                                        <span style="color: #10b981; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                            <i class="fa-solid fa-fingerprint"></i> <?= htmlspecialchars($log['verification_mode'] ?? 'ZKTeco Mini') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge" style="background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.4);">
                                            <?= htmlspecialchars($log['quality_score'] ?? '99.8%') ?>
                                        </span>
                                    </td>
                                    <td style="color: #cbd5e1; font-weight: 600; font-size: 0.88rem;">
                                        <?= date('h:i:s A - M d', strtotime($log['punch_time'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr id="noLogsRow">
                                <td colspan="8" style="text-align: center; padding: 36px 20px; color: #94a3b8;">
                                    <?php if (!empty($isGuestMode)): ?>
                                        <div style="font-weight: 700; color: #94a3b8; margin-bottom: 6px; font-size: 0.95rem;">
                                            <i class="fa-solid fa-lock" style="color: #38bdf8; margin-right: 6px;"></i> Biometric attendance records are protected in Guest Mode.
                                        </div>
                                        <div style="font-size: 0.84rem; color: var(--text-dim);">
                                            Please <a href="#" onclick="openLoginModal(); return false;" style="color: #38bdf8; font-weight: 700; text-decoration: underline;">Sign In</a> with an Agent account to view biometric logs.
                                        </div>
                                    <?php else: ?>
                                        No attendance punches recorded yet. Place finger on sensor to log attendance.
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

<style>
@keyframes zkLaserAnim {
    0% { top: 12%; }
    100% { top: 86%; }
}
@keyframes emptyPulse {
    0%, 100% { transform: scale(1); opacity: 0.6; }
    50% { transform: scale(1.06); opacity: 0.95; }
}
@keyframes successGlow {
    0%, 100% { box-shadow: inset 0 0 35px rgba(0,0,0,0.9), 0 0 30px rgba(16, 185, 129, 0.6); }
    50% { box-shadow: inset 0 0 35px rgba(0,0,0,0.9), 0 0 50px rgba(6, 182, 212, 0.9); }
}
.surface-scan-success {
    animation: successGlow 1.5s infinite ease-in-out !important;
}
</style>

<script>
let isBusyProcessing = false;
let attendancePollTimer = null;

// Audio feedback chime
function playSuccessBeep() {
    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        const ctx = new AudioContext();
        
        // Two-tone pleasant success chime
        const osc1 = ctx.createOscillator();
        const osc2 = ctx.createOscillator();
        const gain = ctx.createGain();

        osc1.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
        osc2.frequency.setValueAtTime(880.00, ctx.currentTime + 0.12); // A5

        gain.gain.setValueAtTime(0.15, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);

        osc1.connect(gain);
        osc2.connect(gain);
        gain.connect(ctx.destination);

        osc1.start(ctx.currentTime);
        osc1.stop(ctx.currentTime + 0.12);
        osc2.start(ctx.currentTime + 0.12);
        osc2.stop(ctx.currentTime + 0.35);
    } catch(e) {}
}

function playFailBeep() {
    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        const ctx = new AudioContext();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.frequency.setValueAtTime(220, ctx.currentTime);
        gain.gain.setValueAtTime(0.2, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.3);
    } catch(e) {}
}

// Background polling loop from hardware scanner on port 9000
let isPollingFetchRunning = false;
function startAttendancePolling() {
    if (attendancePollTimer) clearInterval(attendancePollTimer);

    attendancePollTimer = setInterval(async () => {
        if (isBusyProcessing || isPollingFetchRunning) return;
        isPollingFetchRunning = true;

        try {
            const resp = await fetch('api_zk.php?action=capture&mode=attendance', { cache: 'no-store' });
            if (resp.ok) {
                const data = await resp.json();
                if (data.status === 'success' || data.status === 'unregistered') {
                    // Physical finger touch detected from ZKTeco scanner!
                    handleFingerprintRecognized(data);
                }
            }
        } catch(e) {
        } finally {
            isPollingFetchRunning = false;
        }
    }, 350);
}

window.addEventListener('beforeunload', () => {
    if (attendancePollTimer) clearInterval(attendancePollTimer);
});

// Handle Fingerprint Recognition
async function handleFingerprintRecognized(data) {
    if (isBusyProcessing) return;
    isBusyProcessing = true;

    const capturedImage = data.image || null;
    const isMatched = data.matched === true && data.status === 'success';
    const empId = data.emp_id || '';
    const matchScore = data.score || 0;

    // Show scanned finger image on left display
    if (capturedImage) {
        document.getElementById('liveSensorImg').src = capturedImage;
        document.getElementById('liveSensorImg').style.display = 'block';
        document.getElementById('fpRealImage').style.display = 'flex';
        document.getElementById('fpSvgStandby').style.display = 'none';
    }

    const surface = document.getElementById('blackSurface');
    surface.classList.add('surface-scan-success');

    // Show loading on right side
    document.getElementById('rightSideEmpty').style.display = 'none';
    document.getElementById('rightSideNotFound').style.display = 'none';
    document.getElementById('rightSideSuccess').style.display = 'none';
    document.getElementById('rightSideLoading').style.display = 'block';

    document.getElementById('surfaceMsg').innerHTML = '<span style="color: #38bdf8;"><i class="fa-solid fa-spinner fa-spin"></i> Biometric print detected! Verifying template...</span>';

    // If biometric template is NOT matched (different / unregistered finger)
    if (!isMatched || !empId) {
        setTimeout(() => {
            playFailBeep();
            document.getElementById('rightSideLoading').style.display = 'none';
            document.getElementById('rightSideNotFound').style.display = 'block';
            document.getElementById('surfaceMsg').innerHTML = '<span style="color: #ef4444; font-weight: 700;"><i class="fa-solid fa-circle-xmark"></i> Fingerprint NOT recognized! (Score: ' + matchScore + '%). Attendance NOT marked.</span>';
            
            surface.style.borderColor = '#ef4444';
            surface.style.boxShadow = '0 0 35px rgba(239, 68, 68, 0.7)';
            setTimeout(() => {
                surface.style.borderColor = '#10b981';
                surface.style.boxShadow = '0 0 25px rgba(16, 185, 129, 0.4)';
            }, 1500);

            setTimeout(() => {
                isBusyProcessing = false;
                surface.classList.remove('surface-scan-success');
            }, 1800);
        }, 300);
        return;
    }

    // Fingerprint IS matched to an enrolled employee!
    try {
        const resp = await fetch('api_attendance.php?action=verify&emp_id=' + encodeURIComponent(empId) + '&score=' + encodeURIComponent(matchScore), { cache: 'no-store' });
        const res = await resp.json();

        document.getElementById('rightSideLoading').style.display = 'none';

        if (res.status === 'success' && res.found) {
            playSuccessBeep();

            // Populate Employee Info on Right Side
            const emp = res.employee;
            document.getElementById('resAvatar').innerText = emp.name.charAt(0).toUpperCase();
            document.getElementById('resEmpName').innerText = emp.name;
            document.getElementById('resEmpId').innerText = emp.emp_id;
            document.getElementById('resDept').innerText = emp.department;
            document.getElementById('resDesig').innerText = emp.designation;
            document.getElementById('resLoc').innerText = emp.location + ' (' + emp.company + ')';
            document.getElementById('resPunchTime').innerText = 'Today at ' + res.punch_time + ' (' + res.punch_date + ')';
            document.getElementById('resMatchScore').innerText = 'MATCH: ' + res.quality;

            document.getElementById('rightSideSuccess').style.display = 'block';

            document.getElementById('surfaceMsg').innerHTML = '<span style="color: #10b981; font-weight: 800;"><i class="fa-solid fa-circle-check"></i> ' + emp.name + ' Verified (Score: ' + res.quality + ') & Attendance Logged!</span>';

            // Prepend new row to table
            prependAttendanceRow(emp, res.punch_time, res.punch_date, res.quality);

        } else if (res.status === 'blocked' || res.status === 'inactive') {
            playFailBeep();
            document.getElementById('rightSideSuccess').style.display = 'none';
            document.getElementById('rightSideNotFound').style.display = 'none';
            document.getElementById('rightSideBlocked').style.display = 'block';
            document.getElementById('blockedMsgText').innerText = res.message || 'Attendance is BLOCKED for this employee!';
            document.getElementById('surfaceMsg').innerHTML = '<span style="color: #ef4444; font-weight: 800;"><i class="fa-solid fa-ban"></i> ' + (res.message || 'Attendance is BLOCKED!') + '</span>';
        } else {
            playFailBeep();
            document.getElementById('rightSideBlocked').style.display = 'none';
            document.getElementById('rightSideNotFound').style.display = 'block';
            const errorMsg = res.message || 'Employee not found or unverified in database.';
            document.getElementById('surfaceMsg').innerHTML = '<span style="color: #ef4444; font-weight: 700;"><i class="fa-solid fa-ban"></i> ' + errorMsg + '</span>';
        }
    } catch(err) {
        document.getElementById('rightSideLoading').style.display = 'none';
        document.getElementById('rightSideNotFound').style.display = 'block';
    }

    // Cooldown before allowing next scan
    setTimeout(() => {
        isBusyProcessing = false;
        surface.classList.remove('surface-scan-success');
    }, 2000);
}

// Add row to live table
function prependAttendanceRow(emp, punchTime, punchDate, quality) {
    const tbody = document.getElementById('attendanceTbody');
    const noRow = document.getElementById('noLogsRow');
    if (noRow) noRow.remove();

    const row = document.createElement('tr');
    row.style.animation = 'fadeIn 0.5s ease';
    row.innerHTML = `
        <td><strong style="color: #10b981;">#NEW-PUNCH</strong></td>
        <td><span style="font-weight: 800; color: #38bdf8; font-family: monospace;">${emp.emp_id}</span></td>
        <td><div style="font-weight: 700; color: #f8fafc;">${emp.name}</div></td>
        <td>${emp.department}</td>
        <td>${emp.designation}</td>
        <td>
            <span style="color: #10b981; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-fingerprint"></i> ZKTeco Mini
            </span>
        </td>
        <td>
            <span class="badge" style="background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.4);">
                ${quality}
            </span>
        </td>
        <td style="color: #cbd5e1; font-weight: 600; font-size: 0.88rem;">
            ${punchTime} - ${punchDate}
        </td>
    `;
    tbody.insertBefore(row, tbody.firstChild);
}

// Manual trigger for testing
function manualTriggerScan() {
    if (typeof checkGuestAction === 'function' && !checkGuestAction('Test Biometric Hardware Punch')) return;
    handleFingerprintRecognized();
}

// Keyboard Spacebar trigger
document.addEventListener('keydown', function(e) {
    if (e.code === 'Space' || e.key === ' ') {
        e.preventDefault();
        manualTriggerScan();
    }
});

// Auto-start polling on page load if authenticated
window.addEventListener('DOMContentLoaded', () => {
    <?php if (empty($isGuestMode)): ?>
    startAttendancePolling();
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
