<?php
require_once __DIR__ . '/../config/init.php';
requirePermission('users');

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Security token mismatch. Please refresh and try again.');
        header('Location: users.php');
        exit;
    }

    $action = $_POST['action'];

    // 1. Create User
    if ($action === 'create_user' && $pdo) {
        $name = sanitize($_POST['name'] ?? '');
        $username = strtolower(sanitize($_POST['username'] ?? ''));
        $email = sanitize($_POST['email'] ?? '');
        $role = sanitize($_POST['role'] ?? 'manager');
        $rawPassword = $_POST['password'] ?? '12345678';
        $password = password_hash($rawPassword, PASSWORD_DEFAULT);
        $selectedPerms = $_POST['permissions'] ?? [];

        if (!in_array($role, ['admin', 'manager', 'supervisor'])) {
            $role = 'manager';
        }

        try {
            // Check username or email collision
            $chk = $pdo->prepare("SELECT id FROM `users` WHERE `username` = ? OR `email` = ?");
            $chk->execute([$username, $email]);
            if ($chk->fetch()) {
                setFlash('danger', "Username '@{$username}' or email '{$email}' already exists.");
                header('Location: users.php');
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO `users` (`name`, `username`, `email`, `password`, `role`, `status`) VALUES (?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$name, $username, $email, $password, $role]);
            $newUserId = (int)$pdo->lastInsertId();

            // Set Permissions
            if ($role === 'admin') {
                $finalPerms = array_keys(AVAILABLE_PERMISSIONS);
            } else {
                $finalPerms = !empty($selectedPerms) ? $selectedPerms : getDefaultRolePermissions($role);
            }
            setUserPermissions($pdo, $newUserId, $finalPerms);

            logAudit($pdo, 'USER_CREATED', 'Authentication', "Created administrative user '{$name}' (@{$username}) with role '{$role}' and " . count($finalPerms) . " permissions.");
            setFlash('success', "User account for '{$name}' created with custom permissions successfully!");
        } catch (Exception $e) {
            setFlash('danger', 'Error creating user: ' . $e->getMessage());
        }
        header('Location: users.php');
        exit;
    }

    // 2. Update Permissions
    if ($action === 'update_permissions' && $pdo) {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $perms = $_POST['permissions'] ?? [];

        if ($targetUserId <= 0) {
            setFlash('danger', 'Invalid user selected.');
            header('Location: users.php');
            exit;
        }

        try {
            $uStmt = $pdo->prepare("SELECT id, name, username, role FROM `users` WHERE `id` = ?");
            $uStmt->execute([$targetUserId]);
            $targetUser = $uStmt->fetch();

            if (!$targetUser) {
                setFlash('danger', 'Target user not found.');
                header('Location: users.php');
                exit;
            }

            if ($targetUser['role'] === 'admin') {
                $perms = array_keys(AVAILABLE_PERMISSIONS);
            }

            setUserPermissions($pdo, $targetUserId, $perms);

            // If the user being edited is currently logged in, update session permissions immediately
            if (($currentUser['id'] ?? 0) === $targetUserId) {
                $_SESSION['user_permissions'] = $perms;
            }

            logAudit($pdo, 'PERMISSION_UPDATED', 'Security', "Updated permissions for user '{$targetUser['name']}' (@{$targetUser['username']}): [" . implode(', ', $perms) . "]");
            setFlash('success', "Permissions for '{$targetUser['name']}' updated successfully!");
        } catch (Exception $e) {
            setFlash('danger', 'Error updating permissions: ' . $e->getMessage());
        }
        header('Location: users.php');
        exit;
    }

    // 3. Delete User
    if ($action === 'delete_user' && $pdo) {
        $targetUserId = (int)($_POST['user_id'] ?? 0);

        if ($targetUserId <= 0) {
            setFlash('danger', 'Invalid user.');
            header('Location: users.php');
            exit;
        }

        if ($targetUserId === 1 || ($currentUser['id'] ?? 0) === $targetUserId) {
            setFlash('danger', 'You cannot delete the primary Super Administrator or your own active account.');
            header('Location: users.php');
            exit;
        }

        try {
            $uStmt = $pdo->prepare("SELECT name, username FROM `users` WHERE `id` = ?");
            $uStmt->execute([$targetUserId]);
            $target = $uStmt->fetch();

            if ($target) {
                $del = $pdo->prepare("DELETE FROM `users` WHERE `id` = ?");
                $del->execute([$targetUserId]);
                logAudit($pdo, 'USER_DELETED', 'Authentication', "Deleted administrative user account '{$target['name']}' (@{$target['username']}).");
                setFlash('success', "User '{$target['name']}' deleted successfully.");
            }
        } catch (Exception $e) {
            setFlash('danger', 'Error deleting user: ' . $e->getMessage());
        }
        header('Location: users.php');
        exit;
    }
}

// Fetch users
$users = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT id, name, username, email, role, status, created_at FROM `users` WHERE `role` != 'agent' ORDER BY id ASC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch all assigned permissions
        $pMap = [];
        $pStmt = $pdo->query("SELECT user_id, permission FROM `user_permissions`");
        while ($r = $pStmt->fetch(PDO::FETCH_ASSOC)) {
            $pMap[$r['user_id']][] = $r['permission'];
        }

        foreach ($users as &$u) {
            if ($u['role'] === 'admin') {
                $u['permissions'] = array_keys(AVAILABLE_PERMISSIONS);
            } else {
                $u['permissions'] = $pMap[$u['id']] ?? [];
            }
        }
    } catch (Exception $e) {
        $users = [];
    }
}

$flash = getFlash();

$pageTitle = 'System Users & Permission Management | Pulse Mark';
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

        <?php if (isset($_GET['denied'])): ?>
            <div class="alert-box alert-danger">
                <i class="fa-solid fa-ban"></i>
                <span><strong>Access Restricted:</strong> You do not possess the required permission privileges to access that section.</span>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header" style="margin-bottom: 24px;">
            <div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <h1 class="page-title" style="margin: 0;">Control Panel Access &amp; Permissions</h1>
                    <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); font-weight: 700;">
                        <i class="fa-solid fa-shield-halved"></i> RBAC Enabled
                    </span>
                </div>
                <p class="page-subtitle" style="margin-top: 4px;">
                    Manage administrative accounts and assign granular module permissions for HR Managers, Supervisors &amp; Admins.
                </p>
            </div>
            <button class="btn btn-primary" data-modal-target="addUserModal">
                <i class="fa-solid fa-user-plus"></i>
                <span>Add System User</span>
            </button>
        </div>

        <!-- Role Permissions Overview Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-bottom: 24px;">
            <div class="glass-panel" style="padding: 18px 20px; border-left: 4px solid #38bdf8;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                    <span style="font-weight: 800; color: #38bdf8; font-size: 0.95rem;">
                        <i class="fa-solid fa-crown"></i> Administrator
                    </span>
                    <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; font-size: 0.72rem;">Full Unrestricted</span>
                </div>
                <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0; line-height: 1.5;">
                    Access to all 9 system modules, including user permission management, audit trails, and system settings.
                </p>
            </div>

            <div class="glass-panel" style="padding: 18px 20px; border-left: 4px solid #10b981;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                    <span style="font-weight: 800; color: #10b981; font-size: 0.95rem;">
                        <i class="fa-solid fa-user-tie"></i> HR Manager
                    </span>
                    <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; font-size: 0.72rem;">Customizable</span>
                </div>
                <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0; line-height: 1.5;">
                    Configurable access to Employees, Biometric &amp; Face Enrolment, Attendance Reports, and live Dashboard.
                </p>
            </div>

            <div class="glass-panel" style="padding: 18px 20px; border-left: 4px solid #f59e0b;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                    <span style="font-weight: 800; color: #f59e0b; font-size: 0.95rem;">
                        <i class="fa-solid fa-user-check"></i> Supervisor
                    </span>
                    <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; font-size: 0.72rem;">Operational</span>
                </div>
                <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0; line-height: 1.5;">
                    Assigned to monitor attendance logs, shift durations, employee roster, and daily reports.
                </p>
            </div>
        </div>

        <!-- Users Table -->
        <div class="glass-panel" style="padding: 24px;">
            <div class="card-title-bar">
                <h3 class="card-title" style="margin: 0; font-size: 1.15rem;">
                    <i class="fa-solid fa-users" style="color: var(--primary); margin-right: 8px;"></i>
                    System Accounts &amp; Permission Table
                </h3>
                <div class="nav-search" style="width: 280px;">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" class="table-search-input" data-target-table="usersTable" placeholder="Search user, role or email...">
                </div>
            </div>

            <div class="table-container">
                <table class="custom-table" id="usersTable">
                    <thead>
                        <tr>
                            <th>User Name</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th style="min-width: 280px;">Assigned Permissions</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($users)): ?>
                            <?php foreach ($users as $u): ?>
                                <?php
                                $isSuperAdmin = ($u['role'] === 'admin');
                                $userPerms = $u['permissions'] ?? [];
                                $userPermCount = count($userPerms);
                                ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div class="avatar" style="width: 38px; height: 38px; font-size: 0.95rem; font-weight: 800;">
                                                <?= strtoupper(substr($u['name'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 700; color: var(--text-main); font-size: 0.95rem;">
                                                    <?= htmlspecialchars($u['name']) ?>
                                                    <?php if ($u['id'] == ($currentUser['id'] ?? 0)): ?>
                                                        <span style="font-size: 0.7rem; color: #38bdf8; margin-left: 4px; font-weight: 800;">(You)</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div style="font-size: 0.78rem; color: var(--text-muted);"><?= htmlspecialchars($u['email']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <strong style="color: #38bdf8; font-family: monospace; font-size: 0.95rem;">@<?= htmlspecialchars($u['username']) ?></strong>
                                    </td>
                                    <td>
                                        <?php if ($u['role'] === 'admin'): ?>
                                            <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.35); font-weight: 700;">
                                                <i class="fa-solid fa-crown fa-xs"></i> Administrator
                                            </span>
                                        <?php elseif ($u['role'] === 'manager'): ?>
                                            <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.35); font-weight: 700;">
                                                <i class="fa-solid fa-user-tie fa-xs"></i> HR Manager
                                            </span>
                                        <?php elseif ($u['role'] === 'supervisor'): ?>
                                            <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.35); font-weight: 700;">
                                                <i class="fa-solid fa-user-check fa-xs"></i> Supervisor
                                            </span>
                                        <?php else: ?>
                                            <span class="badge" style="background: rgba(99, 102, 241, 0.15); color: #818cf8; text-transform: capitalize; font-weight: 600;">
                                                <?= htmlspecialchars($u['role']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isSuperAdmin): ?>
                                            <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; font-weight: 700; border: 1px solid rgba(56, 189, 248, 0.3);">
                                                <i class="fa-solid fa-lock-open"></i> Full System Access (All 9 Modules)
                                            </span>
                                        <?php else: ?>
                                            <div style="display: flex; flex-wrap: wrap; gap: 5px; align-items: center;">
                                                <?php if ($userPermCount === 0): ?>
                                                    <span style="font-size: 0.78rem; color: #ef4444; font-weight: 600;">
                                                        <i class="fa-solid fa-triangle-exclamation"></i> No permissions assigned
                                                    </span>
                                                <?php else: ?>
                                                    <?php
                                                    $displayed = 0;
                                                    foreach ($userPerms as $pkey):
                                                        if (isset(AVAILABLE_PERMISSIONS[$pkey])):
                                                            if ($displayed < 3):
                                                                $pInfo = AVAILABLE_PERMISSIONS[$pkey];
                                                    ?>
                                                                <span class="badge" style="background: rgba(148, 163, 184, 0.15); color: var(--text-main); font-size: 0.73rem; border: 1px solid rgba(148, 163, 184, 0.25);">
                                                                    <i class="<?= $pInfo['icon'] ?> fa-2xs" style="color: #38bdf8;"></i> <?= $pInfo['name'] ?>
                                                                </span>
                                                    <?php
                                                                $displayed++;
                                                            endif;
                                                        endif;
                                                    endforeach;
                                                    if ($userPermCount > 3):
                                                    ?>
                                                        <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; font-size: 0.73rem; font-weight: 700;">
                                                            +<?= ($userPermCount - 3) ?> more
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-active">
                                            <i class="fa-solid fa-circle fa-xs"></i> <?= ucfirst($u['status'] ?? 'Active') ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; align-items: center; gap: 8px;">
                                            <button type="button" class="btn btn-sm btn-secondary" 
                                                    onclick='openEditPermissions(<?= json_encode($u) ?>)'
                                                    style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;"
                                                    title="Set or customize permissions">
                                                <i class="fa-solid fa-shield-halved" style="color: #38bdf8;"></i>
                                                <span>Permissions</span>
                                            </button>

                                            <?php if ($u['id'] != 1 && $u['id'] != ($currentUser['id'] ?? 0)): ?>
                                                <form action="users.php" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete user @<?= htmlspecialchars($u['username']) ?>?');">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                                    <button type="submit" class="btn btn-sm" style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3);" title="Delete User">
                                                        <i class="fa-solid fa-trash-can"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                    No administrative user accounts found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- =========================================================================
     MODAL 1: ADD NEW USER (WITH INTERACTIVE PERMISSION SELECTOR)
     ========================================================================= -->
<div class="modal-backdrop" id="addUserModal">
    <div class="modal-box" style="max-width: 720px; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="modal-header">
            <div>
                <h3 style="font-size: 1.2rem; font-weight: 700; margin: 0;">Add Control Panel User</h3>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin: 4px 0 0 0;">Create a new administrative user and customize allowed access modules.</p>
            </div>
            <button class="close-modal">&times;</button>
        </div>
        <form action="users.php" method="POST" style="display: flex; flex-direction: column; overflow: hidden; margin: 0;">
            <input type="hidden" name="action" value="create_user">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">

            <div class="modal-body" style="overflow-y: auto; padding: 24px;">
                <!-- Account Basic Info -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div class="form-group" style="margin: 0;">
                        <label class="form-label">Full Name <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Tanvir Ahmed" required>
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <label class="form-label">Username <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="username" class="form-control" placeholder="tanvir" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                    <div class="form-group" style="margin: 0;">
                        <label class="form-label">Email Address <span style="color: #ef4444;">*</span></label>
                        <input type="email" name="email" class="form-control" placeholder="tanvir@pulsemark.com" required>
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <label class="form-label">Initial Password <span style="color: #ef4444;">*</span></label>
                        <input type="password" name="password" class="form-control" value="admin123" required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 24px;">
                    <label class="form-label">Administrative Role</label>
                    <select name="role" id="addRoleSelect" class="form-control" onchange="handleRolePresetChange(this.value, 'add')">
                        <option value="manager" selected>HR Manager (Human Resources &amp; Roster)</option>
                        <option value="supervisor">Supervisor (Floor Operations &amp; Shifts)</option>
                        <option value="admin">Administrator (Full Unrestricted Access)</option>
                    </select>
                </div>

                <!-- Permission Checkbox Matrix -->
                <div style="border-top: 1px solid var(--border-color); padding-top: 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                        <div>
                            <span style="font-weight: 700; font-size: 0.95rem; color: var(--text-main);">
                                <i class="fa-solid fa-key" style="color: #38bdf8; margin-right: 6px;"></i> Module Permissions
                            </span>
                            <span style="font-size: 0.78rem; color: var(--text-muted); display: block;">Select the specific Control Panel areas this user can access.</span>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="selectAllPerms('add', true)" style="font-size: 0.75rem; padding: 4px 10px;">Select All</button>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="selectAllPerms('add', false)" style="font-size: 0.75rem; padding: 4px 10px;">Clear All</button>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px;" id="addPermContainer">
                        <?php foreach (AVAILABLE_PERMISSIONS as $pkey => $pInfo): ?>
                            <label class="perm-card" style="display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border-radius: 10px; background: var(--bg-surface-elevated); border: 1.5px solid var(--border-color); cursor: pointer; transition: all 0.2s ease;">
                                <input type="checkbox" name="permissions[]" value="<?= $pkey ?>" class="add-perm-cb" style="margin-top: 4px; accent-color: #38bdf8; width: 16px; height: 16px;">
                                <div style="flex: 1;">
                                    <div style="display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 0.88rem; color: var(--text-main);">
                                        <i class="<?= $pInfo['icon'] ?>" style="color: #38bdf8;"></i>
                                        <span><?= $pInfo['name'] ?></span>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 3px; line-height: 1.35;">
                                        <?= $pInfo['desc'] ?>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid var(--border-color);">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Create User Account</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     MODAL 2: EDIT PERMISSIONS MODAL
     ========================================================================= -->
<div class="modal-backdrop" id="editPermissionModal">
    <div class="modal-box" style="max-width: 720px; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="modal-header">
            <div>
                <h3 style="font-size: 1.2rem; font-weight: 700; margin: 0;">Manage User Permissions</h3>
                <p id="editPermSubtitle" style="font-size: 0.82rem; color: var(--text-muted); margin: 4px 0 0 0;">
                    Configuring permissions for user...
                </p>
            </div>
            <button class="close-modal">&times;</button>
        </div>
        <form action="users.php" method="POST" style="display: flex; flex-direction: column; overflow: hidden; margin: 0;">
            <input type="hidden" name="action" value="update_permissions">
            <input type="hidden" name="user_id" id="editPermUserId" value="">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">

            <div class="modal-body" style="overflow-y: auto; padding: 24px;">
                <div id="editSuperAdminNotice" style="display: none; padding: 14px 16px; border-radius: 10px; background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.35); color: #38bdf8; font-size: 0.85rem; margin-bottom: 16px;">
                    <i class="fa-solid fa-crown" style="margin-right: 6px;"></i>
                    <strong>Super Administrator:</strong> This account has unrestricted access to all 9 system modules automatically.
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                    <div>
                        <span style="font-weight: 700; font-size: 0.95rem; color: var(--text-main);">Module Access Rights</span>
                        <span style="font-size: 0.78rem; color: var(--text-muted); display: block;">Toggle permissions below to grant or revoke access.</span>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="selectAllPerms('edit', true)" style="font-size: 0.75rem; padding: 4px 10px;">Select All</button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="selectAllPerms('edit', false)" style="font-size: 0.75rem; padding: 4px 10px;">Clear All</button>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px;">
                    <?php foreach (AVAILABLE_PERMISSIONS as $pkey => $pInfo): ?>
                        <label class="perm-card" style="display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border-radius: 10px; background: var(--bg-surface-elevated); border: 1.5px solid var(--border-color); cursor: pointer; transition: all 0.2s ease;">
                            <input type="checkbox" name="permissions[]" value="<?= $pkey ?>" id="edit_perm_<?= $pkey ?>" class="edit-perm-cb" style="margin-top: 4px; accent-color: #38bdf8; width: 16px; height: 16px;">
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 0.88rem; color: var(--text-main);">
                                    <i class="<?= $pInfo['icon'] ?>" style="color: #38bdf8;"></i>
                                    <span><?= $pInfo['name'] ?></span>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 3px; line-height: 1.35;">
                                    <?= $pInfo['desc'] ?>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid var(--border-color);">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save Permissions</button>
            </div>
        </form>
    </div>
</div>

<script>
// Role Default Permission Presets
const ROLE_PRESETS = {
    'admin': <?= json_encode(array_keys(AVAILABLE_PERMISSIONS)) ?>,
    'manager': ['dashboard', 'employee', 'biometric_entry', 'face_entry', 'attendance_report'],
    'supervisor': ['dashboard', 'employee', 'attendance_report']
};

function handleRolePresetChange(role, prefix) {
    const defaultList = ROLE_PRESETS[role] || ['dashboard'];
    selectAllPerms(prefix, false);
    defaultList.forEach(key => {
        const cb = document.querySelector(`.${prefix}-perm-cb[value="${key}"]`);
        if (cb) cb.checked = true;
    });
}

function selectAllPerms(prefix, checked) {
    document.querySelectorAll(`.${prefix}-perm-cb`).forEach(cb => {
        cb.checked = checked;
    });
}

// Open Edit Permissions Modal
function openEditPermissions(user) {
    document.getElementById('editPermUserId').value = user.id;
    document.getElementById('editPermSubtitle').innerHTML = `Configuring permissions for <strong>${user.name}</strong> (@${user.username}) &bull; Role: <span style="text-transform: capitalize; color: #38bdf8;">${user.role}</span>`;
    
    const isSuperAdmin = (user.role === 'admin');
    const notice = document.getElementById('editSuperAdminNotice');
    if (notice) notice.style.display = isSuperAdmin ? 'block' : 'none';

    // Clear and check active user permissions
    selectAllPerms('edit', false);
    const perms = user.permissions || [];
    perms.forEach(pkey => {
        const cb = document.getElementById('edit_perm_' + pkey);
        if (cb) cb.checked = true;
    });

    const modal = document.getElementById('editPermissionModal');
    if (modal) modal.classList.add('active');
}

// Initial Preset on Page Load for Add Modal
window.addEventListener('DOMContentLoaded', () => {
    handleRolePresetChange('manager', 'add');
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
