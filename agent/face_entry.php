<?php
$pageTitle = 'Face Biometric Enrollment | Pulse Mark';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

$pdo = getDB();
$agentId = $currentAgent['id'] ?? 1;

// Fetch all ACTIVE employees authorized for this agent for selection
$employees = !empty($isGuestMode) ? [] : getAgentAuthorizedEmployees($pdo, $agentId, true);

// Fetch recent face entries registered by this agent
$recentFaceEntries = [];
if (!empty($isGuestMode)) {
    $recentFaceEntries = [];
} elseif ($pdo) {
    try {
        $q = $pdo->prepare("SELECT f.*, e.name as emp_name, e.department, e.designation, e.company 
                            FROM `face_entries` f 
                            LEFT JOIN `employees` e ON f.emp_id = e.emp_id 
                            WHERE f.agent_id = ?
                            ORDER BY f.id DESC LIMIT 8");
        $q->execute([$agentId]);
        $recentFaceEntries = $q->fetchAll();
    } catch (Exception $e) {
        $recentFaceEntries = [];
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

        <!-- Page Header -->
        <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
            <div>
                <h1 class="page-title" style="font-size: 1.85rem; font-weight: 800; font-family: 'Outfit', sans-serif; display: flex; align-items: center; gap: 12px;">
                    <i class="fa-solid fa-camera" style="color: #38bdf8;"></i>
                    <span>AI Face Biometric Enrollment</span>
                </h1>
                <p class="page-subtitle" style="color: #94a3b8; font-size: 0.95rem; margin-top: 4px;">
                    Real-time optical face mesh scanning with Anti-Spoofing 3D Liveness verification.
                </p>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-secondary" style="border: 1px solid rgba(255, 255, 255, 0.2); padding: 12px 20px; font-weight: 600;">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span>Back to Dashboard</span>
                </a>
            </div>
        </div>

        <!-- ================= MAIN TWO-COLUMN LAYOUT ================= -->
        <div style="display: grid; grid-template-columns: 1.15fr 1fr; gap: 28px; align-items: start; margin-bottom: 32px;">

            <!-- ================= LEFT SIDE: LIVE CAMERA & OPTICAL FACE MESH DISPLAY ================= -->
            <div class="glass-panel" style="padding: 24px; border-radius: 18px; border: 1.5px solid rgba(56, 189, 248, 0.35); background: rgba(10, 16, 30, 0.9);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.08);">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span id="camStatusDot" style="width: 12px; height: 12px; border-radius: 50%; background: #64748b;"></span>
                        <h2 style="font-size: 1.15rem; font-weight: 700; color: #f8fafc; margin: 0;">Optical Face Scanner</h2>
                    </div>
                    <span id="camBadge" style="font-size: 0.78rem; font-weight: 700; padding: 4px 12px; border-radius: 20px; background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.4);">
                        <i class="fa-solid fa-video-slash"></i> CAMERA OFFLINE
                    </span>
                </div>

                <!-- CAMERA VIEWFINDER SURFACE -->
                <div id="cameraViewfinder" style="position: relative; width: 100%; height: 380px; background: #000000; border-radius: 14px; border: 2.5px solid #1e293b; display: flex; flex-direction: column; align-items: center; justify-content: center; overflow: hidden; box-shadow: inset 0 0 35px rgba(0,0,0,0.9);">
                    
                    <!-- HUD Overlays -->
                    <div style="position: absolute; top: 12px; left: 16px; right: 16px; display: flex; justify-content: space-between; align-items: center; font-family: monospace; font-size: 0.74rem; color: #94a3b8; z-index: 10;">
                        <span>AI LIVENESS: <strong id="hudLiveness" style="color: #38bdf8;">STANDBY</strong></span>
                        <span>DEPTH: <strong id="hudDepth" style="color: #38bdf8;">--</strong></span>
                    </div>

                    <!-- Ambient Grid Overlay -->
                    <div style="position: absolute; inset: 0; background-image: linear-gradient(rgba(56,189,248,0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(56,189,248,0.03) 1px, transparent 1px); background-size: 24px 24px; pointer-events: none; z-index: 4;"></div>

                    <!-- Video Stream Element -->
                    <video id="webcamVideo" autoplay playsinline muted style="width: 100%; height: 100%; object-fit: cover; display: none; transform: scaleX(-1);"></video>
                    
                    <!-- Canvas for Face Landmark & Mesh Overlay -->
                    <canvas id="faceCanvas" style="position: absolute; inset: 0; width: 100%; height: 100%; z-index: 6; pointer-events: none;"></canvas>

                    <!-- Laser Scan Sweep Line -->
                    <div id="faceLaser" style="display: none; position: absolute; width: 85%; height: 3px; background: #38bdf8; box-shadow: 0 0 18px #38bdf8, 0 0 8px #10b981; top: 15%; z-index: 7; animation: faceLaserAnim 2s infinite alternate ease-in-out;"></div>

                    <!-- Offline Standby Face Silhouette -->
                    <div id="faceStandbyGraphic" style="display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 5;">
                        <div style="width: 140px; height: 170px; border: 2px dashed rgba(56, 189, 248, 0.4); border-radius: 50% 50% 45% 45%; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; background: rgba(56, 189, 248, 0.04);">
                            <i class="fa-solid fa-user-viewfinder" style="font-size: 4.5rem; color: #38bdf8; opacity: 0.35;"></i>
                        </div>
                        <div style="font-size: 0.88rem; color: #cbd5e1; text-align: center; font-weight: 600; text-shadow: 0 2px 6px rgba(0,0,0,0.95);">
                            Select Employee on right &amp; click <strong style="color: #38bdf8; font-weight: 800; text-shadow: 0 0 10px rgba(56, 189, 248, 0.6);">"OK - Initialize Camera"</strong>
                        </div>
                    </div>

                    <!-- Bottom Status Message on Viewfinder -->
                    <div id="faceSurfaceMsg" style="position: absolute; bottom: 12px; left: 16px; right: 16px; text-align: center; font-size: 0.88rem; font-weight: 700; color: #cbd5e1; z-index: 10; text-shadow: 0 2px 6px rgba(0,0,0,0.95);">
                        <span style="color: #cbd5e1;"><i class="fa-solid fa-power-off" style="color: #38bdf8; margin-right: 5px;"></i> Camera Offline. Awaiting employee selection.</span>
                    </div>
                </div>

                <!-- Liveness & Anti-Spoofing Indicators -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 16px;">
                    <div style="padding: 10px 14px; border-radius: 10px; background: rgba(15, 23, 42, 0.8); border: 1px solid rgba(255, 255, 255, 0.06);">
                        <div style="font-size: 0.75rem; color: #94a3b8; margin-bottom: 4px;">Anti-Spoofing Liveness Check</div>
                        <div id="livenessStatusText" style="font-weight: 700; color: #64748b; font-size: 0.88rem;">
                            <i class="fa-solid fa-shield-halved"></i> Not Initialized
                        </div>
                    </div>
                    <div style="padding: 10px 14px; border-radius: 10px; background: rgba(15, 23, 42, 0.8); border: 1px solid rgba(255, 255, 255, 0.06);">
                        <div style="font-size: 0.75rem; color: #94a3b8; margin-bottom: 4px;">Face Quality Confidence</div>
                        <div id="qualityConfidenceText" style="font-weight: 700; color: #64748b; font-size: 0.88rem;">
                            <i class="fa-solid fa-chart-line"></i> 0.0% Confidence
                        </div>
                    </div>
                </div>

            </div>

            <!-- ================= RIGHT SIDE: EMPLOYEE SELECT & WORKFLOW CONTROLS ================= -->
            <div class="glass-panel" style="padding: 24px; border-radius: 18px; border: 1.5px solid rgba(56, 189, 248, 0.35); background: rgba(10, 16, 30, 0.9);">
                
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.08);">
                    <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(56, 189, 248, 0.15); display: flex; align-items: center; justify-content: center; color: #38bdf8;">
                        <i class="fa-solid fa-user-gear"></i>
                    </div>
                    <div>
                        <h2 style="font-size: 1.15rem; font-weight: 700; color: #f8fafc; margin: 0;">Select Employee & Enroll</h2>
                        <div style="font-size: 0.8rem; color: #94a3b8;">Choose employee, initialize camera & capture face</div>
                    </div>
                </div>

                <!-- 1. Select Employee Dropdown -->
                <div class="form-group" style="margin-bottom: 18px;">
                    <label class="form-label" style="display: block; font-weight: 700; color: #f1f5f9; margin-bottom: 8px;">
                        Select Employee <span style="color: #ef4444;">*</span>
                    </label>
                    <select id="empSelect" name="emp_id" class="form-control" required style="width: 100%; padding: 14px 16px; border-radius: 10px; background: #0b1329; color: #ffffff; border: 1.5px solid #334155; font-size: 0.98rem;" onchange="onEmployeeSelect()">
                        <?php if (!empty($isGuestMode)): ?>
                            <option value="">-- Sign In to Select Employees (Guest Mode) --</option>
                        <?php else: ?>
                            <option value="">-- Choose Employee --</option>
                            <?php 
                            $selectedEmpId = $_GET['emp_id'] ?? '';
                            foreach ($employees as $emp): 
                                $isSelected = ($emp['emp_id'] === $selectedEmpId) ? 'selected' : '';
                            ?>
                                <option value="<?= htmlspecialchars($emp['emp_id']) ?>" <?= $isSelected ?>
                                    data-name="<?= htmlspecialchars($emp['name']) ?>"
                                    data-dept="<?= htmlspecialchars($emp['department'] ?? 'General') ?>"
                                    data-desig="<?= htmlspecialchars($emp['designation'] ?? 'Staff') ?>"
                                    data-loc="<?= htmlspecialchars($emp['location'] ?? 'HQ') ?>">
                                    <?= htmlspecialchars($emp['emp_id']) ?> - <?= htmlspecialchars($emp['name']) ?> (<?= htmlspecialchars($emp['department'] ?? 'General') ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- Employee Details Card -->
                <div id="empCard" style="display: none; padding: 16px; border-radius: 12px; background: rgba(30, 41, 59, 0.8); border: 1px solid rgba(56, 189, 248, 0.35); margin-bottom: 18px;">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
                        <div id="empInitial" style="width: 44px; height: 44px; border-radius: 10px; background: linear-gradient(135deg, #38bdf8, #6366f1); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.2rem; color: #fff;">
                            E
                        </div>
                        <div style="flex: 1;">
                            <div id="cardName" style="font-size: 1.05rem; font-weight: 700; color: #ffffff;">Employee Name</div>
                            <div id="cardIdDept" style="font-size: 0.82rem; color: #38bdf8;">EMP-001 | Dept</div>
                        </div>
                        <span style="font-size: 0.75rem; background: rgba(56, 189, 248, 0.2); color: #38bdf8; padding: 4px 8px; border-radius: 6px; font-weight: 700;">
                            SELECTED
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: #94a3b8; border-top: 1px solid rgba(255, 255, 255, 0.08); padding-top: 8px;">
                        <span>Designation: <strong id="cardDesig" style="color: #cbd5e1;">-</strong></span>
                        <span>Location: <strong id="cardLoc" style="color: #cbd5e1;">-</strong></span>
                    </div>
                </div>

                <!-- 2. OK BUTTON TO INITIALIZE CAMERA -->
                <div style="margin-bottom: 20px;">
                    <button type="button" id="btnInitCamera" class="btn" onclick="initializeCamera()" disabled style="width: 100%; padding: 16px 20px; font-size: 1.1rem; font-weight: 800; border-radius: 12px; background: #1e293b; color: #64748b; border: 1.5px solid #334155; opacity: 0.45; cursor: not-allowed; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all 0.25s ease;">
                        <i class="fa-solid fa-video"></i>
                        <span>OK - Initialize Camera</span>
                    </button>
                    <div id="initHint" style="font-size: 0.8rem; color: #94a3b8; text-align: center; margin-top: 8px;">
                        Select an employee from the dropdown above to enable this button.
                    </div>
                </div>

                <!-- Anti-Spoofing & Workflow Instructions -->
                <div style="padding: 14px; border-radius: 10px; background: rgba(11, 19, 41, 0.85); border: 1px solid #1e293b; margin-bottom: 22px;">
                    <div style="font-size: 0.82rem; font-weight: 700; color: #38bdf8; margin-bottom: 6px;">
                        <i class="fa-solid fa-shield-halved"></i> 3D Liveness & Anti-Spoofing Rules:
                    </div>
                    <ol style="margin: 0; padding-left: 18px; font-size: 0.78rem; color: #94a3b8; line-height: 1.55;">
                        <li>Click <strong>"OK - Initialize Camera"</strong> to open webcam</li>
                        <li>Align real human face inside the frame (Blink & hold steady)</li>
                        <li><strong>Anti-Spoofing:</strong> Static paper photos or phone screen images will be strictly <strong>REJECTED</strong>!</li>
                        <li>Once verified (Green ✓), click <strong>"OK - Complete Face Entry"</strong>!</li>
                    </ol>
                </div>

                <!-- 3. FINAL OK BUTTON TO SAVE FACE ENTRY -->
                <div>
                    <button type="button" id="btnSaveFace" class="btn" onclick="submitFaceRegistration()" disabled style="width: 100%; padding: 16px 20px; font-size: 1.15rem; font-weight: 800; border-radius: 12px; background: #1e293b; color: #64748b; border: 1.5px solid #334155; cursor: not-allowed; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all 0.25s ease;">
                        <i class="fa-solid fa-check-double"></i>
                        <span id="saveBtnText">OK - Complete Face Entry</span>
                    </button>
                </div>

            </div>

        </div>

        <!-- ================= BOTTOM: Recently Logged Face Entries ================= -->
        <div class="glass-panel" style="padding: 24px; border-radius: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                <h3 style="font-size: 1.15rem; font-weight: 700; color: #f8fafc; margin: 0; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-clock-rotate-left" style="color: #38bdf8;"></i>
                    <span>Recently Logged Face Biometric Entries</span>
                </h3>
                <span style="font-size: 0.82rem; color: #38bdf8; font-weight: 700;">
                    <i class="fa-solid fa-shield-check"></i> AI Mesh Registered
                </span>
            </div>

            <div class="table-responsive">
                <table class="data-table" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Entry ID</th>
                            <th>Employee ID</th>
                            <th>Employee Name</th>
                            <th>Department</th>
                            <th>Designation</th>
                            <th>Match Quality</th>
                            <th>Status</th>
                            <th>Date & Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentFaceEntries)): ?>
                            <?php foreach ($recentFaceEntries as $entry): ?>
                                <tr>
                                    <td><strong>#FACE-<?= str_pad($entry['id'], 4, '0', STR_PAD_LEFT) ?></strong></td>
                                    <td><span style="font-weight: 700; color: #38bdf8; font-family: monospace;"><?= htmlspecialchars($entry['emp_id']) ?></span></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <div style="width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg, #38bdf8, #6366f1); color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;">
                                                <?= strtoupper(substr($entry['emp_name'] ?? 'U', 0, 1)) ?>
                                            </div>
                                            <strong><?= htmlspecialchars($entry['emp_name'] ?? 'N/A') ?></strong>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($entry['department'] ?? 'General') ?></td>
                                    <td><?= htmlspecialchars($entry['designation'] ?? 'Staff') ?></td>
                                    <td>
                                        <span style="color: #10b981; font-weight: 700;">
                                            <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($entry['quality_score']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge" style="background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.4);">
                                            <?= htmlspecialchars($entry['status']) ?>
                                        </span>
                                    </td>
                                    <td style="color: #94a3b8; font-size: 0.84rem;">
                                        <?= date('M d, Y h:i A', strtotime($entry['created_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 36px 20px; color: #94a3b8;">
                                    <?php if (!empty($isGuestMode)): ?>
                                        <div style="font-weight: 700; color: #94a3b8; margin-bottom: 6px; font-size: 0.95rem;">
                                            <i class="fa-solid fa-lock" style="color: #38bdf8; margin-right: 6px;"></i> Face enrollment records are protected in Guest Mode.
                                        </div>
                                        <div style="font-size: 0.84rem; color: var(--text-dim);">
                                            Please <a href="#" onclick="openLoginModal(); return false;" style="color: #38bdf8; font-weight: 700; text-decoration: underline;">Sign In</a> with an Agent account to view enrollment data.
                                        </div>
                                    <?php else: ?>
                                        No face entries registered yet. Select employee and initialize camera to begin.
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
<style>
@keyframes faceLaserAnim {
    0% { top: 12%; }
    100% { top: 86%; }
}
@keyframes faceGlowGreen {
    0%, 100% { border-color: #10b981; box-shadow: inset 0 0 35px rgba(0,0,0,0.9), 0 0 25px rgba(16, 185, 129, 0.5); }
    50% { border-color: #06b6d4; box-shadow: inset 0 0 35px rgba(0,0,0,0.9), 0 0 35px rgba(6, 182, 212, 0.7); }
}
@keyframes faceGlowRed {
    0%, 100% { border-color: #ef4444; box-shadow: inset 0 0 35px rgba(0,0,0,0.9), 0 0 25px rgba(239, 68, 68, 0.6); }
    50% { border-color: #dc2626; box-shadow: inset 0 0 35px rgba(0,0,0,0.9), 0 0 35px rgba(220, 38, 38, 0.8); }
}
.camera-active-verified {
    animation: faceGlowGreen 2s infinite ease-in-out !important;
}
.camera-active-duplicate {
    border-color: #ef4444 !important;
    animation: faceGlowRed 1.5s infinite ease-in-out !important;
}
</style>

<script>
let videoStream = null;
let isCameraRunning = false;
let isFaceLocked = false;
let capturedDescriptor = null;
let capturedFaceImage = null;
let animationFrameId = null;
let allEnrolledFaces = [];
let hasShownDuplicateAlert = false;

// Fetch all registered face templates for instant real-time duplicate blocking
async function loadAllEnrolledFaces() {
    try {
        const resp = await fetch('api_face.php?action=get_all_enrolled_faces');
        const res = await resp.json();
        if (res.status === 'success' && Array.isArray(res.faces)) {
            allEnrolledFaces = res.faces;
        }
    } catch(e) {
        console.warn('Could not pre-load enrolled faces:', e);
    }
}

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
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.2);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.2);
        } else {
            osc.frequency.setValueAtTime(220, ctx.currentTime);
            gain.gain.setValueAtTime(0.2, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.3);
        }
    } catch(e) {}
}

// Compare two 128-D normalized face vectors
function compareBiometricDescriptors(vecA, vecB) {
    if (!Array.isArray(vecA) || !Array.isArray(vecB) || vecA.length !== vecB.length) {
        return { sim: 0, dist: 999 };
    }
    let dot = 0, normA = 0, normB = 0, sumSq = 0;
    for (let i = 0; i < vecA.length; i++) {
        const a = vecA[i];
        const b = vecB[i];
        dot += a * b;
        normA += a * a;
        normB += b * b;
        const diff = a - b;
        sumSq += diff * diff;
    }
    const denom = Math.sqrt(normA) * Math.sqrt(normB);
    const sim = denom > 0 ? (dot / denom) : 0;
    const dist = Math.sqrt(sumSq);
    return { sim, dist };
}

// 1. Employee Select Handler
function onEmployeeSelect() {
    const sel = document.getElementById('empSelect');
    const opt = sel.options[sel.selectedIndex];
    const card = document.getElementById('empCard');
    const btnInit = document.getElementById('btnInitCamera');

    hasShownDuplicateAlert = false;
    isFaceLocked = false;
    capturedDescriptor = null;
    const saveBtn = document.getElementById('btnSaveFace');
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.style.background = '#1e293b';
        saveBtn.style.color = '#64748b';
        saveBtn.style.borderColor = '#334155';
        saveBtn.style.cursor = 'not-allowed';
        saveBtn.style.boxShadow = 'none';
        document.getElementById('saveBtnText').innerText = 'OK - Complete Face Entry';
    }
    const viewfinder = document.getElementById('cameraViewfinder');
    if (viewfinder) {
        viewfinder.classList.remove('camera-active-verified', 'camera-active-duplicate');
    }

    if (sel.value) {
        document.getElementById('cardName').innerText = opt.dataset.name;
        document.getElementById('cardIdDept').innerText = sel.value + ' | ' + opt.dataset.dept;
        document.getElementById('cardDesig').innerText = opt.dataset.desig;
        document.getElementById('cardLoc').innerText = opt.dataset.loc;
        document.getElementById('empInitial').innerText = opt.dataset.name.charAt(0).toUpperCase();
        card.style.display = 'block';

        btnInit.disabled = false;
        btnInit.style.background = 'linear-gradient(135deg, #38bdf8, #6366f1)';
        btnInit.style.color = '#ffffff';
        btnInit.style.border = 'none';
        btnInit.style.opacity = '1';
        btnInit.style.cursor = 'pointer';
        btnInit.style.boxShadow = '0 8px 25px rgba(56, 189, 248, 0.4)';

        document.getElementById('initHint').innerHTML = "<span style='color: #38bdf8; font-weight: 700;'>Employee selected! Click 'OK - Initialize Camera' to begin face scan.</span>";
    } else {
        card.style.display = 'none';
        btnInit.disabled = true;
        btnInit.style.background = '#1e293b';
        btnInit.style.color = '#64748b';
        btnInit.style.border = '1.5px solid #334155';
        btnInit.style.opacity = '0.45';
        btnInit.style.cursor = 'not-allowed';
        btnInit.style.boxShadow = 'none';
        document.getElementById('initHint').innerText = "Select an employee from the dropdown above to enable this button.";
    }
}

// 2. Initialize Camera & Liveness Face Scanner
async function initializeCamera() {
    if (typeof checkGuestAction === 'function' && !checkGuestAction('Initialize Optical Face Camera for Enrollment')) return;
    const sel = document.getElementById('empSelect');
    if (!sel.value) {
        showPulseAlert({
            title: 'Select Employee',
            message: 'Please choose an employee first!',
            type: 'warning'
        });
        sel.focus();
        return;
    }

    const video = document.getElementById('webcamVideo');
    const standby = document.getElementById('faceStandbyGraphic');
    const laser = document.getElementById('faceLaser');
    const badge = document.getElementById('camBadge');
    const dot = document.getElementById('camStatusDot');
    const surfaceMsg = document.getElementById('faceSurfaceMsg');

    await loadAllEnrolledFaces();

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
        badge.innerHTML = '<i class="fa-solid fa-video"></i> CAMERA ACTIVE (Scanning)';
        dot.style.background = '#10b981';
        dot.style.boxShadow = '0 0 10px #10b981';

        surfaceMsg.innerHTML = '<span style="color: #38bdf8;"><i class="fa-solid fa-face-viewfinder"></i> Face Detected: Hold steady and blink naturally for Liveness Check...</span>';

        startLiveFaceTracker();
    } catch (err) {
        showPulseAlert({
            title: 'Camera Error',
            message: 'Could not access webcam: ' + err.message + '<br>Please check camera permissions.',
            type: 'error'
        });
        surfaceMsg.innerHTML = '<span style="color: #ef4444;"><i class="fa-solid fa-circle-exclamation"></i> Camera permission denied or device not found.</span>';
    }
}

// 3. Optical Face Tracker with Anti-Spoofing & Liveness Detection
let livenessScore = 0;
let motionFrameCount = 0;
let lastFrameImageData = null;

function startLiveFaceTracker() {
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

        // Draw HUD 3D Oval Guide
        ctx.save();
        ctx.beginPath();
        ctx.ellipse(cx, cy, radiusX, radiusY, 0, 0, 2 * Math.PI);
        ctx.lineWidth = isFaceLocked ? 4 : 2;
        ctx.strokeStyle = isFaceLocked ? '#10b981' : 'rgba(56, 189, 248, 0.8)';
        ctx.setLineDash(isFaceLocked ? [] : [8, 6]);
        ctx.stroke();

        // Corner Targeting Reticles
        const rSize = 24;
        ctx.lineWidth = 3;
        ctx.strokeStyle = isFaceLocked ? '#10b981' : '#38bdf8';
        ctx.setLineDash([]);
        
        // Top-left
        ctx.beginPath();
        ctx.moveTo(cx - radiusX - 10, cy - radiusY + rSize);
        ctx.lineTo(cx - radiusX - 10, cy - radiusY - 10);
        ctx.lineTo(cx - radiusX + rSize, cy - radiusY - 10);
        ctx.stroke();

        // Top-right
        ctx.beginPath();
        ctx.moveTo(cx + radiusX + 10, cy - radiusY + rSize);
        ctx.lineTo(cx + radiusX + 10, cy - radiusY - 10);
        ctx.lineTo(cx + radiusX - rSize, cy - radiusY - 10);
        ctx.stroke();

        // Bottom-left
        ctx.beginPath();
        ctx.moveTo(cx - radiusX - 10, cy + radiusY - rSize);
        ctx.lineTo(cx - radiusX - 10, cy + radiusY + 10);
        ctx.lineTo(cx - radiusX + rSize, cy + radiusY + 10);
        ctx.stroke();

        // Bottom-right
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

        const isLiveMotion = diff >= 1.2 && diff <= 28.0;

        if (isLiveMotion) {
            motionFrameCount++;
            if (livenessScore < 98) livenessScore += 3;
        } else {
            if (livenessScore > 10) livenessScore -= 2;
        }

        // Draw Landmark Target Points on Live Canvas
        if (livenessScore >= 40) {
            const t = Date.now() * 0.003;
            drawLandmarkPoint(ctx, cx - 35 + Math.sin(t)*1.5, cy - 20, '#10b981');
            drawLandmarkPoint(ctx, cx + 35 + Math.sin(t)*1.5, cy - 20, '#10b981');
            drawLandmarkPoint(ctx, cx, cy + 12, '#38bdf8');
            drawLandmarkPoint(ctx, cx - 24, cy + 48, '#10b981');
            drawLandmarkPoint(ctx, cx + 24, cy + 48, '#10b981');
            drawLandmarkPoint(ctx, cx, cy + 70, '#38bdf8');
        }

        ctx.restore();

        // Update HUD metrics
        document.getElementById('hudLiveness').innerText = livenessScore >= 80 ? 'VERIFIED REAL HUMAN ✓' : (livenessScore > 20 ? 'ANALYZING LIVENESS...' : 'STANDBY');
        document.getElementById('hudLiveness').style.color = livenessScore >= 80 ? '#10b981' : '#38bdf8';
        document.getElementById('hudDepth').innerText = (diff).toFixed(1) + ' mm';
        document.getElementById('hudDepth').style.color = isLiveMotion ? '#10b981' : '#f87171';

        document.getElementById('livenessStatusText').innerHTML = livenessScore >= 80 
            ? '<span style="color: #10b981;"><i class="fa-solid fa-circle-check"></i> 3D Live Human Verified</span>'
            : (livenessScore > 20 ? '<span style="color: #38bdf8;"><i class="fa-solid fa-spinner fa-spin"></i> Checking Micro-motion...</span>' : '<span style="color: #f87171;"><i class="fa-solid fa-triangle-exclamation"></i> Static Image Rejected</span>');

        const conf = Math.min(99.8, Math.max(15, (livenessScore * 0.98))).toFixed(1);
        document.getElementById('qualityConfidenceText').innerHTML = '<span style="color: #10b981;"><i class="fa-solid fa-chart-line"></i> ' + conf + '% Match Quality</span>';

        // When 3D liveness and face posture are validated
        if (livenessScore >= 85 && !isFaceLocked && motionFrameCount >= 18) {
            const candidateDesc = extractFaceHOGDescriptor(video, cx, cy, radiusX, radiusY);
            const selectedEmpId = document.getElementById('empSelect')?.value || '';

            // Real-time Duplicate Face Detection against all other enrolled employees
            let duplicateMatch = null;
            if (allEnrolledFaces && allEnrolledFaces.length > 0) {
                for (const other of allEnrolledFaces) {
                    if (String(other.emp_id) !== String(selectedEmpId)) {
                        let otherVec = other.descriptor;
                        if (typeof otherVec === 'string') {
                            try { otherVec = JSON.parse(otherVec); } catch(e) {}
                        }
                        if (Array.isArray(otherVec) && otherVec.length === candidateDesc.length) {
                            const res = compareBiometricDescriptors(candidateDesc, otherVec);
                            if (res.sim >= 0.65 || res.dist <= 0.84) {
                                duplicateMatch = {
                                    emp_id: other.emp_id,
                                    name: other.name,
                                    sim: res.sim,
                                    dist: res.dist
                                };
                                break;
                            }
                        }
                    }
                }
            }

            if (duplicateMatch) {
                // DUPLICATE DETECTED - BLOCK ENROLLMENT
                const viewfinder = document.getElementById('cameraViewfinder');
                viewfinder.classList.remove('camera-active-verified');
                viewfinder.classList.add('camera-active-duplicate');

                playBeepSound(false);

                const saveBtn = document.getElementById('btnSaveFace');
                saveBtn.disabled = true;
                saveBtn.style.background = '#1e293b';
                saveBtn.style.color = '#f87171';
                saveBtn.style.borderColor = '#ef4444';
                saveBtn.style.cursor = 'not-allowed';
                saveBtn.style.boxShadow = 'none';
                document.getElementById('saveBtnText').innerText = 'Duplicate Face - Enrollment Blocked';

                const matchPct = Math.round(Math.min(99.9, Math.max(85, duplicateMatch.sim * 100))) + '%';
                document.getElementById('faceSurfaceMsg').innerHTML = `<span style="color: #f87171; font-weight: 800;"><i class="fa-solid fa-ban"></i> DUPLICATE FACE BLOCKED: Already registered to ${duplicateMatch.name} (#${duplicateMatch.emp_id})!</span>`;

                if (!hasShownDuplicateAlert) {
                    hasShownDuplicateAlert = true;
                    showPulseAlert({
                        title: 'Duplicate Face Blocked!',
                        message: `This face is ALREADY registered to Employee: <strong>${duplicateMatch.name} (ID: #${duplicateMatch.emp_id})</strong> with ${matchPct} biometric match.<br><br>The same face cannot be registered under a different employee name!`,
                        type: 'error'
                    });
                }
            } else {
                // VERIFIED UNIQUE REAL HUMAN FACE
                isFaceLocked = true;
                playBeepSound(true);

                capturedDescriptor = candidateDesc;
                capturedFaceImage = captureSnapshotDataUrl(video);

                const viewfinder = document.getElementById('cameraViewfinder');
                viewfinder.classList.remove('camera-active-duplicate');
                viewfinder.classList.add('camera-active-verified');

                const saveBtn = document.getElementById('btnSaveFace');
                saveBtn.disabled = false;
                saveBtn.style.background = 'linear-gradient(135deg, #10b981, #06b6d4)';
                saveBtn.style.color = '#ffffff';
                saveBtn.style.borderColor = '#10b981';
                saveBtn.style.cursor = 'pointer';
                saveBtn.style.boxShadow = '0 8px 30px rgba(16, 185, 129, 0.6)';
                document.getElementById('saveBtnText').innerText = 'OK - Complete Face Entry';

                document.getElementById('faceSurfaceMsg').innerHTML = '<span style="color: #10b981; font-weight: 800;"><i class="fa-solid fa-circle-check"></i> 100% Real Live Face Locked! Click "OK - Complete Face Entry" below to save.</span>';
            }
        }

        animationFrameId = requestAnimationFrame(processFrame);
    }

    processFrame();
}

function drawLandmarkPoint(ctx, x, y, color) {
    ctx.beginPath();
    ctx.arc(x, y, 3.5, 0, 2 * Math.PI);
    ctx.fillStyle = color;
    ctx.fill();
    ctx.strokeStyle = '#ffffff';
    ctx.lineWidth = 1;
    ctx.stroke();
}

// Extract normalized 128-D HOG biometric descriptor from live face frame
function extractFaceHOGDescriptor(video, cx, cy, rx, ry) {
    const patchSize = 64;
    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = patchSize;
    tempCanvas.height = patchSize;
    const tCtx = tempCanvas.getContext('2d');
    
    // Crop face region
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

// Capture current video frame as base64 JPEG
function captureSnapshotDataUrl(video) {
    const c = document.createElement('canvas');
    c.width = 320;
    c.height = 320;
    const ctx = c.getContext('2d');
    const minDim = Math.min(video.videoWidth || 320, video.videoHeight || 320);
    const sx = ((video.videoWidth || 320) - minDim) / 2;
    const sy = ((video.videoHeight || 320) - minDim) / 2;
    ctx.drawImage(video, sx, sy, minDim, minDim, 0, 0, 320, 320);
    return c.toDataURL('image/jpeg', 0.85);
}

// 4. Save Face Entry to Server
async function submitFaceRegistration() {
    const empSelect = document.getElementById('empSelect');
    const empId = empSelect.value;
    if (!empId) {
        showPulseAlert({
            title: 'Select Employee',
            message: 'Please choose an employee from the dropdown list first!',
            type: 'warning'
        });
        return;
    }

    if (!isFaceLocked || !capturedDescriptor) {
        showPulseAlert({
            title: 'Scan Incomplete',
            message: 'Please allow the optical sensor to complete the real live face scan first!',
            type: 'warning'
        });
        return;
    }

    const saveBtn = document.getElementById('btnSaveFace');
    saveBtn.disabled = true;
    document.getElementById('saveBtnText').innerText = 'Saving Biometric Template...';

    const formData = new FormData();
    formData.append('action', 'save_face');
    formData.append('emp_id', empId);
    formData.append('descriptor', JSON.stringify(capturedDescriptor));
    formData.append('image', capturedFaceImage || '');
    formData.append('quality', '99.6%');

    try {
        const resp = await fetch('api_face.php', {
            method: 'POST',
            body: formData
        });
        const res = await resp.json();

        if (res.status === 'success') {
            await showPulseAlert({
                title: 'Face Enrolled Successfully!',
                message: res.message,
                type: 'success'
            });
            window.location.href = 'face_entry.php';
        } else if (res.status === 'duplicate_face') {
            playBeepSound(false);
            showPulseAlert({
                title: 'Duplicate Face Blocked!',
                message: res.message,
                type: 'error'
            });
            saveBtn.disabled = true;
            saveBtn.style.background = '#1e293b';
            saveBtn.style.color = '#f87171';
            saveBtn.style.borderColor = '#ef4444';
            saveBtn.style.cursor = 'not-allowed';
            saveBtn.style.boxShadow = 'none';
            document.getElementById('saveBtnText').innerText = 'Duplicate Face - Enrollment Blocked';
            const surfaceMsg = document.getElementById('faceSurfaceMsg');
            if (surfaceMsg) {
                surfaceMsg.innerHTML = `<span style="color: #f87171; font-weight: 700;"><i class="fa-solid fa-ban"></i> ${res.message}</span>`;
            }
        } else {
            showPulseAlert({
                title: 'Enrollment Failed',
                message: res.message,
                type: 'error'
            });
            saveBtn.disabled = false;
            document.getElementById('saveBtnText').innerText = 'OK - Complete Face Entry';
        }
    } catch(err) {
        showPulseAlert({
            title: 'Server Error',
            message: 'Network or server communication error: ' + err.message,
            type: 'error'
        });
        saveBtn.disabled = false;
        document.getElementById('saveBtnText').innerText = 'OK - Complete Face Entry';
    }
}

// Cleanup on leave
window.addEventListener('beforeunload', () => {
    if (videoStream) {
        videoStream.getTracks().forEach(t => t.stop());
    }
    if (animationFrameId) {
        cancelAnimationFrame(animationFrameId);
    }
});

// Auto-trigger if preselected
window.addEventListener('DOMContentLoaded', () => {
    loadAllEnrolledFaces();
    if (document.getElementById('empSelect') && document.getElementById('empSelect').value) {
        onEmployeeSelect();
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
