<?php
/**
 * Pulse Mark - Authentication & Session Helper
 */

require_once __DIR__ . '/db.php';

// Flash Messages
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Sanitization
function sanitize($data) {
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

// CSRF Security
function getCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function generateCsrfToken() {
    return getCsrfToken();
}

function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

function getLoginUrl() {
    if (defined('LOGIN_URL')) {
        return LOGIN_URL;
    }
    return (defined('BASE_URL') ? BASE_URL : '../') . 'login.php';
}

// Defined Permissions for Control Panel
define('AVAILABLE_PERMISSIONS', [
    'dashboard' => [
        'name' => 'Dashboard',
        'desc' => 'Central live metrics, counters & punch charts',
        'icon' => 'fa-solid fa-chart-pie'
    ],
    'employee' => [
        'name' => 'Employee Management',
        'desc' => 'Add, edit, view & delete employee profiles and shifts',
        'icon' => 'fa-solid fa-id-badge'
    ],
    'agents' => [
        'name' => 'Agent Terminals',
        'desc' => 'Configure attendance terminals and assign employees',
        'icon' => 'fa-solid fa-user-shield'
    ],
    'biometric_entry' => [
        'name' => 'Biometric Entry (ZK)',
        'desc' => 'Hardware USB fingerprint scanner enrollment',
        'icon' => 'fa-solid fa-fingerprint'
    ],
    'face_entry' => [
        'name' => 'Face Entry (AI)',
        'desc' => 'High-definition optical camera AI facial enrollment',
        'icon' => 'fa-solid fa-camera'
    ],
    'attendance_report' => [
        'name' => 'Attendance Reports',
        'desc' => 'Daily, monthly reports, shift duration & file exports',
        'icon' => 'fa-solid fa-chart-column'
    ],
    'users' => [
        'name' => 'System Users & Permissions',
        'desc' => 'Manage administrators, HR managers & permission table',
        'icon' => 'fa-solid fa-users-gear'
    ],
    'audit_logs' => [
        'name' => 'Audit Logs',
        'desc' => 'Security audit trail of administrative activities',
        'icon' => 'fa-solid fa-clipboard-list'
    ],
    'settings' => [
        'name' => 'Settings',
        'desc' => 'Grace times, shift rules and system configurations',
        'icon' => 'fa-solid fa-sliders'
    ],
]);

function getDefaultRolePermissions($role) {
    switch ($role) {
        case 'admin':
            return array_keys(AVAILABLE_PERMISSIONS);
        case 'manager':
            return ['dashboard', 'employee', 'biometric_entry', 'face_entry', 'attendance_report'];
        case 'supervisor':
            return ['dashboard', 'employee', 'attendance_report'];
        default:
            return ['dashboard'];
    }
}

function getUserPermissions($pdo, $userId) {
    if (!$pdo || empty($userId)) return [];
    try {
        $stmt = $pdo->prepare("SELECT permission FROM `user_permissions` WHERE `user_id` = ?");
        $stmt->execute([(int)$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

function setUserPermissions($pdo, $userId, array $permissions) {
    if (!$pdo || empty($userId)) return;
    try {
        $del = $pdo->prepare("DELETE FROM `user_permissions` WHERE `user_id` = ?");
        $del->execute([(int)$userId]);

        if (!empty($permissions)) {
            $ins = $pdo->prepare("INSERT IGNORE INTO `user_permissions` (`user_id`, `permission`) VALUES (?, ?)");
            foreach ($permissions as $p) {
                $p = trim($p);
                if (isset(AVAILABLE_PERMISSIONS[$p])) {
                    $ins->execute([(int)$userId, $p]);
                }
            }
        }
    } catch (Exception $e) {
        error_log("Failed to set user permissions: " . $e->getMessage());
    }
}

// General Auth Checks
function isLoggedIn() {
    return isAdminLoggedIn() || isAgentLoggedIn() || isset($_SESSION['user_id']);
}

function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: ' . getLoginUrl());
        exit;
    }
}

// Admin / Control Panel Auth Checks
function isControlPanelUser() {
    return isset($_SESSION['user_id']) && in_array(($_SESSION['user_role'] ?? ''), ['admin', 'manager', 'supervisor']);
}

function isAdminLoggedIn() {
    return isControlPanelUser();
}

function requireAdminAuth() {
    if (!isControlPanelUser()) {
        header('Location: ' . getLoginUrl());
        exit;
    }
}

// Granular Permission Checks
function hasPermission($permissionKey) {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    // Super Administrator has all permissions unconditionally
    if (($_SESSION['user_role'] ?? '') === 'admin') {
        return true;
    }
    $permissions = $_SESSION['user_permissions'] ?? [];
    return in_array($permissionKey, $permissions);
}

function getFirstPermittedPage() {
    $map = [
        'dashboard' => 'dashboard.php',
        'employee' => 'employee.php',
        'agents' => 'agents.php',
        'biometric_entry' => 'biometric_entry.php',
        'face_entry' => 'face_entry.php',
        'attendance_report' => 'attendance_report.php',
        'users' => 'users.php',
        'audit_logs' => 'audit_logs.php',
        'settings' => 'settings.php',
    ];
    foreach ($map as $perm => $page) {
        if (hasPermission($perm)) {
            return $page;
        }
    }
    return 'dashboard.php';
}

function requirePermission($permissionKey) {
    requireAdminAuth();
    if (!hasPermission($permissionKey)) {
        $first = getFirstPermittedPage();
        $currentPage = basename($_SERVER['PHP_SELF']);
        if ($currentPage !== $first) {
            header('Location: ' . CONTROL_PANEL_URL . $first . '?denied=1');
            exit;
        } else {
            http_response_code(403);
            die("Access Denied: You do not have permission to view this section.");
        }
    }
}

// Agent Auth Checks
function isAgentLoggedIn(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return (isset($_SESSION['agent_id']) && !empty($_SESSION['agent_id'])) || (isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'agent']));
}

function isAgentGuestMode(): bool {
    return !isAgentLoggedIn() && !isAdminLoggedIn();
}

function requireAgentAuth(bool $allowGuest = true) {
    if ($allowGuest) {
        return; // Allow viewing in Guest Mode
    }
    if (!isAgentLoggedIn()) {
        if (isAdminLoggedIn()) {
            $_SESSION['agent_id'] = $_SESSION['user_id'] ?? 1;
            $_SESSION['agent_name'] = $_SESSION['user_name'] ?? 'Administrator';
            $_SESSION['agent_role'] = 'agent';
        } else {
            header('Location: ' . getLoginUrl());
            exit;
        }
    }
}
