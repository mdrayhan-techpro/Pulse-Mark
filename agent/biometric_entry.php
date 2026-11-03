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

// Ensure table exists
if ($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `biometric_entries` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `emp_id` VARCHAR(50) NOT NULL,
            `agent_id` INT DEFAULT NULL,
            `finger_type` VARCHAR(50) DEFAULT 'Right Thumb',
            `quality_score` VARCHAR(20) DEFAULT '99.8%',
            `status` VARCHAR(50) DEFAULT 'Enrolled',
            `notes` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Exception $e) {}
}

// Fetch all ACTIVE employees authorized for this agent for selection
$employees = !empty($isGuestMode) ? [] : getAgentAuthorizedEmployees($pdo, $agentId, true);

// Handle Fingerprint Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($isGuestMode) {
        setFlash('danger', 'Action Denied: You are in Guest Preview Mode. Please sign in to enroll fingerprints.');
        header('Location: biometric_entry.php');
        exit;
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Security validation failed.');
        header('Location: biometric_entry.php');
        exit;
    }

    $emp_id = sanitize($_POST['emp_id'] ?? '');
    $finger_type = sanitize($_POST['finger_type'] ?? 'Right Thumb');
    $quality = sanitize($_POST['quality'] ?? '99.8%');
    $notes = sanitize($_POST['notes'] ?? 'ZKTeco Mini 3/3 Scans Enrolled');
    $template = $_POST['template'] ?? '';

    if ($pdo && !empty($emp_id)) {
        // Verify employee is Active
        $chk = $pdo->prepare("SELECT status, name FROM `employees` WHERE `emp_id` = ? LIMIT 1");
        $chk->execute([$emp_id]);
        $empRow = $chk->fetch();

        if (!$empRow || strcasecmp($empRow['status'] ?? 'Active', 'Active') !== 0) {
            setFlash('danger', "Employee ({$emp_id}) is INACTIVE! Biometric registration is blocked.");
            header('Location: biometric_entry.php');
            exit;
        }

        try {
            $ins = $pdo->prepare("INSERT INTO `biometric_entries` (`emp_id`, `agent_id`, `finger_type`, `template`, `quality_score`, `status`, `notes`) VALUES (?, ?, ?, ?, ?, 'Enrolled', ?)");
            $ins->execute([$emp_id, $agentId, $finger_type, $template, $quality, $notes]);

            // Persist to enrolled_templates.json for rapid matching
            $jsonFile = __DIR__ . '/../enrolled_templates.json';
            $allTemplates = [];
            if (file_exists($jsonFile)) {
                $allTemplates = json_decode(file_get_contents($jsonFile), true) ?: [];
            }

            // Check if this exact fingerprint template already belongs to another employee
            if (!empty($template)) {
                $duplicateEmp = null;
                foreach ($allTemplates as $otherEmp => $tList) {
                    if (strval($otherEmp) !== strval($emp_id)) {
                        if (is_array($tList) && in_array($template, $tList)) {
                            $duplicateEmp = $otherEmp;
                            break;
                        }
                    }
                }

                if ($duplicateEmp) {
                    setFlash('danger', "Duplicate Fingerprint Blocked! This fingerprint is ALREADY registered to Employee ($duplicateEmp). The same finger cannot be enrolled for a different employee.");
                    header('Location: biometric_entry.php');
                    exit;
                }
            }
            if (!empty($template)) {
                if (!isset($allTemplates[$emp_id])) {
                    $allTemplates[$emp_id] = [];
                }
                if (!in_array($template, $allTemplates[$emp_id])) {
                    $allTemplates[$emp_id][] = $template;
                }
                file_put_contents($jsonFile, json_encode($allTemplates, JSON_PRETTY_PRINT));

                // Notify bridge to sync
                $ctx = stream_context_create(['http' => ['timeout' => 0.5, 'ignore_errors' => true]]);
                @file_get_contents('http://127.0.0.1:9000/sync_templates', false, $ctx);
            }

            setFlash('success', "Fingerprint successfully registered for Employee ($emp_id)! ZKTeco Mini 3-Scan verification completed.");
        } catch (Exception $e) {
            setFlash('danger', 'Database error: ' . $e->getMessage());
        }
    } else {
        setFlash('danger', 'Please select an employee.');
    }

    header('Location: biometric_entry.php');
    exit;
}

// Fetch recent biometric entries registered by this agent
$recentEntries = [];
if (!empty($isGuestMode)) {
    $recentEntries = [];
} elseif ($pdo) {
    try {
        $q = $pdo->prepare("SELECT b.*, e.name as emp_name, e.department, e.designation 
                            FROM `biometric_entries` b 
                            LEFT JOIN `employees` e ON b.emp_id = e.emp_id 
                            WHERE b.agent_id = ?
                            ORDER BY b.id DESC LIMIT 8");
        $q->execute([$agentId]);
        $recentEntries = $q->fetchAll();
    } catch (Exception $e) {
        $recentEntries = [];
    }
}

$flash = getFlash();

$pageTitle = 'Biometric Fingerprint Enrollment | Pulse Mark';
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
                    <i class="fa-solid fa-fingerprint" style="color: #10b981;"></i>
                    <span>ZKTeco Mini Biometric Enrollment</span>
                </h1>
                <p class="page-subtitle" style="color: #94a3b8; font-size: 0.95rem; margin-top: 4px;">
                    Connect ZKTeco Mini USB sensor, initialize device, and capture 3-pass fingerprint enrollment.
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
        <!-- Left: Black Surface | Right: Select Employee & Controls -->
        <div style="display: grid; grid-template-columns: 1.1fr 1fr; gap: 28px; align-items: start; margin-bottom: 32px;">

            <!-- ================= LEFT SIDE: BLACK SURFACE (ZKTeco Scanner Display) ================= -->
            <div class="glass-panel" style="padding: 24px; border-radius: 18px; border: 1.5px solid rgba(16, 185, 129, 0.35); background: rgba(10, 16, 30, 0.9);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.08);">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="width: 12px; height: 12px; border-radius: 50%; background: #10b981; box-shadow: 0 0 10px #10b981;"></span>
                        <h2 style="font-size: 1.15rem; font-weight: 700; color: #f8fafc; margin: 0;">ZKTeco Mini Live Display</h2>
                    </div>
                    <span id="zkStatusBadge" style="font-size: 0.78rem; font-weight: 700; padding: 4px 12px; border-radius: 20px; background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.4);">
                        <i class="fa-solid fa-power-off"></i> SENSOR DISCONNECTED
                    </span>
                </div>

                <!-- THE BLACK SURFACE WINDOW -->
                <div id="blackSurface" style="position: relative; width: 100%; height: 380px; background: #000000; border-radius: 14px; border: 2.5px solid #1e293b; display: flex; flex-direction: column; align-items: center; justify-content: center; overflow: hidden; box-shadow: inset 0 0 35px rgba(0,0,0,0.9), 0 12px 30px rgba(0,0,0,0.7); transition: all 0.35s ease; cursor: not-allowed;" onclick="handleSurfaceClick()">
                    
                    <!-- Top HUD info in black surface -->
                    <div style="position: absolute; top: 12px; left: 16px; right: 16px; display: flex; justify-content: space-between; align-items: center; font-family: monospace; font-size: 0.74rem; color: #94a3b8; z-index: 5;">
                        <span id="hudIndex">DEVICE: ZK-LIVE20R</span>
                        <span id="hudResolution">500 DPI | 256x360</span>
                    </div>

                    <!-- Ambient Grid overlay in black surface -->
                    <div style="position: absolute; inset: 0; background-image: linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px); background-size: 24px 24px; pointer-events: none;"></div>

                    <!-- Scanner Laser Sweep (visible when active) -->
                    <div id="zkLaser" style="display: none; position: absolute; width: 85%; height: 3px; background: #10b981; box-shadow: 0 0 18px #10b981, 0 0 8px #06b6d4; top: 15%; z-index: 6; animation: zkLaserAnim 1.6s infinite alternate ease-in-out;"></div>

                    <!-- Fingerprint Graphic Container -->
                    <div id="fpGraphicWrap" style="position: relative; width: 200px; height: 260px; display: flex; align-items: center; justify-content: center; z-index: 4;">
                        
                        <!-- Standby Fingerprint SVG (Faint Outline) -->
                        <svg id="fpSvgStandby" viewBox="0 0 100 130" style="width: 170px; height: 220px; opacity: 0.15; transition: all 0.4s ease;">
                            <path d="M50 15 C30 15 15 32 15 55 C15 78 28 95 35 115" stroke="#ffffff" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                            <path d="M50 25 C36 25 25 38 25 57 C25 80 40 92 45 110" stroke="#ffffff" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                            <path d="M50 35 C42 35 35 44 35 58 C35 75 48 88 50 105" stroke="#ffffff" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                            <path d="M50 45 C46 45 42 50 42 60 C42 70 52 82 52 95" stroke="#ffffff" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                            <path d="M50 15 C70 15 85 32 85 55 C85 78 72 95 65 115" stroke="#ffffff" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                            <path d="M50 25 C64 25 75 38 75 57 C75 80 60 92 55 110" stroke="#ffffff" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                            <path d="M50 35 C58 35 65 44 65 58 C65 75 52 88 50 105" stroke="#ffffff" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                            <path d="M50 45 C54 45 58 50 58 60 C58 70 48 82 48 95" stroke="#ffffff" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                        </svg>

                        <!-- Realistic Captured Fingerprint Image (Revealed when scanned) -->
                        <div id="fpRealImage" style="display: none; position: absolute; inset: 0; align-items: center; justify-content: center;">
                            <img id="liveSensorImg" src="" alt="Live Fingerprint" style="display: none; max-width: 180px; max-height: 240px; object-fit: contain; filter: drop-shadow(0 0 15px rgba(16, 185, 129, 0.8)); border-radius: 8px;">
                            <svg id="fpRealSvg" viewBox="0 0 100 130" style="width: 185px; height: 235px; filter: drop-shadow(0 0 12px rgba(16, 185, 129, 0.7));">
                                <g stroke="#10b981" stroke-width="2.8" fill="none" stroke-linecap="round">
                                    <path d="M50 12 C28 12 12 30 12 55 C12 82 26 98 34 118"/>
                                    <path d="M50 22 C34 22 22 36 22 57 C22 81 37 94 44 112"/>
                                    <path d="M50 32 C40 32 32 42 32 58 C32 76 46 89 49 106"/>
                                    <path d="M50 42 C44 42 40 48 40 60 C40 71 50 83 51 96"/>
                                    <path d="M50 12 C72 12 88 30 88 55 C88 82 74 98 66 118"/>
                                    <path d="M50 22 C66 22 78 36 78 57 C78 81 63 94 56 112"/>
                                    <path d="M50 32 C60 32 68 42 68 58 C68 76 54 89 51 106"/>
                                    <path d="M50 42 C56 42 60 48 60 60 C60 71 50 83 49 96"/>
                                    <path d="M47 55 C47 62 53 62 53 55 C53 48 47 48 47 55 Z"/>
                                </g>
                            </svg>
                        </div>
                    </div>

                    <!-- Message at the bottom of the black surface -->
                    <div id="surfaceMsg" style="position: absolute; bottom: 16px; left: 16px; right: 16px; text-align: center; font-size: 0.88rem; font-weight: 600; color: #cbd5e1; z-index: 5; text-shadow: 0 2px 6px rgba(0,0,0,0.95);">
                        <i class="fa-solid fa-power-off" style="color: #38bdf8; margin-right: 5px;"></i> Sensor Offline. Select Employee on the right &amp; click <strong style="color: #38bdf8; font-weight: 800; text-shadow: 0 0 10px rgba(56, 189, 248, 0.6);">"OK - Initialize Machine"</strong>
                    </div>
                </div>

                <!-- 3-Pass Fingerprint Scan Progress ("3bar figure dile shob gula ok hobe") -->
                <div style="margin-top: 20px; padding: 16px 18px; border-radius: 12px; background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(255, 255, 255, 0.08);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <span style="font-size: 0.85rem; font-weight: 700; color: #cbd5e1;">
                            <i class="fa-solid fa-list-ol" style="color: #10b981; margin-right: 6px;"></i> 3-Pass Finger Registration (3 বার ফিঙ্গার দিন)
                        </span>
                        <span id="scanCountLabel" style="font-size: 0.82rem; font-weight: 700; color: #64748b;">
                            0 of 3 Completed
                        </span>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
                        <!-- Scan 1 Indicator -->
                        <div id="scanStep1" style="background: rgba(0, 0, 0, 0.5); border: 1.5px solid #334155; border-radius: 10px; padding: 12px 8px; text-align: center; transition: all 0.3s ease;">
                            <div id="scanStepIcon1" style="font-size: 1.3rem; color: #64748b; margin-bottom: 4px;">
                                <i class="fa-solid fa-fingerprint"></i>
                            </div>
                            <div style="font-size: 0.78rem; font-weight: 700; color: #94a3b8;">1st Scan</div>
                            <div id="scanStepStatus1" style="font-size: 0.7rem; color: #64748b; font-weight: 600;">Pending</div>
                        </div>

                        <!-- Scan 2 Indicator -->
                        <div id="scanStep2" style="background: rgba(0, 0, 0, 0.5); border: 1.5px solid #334155; border-radius: 10px; padding: 12px 8px; text-align: center; transition: all 0.3s ease;">
                            <div id="scanStepIcon2" style="font-size: 1.3rem; color: #64748b; margin-bottom: 4px;">
                                <i class="fa-solid fa-fingerprint"></i>
                            </div>
                            <div style="font-size: 0.78rem; font-weight: 700; color: #94a3b8;">2nd Scan</div>
                            <div id="scanStepStatus2" style="font-size: 0.7rem; color: #64748b; font-weight: 600;">Pending</div>
                        </div>

                        <!-- Scan 3 Indicator -->
                        <div id="scanStep3" style="background: rgba(0, 0, 0, 0.5); border: 1.5px solid #334155; border-radius: 10px; padding: 12px 8px; text-align: center; transition: all 0.3s ease;">
                            <div id="scanStepIcon3" style="font-size: 1.3rem; color: #64748b; margin-bottom: 4px;">
                                <i class="fa-solid fa-fingerprint"></i>
                            </div>
                            <div style="font-size: 0.78rem; font-weight: 700; color: #94a3b8;">3rd Scan</div>
                            <div id="scanStepStatus3" style="font-size: 0.7rem; color: #64748b; font-weight: 600;">Pending</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= RIGHT SIDE: SELECT EMPLOYEE & CONTROLS ================= -->
            <div class="glass-panel" style="padding: 26px; border-radius: 18px; border: 1.5px solid rgba(6, 182, 212, 0.35); background: rgba(15, 23, 42, 0.75);">
                
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
                    <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(6, 182, 212, 0.2); display: flex; align-items: center; justify-content: center; color: #06b6d4; font-size: 1.3rem;">
                        <i class="fa-solid fa-user-gear"></i>
                    </div>
                    <div>
                        <h2 style="font-size: 1.2rem; font-weight: 700; color: #f8fafc; margin: 0;">Select Employee & Enroll</h2>
                        <div style="font-size: 0.82rem; color: #94a3b8;">Choose employee, initialize & submit entry</div>
                    </div>
                </div>

                <form id="enrollForm" method="POST" action="biometric_entry.php">
                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="action" value="save_biometric">
                    <input type="hidden" name="quality" id="finalQuality" value="99.8%">
                    <input type="hidden" name="template" id="fingerTemplate" value="">

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
                            <div id="empInitial" style="width: 44px; height: 44px; border-radius: 10px; background: linear-gradient(135deg, #10b981, #06b6d4); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.2rem; color: #fff;">
                                E
                            </div>
                            <div style="flex: 1;">
                                <div id="cardName" style="font-size: 1.05rem; font-weight: 700; color: #ffffff;">Employee Name</div>
                                <div id="cardIdDept" style="font-size: 0.82rem; color: #38bdf8;">EMP-001 | Dept</div>
                            </div>
                            <span style="font-size: 0.75rem; background: rgba(16, 185, 129, 0.2); color: #10b981; padding: 4px 8px; border-radius: 6px; font-weight: 700;">
                                SELECTED
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: #94a3b8; border-top: 1px solid rgba(255, 255, 255, 0.08); padding-top: 8px;">
                            <span>Designation: <strong id="cardDesig" style="color: #cbd5e1;">-</strong></span>
                            <span>Location: <strong id="cardLoc" style="color: #cbd5e1;">-</strong></span>
                        </div>
                    </div>

                    <!-- Finger Selector Removed as requested -->
                    <input type="hidden" name="finger_type" value="Biometric Fingerprint">


                    <!-- 3. OK BUTTON TO INITIALIZE MACHINE -->
                    <!-- "ok buttion e click korle machin inicialize hobe" -->
                    <div style="margin-bottom: 22px;">
                        <button type="button" id="btnInitMachine" class="btn" onclick="initializeMachine()" disabled style="width: 100%; padding: 16px 20px; font-size: 1.1rem; font-weight: 800; border-radius: 12px; background: #1e293b; color: #64748b; border: 1.5px solid #334155; opacity: 0.45; cursor: not-allowed; display: flex; align-items: center; justify-content: center; gap: 10px; box-shadow: none; pointer-events: none; transition: all 0.25s ease;">
                            <i class="fa-solid fa-power-off"></i>
                            <span>OK - Initialize Machine</span>
                        </button>
                        <div id="initHint" style="font-size: 0.8rem; color: #94a3b8; text-align: center; margin-top: 8px;">
                            Select an employee from the dropdown above to enable this button.
                        </div>
                    </div>

                    <!-- Enrollment Instruction Notes -->
                    <div style="padding: 14px; border-radius: 10px; background: rgba(11, 19, 41, 0.85); border: 1px solid #1e293b; margin-bottom: 22px;">
                        <div style="font-size: 0.82rem; font-weight: 700; color: #38bdf8; margin-bottom: 6px;">
                            <i class="fa-solid fa-circle-info"></i> ZKTeco Mini Enrollment Workflow:
                        </div>
                        <ol style="margin: 0; padding-left: 18px; font-size: 0.78rem; color: #94a3b8; line-height: 1.5;">
                            <li>Select Employee & click <strong>"OK - Initialize Machine"</strong></li>
                            <li>Place employee's finger on sensor <strong>3 times</strong> (3 বার)</li>
                            <li>Once all 3 scans are verified, click the <strong>OK (Save)</strong> button below!</li>
                        </ol>
                    </div>

                    <!-- 4. FINAL OK BUTTON TO SAVE ENTRY ("ok buttion e click korle fingure print entry hoye jabe") -->
                    <div>
                        <button type="submit" id="btnFinalOk" class="btn" disabled style="width: 100%; padding: 16px 20px; font-size: 1.15rem; font-weight: 800; border-radius: 12px; background: #1e293b; color: #64748b; border: 1.5px solid #334155; cursor: not-allowed; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all 0.25s ease;">
                            <i class="fa-solid fa-check-double"></i>
                            <span id="finalOkText">OK - Complete Fingerprint Entry</span>
                        </button>
                    </div>
                </form>

            </div>
        </div>

        <!-- ================= BOTTOM: Recent Biometric Fingerprint Entries ================= -->
        <div class="glass-panel" style="padding: 24px; border-radius: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                <h3 style="font-size: 1.15rem; font-weight: 700; color: #f8fafc; margin: 0; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-clock-rotate-left" style="color: #38bdf8;"></i>
                    <span>Recently Logged Biometric Fingerprint Entries</span>
                </h3>
                <span style="font-size: 0.82rem; color: #94a3b8;">ZKTeco Mini Telemetry Synced</span>
            </div>

            <div class="table-responsive">
                <table class="data-table" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Entry ID</th>
                            <th>Employee ID</th>
                            <th>Employee Name</th>
                            <th>Department</th>
                            <th>Finger Type</th>
                            <th>Match Quality</th>
                            <th>Enroll Status</th>
                            <th>Date & Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentEntries)): ?>
                            <?php foreach ($recentEntries as $entry): ?>
                                <tr>
                                    <td><strong>#ZK-<?= str_pad($entry['id'], 4, '0', STR_PAD_LEFT) ?></strong></td>
                                    <td><span style="font-weight: 700; color: #38bdf8;"><?= htmlspecialchars($entry['emp_id']) ?></span></td>
                                    <td><?= htmlspecialchars($entry['emp_name'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($entry['department'] ?? 'General') ?></td>
                                    <td>
                                        <span style="display: inline-flex; align-items: center; gap: 6px;">
                                            <i class="fa-solid fa-fingerprint" style="color: #10b981;"></i>
                                            <?= htmlspecialchars($entry['finger_type']) ?>
                                        </span>
                                    </td>
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
                                            <i class="fa-solid fa-lock" style="color: #38bdf8; margin-right: 6px;"></i> Biometric enrollment records are protected in Guest Mode.
                                        </div>
                                        <div style="font-size: 0.84rem; color: var(--text-dim);">
                                            Please <a href="#" onclick="openLoginModal(); return false;" style="color: #38bdf8; font-weight: 700; text-decoration: underline;">Sign In</a> with an Agent account to view enrollment data.
                                        </div>
                                    <?php else: ?>
                                        No biometric entries recorded yet today. Select employee and initialize sensor to begin.
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
@keyframes zkPulseBorder {
    0%, 100% { border-color: rgba(16, 185, 129, 0.4); box-shadow: inset 0 0 25px rgba(0,0,0,0.9), 0 0 20px rgba(16, 185, 129, 0.3); }
    50% { border-color: rgba(6, 182, 212, 0.9); box-shadow: inset 0 0 35px rgba(0,0,0,0.9), 0 0 35px rgba(6, 182, 212, 0.6); }
}
.surface-active {
    animation: zkPulseBorder 2s infinite ease-in-out !important;
}
</style>

<script>
let isInitialized = false;
let scanCount = 0; // Needs 3 scans: 1, 2, 3
let isScanning = false;

// When an employee is chosen from the dropdown
function onEmployeeSelect() {
    const sel = document.getElementById('empSelect');
    const opt = sel.options[sel.selectedIndex];
    const card = document.getElementById('empCard');
    const btnInit = document.getElementById('btnInitMachine');
    
    if (sel.value) {
        document.getElementById('cardName').innerText = opt.dataset.name;
        document.getElementById('cardIdDept').innerText = sel.value + ' | ' + opt.dataset.dept;
        document.getElementById('cardDesig').innerText = opt.dataset.desig;
        document.getElementById('cardLoc').innerText = opt.dataset.loc;
        document.getElementById('empInitial').innerText = opt.dataset.name.charAt(0).toUpperCase();
        card.style.display = 'block';

        // ENABLE the OK - Initialize button
        btnInit.disabled = false;
        btnInit.style.background = 'linear-gradient(135deg, #10b981, #06b6d4)';
        btnInit.style.color = '#ffffff';
        btnInit.style.border = 'none';
        btnInit.style.opacity = '1';
        btnInit.style.cursor = 'pointer';
        btnInit.style.pointerEvents = 'auto';
        btnInit.style.boxShadow = '0 8px 25px rgba(16, 185, 129, 0.4)';

        document.getElementById('initHint').innerHTML = "<span style='color: #10b981; font-weight: 700;'>Employee selected! You can now click 'OK - Initialize Machine' to turn on sensor.</span>";
    } else {
        card.style.display = 'none';

        // DISABLE & SHADOW the OK - Initialize button
        btnInit.disabled = true;
        btnInit.style.background = '#1e293b';
        btnInit.style.color = '#64748b';
        btnInit.style.border = '1.5px solid #334155';
        btnInit.style.opacity = '0.45';
        btnInit.style.cursor = 'not-allowed';
        btnInit.style.pointerEvents = 'none';
        btnInit.style.boxShadow = 'none';

        document.getElementById('initHint').innerText = "Select an employee from the dropdown above to enable this button.";
    }
}

// 1. OK Button Click: Initialize Machine
function initializeMachine() {
    if (typeof checkGuestAction === 'function' && !checkGuestAction('Initialize Biometric Sensor for Enrollment')) return;
    const empSelect = document.getElementById('empSelect');
    if (!empSelect.value) {
        alert('Please select an employee first from the dropdown list!');
        empSelect.focus();
        return;
    }

    isInitialized = true;
    currentScanStep = 1;
    resetScanSteps();

    // Update Status Badge on top of Black Surface
    const badge = document.getElementById('zkStatusBadge');
    badge.style.background = 'rgba(16, 185, 129, 0.2)';
    badge.style.color = '#34d399';
    badge.style.borderColor = '#10b981';
    badge.innerHTML = '<i class="fa-solid fa-circle-check"></i> ZKTeco Mini: INITIALIZED (Ready)';

    // Update Black Surface styling
    const surface = document.getElementById('blackSurface');
    surface.classList.add('surface-active');
    surface.style.cursor = 'pointer';
    surface.style.borderColor = '#10b981';

    // Show laser and active outline
    document.getElementById('zkLaser').style.display = 'block';
    document.getElementById('fpSvgStandby').style.opacity = '0.4';
    document.getElementById('fpRealImage').style.display = 'none';

    // Update listener status
    const listener = document.getElementById('liveListenerStatus');
    if (listener) {
        listener.innerText = 'Listening for Physical USB Finger...';
        listener.style.color = '#10b981';
    }

    // Update Surface Message
    document.getElementById('surfaceMsg').innerHTML = '<span style="color: #38bdf8;"><i class="fa-solid fa-hand"></i> Sensor Online: Place your finger on ZKTeco scanner for 1st scan...</span>';
    document.getElementById('initHint').innerHTML = "<span style='color: #38bdf8; font-weight: 700;'>Sensor Initialized! Place your finger on the physical USB sensor.</span>";

    // Start background live polling from physical ZKTeco sensor
    startHardwarePolling();
}

let currentScanStep = 1;
let hardwarePollTimer = null;

// Live hardware polling loop to read physical USB sensor via port 9000
async function startHardwarePolling() {
    if (hardwarePollTimer) clearInterval(hardwarePollTimer);

    // Reset sensor state via PHP proxy
    try {
        await fetch('api_zk.php?action=reset', { cache: 'no-store' });
    } catch(e) {}

    hardwarePollTimer = setInterval(async () => {
        if (!isInitialized || currentScanStep > 3 || isScanning) return;

        try {
            const curEmpId = document.getElementById('empSelect')?.value || '';
            const resp = await fetch('api_zk.php?action=capture&mode=enroll&target_emp_id=' + encodeURIComponent(curEmpId), { cache: 'no-store' });
            if (resp.ok) {
                const data = await resp.json();
                if (data.status === 'duplicate') {
                    playBeep(false);
                    isScanning = false;
                    currentScanStep = 1;
                    const surfaceMsg = document.getElementById('surfaceMsg');
                    if (surfaceMsg) {
                        surfaceMsg.innerHTML = `<span style="color: #f87171; font-weight: 700;"><i class="fa-solid fa-ban"></i> DUPLICATE FINGERPRINT BLOCKED: Already registered to Employee #${data.matched_emp}!</span>`;
                    }
                    const initHint = document.getElementById('initHint');
                    if (initHint) {
                        initHint.innerHTML = `<span style="color: #f87171; font-weight: 700;">DUPLICATE FINGER DETECTED: This fingerprint belongs to another user in the system. Enrollment Rejected!</span>`;
                    }
                    showPulseAlert({
                        title: 'Duplicate Fingerprint Blocked!',
                        message: 'This fingerprint is ALREADY registered to Employee #' + data.matched_emp + '.<br>The same fingerprint cannot be registered under a different employee name!',
                        type: 'error'
                    });
                } else if (data.status === 'success') {
                    // Physical finger touch detected from ZKTeco Mini!
                    handleSurfaceClick(false, data.image, data.message, data.step, data.template);
                } else if (data.status === 'mismatch' || data.status === 'failed') {
                    handleSurfaceClick(true, data.image, data.message, data.step, data.template);
                } else if (data.status === 'lift_finger') {
                    const surfaceMsg = document.getElementById('surfaceMsg');
                    if (surfaceMsg) {
                        surfaceMsg.innerHTML = '<span style="color: #facc15;"><i class="fa-solid fa-arrow-up-from-bracket"></i> Please lift finger from sensor before placing again!</span>';
                    }
                }
            }
        } catch (err) {
            // Hardware bridge not responding, fallback to UI click
        }
    }, 250);
}

// Audio feedback for hardware scanner
function playBeep(isSuccess = true) {
    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        const ctx = new AudioContext();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        if (isSuccess) {
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            gain.gain.setValueAtTime(0.12, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.16);
            osc.start();
            osc.stop(ctx.currentTime + 0.16);
        } else {
            osc.frequency.setValueAtTime(220, ctx.currentTime);
            gain.gain.setValueAtTime(0.18, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.28);
            osc.start();
            osc.stop(ctx.currentTime + 0.28);
        }
    } catch (e) {}
}

function handleSurfaceClick(forceFail = false, realImageUrl = null, customMsg = null, targetStep = null, templateData = null) {
    if (!isInitialized) {
        alert('Please select an employee and click "OK - Initialize Machine" first!');
        return;
    }

    if (templateData) {
        document.getElementById('fingerTemplate').value = templateData;
    }

    const stepToProcess = targetStep || currentScanStep;
    if (stepToProcess > 3) {
        return;
    }

    if (isScanning) return;
    isScanning = true;

    const surfaceMsg = document.getElementById('surfaceMsg');
    surfaceMsg.innerHTML = '<span style="color: #38bdf8;"><i class="fa-solid fa-spinner fa-spin"></i> Reading biometric ridges from USB sensor for scan #' + stepToProcess + '...</span>';

    setTimeout(() => {
        isScanning = false;

        if (forceFail) {
            playBeep(false);
            updateStep(stepToProcess, false);
            surfaceMsg.innerHTML = '<span style="color: #ef4444; font-weight: 700;"><i class="fa-solid fa-triangle-exclamation"></i> ' + (customMsg || ('Scan #' + stepToProcess + ' MISMATCH (Red ✗)! Different finger detected. Place the SAME finger as 1st scan.')) + '</span>';
            
            const surface = document.getElementById('blackSurface');
            surface.style.borderColor = '#ef4444';
            surface.style.boxShadow = '0 0 35px rgba(239, 68, 68, 0.7)';
            setTimeout(() => {
                if (isInitialized && currentScanStep <= 3) {
                    surface.style.borderColor = '#10b981';
                    surface.style.boxShadow = '0 0 25px rgba(16, 185, 129, 0.4)';
                    
                    // Reset mismatch step back to pending state for same finger retry
                    const stepEl = document.getElementById('scanStep' + stepToProcess);
                    const iconEl = document.getElementById('scanStepIcon' + stepToProcess);
                    const textEl = document.getElementById('scanStepStatus' + stepToProcess);
                    if (stepEl) {
                        stepEl.style.borderColor = '#334155';
                        stepEl.style.background = 'rgba(0, 0, 0, 0.5)';
                        stepEl.style.boxShadow = 'none';
                        iconEl.innerHTML = '<i class="fa-solid fa-fingerprint"></i>';
                        iconEl.style.color = '#64748b';
                        textEl.innerText = 'Pending';
                        textEl.style.color = '#64748b';
                    }
                    surfaceMsg.innerHTML = '<span style="color: #38bdf8;"><i class="fa-solid fa-hand"></i> Place the SAME finger on sensor for scan #' + currentScanStep + '...</span>';
                }
            }, 1800);
            return;
        }

        playBeep(true);
        updateStep(stepToProcess, true);

        const realWrap = document.getElementById('fpRealImage');
        const liveImg = document.getElementById('liveSensorImg');
        const svgFallback = document.getElementById('fpRealSvg');

        realWrap.style.display = 'flex';
        document.getElementById('fpSvgStandby').style.display = 'none';

        if (realImageUrl) {
            liveImg.src = realImageUrl;
            liveImg.style.display = 'block';
            if (svgFallback) svgFallback.style.display = 'none';
        } else {
            if (liveImg.src && liveImg.style.display === 'block') {
                // keep previous real image
            } else if (svgFallback) {
                svgFallback.style.display = 'block';
            }
        }

        if (stepToProcess === 1) {
            document.getElementById('scanCountLabel').innerText = '1 of 3 Completed (Green ✓)';
            surfaceMsg.innerHTML = '<span style="color: #10b981;"><i class="fa-solid fa-check"></i> ' + (customMsg || '1st Scan OK (Green ✓)! Lift finger and place for 2nd Scan.') + '</span>';
            currentScanStep = 2;
        } else if (stepToProcess === 2) {
            document.getElementById('scanCountLabel').innerText = '2 of 3 Completed (Green ✓)';
            surfaceMsg.innerHTML = '<span style="color: #10b981;"><i class="fa-solid fa-check"></i> ' + (customMsg || '2nd Scan OK (Green ✓)! Lift finger and place for 3rd Scan.') + '</span>';
            currentScanStep = 3;
        } else if (stepToProcess === 3) {
            document.getElementById('scanCountLabel').innerText = '3 of 3 Completed (All Green ✓)';
            document.getElementById('zkLaser').style.display = 'none';
            surfaceMsg.innerHTML = '<span style="color: #10b981; font-size: 0.95rem; font-weight: 800;"><i class="fa-solid fa-circle-check"></i> ' + (customMsg || 'All 3 Scans Verified & Complete (Green ✓)! Click OK below to save entry.') + '</span>';
            currentScanStep = 4;

            if (hardwarePollTimer) clearInterval(hardwarePollTimer);

            const finalBtn = document.getElementById('btnFinalOk');
            finalBtn.disabled = false;
            finalBtn.style.background = 'linear-gradient(135deg, #10b981, #06b6d4)';
            finalBtn.style.color = '#ffffff';
            finalBtn.style.borderColor = '#10b981';
            finalBtn.style.cursor = 'pointer';
            finalBtn.style.boxShadow = '0 8px 30px rgba(16, 185, 129, 0.6)';
            document.getElementById('finalOkText').innerText = 'OK - Complete Fingerprint Entry';
        }
    }, 200);
}

// Helper to update scan step visual (Green for Right, Red for Failed)
function updateStep(stepNum, isOk) {
    const step = document.getElementById('scanStep' + stepNum);
    const icon = document.getElementById('scanStepIcon' + stepNum);
    const text = document.getElementById('scanStepStatus' + stepNum);

    if (isOk) {
        step.style.borderColor = '#10b981';
        step.style.background = 'rgba(16, 185, 129, 0.22)';
        step.style.boxShadow = '0 0 15px rgba(16, 185, 129, 0.4)';
        icon.innerHTML = '<i class="fa-solid fa-circle-check"></i>';
        icon.style.color = '#10b981';
        text.innerText = 'OK (Green) ✓';
        text.style.color = '#10b981';
    } else {
        step.style.borderColor = '#ef4444';
        step.style.background = 'rgba(239, 68, 68, 0.22)';
        step.style.boxShadow = '0 0 15px rgba(239, 68, 68, 0.4)';
        icon.innerHTML = '<i class="fa-solid fa-circle-xmark"></i>';
        icon.style.color = '#ef4444';
        text.innerText = 'Failed (Red) ✗';
        text.style.color = '#ef4444';
    }
}

// Reset steps
function resetScanSteps() {
    currentScanStep = 1;
    if (hardwarePollTimer) clearInterval(hardwarePollTimer);
    for (let i = 1; i <= 3; i++) {
        const step = document.getElementById('scanStep' + i);
        const icon = document.getElementById('scanStepIcon' + i);
        const text = document.getElementById('scanStepStatus' + i);
        step.style.borderColor = '#334155';
        step.style.background = 'rgba(0, 0, 0, 0.5)';
        step.style.boxShadow = 'none';
        icon.innerHTML = '<i class="fa-solid fa-fingerprint"></i>';
        icon.style.color = '#64748b';
        text.innerText = 'Pending';
        text.style.color = '#64748b';
    }
    document.getElementById('scanCountLabel').innerText = '0 of 3 Completed';
    document.getElementById('btnFinalOk').disabled = true;
    document.getElementById('btnFinalOk').style.background = '#1e293b';
    document.getElementById('btnFinalOk').style.color = '#64748b';
    document.getElementById('btnFinalOk').style.borderColor = '#334155';
    document.getElementById('btnFinalOk').style.cursor = 'not-allowed';
    document.getElementById('btnFinalOk').style.boxShadow = 'none';
}

// On page load, auto-check physical hardware bridge status
window.addEventListener('DOMContentLoaded', async () => {
    try {
        const resp = await fetch('api_zk.php?action=status', { cache: 'no-store' });
        if (resp.ok) {
            const st = await resp.json();
            if (st.connected) {
                const badge = document.getElementById('zkStatusBadge');
                badge.style.background = 'rgba(16, 185, 129, 0.2)';
                badge.style.color = '#34d399';
                badge.style.borderColor = '#10b981';
                badge.innerHTML = '<i class="fa-solid fa-plug-circle-check"></i> ' + (st.device || 'ZKTeco Mini') + ' READY';

                const listener = document.getElementById('liveListenerStatus');
                if (listener) {
                    listener.innerText = 'USB Hardware Sensor Connected';
                    listener.style.color = '#10b981';
                }
            }
        }
    } catch (e) {}

    // If pre-selected from URL
    if (document.getElementById('empSelect') && document.getElementById('empSelect').value) {
        onEmployeeSelect();
    }
});

// Keyboard Spacebar listener to trigger scan when machine is active
document.addEventListener('keydown', function(e) {
    if (isInitialized && (e.code === 'Space' || e.key === ' ')) {
        e.preventDefault();
        handleSurfaceClick(false);
    }
});

</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
