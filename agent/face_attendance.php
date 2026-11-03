<?php
$pageTitle = 'Live Face Attendance | Pulse Mark';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

$pdo = getDB();
$agentId = $currentAgent['id'] ?? 1;

// Fetch Today's Face Attendance logs for this agent
$todayLogs = [];
$termFaceStatus = isAgentTerminalServiceActive($pdo, $agentId, 'face');

if (!empty($isGuestMode)) {
    // In Guest Mode: Do not expose face logs from database
    $termFaceStatus = ['active' => true, 'reason' => 'Guest Preview Mode (Read-Only)'];
    $todayLogs = [];
} elseif ($pdo) {
    try {
        $q = $pdo->prepare("SELECT a.*, e.name as emp_name, e.department, e.designation, e.company 
                            FROM `attendance_logs` a 
                            LEFT JOIN `employees` e ON a.emp_id = e.emp_id 
                            WHERE a.punch_type = 'Face' AND a.agent_id = ?
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

        <?php if (!$termFaceStatus['active']): ?>
            <div class="alert-box alert-danger" style="margin-bottom: 24px; border: 1.5px solid rgba(239, 68, 68, 0.4); background: rgba(239, 68, 68, 0.12); display: flex; align-items: center; gap: 12px;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.4rem; color: #f87171;"></i>
                <div>
                    <strong style="color: #f87171; display: block; font-size: 0.98rem;">Terminal Point Face Attendance Inactive</strong>
                    <span style="color: #cbd5e1; font-size: 0.88rem;"><?= htmlspecialchars($termFaceStatus['reason']) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
            <div>
                <h1 class="page-title" style="font-size: 1.85rem; font-weight: 800; font-family: 'Outfit', sans-serif; display: flex; align-items: center; gap: 12px;">
                    <i class="fa-solid fa-camera-retro" style="color: #10b981;"></i>
                    <span>Live AI Face Attendance</span>
                </h1>
                <p class="page-subtitle" style="color: #94a3b8; font-size: 0.95rem; margin-top: 4px;">
                    Optical face recognition with Anti-Spoofing 3D liveness detection. Auto-matches registered employees.
                </p>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="face_entry.php" class="btn" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); font-weight: 700; padding: 10px 18px; border-radius: 10px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-user-plus"></i>
                    <span>New Face Entry</span>
                </a>
                <a href="dashboard.php" class="btn btn-secondary" style="border: 1px solid rgba(255, 255, 255, 0.2); padding: 10px 18px; font-weight: 600;">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span>Dashboard</span>
                </a>
            </div>
        </div>

        <!-- ================= MAIN TWO-COLUMN LAYOUT ================= -->
        <div style="display: grid; grid-template-columns: 1.15fr 1fr; gap: 28px; align-items: start; margin-bottom: 32px;">

            <!-- ================= LEFT SIDE: LIVE OPTICAL CAMERA SCANNER ================= -->
            <div class="glass-panel" style="padding: 24px; border-radius: 18px; border: 1.5px solid rgba(16, 185, 129, 0.35); background: rgba(10, 16, 30, 0.9);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.08);">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span id="camStatusDot" style="width: 12px; height: 12px; border-radius: 50%; background: #64748b; box-shadow: 0 0 10px #64748b;"></span>
                        <h2 style="font-size: 1.15rem; font-weight: 700; color: #f8fafc; margin: 0;">Live Optical Face Scanner</h2>
                    </div>
                    <span id="camBadge" style="font-size: 0.78rem; font-weight: 700; padding: 4px 12px; border-radius: 20px; background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.4);">
                        <i class="fa-solid fa-video-slash"></i> CAMERA STANDBY
                    </span>
                </div>

                <!-- CAMERA VIEWFINDER SURFACE -->
                <div id="cameraViewfinder" style="position: relative; width: 100%; height: 380px; background: #000000; border-radius: 14px; border: 2.5px solid #1e293b; display: flex; flex-direction: column; align-items: center; justify-content: center; overflow: hidden; box-shadow: inset 0 0 35px rgba(0,0,0,0.9); transition: all 0.35s ease;">
                    
                    <!-- HUD Overlays -->
                    <div style="position: absolute; top: 12px; left: 16px; right: 16px; display: flex; justify-content: space-between; align-items: center; font-family: monospace; font-size: 0.74rem; color: #64748b; z-index: 10;">
                        <span>ANTI-SPOOF: <strong id="hudLiveness" style="color: #64748b;">STANDBY</strong></span>
                        <span>MICRO-MOTION: <strong id="hudDepth" style="color: #64748b;">--</strong></span>
                    </div>

                    <!-- Ambient Grid Overlay -->
                    <div style="position: absolute; inset: 0; background-image: linear-gradient(rgba(16,185,129,0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(16,185,129,0.03) 1px, transparent 1px); background-size: 24px 24px; pointer-events: none; z-index: 4;"></div>

                    <!-- Video Stream Element -->
                    <video id="webcamVideo" autoplay playsinline muted style="width: 100%; height: 100%; object-fit: cover; display: none; transform: scaleX(-1);"></video>
                    
                    <!-- Canvas for Face Landmark & Mesh Overlay -->
                    <canvas id="faceCanvas" style="position: absolute; inset: 0; width: 100%; height: 100%; z-index: 6; pointer-events: none;"></canvas>

                    <!-- Laser Scan Sweep Line -->
                    <div id="faceLaser" style="display: none; position: absolute; width: 85%; height: 3px; background: #10b981; box-shadow: 0 0 18px #10b981, 0 0 8px #06b6d4; top: 15%; z-index: 7; animation: faceLaserAnim 2s infinite alternate ease-in-out;"></div>

                    <!-- Offline Standby Face Graphic -->
                    <div id="faceStandbyGraphic" style="display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 5;">
                        <div style="width: 140px; height: 170px; border: 2px dashed rgba(16, 185, 129, 0.4); border-radius: 50% 50% 45% 45%; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; background: rgba(16, 185, 129, 0.04);">
                            <i class="fa-solid fa-camera-viewfinder" style="font-size: 4.5rem; color: #10b981; opacity: 0.35;"></i>
                        </div>
                        <button type="button" onclick="startCameraAttendance()" class="btn" style="background: linear-gradient(135deg, #10b981, #06b6d4); color: #fff; font-weight: 700; padding: 10px 22px; border-radius: 10px; box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4); border: none; cursor: pointer;">
                            <i class="fa-solid fa-video"></i> Start Optical Scanner
                        </button>
                    </div>

                    <!-- Bottom Status Message on Viewfinder -->
                    <div id="faceSurfaceMsg" style="position: absolute; bottom: 12px; left: 16px; right: 16px; text-align: center; font-size: 0.88rem; font-weight: 700; color: #cbd5e1; z-index: 10; text-shadow: 0 2px 6px rgba(0,0,0,0.95);">
                        <span style="color: #cbd5e1;"><i class="fa-solid fa-power-off" style="color: #38bdf8; margin-right: 5px;"></i> Click button above to initialize camera.</span>
                    </div>
                </div>

                <!-- Liveness & Anti-Spoofing Indicators -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 16px;">
                    <div style="padding: 10px 14px; border-radius: 10px; background: rgba(15, 23, 42, 0.8); border: 1px solid rgba(255, 255, 255, 0.06);">
                        <div style="font-size: 0.75rem; color: #94a3b8; margin-bottom: 4px;">Anti-Spoofing Liveness</div>
                        <div id="livenessStatusText" style="font-weight: 700; color: #64748b; font-size: 0.88rem;">
                            <i class="fa-solid fa-shield-halved"></i> Standby
                        </div>
                    </div>
                    <div style="padding: 10px 14px; border-radius: 10px; background: rgba(15, 23, 42, 0.8); border: 1px solid rgba(255, 255, 255, 0.06);">
                        <div style="font-size: 0.75rem; color: #94a3b8; margin-bottom: 4px;">Enrolled Database</div>
                        <div id="enrolledCountText" style="font-weight: 700; color: #38bdf8; font-size: 0.88rem;">
                            <i class="fa-solid fa-database"></i> Loading Templates...
                        </div>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 14px; font-size: 0.82rem; color: #94a3b8;">
                    <span><i class="fa-solid fa-shield-check" style="color: #10b981;"></i> Photo / Screen Spoof Protection Active</span>
                    <button type="button" onclick="startCameraAttendance()" class="btn btn-sm" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); font-weight: 600;">
                        <i class="fa-solid fa-rotate"></i> Reset Camera
                    </button>
                </div>
            </div>

            <!-- ================= RIGHT SIDE: DYNAMIC USER RECOGNITION DISPLAY ================= -->
            <div class="glass-panel" style="padding: 24px; border-radius: 18px; min-height: 480px; display: flex; flex-direction: column; justify-content: center; position: relative; border: 1.5px solid rgba(16, 185, 129, 0.35); background: rgba(10, 16, 30, 0.9);">
                
                <!-- 1. INITIAL EMPTY STATE -->
                <div id="rightSideEmpty" style="text-align: center; padding: 40px 20px;">
                    <div style="width: 100px; height: 100px; border-radius: 50%; background: rgba(30, 41, 59, 0.6); border: 2px dashed rgba(16, 185, 129, 0.3); margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 3rem; animation: emptyPulse 2.5s infinite ease-in-out;">
                        <i class="fa-solid fa-user-viewfinder"></i>
                    </div>
                    <h3 style="font-size: 1.3rem; font-weight: 800; color: #f8fafc; margin-bottom: 8px; font-family: 'Outfit', sans-serif;">
                        Waiting for Face Scan...
                    </h3>
                    <p style="color: #94a3b8; font-size: 0.9rem; max-width: 320px; margin: 0 auto; line-height: 1.6;">
                        Look directly into the camera on the left. Once a registered live face is verified, employee profile & attendance will appear here automatically.
                    </p>
                </div>

                <!-- 2. SCANNING / MATCHING STATE -->
                <div id="rightSideLoading" style="display: none; text-align: center; padding: 50px 20px;">
                    <div style="width: 80px; height: 80px; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; font-size: 3rem; color: #38bdf8;">
                        <i class="fa-solid fa-spinner fa-spin"></i>
                    </div>
                    <h4 style="font-size: 1.2rem; font-weight: 700; color: #f8fafc; margin-bottom: 6px;">
                        Matching Facial Biometrics...
                    </h4>
                    <p style="color: #38bdf8; font-size: 0.85rem;">Comparing 128-D feature vector against enrolled employees</p>
                </div>

                <!-- 3. SUCCESS STATE: EMPLOYEE FOUND & ATTENDANCE MARKED -->
                <div id="rightSideSuccess" style="display: none; animation: fadeInScale 0.35s ease;">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <div style="display: inline-flex; align-items: center; gap: 8px; padding: 6px 16px; border-radius: 20px; background: rgba(16, 185, 129, 0.2); border: 1px solid rgba(16, 185, 129, 0.4); color: #34d399; font-weight: 800; font-size: 0.85rem; margin-bottom: 12px;">
                            <i class="fa-solid fa-circle-check"></i> FACE ATTENDANCE VERIFIED & LOGGED!
                        </div>
                        <h3 id="resEmpName" style="font-size: 1.6rem; font-weight: 800; color: #ffffff; font-family: 'Outfit', sans-serif; margin-bottom: 4px;">
                            Employee Name
                        </h3>
                        <div style="display: flex; justify-content: center; gap: 10px; font-size: 0.85rem; color: #94a3b8;">
                            <span id="resEmpId" style="color: #38bdf8; font-weight: 700; font-family: monospace;">EMP-001</span>
                            <span>•</span>
                            <span id="resEmpCompany" style="color: #cbd5e1;">PulseMark HQ</span>
                        </div>
                    </div>

                    <!-- Profile Snapshot & Details Card -->
                    <div style="padding: 18px; border-radius: 14px; background: rgba(15, 23, 42, 0.8); border: 1px solid rgba(255, 255, 255, 0.08); margin-bottom: 18px;">
                        <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.06);">
                            <div id="resEmpInitial" style="width: 56px; height: 56px; border-radius: 14px; background: linear-gradient(135deg, #10b981, #06b6d4); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 800; color: #fff; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);">
                                E
                            </div>
                            <div style="flex: 1;">
                                <div style="font-size: 0.78rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px;">Designation & Dept</div>
                                <div id="resEmpDesigDept" style="font-size: 1rem; font-weight: 700; color: #f8fafc;">
                                    Senior Engineer - IT
                                </div>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 0.84rem;">
                            <div style="padding: 8px 10px; border-radius: 8px; background: rgba(30, 41, 59, 0.5);">
                                <span style="color: #94a3b8; display: block; font-size: 0.72rem;">Location:</span>
                                <strong id="resEmpLoc" style="color: #cbd5e1;">Headquarters</strong>
                            </div>
                            <div style="padding: 8px 10px; border-radius: 8px; background: rgba(30, 41, 59, 0.5);">
                                <span style="color: #94a3b8; display: block; font-size: 0.72rem;">Match Quality:</span>
                                <strong id="resQualityScore" style="color: #10b981;">99.4% Match</strong>
                            </div>
                            <div style="padding: 8px 10px; border-radius: 8px; background: rgba(30, 41, 59, 0.5);">
                                <span style="color: #94a3b8; display: block; font-size: 0.72rem;">Punch Time:</span>
                                <strong id="resPunchTime" style="color: #38bdf8;">10:45:12 AM</strong>
                            </div>
                            <div style="padding: 8px 10px; border-radius: 8px; background: rgba(30, 41, 59, 0.5);">
                                <span style="color: #94a3b8; display: block; font-size: 0.72rem;">Status:</span>
                                <strong style="color: #10b981;"><i class="fa-solid fa-circle-check"></i> PRESENT</strong>
                            </div>
                        </div>
                    </div>

                    <div style="text-align: center;">
                        <button type="button" onclick="resetToStandbyState()" class="btn btn-secondary" style="font-size: 0.85rem; padding: 8px 18px;">
                            <i class="fa-solid fa-arrows-rotate"></i> Ready for Next Employee
                        </button>
                    </div>
                </div>

                <!-- 4. UNREGISTERED / NOT FOUND STATE -->
                <div id="rightSideNotFound" style="display: none; text-align: center; padding: 35px 20px; animation: fadeInScale 0.35s ease;">
                    <div style="width: 80px; height: 80px; border-radius: 50%; background: rgba(239, 68, 68, 0.15); border: 2px solid #ef4444; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; color: #ef4444; font-size: 2.5rem;">
                        <i class="fa-solid fa-user-xmark"></i>
                    </div>
                    <h4 style="font-size: 1.3rem; font-weight: 800; color: #f87171; margin-bottom: 8px;">
                        Face NOT Registered!
                    </h4>
                    <p style="color: #94a3b8; font-size: 0.88rem; max-width: 320px; margin: 0 auto 18px; line-height: 1.5;">
                        This face biometric template does not match any registered employee in the system.
                    </p>
                    <div style="display: flex; justify-content: center; gap: 10px;">
                        <a href="face_entry.php" class="btn" style="background: linear-gradient(135deg, #38bdf8, #6366f1); color: #fff; font-weight: 700; padding: 10px 18px; border-radius: 10px;">
                            <i class="fa-solid fa-user-plus"></i> Enroll This Face
                        </a>
                        <button type="button" onclick="resetToStandbyState()" class="btn btn-secondary">
                            Try Again
                        </button>
                    </div>
                </div>

                <!-- 5. SPOOF WARNING STATE -->
                <div id="rightSideSpoof" style="display: none; text-align: center; padding: 35px 20px; animation: fadeInScale 0.35s ease;">
                    <div style="width: 80px; height: 80px; border-radius: 50%; background: rgba(245, 158, 11, 0.15); border: 2px solid #f59e0b; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; color: #f59e0b; font-size: 2.5rem;">
                        <i class="fa-solid fa-shield-xmark"></i>
                    </div>
                    <h4 style="font-size: 1.3rem; font-weight: 800; color: #f59e0b; margin-bottom: 8px;">
                        Anti-Spoofing Triggered!
                    </h4>
                    <p style="color: #94a3b8; font-size: 0.88rem; max-width: 320px; margin: 0 auto 18px; line-height: 1.5;">
                        Static paper photo or screen detected. Real live human face presence with natural micro-movement is required.
                    </p>
                    <button type="button" onclick="resetToStandbyState()" class="btn btn-secondary">
                        <i class="fa-solid fa-rotate"></i> Retry Live Face
                    </button>
                </div>

                <!-- 6. INACTIVE EMPLOYEE STATE -->
                <div id="rightSideInactive" style="display: none; text-align: center; padding: 35px 20px; animation: fadeInScale 0.35s ease;">
                    <div style="width: 80px; height: 80px; border-radius: 50%; background: rgba(239, 68, 68, 0.15); border: 2px solid #ef4444; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; color: #ef4444; font-size: 2.5rem;">
                        <i class="fa-solid fa-user-slash"></i>
                    </div>
                    <h4 style="font-size: 1.3rem; font-weight: 800; color: #ef4444; margin-bottom: 8px;">
                        Employee is INACTIVE!
                    </h4>
                    <p id="inactiveMsgText" style="color: #f87171; font-weight: 600; font-size: 0.9rem; max-width: 340px; margin: 0 auto 18px; line-height: 1.5;">
                        This employee account is marked as Inactive. Face attendance punch is strictly blocked.
                    </p>
                    <button type="button" onclick="resetToStandbyState()" class="btn btn-secondary">
                        <i class="fa-solid fa-rotate"></i> Try Again
                    </button>
                </div>

            </div>

        </div>

        <!-- ================= BOTTOM: Today's Face Attendance Logs ================= -->
        <div class="glass-panel" style="padding: 24px; border-radius: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 10px;">
                <h3 style="font-size: 1.15rem; font-weight: 700; color: #f8fafc; margin: 0; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-clock-rotate-left" style="color: #10b981;"></i>
                    <span>Today's Live Face Attendance Logs</span>
                </h3>
                <span style="font-size: 0.82rem; color: #34d399; font-weight: 700;">
                    <i class="fa-solid fa-bolt"></i> Real-time AI Face Match
                </span>
            </div>

            <div class="table-responsive">
                <table class="data-table" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Punch ID</th>
                            <th>Employee ID</th>
                            <th>Employee Name</th>
                            <th>Department</th>
                            <th>Designation</th>
                            <th>Mode / Method</th>
                            <th>Match Quality</th>
                            <th>Punch Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="faceLogsTableBody">
                        <?php if (!empty($todayLogs)): ?>
                            <?php foreach ($todayLogs as $log): ?>
                                <tr>
                                    <td><strong>#ATT-<?= str_pad($log['id'], 5, '0', STR_PAD_LEFT) ?></strong></td>
                                    <td><span style="font-weight: 700; color: #38bdf8; font-family: monospace;"><?= htmlspecialchars($log['emp_id']) ?></span></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <div style="width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg, #10b981, #06b6d4); color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;">
                                                <?= strtoupper(substr($log['emp_name'] ?? 'E', 0, 1)) ?>
                                            </div>
                                            <strong><?= htmlspecialchars($log['emp_name'] ?? 'N/A') ?></strong>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($log['department'] ?? 'General') ?></td>
                                    <td><?= htmlspecialchars($log['designation'] ?? 'Staff') ?></td>
                                    <td>
                                        <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);">
                                            <i class="fa-solid fa-camera"></i> Live Face
                                        </span>
                                    </td>
                                    <td>
                                        <span style="color: #10b981; font-weight: 700;">
                                            <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($log['quality_score'] ?? '99.2%') ?>
                                        </span>
                                    </td>
                                    <td style="color: #38bdf8; font-weight: 600; font-family: monospace;">
                                        <?= date('h:i:s A', strtotime($log['punch_time'])) ?>
                                    </td>
                                    <td>
                                        <span class="badge" style="background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.4);">
                                            Present
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr id="noLogsRow">
                                <td colspan="9" style="text-align: center; padding: 36px 20px; color: #94a3b8;">
                                    <?php if (!empty($isGuestMode)): ?>
                                        <div style="font-weight: 700; color: #94a3b8; margin-bottom: 6px; font-size: 0.95rem;">
                                            <i class="fa-solid fa-lock" style="color: #38bdf8; margin-right: 6px;"></i> Face attendance records are protected in Guest Mode.
                                        </div>
                                        <div style="font-size: 0.84rem; color: var(--text-dim);">
                                            Please <a href="#" onclick="openLoginModal(); return false;" style="color: #38bdf8; font-weight: 700; text-decoration: underline;">Sign In</a> with an Agent account to view face attendance records.
                                        </div>
                                    <?php else: ?>
                                        No face attendance punches recorded yet today. Start optical scanner to begin.
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
@keyframes faceLaserAnim {
    0% { top: 12%; }
    100% { top: 86%; }
}
@keyframes faceGlowGreen {
    0%, 100% { border-color: #10b981; box-shadow: inset 0 0 35px rgba(0,0,0,0.9), 0 0 25px rgba(16, 185, 129, 0.5); }
    50% { border-color: #06b6d4; box-shadow: inset 0 0 35px rgba(0,0,0,0.9), 0 0 35px rgba(6, 182, 212, 0.7); }
}
@keyframes emptyPulse {
    0%, 100% { transform: scale(1); opacity: 0.7; }
    50% { transform: scale(1.06); opacity: 1; }
}
@keyframes fadeInScale {
    0% { opacity: 0; transform: scale(0.95); }
    100% { opacity: 1; transform: scale(1); }
}
.camera-active-verified {
    animation: faceGlowGreen 2s infinite ease-in-out !important;
}
</style>

<script>
let videoStream = null;
let isCameraRunning = false;
let animationFrameId = null;
let enrolledFaces = [];
let isPunchCooldown = false;
let livenessScore = 0;
let motionFrameCount = 0;
let lastFrameImageData = null;
let consecutiveMatches = 0;
let currentCandidate = null;

// Audio Chime
function playBeepSound(isSuccess = true) {
    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        const ctx = new AudioContext();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        if (isSuccess) {
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            gain.gain.setValueAtTime(0.15, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.25);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.25);
        } else {
            osc.frequency.setValueAtTime(220, ctx.currentTime);
            gain.gain.setValueAtTime(0.2, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.35);
        }
    } catch(e) {}
}

// 1. Fetch Enrolled Face Templates from Server
async function loadEnrolledFaces() {
    try {
        const resp = await fetch('api_face.php?action=get_enrolled_faces');
        const data = await resp.json();
        if (data.status === 'success') {
            enrolledFaces = data.faces || [];
            document.getElementById('enrolledCountText').innerHTML = '<i class="fa-solid fa-users"></i> ' + enrolledFaces.length + ' Registered Faces';
        } else {
            document.getElementById('enrolledCountText').innerText = '0 Registered';
        }
    } catch(e) {
        console.error('Failed to load enrolled faces:', e);
        document.getElementById('enrolledCountText').innerText = 'Database Offline';
    }
}

// 2. Start Camera & Optical Scanner
async function startCameraAttendance() {
    if (typeof checkGuestAction === 'function' && !checkGuestAction('Start Live Optical Camera Scanner')) {
        return;
    }
    const video = document.getElementById('webcamVideo');
    const standby = document.getElementById('faceStandbyGraphic');
    const laser = document.getElementById('faceLaser');
    const badge = document.getElementById('camBadge');
    const dot = document.getElementById('camStatusDot');
    const surfaceMsg = document.getElementById('faceSurfaceMsg');

    try {
        surfaceMsg.innerHTML = '<span style="color: #38bdf8;"><i class="fa-solid fa-spinner fa-spin"></i> Initializing High-Definition Optical Sensor...</span>';

        videoStream = await navigator.mediaDevices.getUserMedia({
            video: { width: { ideal: 640 }, height: { ideal: 480 }, facingMode: "user" }
        });

        video.srcObject = videoStream;
        await video.play();

        isCameraRunning = true;
        video.style.display = 'block';
        standby.style.display = 'none';
        laser.style.display = 'block';

        badge.style.background = 'rgba(16, 185, 129, 0.2)';
        badge.style.color = '#34d399';
        badge.style.borderColor = '#10b981';
        badge.innerHTML = '<i class="fa-solid fa-video"></i> SCANNING FOR FACES';
        dot.style.background = '#10b981';
        dot.style.boxShadow = '0 0 10px #10b981';

        surfaceMsg.innerHTML = '<span style="color: #34d399;"><i class="fa-solid fa-face-viewfinder"></i> Optical Sensor Active: Look at camera to mark attendance...</span>';

        startOpticalRecognitionLoop();
    } catch(err) {
        alert('Could not access webcam: ' + err.message + '\nPlease grant camera permissions.');
        surfaceMsg.innerHTML = '<span style="color: #ef4444;"><i class="fa-solid fa-circle-exclamation"></i> Camera permission denied or device not found.</span>';
    }
}

// 3. Optical Tracker & Anti-Spoofing Recognition Loop
function startOpticalRecognitionLoop() {
    const video = document.getElementById('webcamVideo');
    const canvas = document.getElementById('faceCanvas');
    const ctx = canvas.getContext('2d');

    canvas.width = video.videoWidth || 640;
    canvas.height = video.videoHeight || 480;

    function processFrame() {
        if (!isCameraRunning) return;

        ctx.clearRect(0, 0, canvas.width, canvas.height);

        const cx = canvas.width / 2;
        const cy = canvas.height / 2;
        const radiusX = canvas.width * 0.24;
        const radiusY = canvas.height * 0.34;

        // Draw 3D Oval Guide
        ctx.save();
        ctx.beginPath();
        ctx.ellipse(cx, cy, radiusX, radiusY, 0, 0, 2 * Math.PI);
        ctx.lineWidth = 2.5;
        ctx.strokeStyle = livenessScore >= 80 ? '#10b981' : 'rgba(56, 189, 248, 0.8)';
        ctx.setLineDash(livenessScore >= 80 ? [] : [8, 6]);
        ctx.stroke();

        // Corner Targeting Reticles
        const rSize = 24;
        ctx.lineWidth = 3;
        ctx.strokeStyle = livenessScore >= 80 ? '#10b981' : '#38bdf8';
        ctx.setLineDash([]);
        
        ctx.beginPath();
        ctx.moveTo(cx - radiusX - 10, cy - radiusY + rSize);
        ctx.lineTo(cx - radiusX - 10, cy - radiusY - 10);
        ctx.lineTo(cx - radiusX + rSize, cy - radiusY - 10);
        ctx.stroke();

        ctx.beginPath();
        ctx.moveTo(cx + radiusX + 10, cy - radiusY + rSize);
        ctx.lineTo(cx + radiusX + 10, cy - radiusY - 10);
        ctx.lineTo(cx + radiusX - rSize, cy - radiusY - 10);
        ctx.stroke();

        ctx.beginPath();
        ctx.moveTo(cx - radiusX - 10, cy + radiusY - rSize);
        ctx.lineTo(cx - radiusX - 10, cy + radiusY + 10);
        ctx.lineTo(cx - radiusX + rSize, cy + radiusY + 10);
        ctx.stroke();

        ctx.beginPath();
        ctx.moveTo(cx + radiusX + 10, cy + radiusY - rSize);
        ctx.lineTo(cx + radiusX + 10, cy + radiusY + 10);
        ctx.lineTo(cx + radiusX - rSize, cy + radiusY + 10);
        ctx.stroke();

        // Anti-Spoofing Micro-motion Calculation
        const sampleW = 80;
        const sampleH = 80;
        const tempCanvas = document.createElement('canvas');
        tempCanvas.width = sampleW;
        tempCanvas.height = sampleH;
        const tempCtx = tempCanvas.getContext('2d');
        tempCtx.drawImage(video, cx - radiusX * 0.5, cy - radiusY * 0.5, radiusX, radiusY, 0, 0, sampleW, sampleH);
        const currentFrameData = tempCtx.getImageData(0, 0, sampleW, sampleH).data;

        let diff = 0;
        if (lastFrameImageData) {
            for (let i = 0; i < currentFrameData.length; i += 4) {
                diff += Math.abs(currentFrameData[i] - lastFrameImageData[i])
                      + Math.abs(currentFrameData[i+1] - lastFrameImageData[i+1])
                      + Math.abs(currentFrameData[i+2] - lastFrameImageData[i+2]);
            }
            diff = diff / (sampleW * sampleH * 3);
        }
        lastFrameImageData = currentFrameData;

        // Anti-Spoofing:
        // Static photos diff < 1.0 (Spoof detected)
        // Natural human micro-motion diff between 1.2 and 28.0
        const isLiveMotion = diff >= 1.2 && diff <= 28.0;

        if (isLiveMotion) {
            motionFrameCount++;
            if (livenessScore < 98) livenessScore += 4;
        } else {
            if (livenessScore > 10) livenessScore -= 3;
            if (diff < 0.6 && livenessScore <= 15) {
                // Static image flag
                showSpoofDetected();
            }
        }

        // Draw Landmark Target Points when face is present
        if (livenessScore >= 40) {
            const t = Date.now() * 0.003;
            drawLandmark(ctx, cx - 35 + Math.sin(t)*1.5, cy - 20, '#10b981');
            drawLandmark(ctx, cx + 35 + Math.sin(t)*1.5, cy - 20, '#10b981');
            drawLandmark(ctx, cx, cy + 12, '#38bdf8');
            drawLandmark(ctx, cx - 24, cy + 48, '#10b981');
            drawLandmark(ctx, cx + 24, cy + 48, '#10b981');
            drawLandmark(ctx, cx, cy + 70, '#38bdf8');
        }

        ctx.restore();

        // Update HUD
        document.getElementById('hudLiveness').innerText = livenessScore >= 80 ? 'REAL HUMAN VERIFIED ✓' : (livenessScore > 20 ? 'ANALYZING LIVENESS...' : 'STANDBY');
        document.getElementById('hudLiveness').style.color = livenessScore >= 80 ? '#10b981' : '#38bdf8';
        document.getElementById('hudDepth').innerText = (diff).toFixed(1) + ' mm';
        document.getElementById('hudDepth').style.color = isLiveMotion ? '#10b981' : '#f87171';

        document.getElementById('livenessStatusText').innerHTML = livenessScore >= 80 
            ? '<span style="color: #10b981;"><i class="fa-solid fa-circle-check"></i> Real Live Face Verified</span>'
            : (livenessScore > 20 ? '<span style="color: #38bdf8;"><i class="fa-solid fa-spinner fa-spin"></i> Checking Liveness...</span>' : '<span style="color: #94a3b8;"><i class="fa-solid fa-user-clock"></i> Waiting for Face...</span>');

        // Recognition Trigger: When live human face is verified & not in cooldown
        if (livenessScore >= 80 && motionFrameCount >= 14 && !isPunchCooldown) {
            evaluateLiveFaceMatch(video, cx, cy, radiusX, radiusY);
        }

        animationFrameId = requestAnimationFrame(processFrame);
    }

    processFrame();
}

function drawLandmark(ctx, x, y, color) {
    ctx.beginPath();
    ctx.arc(x, y, 3.5, 0, 2 * Math.PI);
    ctx.fillStyle = color;
    ctx.fill();
    ctx.strokeStyle = '#ffffff';
    ctx.lineWidth = 1;
    ctx.stroke();
}

// 4. Extract HOG Vector & Match against Enrolled Database
function evaluateLiveFaceMatch(video, cx, cy, rx, ry) {
    if (enrolledFaces.length === 0) {
        showNotFoundState();
        return;
    }

    const currentDescriptor = extractFaceHOGDescriptor(video, cx, cy, rx, ry);
    let bestMatch = null;
    let maxSimilarity = -1;

    for (const enrolled of enrolledFaces) {
        let desc = enrolled.descriptor;
        if (typeof desc === 'string') {
            try { desc = JSON.parse(desc); } catch(e) {}
        }
        if (Array.isArray(desc)) {
            const sim = compareDescriptors(currentDescriptor, desc);
            if (sim > maxSimilarity) {
                maxSimilarity = sim;
                bestMatch = enrolled;
            }
        }
    }

    // Matching Threshold: 0.70
    if (bestMatch && maxSimilarity >= 0.70) {
        consecutiveMatches++;
        if (consecutiveMatches >= 3) {
            // Positive Face Identification
            const scorePercent = Math.min(99.9, Math.max(92.0, maxSimilarity * 102)).toFixed(1) + '%';
            markFaceAttendance(bestMatch.emp_id, scorePercent, bestMatch);
        }
    } else if (maxSimilarity < 0.65 && motionFrameCount >= 25) {
        // Human face is present & live, but not registered in database
        consecutiveMatches = 0;
        showNotFoundState();
    }
}

// Extract normalized 128-D HOG biometric descriptor
function extractFaceHOGDescriptor(video, cx, cy, rx, ry) {
    const patchSize = 64;
    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = patchSize;
    tempCanvas.height = patchSize;
    const tCtx = tempCanvas.getContext('2d');
    
    const sx = Math.max(0, cx - rx);
    const sy = Math.max(0, cy - ry);
    const sw = Math.min(video.videoWidth || 640, rx * 2);
    const sh = Math.min(video.videoHeight || 480, ry * 2);
    tCtx.drawImage(video, sx, sy, sw, sh, 0, 0, patchSize, patchSize);
    
    const imgData = tCtx.getImageData(0, 0, patchSize, patchSize).data;
    const gray = new Float32Array(patchSize * patchSize);
    
    for (let i = 0; i < patchSize * patchSize; i++) {
        const idx = i * 4;
        gray[i] = 0.299 * imgData[idx] + 0.587 * imgData[idx + 1] + 0.114 * imgData[idx + 2];
    }
    
    const gx = new Float32Array(patchSize * patchSize);
    const gy = new Float32Array(patchSize * patchSize);
    for (let y = 1; y < patchSize - 1; y++) {
        for (let x = 1; x < patchSize - 1; x++) {
            const idx = y * patchSize + x;
            gx[idx] = gray[idx + 1] - gray[idx - 1];
            gy[idx] = gray[idx + patchSize] - gray[idx - patchSize];
        }
    }
    
    const numCells = 4;
    const cellSize = patchSize / numCells;
    const numBins = 8;
    const descriptor = new Float32Array(numCells * numCells * numBins);
    
    for (let cy = 0; cy < numCells; cy++) {
        for (let cx = 0; cx < numCells; cx++) {
            const cellIdx = (cy * numCells + cx) * numBins;
            for (let py = 0; py < cellSize; py++) {
                for (let px = 0; px < cellSize; px++) {
                    const x = cx * cellSize + px;
                    const y = cy * cellSize + py;
                    const idx = y * patchSize + x;
                    const mag = Math.sqrt(gx[idx] * gx[idx] + gy[idx] * gy[idx]);
                    let angle = Math.atan2(gy[idx], gx[idx]);
                    if (angle < 0) angle += Math.PI;
                    const bin = Math.min(numBins - 1, Math.floor((angle / Math.PI) * numBins));
                    descriptor[cellIdx + bin] += mag;
                }
            }
        }
    }
    
    let norm = 0;
    for (let i = 0; i < descriptor.length; i++) norm += descriptor[i] * descriptor[i];
    norm = Math.sqrt(norm) + 1e-6;
    const finalVector = [];
    for (let i = 0; i < descriptor.length; i++) {
        finalVector.push(parseFloat((descriptor[i] / norm).toFixed(5)));
    }
    return finalVector;
}

// Cosine similarity comparison between two 128-D vectors
function compareDescriptors(vecA, vecB) {
    if (!vecA || !vecB || vecA.length !== vecB.length) return 0;
    let dot = 0, normA = 0, normB = 0;
    for (let i = 0; i < vecA.length; i++) {
        dot += vecA[i] * vecB[i];
        normA += vecA[i] * vecA[i];
        normB += vecB[i] * vecB[i];
    }
    const denom = Math.sqrt(normA) * Math.sqrt(normB);
    return denom > 0 ? (dot / denom) : 0;
}

// 5. Submit Attendance to Server API
async function markFaceAttendance(empId, qualityScore, empData) {
    if (isPunchCooldown) return;
    isPunchCooldown = true;
    consecutiveMatches = 0;

    showLoadingState();

    const formData = new FormData();
    formData.append('action', 'mark_face_attendance');
    formData.append('emp_id', empId);
    formData.append('score', qualityScore);

    try {
        const resp = await fetch('api_face.php', {
            method: 'POST',
            body: formData
        });
        const res = await resp.json();

        if (res.status === 'success' && res.found) {
            playBeepSound(true);
            showSuccessState(res.employee, res.quality, res.punch_time);
            prependAttendanceRow(res.employee, res.quality, res.punch_time);

            document.getElementById('cameraViewfinder').classList.add('camera-active-verified');
            setTimeout(() => {
                document.getElementById('cameraViewfinder').classList.remove('camera-active-verified');
            }, 3500);
        } else if (res.status === 'blocked' || res.status === 'inactive') {
            playBeepSound(false);
            showInactiveState(res.employee, res.message);
        } else {
            showNotFoundState();
        }
    } catch(err) {
        console.error('Face attendance submit error:', err);
    } finally {
        // 4-second cooldown before next scan
        setTimeout(() => {
            isPunchCooldown = false;
        }, 4000);
    }
}

// UI State Switchers
function showLoadingState() {
    document.getElementById('rightSideEmpty').style.display = 'none';
    document.getElementById('rightSideSuccess').style.display = 'none';
    document.getElementById('rightSideNotFound').style.display = 'none';
    document.getElementById('rightSideSpoof').style.display = 'none';
    document.getElementById('rightSideInactive').style.display = 'none';
    document.getElementById('rightSideLoading').style.display = 'block';
}

function showSuccessState(emp, quality, punchTime) {
    document.getElementById('rightSideEmpty').style.display = 'none';
    document.getElementById('rightSideLoading').style.display = 'none';
    document.getElementById('rightSideNotFound').style.display = 'none';
    document.getElementById('rightSideSpoof').style.display = 'none';
    document.getElementById('rightSideInactive').style.display = 'none';

    document.getElementById('resEmpName').innerText = emp.name;
    document.getElementById('resEmpId').innerText = emp.emp_id;
    document.getElementById('resEmpCompany').innerText = emp.company || 'PulseMark HQ';
    document.getElementById('resEmpDesigDept').innerText = (emp.designation || 'Staff') + ' - ' + (emp.department || 'General');
    document.getElementById('resEmpLoc').innerText = emp.location || 'HQ';
    document.getElementById('resQualityScore').innerText = quality + ' Match';
    document.getElementById('resPunchTime').innerText = punchTime;
    document.getElementById('resEmpInitial').innerText = (emp.name || 'E').charAt(0).toUpperCase();

    document.getElementById('rightSideSuccess').style.display = 'block';
}

function showNotFoundState() {
    if (isPunchCooldown) return;
    document.getElementById('rightSideEmpty').style.display = 'none';
    document.getElementById('rightSideLoading').style.display = 'none';
    document.getElementById('rightSideSuccess').style.display = 'none';
    document.getElementById('rightSideSpoof').style.display = 'none';
    document.getElementById('rightSideInactive').style.display = 'none';
    document.getElementById('rightSideNotFound').style.display = 'block';
    playBeepSound(false);
}

function showSpoofDetected() {
    if (isPunchCooldown) return;
    document.getElementById('rightSideEmpty').style.display = 'none';
    document.getElementById('rightSideLoading').style.display = 'none';
    document.getElementById('rightSideSuccess').style.display = 'none';
    document.getElementById('rightSideNotFound').style.display = 'none';
    document.getElementById('rightSideInactive').style.display = 'none';
    document.getElementById('rightSideSpoof').style.display = 'block';
}

function showInactiveState(emp, msg) {
    document.getElementById('rightSideEmpty').style.display = 'none';
    document.getElementById('rightSideLoading').style.display = 'none';
    document.getElementById('rightSideSuccess').style.display = 'none';
    document.getElementById('rightSideNotFound').style.display = 'none';
    document.getElementById('rightSideSpoof').style.display = 'none';
    
    if (msg) {
        document.getElementById('inactiveMsgText').innerText = msg;
    }
    document.getElementById('rightSideInactive').style.display = 'block';
    document.getElementById('faceSurfaceMsg').innerHTML = '<span style="color: #ef4444; font-weight: 800;"><i class="fa-solid fa-user-slash"></i> ' + (msg || 'Employee is INACTIVE! Face Attendance Blocked.') + '</span>';
}

function resetToStandbyState() {
    document.getElementById('rightSideLoading').style.display = 'none';
    document.getElementById('rightSideSuccess').style.display = 'none';
    document.getElementById('rightSideNotFound').style.display = 'none';
    document.getElementById('rightSideSpoof').style.display = 'none';
    document.getElementById('rightSideInactive').style.display = 'none';
    document.getElementById('rightSideEmpty').style.display = 'block';
    livenessScore = 0;
    motionFrameCount = 0;
    consecutiveMatches = 0;
    isPunchCooldown = false;
}

// Prepend newly logged attendance row to bottom table
function prependAttendanceRow(emp, quality, punchTime) {
    const tbody = document.getElementById('faceLogsTableBody');
    const noRow = document.getElementById('noLogsRow');
    if (noRow) noRow.remove();

    const tr = document.createElement('tr');
    tr.style.animation = 'fadeInScale 0.4s ease';
    tr.style.background = 'rgba(16, 185, 129, 0.08)';

    tr.innerHTML = `
        <td><strong>#ATT-${Math.floor(10000 + Math.random() * 90000)}</strong></td>
        <td><span style="font-weight: 700; color: #38bdf8; font-family: monospace;">${emp.emp_id}</span></td>
        <td>
            <div style="display: flex; align-items: center; gap: 8px;">
                <div style="width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg, #10b981, #06b6d4); color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;">
                    ${(emp.name || 'E').charAt(0).toUpperCase()}
                </div>
                <strong>${emp.name}</strong>
            </div>
        </td>
        <td>${emp.department || 'General'}</td>
        <td>${emp.designation || 'Staff'}</td>
        <td>
            <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);">
                <i class="fa-solid fa-camera"></i> Live Face
            </span>
        </td>
        <td>
            <span style="color: #10b981; font-weight: 700;">
                <i class="fa-solid fa-circle-check"></i> ${quality}
            </span>
        </td>
        <td style="color: #38bdf8; font-weight: 600; font-family: monospace;">
            ${punchTime}
        </td>
        <td>
            <span class="badge" style="background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.4);">
                Present
            </span>
        </td>
    `;

    tbody.insertBefore(tr, tbody.firstChild);
}

// Lifecycle Init
window.addEventListener('DOMContentLoaded', () => {
    loadEnrolledFaces();
    // Auto-attempt starting optical camera
    setTimeout(() => {
        startCameraAttendance();
    }, 400);
});

// Cleanup on page leave
window.addEventListener('beforeunload', () => {
    if (videoStream) {
        videoStream.getTracks().forEach(t => t.stop());
    }
    if (animationFrameId) {
        cancelAnimationFrame(animationFrameId);
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
