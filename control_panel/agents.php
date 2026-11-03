<?php
require_once __DIR__ . '/../config/init.php';
requirePermission('agents');

$pdo = getDB();

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

// Handle Agent Creation & Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Security validation failed.');
        header('Location: agents.php');
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'create_agent' && $pdo) {
        $name = sanitize($_POST['name'] ?? '');
        $username = sanitize($_POST['username'] ?? '');
        $location = sanitize($_POST['location'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $authorize_person = sanitize($_POST['authorize_person'] ?? '');
        $rawPass = $_POST['password'] ?? 'agent123';
        $password = password_hash($rawPass, PASSWORD_DEFAULT);
        $email = $username . '@pulsemark.com';

        // Biometric & Face Schedule Configuration
        $biometric_mode = sanitize($_POST['biometric_mode'] ?? 'always_on');
        $bio_start_hour = sanitize($_POST['biometric_start_hour'] ?? '');
        $bio_start_min = sanitize($_POST['biometric_start_min'] ?? '');
        $bio_start_ampm = sanitize($_POST['biometric_start_ampm'] ?? '');
        $biometric_start = (!empty($bio_start_hour) && $bio_start_min !== '' && !empty($bio_start_ampm)) 
            ? formatTime12to24($bio_start_hour, $bio_start_min, $bio_start_ampm) 
            : sanitize($_POST['biometric_start'] ?? '09:00:00');

        $bio_end_hour = sanitize($_POST['biometric_end_hour'] ?? '');
        $bio_end_min = sanitize($_POST['biometric_end_min'] ?? '');
        $bio_end_ampm = sanitize($_POST['biometric_end_ampm'] ?? '');
        $biometric_end = (!empty($bio_end_hour) && $bio_end_min !== '' && !empty($bio_end_ampm)) 
            ? formatTime12to24($bio_end_hour, $bio_end_min, $bio_end_ampm) 
            : sanitize($_POST['biometric_end'] ?? '18:00:00');

        $face_mode = sanitize($_POST['face_mode'] ?? 'always_on');
        $face_start_hour = sanitize($_POST['face_start_hour'] ?? '');
        $face_start_min = sanitize($_POST['face_start_min'] ?? '');
        $face_start_ampm = sanitize($_POST['face_start_ampm'] ?? '');
        $face_start = (!empty($face_start_hour) && $face_start_min !== '' && !empty($face_start_ampm)) 
            ? formatTime12to24($face_start_hour, $face_start_min, $face_start_ampm) 
            : sanitize($_POST['face_start'] ?? '09:00:00');

        $face_end_hour = sanitize($_POST['face_end_hour'] ?? '');
        $face_end_min = sanitize($_POST['face_end_min'] ?? '');
        $face_end_ampm = sanitize($_POST['face_end_ampm'] ?? '');
        $face_end = (!empty($face_end_hour) && $face_end_min !== '' && !empty($face_end_ampm)) 
            ? formatTime12to24($face_end_hour, $face_end_min, $face_end_ampm) 
            : sanitize($_POST['face_end'] ?? '18:00:00');

        // Check if username already exists
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM `users` WHERE `username` = ?");
        $checkStmt->execute([$username]);
        if ($checkStmt->fetchColumn() > 0) {
            setFlash('danger', "Username '$username' is already registered!");
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO `users` (`name`, `username`, `email`, `phone`, `password`, `role`, `location`, `authorize_person`, `biometric_mode`, `biometric_start`, `biometric_end`, `face_mode`, `face_start`, `face_end`, `status`) VALUES (?, ?, ?, ?, ?, 'agent', ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                $stmt->execute([$name, $username, $email, $phone, $password, $location, $authorize_person, $biometric_mode, $biometric_start, $biometric_end, $face_mode, $face_start, $face_end]);
                
                // Assign a starter onboarding task to new agent
                $newAgentId = $pdo->lastInsertId();
                if ($newAgentId) {
                    $taskStmt = $pdo->prepare("INSERT INTO `tasks` (`agent_id`, `title`, `description`, `priority`, `status`, `due_date`) VALUES (?, ?, ?, 'Medium', 'Pending', ?)");
                    $taskStmt->execute([$newAgentId, 'Complete Agent Onboarding & Verification', 'Review system guidelines and setup pulse diagnostics tool.', date('Y-m-d', strtotime('+7 days'))]);
                }

                setFlash('success', "Agent account '$name' (@$username) created successfully with terminal attendance schedules!");
            } catch (Exception $e) {
                setFlash('danger', 'Error creating agent: ' . $e->getMessage());
            }
        }
        header('Location: agents.php');
        exit;
    }

    if ($action === 'toggle_status' && $pdo) {
        $agentId = (int)($_POST['agent_id'] ?? 0);
        $newStatus = sanitize($_POST['status'] ?? 'active');
        try {
            $stmt = $pdo->prepare("UPDATE `users` SET `status` = ? WHERE `id` = ? AND `role` = 'agent'");
            $stmt->execute([$newStatus, $agentId]);
            setFlash('success', "Agent account status changed to $newStatus.");
        } catch (Exception $e) {
            setFlash('danger', 'Error updating status: ' . $e->getMessage());
        }
        header('Location: agents.php');
        exit;
    }

    if ($action === 'update_agent' && $pdo) {
        $agentId = (int)($_POST['agent_id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $username = sanitize($_POST['username'] ?? '');
        $location = sanitize($_POST['location'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $authorize_person = sanitize($_POST['authorize_person'] ?? '');
        $password = trim($_POST['password'] ?? '');

        // Biometric & Face Schedule Configuration
        $biometric_mode = sanitize($_POST['biometric_mode'] ?? 'always_on');
        $bio_start_hour = sanitize($_POST['biometric_start_hour'] ?? '');
        $bio_start_min = sanitize($_POST['biometric_start_min'] ?? '');
        $bio_start_ampm = sanitize($_POST['biometric_start_ampm'] ?? '');
        $biometric_start = (!empty($bio_start_hour) && $bio_start_min !== '' && !empty($bio_start_ampm)) 
            ? formatTime12to24($bio_start_hour, $bio_start_min, $bio_start_ampm) 
            : sanitize($_POST['biometric_start'] ?? '09:00:00');

        $bio_end_hour = sanitize($_POST['biometric_end_hour'] ?? '');
        $bio_end_min = sanitize($_POST['biometric_end_min'] ?? '');
        $bio_end_ampm = sanitize($_POST['biometric_end_ampm'] ?? '');
        $biometric_end = (!empty($bio_end_hour) && $bio_end_min !== '' && !empty($bio_end_ampm)) 
            ? formatTime12to24($bio_end_hour, $bio_end_min, $bio_end_ampm) 
            : sanitize($_POST['biometric_end'] ?? '18:00:00');

        $face_mode = sanitize($_POST['face_mode'] ?? 'always_on');
        $face_start_hour = sanitize($_POST['face_start_hour'] ?? '');
        $face_start_min = sanitize($_POST['face_start_min'] ?? '');
        $face_start_ampm = sanitize($_POST['face_start_ampm'] ?? '');
        $face_start = (!empty($face_start_hour) && $face_start_min !== '' && !empty($face_start_ampm)) 
            ? formatTime12to24($face_start_hour, $face_start_min, $face_start_ampm) 
            : sanitize($_POST['face_start'] ?? '09:00:00');

        $face_end_hour = sanitize($_POST['face_end_hour'] ?? '');
        $face_end_min = sanitize($_POST['face_end_min'] ?? '');
        $face_end_ampm = sanitize($_POST['face_end_ampm'] ?? '');
        $face_end = (!empty($face_end_hour) && $face_end_min !== '' && !empty($face_end_ampm)) 
            ? formatTime12to24($face_end_hour, $face_end_min, $face_end_ampm) 
            : sanitize($_POST['face_end'] ?? '18:00:00');

        // Check if username taken by another account
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM `users` WHERE `username` = ? AND `id` != ?");
        $checkStmt->execute([$username, $agentId]);
        if ($checkStmt->fetchColumn() > 0) {
            setFlash('danger', "Username '$username' is already taken by another account!");
        } else {
            try {
                if (!empty($password)) {
                    $hashPass = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE `users` SET `name` = ?, `username` = ?, `location` = ?, `phone` = ?, `authorize_person` = ?, `biometric_mode` = ?, `biometric_start` = ?, `biometric_end` = ?, `face_mode` = ?, `face_start` = ?, `face_end` = ?, `password` = ? WHERE `id` = ? AND `role` = 'agent'");
                    $stmt->execute([$name, $username, $location, $phone, $authorize_person, $biometric_mode, $biometric_start, $biometric_end, $face_mode, $face_start, $face_end, $hashPass, $agentId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE `users` SET `name` = ?, `username` = ?, `location` = ?, `phone` = ?, `authorize_person` = ?, `biometric_mode` = ?, `biometric_start` = ?, `biometric_end` = ?, `face_mode` = ?, `face_start` = ?, `face_end` = ? WHERE `id` = ? AND `role` = 'agent'");
                    $stmt->execute([$name, $username, $location, $phone, $authorize_person, $biometric_mode, $biometric_start, $biometric_end, $face_mode, $face_start, $face_end, $agentId]);
                }
                setFlash('success', "Agent account '$name' updated successfully with terminal attendance settings!");
            } catch (Exception $e) {
                setFlash('danger', 'Error updating agent: ' . $e->getMessage());
            }
        }
        header('Location: agents.php');
        exit;
    }

    if ($action === 'delete_agent' && $pdo) {
        $agentId = (int)($_POST['agent_id'] ?? 0);
        try {
            // Clean up related records
            $pdo->prepare("DELETE FROM `employee_agent_permissions` WHERE `agent_id` = ?")->execute([$agentId]);
            $pdo->prepare("DELETE FROM `tasks` WHERE `agent_id` = ?")->execute([$agentId]);
            $pdo->prepare("UPDATE `employees` SET `agent_id` = NULL WHERE `agent_id` = ?")->execute([$agentId]);
            $stmt = $pdo->prepare("DELETE FROM `users` WHERE `id` = ? AND `role` = 'agent'");
            $stmt->execute([$agentId]);
            setFlash('success', 'Agent account deleted successfully.');
        } catch (Exception $e) {
            setFlash('danger', 'Error deleting agent: ' . $e->getMessage());
        }
        header('Location: agents.php');
        exit;
    }
}

// Fetch all Agents
$agents = [];
$employees = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM `tasks` WHERE `agent_id` = u.id) as task_count FROM `users` u WHERE u.role = 'agent' ORDER BY u.id DESC");
        $agents = $stmt->fetchAll();

        // Fetch employees for Authorize Person dropdown
        $empStmt = $pdo->query("SELECT `id`, `emp_id`, `name`, `designation` FROM `employees` ORDER BY `name` ASC");
        $employees = $empStmt ? $empStmt->fetchAll() : [];
    } catch (Exception $e) {
        $agents = [];
        $employees = [];
    }
}

$flash = getFlash();

$pageTitle = 'Agent Management';
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

        <div class="page-header">
            <div>
                <h1 class="page-title">Agent User Management</h1>
                <p class="page-subtitle">Create and authorize Agent accounts for the user portal (Agent Workspace)</p>
            </div>
            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <button class="btn btn-primary" data-modal-target="createAgentModal">
                    <i class="fa-solid fa-user-plus"></i>
                    <span>Create New Agent</span>
                </button>
            </div>
        </div>

        <!-- Agents List Table -->
        <div class="glass-panel" style="padding: 24px;">
            <div class="card-title-bar">
                <div class="nav-search" style="width: 320px;">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" class="table-search-input" data-target-table="agentsTable" placeholder="Search by name, username, phone...">
                </div>

                <div style="font-size: 0.88rem; color: var(--text-muted);">
                    Total Agents: <strong style="color: var(--primary);"><?= count($agents) ?></strong>
                </div>
            </div>

            <div class="table-container">
                <table class="custom-table" id="agentsTable">
                    <thead>
                        <tr>
                            <th>Agent Name</th>
                            <th>Username</th>
                            <th>Location</th>
                            <th>Phone Number</th>
                            <th>Authorize Person</th>
                            <th>Terminal Attendance Modes</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($agents)): ?>
                            <?php foreach ($agents as $ag): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div class="avatar" style="background: linear-gradient(135deg, #10b981, #06b6d4);">
                                                <?= strtoupper(substr($ag['name'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 600;"><?= htmlspecialchars($ag['name']) ?></div>
                                                <div style="font-size: 0.78rem; color: var(--text-muted);">ID: #<?= $ag['id'] ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <code style="font-weight: 700; color: var(--primary); font-size: 0.9rem;">@<?= htmlspecialchars($ag['username']) ?></code>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($ag['location'] ?: '-') ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($ag['phone'] ?: '-') ?>
                                    </td>
                                    <td>
                                        <span class="badge" style="background: rgba(99, 102, 241, 0.15); color: #818cf8; border: 1px solid rgba(99, 102, 241, 0.3);">
                                            <?= htmlspecialchars($ag['authorize_person'] ?: 'None') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: 6px;">
                                            <!-- Biometric Service Badge -->
                                            <?php 
                                            $bioMode = $ag['biometric_mode'] ?? 'always_on';
                                            $bioStart = date('h:i A', strtotime($ag['biometric_start'] ?? '09:00:00'));
                                            $bioEnd = date('h:i A', strtotime($ag['biometric_end'] ?? '18:00:00'));
                                            ?>
                                            <?php if ($bioMode === 'always_on'): ?>
                                                <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.35); font-size: 0.72rem; display: inline-flex; align-items: center; gap: 5px; width: fit-content;">
                                                    <i class="fa-solid fa-fingerprint"></i> Bio: 24/7 Active
                                                </span>
                                            <?php elseif ($bioMode === 'scheduled'): ?>
                                                <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.35); font-size: 0.72rem; display: inline-flex; align-items: center; gap: 5px; width: fit-content;">
                                                    <i class="fa-solid fa-clock"></i> Bio: <?= $bioStart ?> - <?= $bioEnd ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.35); font-size: 0.72rem; display: inline-flex; align-items: center; gap: 5px; width: fit-content;">
                                                    <i class="fa-solid fa-ban"></i> Bio: Disabled
                                                </span>
                                            <?php endif; ?>

                                            <!-- Face Recognition Badge -->
                                            <?php 
                                            $faceMode = $ag['face_mode'] ?? 'always_on';
                                            $faceStart = date('h:i A', strtotime($ag['face_start'] ?? '09:00:00'));
                                            $faceEnd = date('h:i A', strtotime($ag['face_end'] ?? '18:00:00'));
                                            ?>
                                            <?php if ($faceMode === 'always_on'): ?>
                                                <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.35); font-size: 0.72rem; display: inline-flex; align-items: center; gap: 5px; width: fit-content;">
                                                    <i class="fa-solid fa-camera"></i> Face: 24/7 Active
                                                </span>
                                            <?php elseif ($faceMode === 'scheduled'): ?>
                                                <span class="badge" style="background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.35); font-size: 0.72rem; display: inline-flex; align-items: center; gap: 5px; width: fit-content;">
                                                    <i class="fa-solid fa-clock"></i> Face: <?= $faceStart ?> - <?= $faceEnd ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.35); font-size: 0.72rem; display: inline-flex; align-items: center; gap: 5px; width: fit-content;">
                                                    <i class="fa-solid fa-ban"></i> Face: Disabled
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (($ag['status'] ?? 'active') === 'active'): ?>
                                            <span class="badge badge-normal"><i class="fa-solid fa-circle fa-xs"></i> Active</span>
                                        <?php else: ?>
                                            <span class="badge badge-critical"><i class="fa-solid fa-circle fa-xs"></i> Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; gap: 6px;">
                                            <!-- Toggle Status -->
                                            <form action="agents.php" method="POST" style="margin: 0;">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="agent_id" value="<?= $ag['id'] ?>">
                                                <input type="hidden" name="status" value="<?= ($ag['status'] ?? 'active') === 'active' ? 'inactive' : 'active' ?>">
                                                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                                <button type="submit" class="btn btn-secondary btn-sm" title="<?= ($ag['status'] ?? 'active') === 'active' ? 'Deactivate' : 'Activate' ?>">
                                                    <i class="fa-solid <?= ($ag['status'] ?? 'active') === 'active' ? 'fa-ban' : 'fa-check' ?>"></i>
                                                </button>
                                            </form>

                                            <!-- Edit Agent -->
                                            <button type="button" class="btn btn-secondary btn-sm edit-agent-btn" 
                                                data-agent='<?= json_encode($ag, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>' 
                                                style="color: var(--primary);" 
                                                title="Edit Agent">
                                                <i class="fa-regular fa-pen-to-square"></i>
                                            </button>

                                            <!-- Delete -->
                                            <form action="agents.php" method="POST" style="margin: 0;">
                                                <input type="hidden" name="action" value="delete_agent">
                                                <input type="hidden" name="agent_id" value="<?= $ag['id'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                                <button type="button" onclick="confirmDeleteAgent(event, this.form, '<?= htmlspecialchars(addslashes($ag['name'])) ?>')" class="btn btn-danger btn-sm" title="Delete Account">
                                                    <i class="fa-regular fa-trash-can"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                    No agents created yet. Click "Create New Agent" above to create an agent!
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

<!-- Create Agent Modal -->
<div class="modal-backdrop" id="createAgentModal">
    <div class="modal-box" style="max-width: 680px;">
        <div class="modal-header">
            <h3 style="font-size: 1.15rem; font-weight: 700;">Create New Agent / Terminal Point</h3>
            <button class="close-modal">&times;</button>
        </div>
        <form action="agents.php" method="POST">
            <input type="hidden" name="action" value="create_agent">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <div class="modal-body" style="max-height: 75vh; overflow-y: auto;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Point / Agent Name *</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Dhaka Main Point" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Username *</label>
                        <input type="text" name="username" class="form-control" placeholder="e.g. agent_dhaka" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Location</label>
                        <input type="text" name="location" class="form-control" placeholder="e.g. Corporate 12th Floor">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="phone" class="form-control" placeholder="e.g. 01700000000">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Authorize Person</label>
                        <select name="authorize_person" class="form-control">
                            <option value="">-- Select Authorize Person --</option>
                            <?php if (!empty($employees)): ?>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= htmlspecialchars($emp['emp_id'] . ' - ' . $emp['name']) ?>">
                                        <?= htmlspecialchars($emp['emp_id'] . ' - ' . $emp['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>No employees registered in Employee directory</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password *</label>
                        <div style="position: relative; display: flex; align-items: center;">
                            <input type="password" name="password" id="agentPasswordInput" class="form-control" placeholder="Enter Password" required style="padding-right: 42px;">
                            <button type="button" id="toggleAgentPassword" style="position: absolute; right: 14px; background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 0; display: flex; align-items: center; justify-content: center; font-size: 1rem;" title="Show/Hide Password">
                                <i class="fa-regular fa-eye" id="agentEyeIcon"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Terminal Point Attendance Capabilities & Scheduling -->
                <div style="background: rgba(15, 23, 42, 0.6); border: 1.5px solid rgba(56, 189, 248, 0.25); border-radius: 14px; padding: 18px; margin-top: 10px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid rgba(255, 255, 255, 0.08);">
                        <div style="font-weight: 700; color: #38bdf8; font-size: 0.95rem; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-sliders"></i> Terminal Attendance Modes & Operating Schedule
                        </div>
                        <span style="font-size: 0.75rem; color: #94a3b8;">Biometric & Face Rules</span>
                    </div>

                    <!-- 1. Biometric Fingerprint Configuration -->
                    <div style="background: rgba(30, 41, 59, 0.5); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 10px; padding: 14px; margin-bottom: 16px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">
                            <label style="font-weight: 700; color: #34d399; font-size: 0.88rem; display: flex; align-items: center; gap: 6px; margin: 0;">
                                <i class="fa-solid fa-fingerprint"></i> Biometric Fingerprint Service
                            </label>
                            <select name="biometric_mode" id="create_biometric_mode" class="form-control" style="width: 230px; padding: 6px 10px; font-size: 0.84rem;" onchange="toggleScheduleBox('create_biometric')">
                                <option value="always_on">✓ Always Active (24/7)</option>
                                <option value="scheduled">⏱ Time Scheduled (Custom Window)</option>
                                <option value="disabled">✕ Disabled / Blocked</option>
                            </select>
                        </div>

                        <div id="create_biometric_schedule_box" style="display: none; padding-top: 10px; border-top: 1px dashed var(--border-color);">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                                <div>
                                    <label style="font-size: 0.78rem; color: var(--text-muted); display: block; margin-bottom: 4px;">Fingerprint Start (Active From)</label>
                                    <div style="display: flex; gap: 6px; align-items: center;">
                                        <select name="biometric_start_hour" id="create_bio_start_hour" class="form-control form-control-sm"></select>
                                        <span style="color: var(--text-dim);">:</span>
                                        <select name="biometric_start_min" id="create_bio_start_min" class="form-control form-control-sm"></select>
                                        <select name="biometric_start_ampm" id="create_bio_start_ampm" class="form-control form-control-sm">
                                            <option value="AM" selected>AM</option>
                                            <option value="PM">PM</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label style="font-size: 0.78rem; color: var(--text-muted); display: block; margin-bottom: 4px;">Fingerprint End (Active Till)</label>
                                    <div style="display: flex; gap: 6px; align-items: center;">
                                        <select name="biometric_end_hour" id="create_bio_end_hour" class="form-control form-control-sm"></select>
                                        <span style="color: var(--text-dim);">:</span>
                                        <select name="biometric_end_min" id="create_bio_end_min" class="form-control form-control-sm"></select>
                                        <select name="biometric_end_ampm" id="create_bio_end_ampm" class="form-control form-control-sm">
                                            <option value="AM">AM</option>
                                            <option value="PM" selected>PM</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. AI Face Recognition Configuration -->
                    <div style="background: var(--bg-surface-elevated); border: 1px solid rgba(56, 189, 248, 0.25); border-radius: 10px; padding: 14px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">
                            <label style="font-weight: 700; color: #38bdf8; font-size: 0.88rem; display: flex; align-items: center; gap: 6px; margin: 0;">
                                <i class="fa-solid fa-camera"></i> AI Face Recognition Service
                            </label>
                            <select name="face_mode" id="create_face_mode" class="form-control" style="width: 230px; padding: 6px 10px; font-size: 0.84rem;" onchange="toggleScheduleBox('create_face')">
                                <option value="always_on">✓ Always Active (24/7)</option>
                                <option value="scheduled">⏱ Time Scheduled (Custom Window)</option>
                                <option value="disabled">✕ Disabled / Blocked</option>
                            </select>
                        </div>

                        <div id="create_face_schedule_box" style="display: none; padding-top: 10px; border-top: 1px dashed var(--border-color);">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                                <div>
                                    <label style="font-size: 0.78rem; color: var(--text-muted); display: block; margin-bottom: 4px;">Face Start (Active From)</label>
                                    <div style="display: flex; gap: 6px; align-items: center;">
                                        <select name="face_start_hour" id="create_face_start_hour" class="form-control form-control-sm"></select>
                                        <span style="color: var(--text-dim);">:</span>
                                        <select name="face_start_min" id="create_face_start_min" class="form-control form-control-sm"></select>
                                        <select name="face_start_ampm" id="create_face_start_ampm" class="form-control form-control-sm">
                                            <option value="AM" selected>AM</option>
                                            <option value="PM">PM</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label style="font-size: 0.78rem; color: var(--text-muted); display: block; margin-bottom: 4px;">Face End (Active Till)</label>
                                    <div style="display: flex; gap: 6px; align-items: center;">
                                        <select name="face_end_hour" id="create_face_end_hour" class="form-control form-control-sm"></select>
                                        <span style="color: var(--text-dim);">:</span>
                                        <select name="face_end_min" id="create_face_end_min" class="form-control form-control-sm"></select>
                                        <select name="face_end_ampm" id="create_face_end_ampm" class="form-control form-control-sm">
                                            <option value="AM">AM</option>
                                            <option value="PM" selected>PM</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Create Agent Account</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Agent Modal -->
<div class="modal-backdrop" id="editAgentModal">
    <div class="modal-box" style="max-width: 680px;">
        <div class="modal-header">
            <h3 style="font-size: 1.15rem; font-weight: 700;">Edit Agent & Terminal Schedules</h3>
            <button class="close-modal">&times;</button>
        </div>
        <form action="agents.php" method="POST">
            <input type="hidden" name="action" value="update_agent">
            <input type="hidden" name="agent_id" id="edit_agent_id">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <div class="modal-body" style="max-height: 75vh; overflow-y: auto;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Point / Agent Name *</label>
                        <input type="text" name="name" id="edit_agent_name" class="form-control" placeholder="Enter Full Name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Username *</label>
                        <input type="text" name="username" id="edit_agent_username" class="form-control" placeholder="Enter Username" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Location</label>
                        <input type="text" name="location" id="edit_agent_location" class="form-control" placeholder="Enter Location">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="phone" id="edit_agent_phone" class="form-control" placeholder="Enter Phone Number">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Authorize Person</label>
                        <select name="authorize_person" id="edit_agent_authorize" class="form-control">
                            <option value="">-- Select Authorize Person --</option>
                            <?php if (!empty($employees)): ?>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= htmlspecialchars($emp['emp_id'] . ' - ' . $emp['name']) ?>">
                                        <?= htmlspecialchars($emp['emp_id'] . ' - ' . $emp['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>No employees registered in Employee directory</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password (Optional)</label>
                        <div style="position: relative; display: flex; align-items: center;">
                            <input type="password" name="password" id="editAgentPasswordInput" class="form-control" placeholder="Leave blank to keep current" style="padding-right: 42px;">
                            <button type="button" id="toggleEditAgentPassword" style="position: absolute; right: 14px; background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 0; display: flex; align-items: center; justify-content: center; font-size: 1rem;" title="Show/Hide Password">
                                <i class="fa-regular fa-eye" id="editAgentEyeIcon"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Terminal Point Attendance Capabilities & Scheduling -->
                <div style="background: rgba(15, 23, 42, 0.6); border: 1.5px solid rgba(56, 189, 248, 0.25); border-radius: 14px; padding: 18px; margin-top: 10px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid rgba(255, 255, 255, 0.08);">
                        <div style="font-weight: 700; color: #38bdf8; font-size: 0.95rem; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-sliders"></i> Terminal Attendance Modes & Operating Schedule
                        </div>
                        <span style="font-size: 0.75rem; color: #94a3b8;">Biometric & Face Rules</span>
                    </div>

                    <!-- 1. Biometric Fingerprint Configuration -->
                    <div style="background: rgba(30, 41, 59, 0.5); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 10px; padding: 14px; margin-bottom: 16px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">
                            <label style="font-weight: 700; color: #34d399; font-size: 0.88rem; display: flex; align-items: center; gap: 6px; margin: 0;">
                                <i class="fa-solid fa-fingerprint"></i> Biometric Fingerprint Service
                            </label>
                            <select name="biometric_mode" id="edit_biometric_mode" class="form-control" style="width: 230px; padding: 6px 10px; font-size: 0.84rem;" onchange="toggleScheduleBox('edit_biometric')">
                                <option value="always_on">✓ Always Active (24/7)</option>
                                <option value="scheduled">⏱ Time Scheduled (Custom Window)</option>
                                <option value="disabled">✕ Disabled / Blocked</option>
                            </select>
                        </div>

                        <div id="edit_biometric_schedule_box" style="display: none; padding-top: 10px; border-top: 1px dashed var(--border-color);">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                                <div>
                                    <label style="font-size: 0.78rem; color: var(--text-muted); display: block; margin-bottom: 4px;">Fingerprint Start (Active From)</label>
                                    <div style="display: flex; gap: 6px; align-items: center;">
                                        <select name="biometric_start_hour" id="edit_bio_start_hour" class="form-control form-control-sm"></select>
                                        <span style="color: var(--text-dim);">:</span>
                                        <select name="biometric_start_min" id="edit_bio_start_min" class="form-control form-control-sm"></select>
                                        <select name="biometric_start_ampm" id="edit_bio_start_ampm" class="form-control form-control-sm">
                                            <option value="AM">AM</option>
                                            <option value="PM">PM</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label style="font-size: 0.78rem; color: var(--text-muted); display: block; margin-bottom: 4px;">Fingerprint End (Active Till)</label>
                                    <div style="display: flex; gap: 6px; align-items: center;">
                                        <select name="biometric_end_hour" id="edit_bio_end_hour" class="form-control form-control-sm"></select>
                                        <span style="color: var(--text-dim);">:</span>
                                        <select name="biometric_end_min" id="edit_bio_end_min" class="form-control form-control-sm"></select>
                                        <select name="biometric_end_ampm" id="edit_bio_end_ampm" class="form-control form-control-sm">
                                            <option value="AM">AM</option>
                                            <option value="PM">PM</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. AI Face Recognition Configuration -->
                    <div style="background: var(--bg-surface-elevated); border: 1px solid rgba(56, 189, 248, 0.25); border-radius: 10px; padding: 14px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">
                            <label style="font-weight: 700; color: #38bdf8; font-size: 0.88rem; display: flex; align-items: center; gap: 6px; margin: 0;">
                                <i class="fa-solid fa-camera"></i> AI Face Recognition Service
                            </label>
                            <select name="face_mode" id="edit_face_mode" class="form-control" style="width: 230px; padding: 6px 10px; font-size: 0.84rem;" onchange="toggleScheduleBox('edit_face')">
                                <option value="always_on">✓ Always Active (24/7)</option>
                                <option value="scheduled">⏱ Time Scheduled (Custom Window)</option>
                                <option value="disabled">✕ Disabled / Blocked</option>
                            </select>
                        </div>

                        <div id="edit_face_schedule_box" style="display: none; padding-top: 10px; border-top: 1px dashed var(--border-color);">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                                <div>
                                    <label style="font-size: 0.78rem; color: var(--text-muted); display: block; margin-bottom: 4px;">Face Start (Active From)</label>
                                    <div style="display: flex; gap: 6px; align-items: center;">
                                        <select name="face_start_hour" id="edit_face_start_hour" class="form-control form-control-sm"></select>
                                        <span style="color: var(--text-dim);">:</span>
                                        <select name="face_start_min" id="edit_face_start_min" class="form-control form-control-sm"></select>
                                        <select name="face_start_ampm" id="edit_face_start_ampm" class="form-control form-control-sm">
                                            <option value="AM">AM</option>
                                            <option value="PM">PM</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label style="font-size: 0.78rem; color: var(--text-muted); display: block; margin-bottom: 4px;">Face End (Active Till)</label>
                                    <div style="display: flex; gap: 6px; align-items: center;">
                                        <select name="face_end_hour" id="edit_face_end_hour" class="form-control form-control-sm"></select>
                                        <span style="color: var(--text-dim);">:</span>
                                        <select name="face_end_min" id="edit_face_end_min" class="form-control form-control-sm"></select>
                                        <select name="face_end_ampm" id="edit_face_end_ampm" class="form-control form-control-sm">
                                            <option value="AM">AM</option>
                                            <option value="PM">PM</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Update Agent Account</button>
            </div>
        </form>
    </div>
</div>

<script>
// Helper to populate time dropdown options
function populateTimeSelectOptions(hourSelectId, minSelectId, defaultHour = '09', defaultMin = '00') {
    const hSelect = document.getElementById(hourSelectId);
    const mSelect = document.getElementById(minSelectId);
    if (!hSelect || !mSelect) return;

    hSelect.innerHTML = '';
    for (let i = 1; i <= 12; i++) {
        const val = String(i).padStart(2, '0');
        const opt = document.createElement('option');
        opt.value = val;
        opt.textContent = val;
        if (val === String(defaultHour).padStart(2, '0')) opt.selected = true;
        hSelect.appendChild(opt);
    }

    mSelect.innerHTML = '';
    for (let i = 0; i < 60; i += 5) {
        const val = String(i).padStart(2, '0');
        const opt = document.createElement('option');
        opt.value = val;
        opt.textContent = val;
        if (val === String(defaultMin).padStart(2, '0')) opt.selected = true;
        mSelect.appendChild(opt);
    }
}

// Convert 24-hour HH:MM:SS to 12-hour object {hour, min, ampm}
function parse24to12(timeStr) {
    if (!timeStr) return { hour: '09', min: '00', ampm: 'AM' };
    const parts = timeStr.split(':');
    let h = parseInt(parts[0] || '9', 10);
    const m = String(parseInt(parts[1] || '0', 10)).padStart(2, '0');
    let ampm = 'AM';
    if (h >= 12) {
        ampm = 'PM';
        if (h > 12) h -= 12;
    } else if (h === 0) {
        h = 12;
    }
    return { hour: String(h).padStart(2, '0'), min: m, ampm: ampm };
}

// Set time controls from 24h string
function setTimeControls(prefix, timeStr) {
    const parsed = parse24to12(timeStr);
    const hSel = document.getElementById(prefix + '_hour');
    const mSel = document.getElementById(prefix + '_min');
    const aSel = document.getElementById(prefix + '_ampm');
    if (hSel) hSel.value = parsed.hour;
    if (mSel) mSel.value = parsed.min;
    if (aSel) aSel.value = parsed.ampm;
}

// Toggle schedule box visibility based on mode
function toggleScheduleBox(typePrefix) {
    const modeSelect = document.getElementById(typePrefix + '_mode');
    const scheduleBox = document.getElementById(typePrefix + '_schedule_box');
    if (modeSelect && scheduleBox) {
        scheduleBox.style.display = (modeSelect.value === 'scheduled') ? 'block' : 'none';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    // Populate all time dropdowns
    populateTimeSelectOptions('create_bio_start_hour', 'create_bio_start_min', '09', '00');
    populateTimeSelectOptions('create_bio_end_hour', 'create_bio_end_min', '06', '00');
    populateTimeSelectOptions('create_face_start_hour', 'create_face_start_min', '09', '00');
    populateTimeSelectOptions('create_face_end_hour', 'create_face_end_min', '06', '00');

    populateTimeSelectOptions('edit_bio_start_hour', 'edit_bio_start_min', '09', '00');
    populateTimeSelectOptions('edit_bio_end_hour', 'edit_bio_end_min', '06', '00');
    populateTimeSelectOptions('edit_face_start_hour', 'edit_face_start_min', '09', '00');
    populateTimeSelectOptions('edit_face_end_hour', 'edit_face_end_min', '06', '00');

    // Password toggle for create modal
    const toggleBtn = document.getElementById('toggleAgentPassword');
    const passInput = document.getElementById('agentPasswordInput');
    const eyeIcon = document.getElementById('agentEyeIcon');
    if (toggleBtn && passInput && eyeIcon) {
        toggleBtn.addEventListener('click', function (e) {
            e.preventDefault();
            const isPass = passInput.getAttribute('type') === 'password';
            passInput.setAttribute('type', isPass ? 'text' : 'password');
            eyeIcon.className = isPass ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
            eyeIcon.style.color = isPass ? 'var(--primary)' : 'var(--text-muted)';
        });
    }

    // Password toggle for edit modal
    const editToggleBtn = document.getElementById('toggleEditAgentPassword');
    const editPassInput = document.getElementById('editAgentPasswordInput');
    const editEyeIcon = document.getElementById('editAgentEyeIcon');
    if (editToggleBtn && editPassInput && editEyeIcon) {
        editToggleBtn.addEventListener('click', function (e) {
            e.preventDefault();
            const isPass = editPassInput.getAttribute('type') === 'password';
            editPassInput.setAttribute('type', isPass ? 'text' : 'password');
            eyeIcon.className = isPass ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
            eyeIcon.style.color = isPass ? 'var(--primary)' : 'var(--text-muted)';
        });
    }

    // Handle Edit Agent button click
    document.querySelectorAll('.edit-agent-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            try {
                const ag = JSON.parse(this.getAttribute('data-agent'));
                document.getElementById('edit_agent_id').value = ag.id || '';
                document.getElementById('edit_agent_name').value = ag.name || '';
                document.getElementById('edit_agent_username').value = ag.username || '';
                document.getElementById('edit_agent_location').value = ag.location || '';
                document.getElementById('edit_agent_phone').value = ag.phone || '';
                document.getElementById('edit_agent_authorize').value = ag.authorize_person || '';
                document.getElementById('editAgentPasswordInput').value = '';

                // Biometric settings
                const bioMode = ag.biometric_mode || 'always_on';
                document.getElementById('edit_biometric_mode').value = bioMode;
                setTimeControls('edit_bio_start', ag.biometric_start || '09:00:00');
                setTimeControls('edit_bio_end', ag.biometric_end || '18:00:00');
                toggleScheduleBox('edit_biometric');

                // Face settings
                const faceMode = ag.face_mode || 'always_on';
                document.getElementById('edit_face_mode').value = faceMode;
                setTimeControls('edit_face_start', ag.face_start || '09:00:00');
                setTimeControls('edit_face_end', ag.face_end || '18:00:00');
                toggleScheduleBox('edit_face');
                
                const modal = document.getElementById('editAgentModal');
                if (modal) modal.classList.add('active');
            } catch (err) {
                console.error('Error loading agent data:', err);
            }
        });
    });
});

async function confirmDeleteAgent(e, form, name) {
    e.preventDefault();
    const confirmed = await showPulseConfirm({
        title: 'Delete Agent Account?',
        message: 'Are you sure you want to delete agent point <strong>' + name + '</strong>? All terminal access permissions for this agent will be revoked.',
        confirmText: 'Yes, Delete Agent',
        type: 'error'
    });
    if (confirmed) {
        form.submit();
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
