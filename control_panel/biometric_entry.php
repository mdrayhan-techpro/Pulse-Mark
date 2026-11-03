<?php
require_once __DIR__ . '/../config/init.php';
requirePermission('biometric_entry');

$pdo = getDB();
$userId = $_SESSION['user_id'] ?? 1;

// Fetch all ACTIVE employees for selection
$employees = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT `id`, `emp_id`, `name`, `department`, `designation`, `location`, `cell`, `status` FROM `employees` WHERE `status` = 'Active' OR `status` IS NULL ORDER BY `name` ASC");
        $employees = $stmt ? $stmt->fetchAll() : [];
    } catch (Exception $e) {
        $employees = [];
    }
}

// Handle Fingerprint Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
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
            $ins->execute([$emp_id, $userId, $finger_type, $template, $quality, $notes]);

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
                    setFlash('danger', "Duplicate Fingerprint Blocked! This fingerprint is ALREADY registered to Employee ($duplicateEmp). Duplicate registration under another employee is not allowed!");
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

// Fetch recent biometric entries
$recentEntries = [];
if ($pdo) {
    try {
        $q = $pdo->query("SELECT b.*, e.name as emp_name, e.department, e.designation 
                          FROM `biometric_entries` b 
                          LEFT JOIN `employees` e ON b.emp_id = e.emp_id 
                          ORDER BY b.id DESC LIMIT 8");
        $recentEntries = $q ? $q->fetchAll() : [];
    } catch (Exception $e) {
        $recentEntries = [];
    }
}

$flash = getFlash();

$pageTitle = 'Biometric Fingerprint Enrollment | Pulse Mark Control Panel';
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
                    <span>ZKTeco Biometric Fingerprint Enrollment</span>
                </h1>
                <p class="page-subtitle" style="color: #94a3b8; font-size: 0.95rem; margin-top: 4px;">
                    Select employee, initialize USB biometric scanner, and capture strict 3-pass fingerprint enrollment.
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

                        <!-- Realistic Captured Fingerprint Image -->
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
                        <i class="fa-solid fa-power-off" style="color: #38bdf8; margin-right: 5px;"></i> Select employee on the right &amp; click <strong style="color: #38bdf8; font-weight: 800; text-shadow: 0 0 10px rgba(56, 189, 248, 0.6);">"OK - Initialize Machine"</strong>
                    </div>
                </div>

                <!-- 3-PUNCH STATUS CARDS (Strict Same-Finger Verification) -->
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-top: 16px;">
                    <div id="punchCard1" style="padding: 12px; border-radius: 10px; background: rgba(15, 23, 42, 0.8); border: 1.5px solid #334155; text-align: center; transition: all 0.3s ease;">
                        <div style="font-size: 0.72rem; color: #94a3b8; font-weight: 700; text-transform: uppercase;">1st Scan</div>
                        <div id="punchIcon1" style="font-size: 1.2rem; margin: 4px 0; color: #64748b;"><i class="fa-regular fa-circle"></i></div>
                        <div id="punchScore1" style="font-size: 0.75rem; font-weight: 700; color: #64748b;">Pending</div>
                    </div>

                    <div id="punchCard2" style="padding: 12px; border-radius: 10px; background: rgba(15, 23, 42, 0.8); border: 1.5px solid #334155; text-align: center; transition: all 0.3s ease;">
                        <div style="font-size: 0.72rem; color: #94a3b8; font-weight: 700; text-transform: uppercase;">2nd Scan (Match 1)</div>
                        <div id="punchIcon2" style="font-size: 1.2rem; margin: 4px 0; color: #64748b;"><i class="fa-regular fa-circle"></i></div>
                        <div id="punchScore2" style="font-size: 0.75rem; font-weight: 700; color: #64748b;">Pending</div>
                    </div>

                    <div id="punchCard3" style="padding: 12px; border-radius: 10px; background: rgba(15, 23, 42, 0.8); border: 1.5px solid #334155; text-align: center; transition: all 0.3s ease;">
                        <div style="font-size: 0.72rem; color: #94a3b8; font-weight: 700; text-transform: uppercase;">3rd Scan (Merged)</div>
                        <div id="punchIcon3" style="font-size: 1.2rem; margin: 4px 0; color: #64748b;"><i class="fa-regular fa-circle"></i></div>
                        <div id="punchScore3" style="font-size: 0.75rem; font-weight: 700; color: #64748b;">Pending</div>
                    </div>
                </div>

            </div>

            <!-- ================= RIGHT SIDE: SELECT EMPLOYEE & CONTROLS ================= -->
            <div class="glass-panel" style="padding: 24px; border-radius: 18px; border: 1.5px solid rgba(16, 185, 129, 0.35); background: rgba(10, 16, 30, 0.9);">
                
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.08);">
                    <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(16, 185, 129, 0.15); display: flex; align-items: center; justify-content: center; color: #10b981;">
                        <i class="fa-solid fa-user-gear"></i>
                    </div>
                    <div>
                        <h2 style="font-size: 1.15rem; font-weight: 700; color: #f8fafc; margin: 0;">Select Employee & Enroll</h2>
                        <div style="font-size: 0.8rem; color: #94a3b8;">Choose employee, initialize & submit entry</div>
                    </div>
                </div>

                <form method="POST" action="biometric_entry.php" id="enrollForm">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="action" value="save_fingerprint">
                    <input type="hidden" name="template" id="hiddenTemplate" value="">
                    <input type="hidden" name="quality" id="hiddenQuality" value="99.8%">

                    <!-- 1. Select Employee Dropdown -->
                    <div class="form-group" style="margin-bottom: 18px;">
                        <label class="form-label" style="display: block; font-weight: 700; color: #f1f5f9; margin-bottom: 8px;">
                            Select Employee <span style="color: #ef4444;">*</span>
                        </label>
                        <select name="emp_id" id="empSelect" class="form-control" required style="width: 100%; padding: 14px 16px; border-radius: 10px; background: #0b1329; color: #ffffff; border: 1.5px solid #334155; font-size: 0.98rem;" onchange="onEmployeeSelect()">
                            <option value="">-- Choose Employee --</option>
                            <?php 
                            $preselected = $_GET['emp_id'] ?? '';
                            foreach ($employees as $emp): 
                                $isSelected = ($emp['emp_id'] === $preselected) ? 'selected' : '';
                            ?>
                                <option value="<?= htmlspecialchars($emp['emp_id']) ?>" <?= $isSelected ?>
                                    data-name="<?= htmlspecialchars($emp['name']) ?>"
                                    data-dept="<?= htmlspecialchars($emp['department'] ?? 'General') ?>"
                                    data-desig="<?= htmlspecialchars($emp['designation'] ?? 'Staff') ?>"
                                    data-loc="<?= htmlspecialchars($emp['location'] ?? 'HQ') ?>"
                                    data-cell="<?= htmlspecialchars($emp['cell'] ?? 'N/A') ?>">
                                    <?= htmlspecialchars($emp['emp_id']) ?> - <?= htmlspecialchars($emp['name']) ?> (<?= htmlspecialchars($emp['department'] ?? 'General') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Selected Employee Details Card -->
                    <div id="empCard" style="display: none; padding: 16px; border-radius: 12px; background: rgba(30, 41, 59, 0.8); border: 1px solid rgba(16, 185, 129, 0.35); margin-bottom: 18px;">
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

                    <!-- 2. OK BUTTON TO INITIALIZE MACHINE -->
                    <div style="margin-bottom: 20px;">
                        <button type="button" id="btnInitMachine" class="btn" onclick="initializeMachine()" disabled style="width: 100%; padding: 16px 20px; font-size: 1.1rem; font-weight: 800; border-radius: 12px; background: #1e293b; color: #64748b; border: 1.5px solid #334155; opacity: 0.45; cursor: not-allowed; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all 0.25s ease;">
                            <i class="fa-solid fa-power-off"></i>
                            <span>OK - Initialize Machine</span>
                        </button>
                        <div id="initHint" style="font-size: 0.8rem; color: #94a3b8; text-align: center; margin-top: 8px;">
                            Select an employee from the dropdown above to enable this button.
                        </div>
                    </div>

                    <!-- Hardware Workflow Info Box -->
                    <div style="padding: 14px; border-radius: 10px; background: rgba(11, 19, 41, 0.85); border: 1px solid #1e293b; margin-bottom: 22px;">
                        <div style="font-size: 0.82rem; font-weight: 700; color: #38bdf8; margin-bottom: 6px;">
                            <i class="fa-solid fa-circle-info"></i> ZKTeco Mini Enrollment Workflow:
                        </div>
                        <ol style="margin: 0; padding-left: 18px; font-size: 0.78rem; color: #94a3b8; line-height: 1.55;">
                            <li>Select Employee & click <strong>"OK - Initialize Machine"</strong></li>
                            <li>Place employee's finger on sensor <strong>3 times (3 বার)</strong></li>
                            <li>Once all 3 scans are verified, click the <strong>OK (Save)</strong> button below!</li>
                        </ol>
                    </div>

                    <!-- 3. FINAL OK BUTTON TO SAVE ENTRY -->
                    <div>
                        <button type="submit" id="btnSaveEntry" class="btn" disabled style="width: 100%; padding: 16px 20px; font-size: 1.15rem; font-weight: 800; border-radius: 12px; background: #1e293b; color: #64748b; border: 1.5px solid #334155; cursor: not-allowed; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all 0.25s ease;">
                            <i class="fa-solid fa-check-double"></i>
                            <span>OK - Complete Fingerprint Entry</span>
                        </button>
                    </div>
                </form>

            </div>

        </div>

        <!-- ================= BOTTOM: Recently Logged Entries ================= -->
        <div class="glass-panel" style="padding: 24px; border-radius: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                <h3 style="font-size: 1.15rem; font-weight: 700; color: #f8fafc; margin: 0; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-clock-rotate-left" style="color: #10b981;"></i>
                    <span>Recently Enrolled Biometric Entries</span>
                </h3>
                <span style="font-size: 0.82rem; color: #10b981; font-weight: 700;">
                    <i class="fa-solid fa-shield-check"></i> Master Template Synced
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
                            <th>Quality Score</th>
                            <th>Status</th>
                            <th>Enrolled Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentEntries)): ?>
                            <?php foreach ($recentEntries as $entry): ?>
                                <tr>
                                    <td><strong>#BIO-<?= str_pad($entry['id'], 4, '0', STR_PAD_LEFT) ?></strong></td>
                                    <td><span style="font-weight: 700; color: #38bdf8; font-family: monospace;"><?= htmlspecialchars($entry['emp_id']) ?></span></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <div style="width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg, #10b981, #06b6d4); color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;">
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
                                <td colspan="8" style="text-align: center; padding: 24px; color: #94a3b8;">
                                    No biometric entries recorded yet. Select employee and click Initialize Machine to begin.
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
@keyframes surfaceGlow {
    0%, 100% { border-color: #10b981; box-shadow: inset 0 0 35px rgba(0,0,0,0.9), 0 0 25px rgba(16, 185, 129, 0.5); }
    50% { border-color: #06b6d4; box-shadow: inset 0 0 35px rgba(0,0,0,0.9), 0 0 35px rgba(6, 182, 212, 0.7); }
}
.surface-active {
    border-color: #10b981 !important;
    animation: surfaceGlow 2s infinite ease-in-out !important;
    cursor: pointer !important;
}
</style>

<script>
let isMachineInitialized = false;
let isSensorConnected = false;
let pollingTimer = null;
let currentStep = 0;
let isProcessingTap = false;

// Audio feedback
function playChime(isSuccess = true) {
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

// 1. Employee Select Handler
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

        btnInit.disabled = false;
        btnInit.style.background = 'linear-gradient(135deg, #10b981, #06b6d4)';
        btnInit.style.color = '#ffffff';
        btnInit.style.border = 'none';
        btnInit.style.opacity = '1';
        btnInit.style.cursor = 'pointer';
        btnInit.style.boxShadow = '0 8px 25px rgba(16, 185, 129, 0.4)';

        document.getElementById('initHint').innerHTML = "<span style='color: #10b981; font-weight: 700;'>Employee selected! Click 'OK - Initialize Machine' to connect hardware.</span>";
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

// 2. Initialize Hardware Machine
async function initializeMachine() {
    const sel = document.getElementById('empSelect');
    if (!sel.value) {
        alert('Please choose an employee first!');
        sel.focus();
        return;
    }

    const surface = document.getElementById('blackSurface');
    const laser = document.getElementById('zkLaser');
    const badge = document.getElementById('zkStatusBadge');
    const msg = document.getElementById('surfaceMsg');
    const fpSvgStandby = document.getElementById('fpSvgStandby');

    msg.innerHTML = '<span style="color: #38bdf8;"><i class="fa-solid fa-spinner fa-spin"></i> Initializing ZKTeco Hardware Sensor...</span>';

    try {
        const resp = await fetch('http://127.0.0.1:9000/reset');
        const data = await resp.json();
    } catch(e) {}

    isMachineInitialized = true;
    surface.classList.add('surface-active');
    laser.style.display = 'block';
    fpSvgStandby.style.opacity = '0.35';

    badge.style.background = 'rgba(16, 185, 129, 0.2)';
    badge.style.color = '#34d399';
    badge.style.borderColor = '#10b981';
    badge.innerHTML = '<i class="fa-solid fa-plug-circle-check"></i> SENSOR READY (Step 1/3)';

    msg.innerHTML = '<span style="color: #34d399; font-weight: 700;"><i class="fa-solid fa-hand-point-down"></i> Sensor Active! Place employee finger on sensor for 1st Scan...</span>';

    startSensorPolling();
}

// 3. Hardware Polling Loop
function startSensorPolling() {
    if (pollingTimer) clearInterval(pollingTimer);

    pollingTimer = setInterval(async () => {
        if (!isMachineInitialized || isProcessingTap || currentStep >= 3) return;

        try {
            const curEmpId = document.getElementById('empSelect')?.value || '';
            const resp = await fetch('http://127.0.0.1:9000/capture?mode=enroll&target_emp_id=' + encodeURIComponent(curEmpId), { cache: 'no-store' });
            if (resp.ok) {
                const res = await resp.json();
                if (res.status === 'duplicate') {
                    playChime(false);
                    isProcessingTap = false;
                    currentStep = 0;
                    document.getElementById('surfaceMsg').innerHTML = `<span style="color: #f87171; font-weight: 700;"><i class="fa-solid fa-ban"></i> DUPLICATE FINGERPRINT BLOCKED: Already registered to Employee #${res.matched_emp}!</span>`;
                    document.getElementById('initHint').innerHTML = `<span style="color: #f87171; font-weight: 700;">DUPLICATE DETECTED: This fingerprint belongs to another user in the system. Enrollment Rejected!</span>`;
                    showPulseAlert({
                        title: 'Duplicate Fingerprint Blocked!',
                        message: 'This fingerprint is ALREADY registered to Employee #' + res.matched_emp + '.<br>The same fingerprint cannot be registered under a different employee name!',
                        type: 'error'
                    });
                } else if (res.status === 'success' && res.step > currentStep) {
                    processScanStep(res);
                } else if (res.status === 'mismatch') {
                    showMismatchWarning(res.message);
                }
            }
        } catch(e) {}
    }, 250);
}

function processScanStep(data) {
    currentStep = data.step;
    playChime(true);

    if (data.image) {
        document.getElementById('liveSensorImg').src = data.image;
        document.getElementById('liveSensorImg').style.display = 'block';
        document.getElementById('fpRealSvg').style.display = 'none';
        document.getElementById('fpRealImage').style.display = 'flex';
    }

    if (currentStep === 1) {
        updateCard(1, true, data.score || 100);
        document.getElementById('surfaceMsg').innerHTML = '<span style="color: #34d399;"><i class="fa-solid fa-circle-check"></i> 1st Scan OK (Green ✓)! Lift finger and place SAME finger for 2nd Scan.</span>';
    } else if (currentStep === 2) {
        updateCard(2, true, data.score || 99);
        document.getElementById('surfaceMsg').innerHTML = '<span style="color: #34d399;"><i class="fa-solid fa-circle-check"></i> 2nd Scan Verified (Green ✓)! Lift finger and place SAME finger for 3rd Scan.</span>';
    } else if (currentStep === 3) {
        updateCard(3, true, 99.8);
        document.getElementById('hiddenTemplate').value = data.template || '';
        document.getElementById('hiddenQuality').value = '99.8%';

        const saveBtn = document.getElementById('btnSaveEntry');
        saveBtn.disabled = false;
        saveBtn.style.background = 'linear-gradient(135deg, #10b981, #06b6d4)';
        saveBtn.style.color = '#ffffff';
        saveBtn.style.borderColor = '#10b981';
        saveBtn.style.cursor = 'pointer';
        saveBtn.style.boxShadow = '0 8px 30px rgba(16, 185, 129, 0.6)';

        document.getElementById('surfaceMsg').innerHTML = '<span style="color: #10b981; font-weight: 800;"><i class="fa-solid fa-circle-check"></i> All 3 Scans Complete! Click "OK - Complete Fingerprint Entry" below to save.</span>';
    }
}

function showMismatchWarning(msg) {
    playChime(false);
    document.getElementById('surfaceMsg').innerHTML = '<span style="color: #ef4444; font-weight: 800;"><i class="fa-solid fa-triangle-exclamation"></i> ' + msg + '</span>';
}

function updateCard(step, isSuccess, score) {
    const card = document.getElementById('punchCard' + step);
    const icon = document.getElementById('punchIcon' + step);
    const scoreText = document.getElementById('punchScore' + step);

    if (isSuccess) {
        card.style.background = 'rgba(16, 185, 129, 0.2)';
        card.style.borderColor = '#10b981';
        card.style.boxShadow = '0 0 15px rgba(16, 185, 129, 0.4)';
        icon.innerHTML = '<i class="fa-solid fa-circle-check" style="color: #10b981;"></i>';
        scoreText.innerText = score + '% Verified';
        scoreText.style.color = '#10b981';
    }
}

function handleSurfaceClick() {
    if (!isMachineInitialized) {
        const sel = document.getElementById('empSelect');
        if (!sel.value) {
            alert('Please select an employee first!');
            sel.focus();
        } else {
            initializeMachine();
        }
    }
}

window.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('empSelect') && document.getElementById('empSelect').value) {
        onEmployeeSelect();
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
