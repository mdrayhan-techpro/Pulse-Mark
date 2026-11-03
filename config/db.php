<?php
/**
 * Pulse Mark - Database Configuration & Tables Initialization
 */

// Load .env configuration if present
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($k, $v) = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            if (getenv($k) === false) {
                putenv("{$k}={$v}");
                $_ENV[$k] = $v;
            }
        }
    }
}

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'B@nR@y#257');
define('DB_NAME', getenv('DB_NAME') ?: 'pulse_mark');
define('DB_PORT', getenv('DB_PORT') ?: '3306');

date_default_timezone_set('Asia/Dhaka');

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 80) == 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

/**
 * Get PDO Database Connection
 */
function getDB() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // TiDB Cloud & Remote Cloud SSL/TLS support
    $isSsl = (getenv('DB_SSL') === 'true') || ((int)DB_PORT === 4000) || (strpos(DB_HOST, 'tidbcloud.com') !== false);
    if ($isSsl) {
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        $caPaths = ['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt'];
        foreach ($caPaths as $ca) {
            if (file_exists($ca)) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
                break;
            }
        }
    }

    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $pdo->exec("SET time_zone = '+06:00'");
        return $pdo;
    } catch (PDOException $e) {
        // If database doesn't exist, attempt to create it
        if ($e->getCode() == 1049 || strpos($e->getMessage(), 'Unknown database') !== false) {
            try {
                $rootDsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
                $rootPdo = new PDO($rootDsn, DB_USER, DB_PASS, $options);
                $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                $pdo->exec("SET time_zone = '+06:00'");
                initDatabaseTables($pdo);
                return $pdo;
            } catch (Exception $ex) {
                error_log("Database auto-create error: " . $ex->getMessage());
                return null;
            }
        }
        error_log("Database connection error: " . $e->getMessage());
        return null;
    }
}

/**
 * Initialize Default Tables & Demo Seed Data
 */
function initDatabaseTables($pdo) {
    if (!$pdo) return;

    $queries = [
        "CREATE TABLE IF NOT EXISTS `users` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `username` VARCHAR(50) NOT NULL UNIQUE,
            `email` VARCHAR(100) NOT NULL UNIQUE,
            `phone` VARCHAR(30) DEFAULT NULL,
            `password` VARCHAR(255) NOT NULL,
            `role` ENUM('admin', 'agent') DEFAULT 'agent',
            `department` VARCHAR(100) DEFAULT 'Field Operations',
            `avatar` VARCHAR(255) DEFAULT 'default-avatar.png',
            `status` ENUM('active', 'inactive') DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `tasks` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `agent_id` INT NOT NULL,
            `title` VARCHAR(200) NOT NULL,
            `description` TEXT,
            `priority` ENUM('Low', 'Medium', 'High') DEFAULT 'Medium',
            `status` ENUM('Pending', 'In Progress', 'Completed') DEFAULT 'Pending',
            `due_date` DATE DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `patients` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `patient_code` VARCHAR(20) NOT NULL UNIQUE,
            `name` VARCHAR(100) NOT NULL,
            `age` INT NOT NULL,
            `gender` ENUM('Male', 'Female', 'Other') NOT NULL,
            `blood_group` VARCHAR(5) DEFAULT 'O+',
            `phone` VARCHAR(20) NOT NULL,
            `email` VARCHAR(100),
            `address` TEXT,
            `pulse_rate` INT DEFAULT 72,
            `blood_pressure` VARCHAR(20) DEFAULT '120/80',
            `oxygen_level` INT DEFAULT 98,
            `condition_status` ENUM('Normal', 'Attention', 'Critical') DEFAULT 'Normal',
            `created_by_agent` INT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `appointments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `patient_id` INT NOT NULL,
            `doctor_name` VARCHAR(100) NOT NULL,
            `department` VARCHAR(100) DEFAULT 'General Medicine',
            `appointment_date` DATE NOT NULL,
            `appointment_time` TIME NOT NULL,
            `status` ENUM('Pending', 'Confirmed', 'Completed', 'Cancelled') DEFAULT 'Pending',
            `notes` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `vitals_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `patient_id` INT NOT NULL,
            `pulse_rate` INT NOT NULL,
            `systolic` INT NOT NULL,
            `diastolic` INT NOT NULL,
            `spo2` INT NOT NULL,
            `stress_level` INT DEFAULT 20,
            `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `employees` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `emp_id` VARCHAR(50) NOT NULL UNIQUE,
            `name` VARCHAR(100) NOT NULL,
            `designation` VARCHAR(100) DEFAULT NULL,
            `department` VARCHAR(100) DEFAULT NULL,
            `section` VARCHAR(100) DEFAULT NULL,
            `email` VARCHAR(100) DEFAULT NULL,
            `cell` VARCHAR(50) DEFAULT NULL,
            `company` VARCHAR(100) DEFAULT NULL,
            `location` VARCHAR(100) DEFAULT NULL,
            `floor` VARCHAR(50) DEFAULT NULL,
            `status` VARCHAR(50) DEFAULT 'Active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `employee_agent_permissions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `emp_id` VARCHAR(50) NOT NULL,
            `agent_id` INT NOT NULL,
            `access_type` ENUM('permanent', 'temporary') DEFAULT 'permanent',
            `valid_from` DATETIME DEFAULT NULL,
            `valid_to` DATETIME DEFAULT NULL,
            `status` ENUM('active', 'inactive') DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_emp_agent` (`emp_id`, `agent_id`),
            INDEX `idx_emp_id` (`emp_id`),
            INDEX `idx_agent_id` (`agent_id`),
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `biometric_entries` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `emp_id` VARCHAR(50) NOT NULL,
            `agent_id` INT DEFAULT NULL,
            `finger_type` VARCHAR(50) DEFAULT 'Right Thumb',
            `quality_score` VARCHAR(20) DEFAULT '99.2%',
            `status` VARCHAR(50) DEFAULT 'Verified',
            `notes` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `attendance_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `emp_id` VARCHAR(50) NOT NULL,
            `agent_id` INT DEFAULT NULL,
            `punch_type` ENUM('Biometric', 'Face', 'Manual') DEFAULT 'Biometric',
            `verification_mode` VARCHAR(50) DEFAULT 'ZKTeco Mini Live20R',
            `quality_score` VARCHAR(20) DEFAULT '99.8%',
            `punch_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_emp_id` (`emp_id`),
            INDEX `idx_agent_id` (`agent_id`),
            INDEX `idx_punch_time` (`punch_time`),
            INDEX `idx_emp_punch` (`emp_id`, `punch_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `face_entries` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `emp_id` VARCHAR(50) NOT NULL,
            `agent_id` INT DEFAULT NULL,
            `face_descriptor` LONGTEXT NOT NULL,
            `face_image` LONGTEXT DEFAULT NULL,
            `quality_score` VARCHAR(20) DEFAULT '99.5%',
            `status` VARCHAR(50) DEFAULT 'Active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `audit_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT DEFAULT NULL,
            `user_name` VARCHAR(100) DEFAULT 'System',
            `user_role` VARCHAR(50) DEFAULT 'admin',
            `category` VARCHAR(50) NOT NULL,
            `action` VARCHAR(100) NOT NULL,
            `details` TEXT DEFAULT NULL,
            `ip_address` VARCHAR(45) DEFAULT '127.0.0.1',
            `status` ENUM('Success', 'Warning', 'Failed') DEFAULT 'Success',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_category` (`category`),
            INDEX `idx_action` (`action`),
            INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `user_permissions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `permission` VARCHAR(60) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `idx_user_perm` (`user_id`, `permission`),
            INDEX `idx_perm_user` (`user_id`),
            CONSTRAINT `fk_user_perm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    ];

    foreach ($queries as $sql) {
        $pdo->exec($sql);
    }

    // Ensure missing columns exist in users & employees tables if table already existed
    try {
        $cols = $pdo->query("DESCRIBE `users`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('phone', $cols)) {
            $pdo->exec("ALTER TABLE `users` ADD `phone` VARCHAR(30) DEFAULT NULL AFTER `email`");
        }
        if (!in_array('department', $cols)) {
            $pdo->exec("ALTER TABLE `users` ADD `department` VARCHAR(100) DEFAULT 'Field Operations'");
        }
        if (!in_array('avatar', $cols)) {
            $pdo->exec("ALTER TABLE `users` ADD `avatar` VARCHAR(255) DEFAULT 'default-avatar.png'");
        }
        if (!in_array('status', $cols)) {
            $pdo->exec("ALTER TABLE `users` ADD `status` ENUM('active', 'inactive') DEFAULT 'active'");
        }
        if (!in_array('location', $cols)) {
            $pdo->exec("ALTER TABLE `users` ADD `location` VARCHAR(100) DEFAULT NULL");
        }
        if (!in_array('authorize_person', $cols)) {
            $pdo->exec("ALTER TABLE `users` ADD `authorize_person` VARCHAR(100) DEFAULT NULL");
        }

        // Terminal Point Mode & Schedule columns (Biometric & Face control per agent)
        if (!in_array('biometric_mode', $cols)) {
            $pdo->exec("ALTER TABLE `users` ADD `biometric_mode` VARCHAR(30) DEFAULT 'always_on' AFTER `status`");
        }
        if (!in_array('biometric_start', $cols)) {
            $pdo->exec("ALTER TABLE `users` ADD `biometric_start` TIME DEFAULT '09:00:00' AFTER `biometric_mode`");
        }
        if (!in_array('biometric_end', $cols)) {
            $pdo->exec("ALTER TABLE `users` ADD `biometric_end` TIME DEFAULT '18:00:00' AFTER `biometric_start`");
        }
        if (!in_array('face_mode', $cols)) {
            $pdo->exec("ALTER TABLE `users` ADD `face_mode` VARCHAR(30) DEFAULT 'always_on' AFTER `biometric_end`");
        }
        if (!in_array('face_start', $cols)) {
            $pdo->exec("ALTER TABLE `users` ADD `face_start` TIME DEFAULT '09:00:00' AFTER `face_mode`");
        }
        if (!in_array('face_end', $cols)) {
            $pdo->exec("ALTER TABLE `users` ADD `face_end` TIME DEFAULT '18:00:00' AFTER `face_start`");
        }

        // Biometric columns
        $bioCols = $pdo->query("DESCRIBE `biometric_entries`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('template', $bioCols)) {
            $pdo->exec("ALTER TABLE `biometric_entries` ADD `template` LONGTEXT DEFAULT NULL AFTER `finger_type`");
        }

        // Employee columns: Agent Isolation, Shift & Attendance Block
        $empCols = $pdo->query("DESCRIBE `employees`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('agent_id', $empCols)) {
            $pdo->exec("ALTER TABLE `employees` ADD `agent_id` INT DEFAULT NULL AFTER `id`");
        }
        if (!in_array('shift_name', $empCols)) {
            $pdo->exec("ALTER TABLE `employees` ADD `shift_name` VARCHAR(100) DEFAULT 'General (09:00 AM - 06:00 PM)' AFTER `floor`");
        }
        if (!in_array('shift_start', $empCols)) {
            $pdo->exec("ALTER TABLE `employees` ADD `shift_start` TIME DEFAULT '09:00:00' AFTER `shift_name`");
        }
        if (!in_array('shift_end', $empCols)) {
            $pdo->exec("ALTER TABLE `employees` ADD `shift_end` TIME DEFAULT '18:00:00' AFTER `shift_start`");
        }
        if (!in_array('is_attendance_blocked', $empCols)) {
            $pdo->exec("ALTER TABLE `employees` ADD `is_attendance_blocked` TINYINT(1) DEFAULT 0 AFTER `shift_end`");
        }
        if (!in_array('attendance_block_type', $empCols)) {
            $pdo->exec("ALTER TABLE `employees` ADD `attendance_block_type` VARCHAR(20) DEFAULT 'none' AFTER `is_attendance_blocked`");
        }

        // Set safe defaults for any null employee fields
        $pdo->exec("UPDATE `employees` SET `shift_name` = 'General (09:00 AM - 06:00 PM)' WHERE `shift_name` IS NULL OR `shift_name` = ''");
        $pdo->exec("UPDATE `employees` SET `shift_start` = '09:00:00' WHERE `shift_start` IS NULL");
        $pdo->exec("UPDATE `employees` SET `shift_end` = '18:00:00' WHERE `shift_end` IS NULL");
        $pdo->exec("UPDATE `employees` SET `is_attendance_blocked` = 0 WHERE `is_attendance_blocked` IS NULL");
        $pdo->exec("UPDATE `employees` SET `attendance_block_type` = 'none' WHERE `attendance_block_type` IS NULL OR `attendance_block_type` = ''");
        $pdo->exec("UPDATE `employees` SET `attendance_block_type` = 'both' WHERE `is_attendance_blocked` = 1 AND `attendance_block_type` = 'none'");

        // Assign existing unassigned records to primary agent (agent1)
        $agent1Id = $pdo->query("SELECT `id` FROM `users` WHERE `username` = 'agent1'")->fetchColumn();
        if ($agent1Id) {
            $pdo->exec("UPDATE `employees` SET `agent_id` = {$agent1Id} WHERE `agent_id` IS NULL");
            $pdo->exec("UPDATE `attendance_logs` SET `agent_id` = {$agent1Id} WHERE `agent_id` IS NULL");
            $pdo->exec("UPDATE `biometric_entries` SET `agent_id` = {$agent1Id} WHERE `agent_id` IS NULL");
            $pdo->exec("UPDATE `face_entries` SET `agent_id` = {$agent1Id} WHERE `agent_id` IS NULL");
            $pdo->exec("UPDATE `patients` SET `created_by_agent` = {$agent1Id} WHERE `created_by_agent` IS NULL");
        }

        // Migrate all employees to employee_agent_permissions if not present
        $pdo->exec("INSERT IGNORE INTO `employee_agent_permissions` (`emp_id`, `agent_id`, `access_type`, `valid_from`, `status`)
            SELECT `emp_id`, `agent_id`, 'permanent', NOW(), 'active' 
            FROM `employees` 
            WHERE `agent_id` IS NOT NULL");

        // High-Performance Indexes for Live Production Attendance & Matching
        try {
            $attIdx = array_column($pdo->query("SHOW INDEX FROM `attendance_logs`")->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
            if (!in_array('idx_emp_id', $attIdx)) {
                $pdo->exec("ALTER TABLE `attendance_logs` ADD INDEX `idx_emp_id` (`emp_id`)");
            }
            if (!in_array('idx_agent_id', $attIdx)) {
                $pdo->exec("ALTER TABLE `attendance_logs` ADD INDEX `idx_agent_id` (`agent_id`)");
            }
            if (!in_array('idx_punch_time', $attIdx)) {
                $pdo->exec("ALTER TABLE `attendance_logs` ADD INDEX `idx_punch_time` (`punch_time`)");
            }
            if (!in_array('idx_emp_punch', $attIdx)) {
                $pdo->exec("ALTER TABLE `attendance_logs` ADD INDEX `idx_emp_punch` (`emp_id`, `punch_time`)");
            }

            $bioIdx = array_column($pdo->query("SHOW INDEX FROM `biometric_entries`")->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
            if (!in_array('idx_emp_id', $bioIdx)) {
                $pdo->exec("ALTER TABLE `biometric_entries` ADD INDEX `idx_emp_id` (`emp_id`)");
            }
            if (!in_array('idx_agent_id', $bioIdx)) {
                $pdo->exec("ALTER TABLE `biometric_entries` ADD INDEX `idx_agent_id` (`agent_id`)");
            }

            $faceIdx = array_column($pdo->query("SHOW INDEX FROM `face_entries`")->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
            if (!in_array('idx_emp_id', $faceIdx)) {
                $pdo->exec("ALTER TABLE `face_entries` ADD INDEX `idx_emp_id` (`emp_id`)");
            }
            if (!in_array('idx_agent_id', $faceIdx)) {
                $pdo->exec("ALTER TABLE `face_entries` ADD INDEX `idx_agent_id` (`agent_id`)");
            }
        } catch (Exception $idxEx) {}

    } catch (Exception $e) {}

    // Seed default Admin if not present
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `users` WHERE `username` = 'admin'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $adminPass = password_hash('admin123', PASSWORD_DEFAULT);
        $insertAdmin = $pdo->prepare("INSERT INTO `users` (`name`, `username`, `email`, `phone`, `password`, `role`, `department`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $insertAdmin->execute(['Super Administrator', 'admin', 'admin@pulsemark.com', '01700000000', $adminPass, 'admin', 'Executive Management', 'active']);
    }

    // Seed default Agent if not present
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `users` WHERE `username` = 'agent1'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $agentPass = password_hash('agent123', PASSWORD_DEFAULT);
        $insertAgent = $pdo->prepare("INSERT INTO `users` (`name`, `username`, `email`, `phone`, `password`, `role`, `department`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $insertAgent->execute(['Agent Rayhan', 'agent1', 'agent1@pulsemark.com', '01811223344', $agentPass, 'agent', 'Biometric Data Collection', 'active']);
        
        $agentId = $pdo->lastInsertId();
        if ($agentId) {
            $taskInsert = $pdo->prepare("INSERT INTO `tasks` (`agent_id`, `title`, `description`, `priority`, `status`, `due_date`) VALUES (?, ?, ?, ?, ?, ?)");
            $taskInsert->execute([$agentId, 'Collect Pulse Records - Zone A', 'Collect pulse and BP telemetry data from 10 assigned patients.', 'High', 'In Progress', date('Y-m-d', strtotime('+3 days'))]);
            $taskInsert->execute([$agentId, 'Verify Biometric Sensors', 'Run diagnostic check on portable pulse meter hardware.', 'Medium', 'Pending', date('Y-m-d', strtotime('+5 days'))]);
        }
    }

    // Seed initial Audit Logs if empty
    try {
        $auditCount = $pdo->query("SELECT COUNT(*) FROM `audit_logs`")->fetchColumn();
        if ($auditCount == 0) {
            $seedLogs = [
                ['System Administrator', 'admin', 'Authentication', 'USER_LOGIN', 'Super Administrator logged into Control Panel securely.', '127.0.0.1', 'Success', date('Y-m-d H:i:s', strtotime('-2 hours'))],
                ['Agent Rayhan', 'agent', 'Biometric', 'BIOMETRIC_ENROLLED', 'Fingerprint template enrolled for Employee M15 (Mahade Hasan) via ZKTeco Mini.', '127.0.0.1', 'Success', date('Y-m-d H:i:s', strtotime('-1 hour 45 mins'))],
                ['Agent Rayhan', 'agent', 'Face Recognition', 'FACE_ENROLLED', '128-D Face descriptor registered for Employee M5326 (Towfiq) with 99.4% confidence.', '127.0.0.1', 'Success', date('Y-m-d H:i:s', strtotime('-1 hour 20 mins'))],
                ['System Administrator', 'admin', 'Employee', 'EMPLOYEE_UPDATED', 'Updated duty shift timing and agent permissions for Mahade Hasan (M15).', '127.0.0.1', 'Success', date('Y-m-d H:i:s', strtotime('-45 mins'))],
                ['System Administrator', 'admin', 'Agent Terminals', 'AGENT_SETTINGS_UPDATED', 'Configured terminal operational hours (09:00 AM - 06:00 PM) for Agent Rayhan.', '127.0.0.1', 'Success', date('Y-m-d H:i:s', strtotime('-25 mins'))],
                ['Agent Rayhan', 'agent', 'Attendance', 'ATTENDANCE_PUNCH', 'Biometric punch verified for Employee M15 at Agent Rayhan point.', '127.0.0.1', 'Success', date('Y-m-d H:i:s', strtotime('-10 mins'))],
                ['System Administrator', 'admin', 'Data Export/Import', 'CSV_EXPORTED', 'Exported employee records and duty shift roster to CSV spreadsheet.', '127.0.0.1', 'Success', date('Y-m-d H:i:s', strtotime('-2 mins'))]
            ];

            $insAudit = $pdo->prepare("INSERT INTO `audit_logs` (`user_name`, `user_role`, `category`, `action`, `details`, `ip_address`, `status`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($seedLogs as $sl) {
                $insAudit->execute($sl);
            }
        }
    } catch (Exception $e) {}
}

/**
 * Global Audit Logger Helper
 */
function logAudit($pdo, $action, $category, $details = '', $userId = null, $userName = null, $userRole = null, $status = 'Success') {
    if (!$pdo) return;
    try {
        if ($userId === null && isset($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];
        }
        if ($userName === null && isset($_SESSION['user_name'])) {
            $userName = $_SESSION['user_name'];
        }
        if ($userRole === null && isset($_SESSION['user_role'])) {
            $userRole = $_SESSION['user_role'];
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        
        $stmt = $pdo->prepare("INSERT INTO `audit_logs` (`user_id`, `user_name`, `user_role`, `category`, `action`, `details`, `ip_address`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $userName ?: 'System Admin', $userRole ?: 'admin', $category, $action, $details, $ip, $status]);
    } catch (Exception $e) {}
}

/**
 * Get all assigned agents and their validity for a specific employee
 */
function getEmployeeAgentPermissions($pdo, $emp_id) {
    if (!$pdo || empty($emp_id)) return [];
    try {
        $stmt = $pdo->prepare("SELECT p.*, u.name as agent_name, u.username as agent_username, u.department as agent_department 
                               FROM `employee_agent_permissions` p 
                               JOIN `users` u ON p.agent_id = u.id 
                               WHERE p.emp_id = ? AND p.status = 'active'
                               ORDER BY u.name ASC");
        $stmt->execute([$emp_id]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Sync / Save Assigned Agents and Access Validity
 */
function syncEmployeeAgentPermissions($pdo, $emp_id, $assigned_agents, $access_types = [], $valid_froms = [], $valid_tos = []) {
    if (!$pdo || empty($emp_id)) return;

    try {
        if (empty($assigned_agents) || !is_array($assigned_agents)) {
            $del = $pdo->prepare("DELETE FROM `employee_agent_permissions` WHERE `emp_id` = ?");
            $del->execute([$emp_id]);
            return;
        }

        // Delete unselected agents
        $inPlaceholders = implode(',', array_fill(0, count($assigned_agents), '?'));
        $del = $pdo->prepare("DELETE FROM `employee_agent_permissions` WHERE `emp_id` = ? AND `agent_id` NOT IN ($inPlaceholders)");
        $params = array_merge([$emp_id], array_map('intval', $assigned_agents));
        $del->execute($params);

        $stmt = $pdo->prepare("INSERT INTO `employee_agent_permissions` (`emp_id`, `agent_id`, `access_type`, `valid_from`, `valid_to`, `status`) 
            VALUES (?, ?, ?, ?, ?, 'active') 
            ON DUPLICATE KEY UPDATE `access_type` = VALUES(`access_type`), `valid_from` = VALUES(`valid_from`), `valid_to` = VALUES(`valid_to`), `status` = 'active'");

        foreach ($assigned_agents as $agentId) {
            $agentId = (int)$agentId;
            if ($agentId <= 0) continue;

            $type = ($access_types[$agentId] ?? 'permanent') === 'temporary' ? 'temporary' : 'permanent';
            $from = null;
            $to = null;
            if ($type === 'temporary') {
                $fromRaw = $valid_froms[$agentId] ?? '';
                $toRaw = $valid_tos[$agentId] ?? '';
                if (!empty($fromRaw)) {
                    $from = date('Y-m-d H:i:s', strtotime($fromRaw));
                }
                if (!empty($toRaw)) {
                    $to = date('Y-m-d H:i:s', strtotime($toRaw));
                }
            }

            $stmt->execute([$emp_id, $agentId, $type, $from, $to]);
        }
    } catch (Exception $e) {}
}

/**
 * Check if employee is authorized for attendance at this specific agent
 */
function checkAgentAttendanceAuthorization($pdo, $emp_id, $agentId = null) {
    if (!$pdo || empty($emp_id)) {
        return ['authorized' => false, 'reason' => 'Invalid parameters'];
    }

    // If Admin is logged in, always authorize
    if (function_exists('isAdminLoggedIn') && isAdminLoggedIn()) {
        return ['authorized' => true, 'access_type' => 'permanent'];
    }

    if (empty($agentId)) {
        $agentId = $_SESSION['agent_id'] ?? ($_SESSION['user_id'] ?? 2);
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM `employee_agent_permissions` WHERE `emp_id` = ? AND `agent_id` = ? AND `status` = 'active' LIMIT 1");
        $stmt->execute([$emp_id, $agentId]);
        $perm = $stmt->fetch();

        $now = date('Y-m-d H:i:s');

        if ($perm) {
            if ($perm['access_type'] === 'permanent') {
                return ['authorized' => true, 'access_type' => 'permanent'];
            }
            if ($perm['access_type'] === 'temporary') {
                if (!empty($perm['valid_from']) && $now < $perm['valid_from']) {
                    return [
                        'authorized' => false, 
                        'reason' => 'Attendance access scheduled to begin on ' . date('d M Y, h:i A', strtotime($perm['valid_from']))
                    ];
                }
                if (!empty($perm['valid_to']) && $now > $perm['valid_to']) {
                    return [
                        'authorized' => false, 
                        'reason' => 'Temporary attendance access for this agent expired on ' . date('d M Y, h:i A', strtotime($perm['valid_to']))
                    ];
                }
                return ['authorized' => true, 'access_type' => 'temporary', 'valid_to' => $perm['valid_to']];
            }
        }

        // Fallback: check if primary creator agent
        $empCheck = $pdo->prepare("SELECT agent_id FROM `employees` WHERE `emp_id` = ? LIMIT 1");
        $empCheck->execute([$emp_id]);
        $originAgent = (int)$empCheck->fetchColumn();
        if ($originAgent === (int)$agentId || (empty($originAgent) && (int)$agentId === 2)) {
            // Auto heal permission record
            $pdo->prepare("INSERT IGNORE INTO `employee_agent_permissions` (`emp_id`, `agent_id`, `access_type`, `valid_from`, `status`) VALUES (?, ?, 'permanent', NOW(), 'active')")->execute([$emp_id, $agentId]);
            return ['authorized' => true, 'access_type' => 'permanent'];
        }

        // Also check if employee has any assigned permissions; if none exist, auto-assign
        $anyPerm = $pdo->prepare("SELECT COUNT(*) FROM `employee_agent_permissions` WHERE `emp_id` = ? AND `status` = 'active'");
        $anyPerm->execute([$emp_id]);
        if ((int)$anyPerm->fetchColumn() === 0) {
            $pdo->prepare("INSERT IGNORE INTO `employee_agent_permissions` (`emp_id`, `agent_id`, `access_type`, `valid_from`, `status`) VALUES (?, ?, 'permanent', NOW(), 'active')")->execute([$emp_id, $agentId]);
            return ['authorized' => true, 'access_type' => 'permanent'];
        }

        return [
            'authorized' => false, 
            'reason' => 'Employee is not assigned to this Agent terminal.'
        ];
    } catch (Exception $e) {
        return ['authorized' => false, 'reason' => $e->getMessage()];
    }
}

/**
 * Fetch all employees authorized for a specific agent (or all employees if admin/unrestricted)
 */
function getAgentAuthorizedEmployees($pdo, $agentId, $activeOnly = true) {
    if (!$pdo) return [];
    
    try {
        $statusCond = $activeOnly ? "AND (e.`status` = 'Active' OR e.`status` IS NULL)" : "";
        
        // If in guest mode, do not expose database employees
        if (function_exists('isAgentGuestMode') && isAgentGuestMode()) {
            return [];
        }

        // If agent is admin, return all active employees
        if (function_exists('isAdminLoggedIn') && isAdminLoggedIn()) {
            $sql = "SELECT e.*, 
                           'permanent' as my_access_type,
                           NULL as my_valid_from,
                           NULL as my_valid_to
                    FROM `employees` e 
                    WHERE 1=1 {$statusCond}
                    ORDER BY e.`name` ASC";
            return $pdo->query($sql)->fetchAll();
        }

        if (empty($agentId)) {
            $agentId = 1;
        }

        $sql = "SELECT DISTINCT e.*, 
                       p.access_type as my_access_type,
                       p.valid_from as my_valid_from,
                       p.valid_to as my_valid_to
                FROM `employees` e 
                LEFT JOIN `employee_agent_permissions` p ON p.emp_id = e.emp_id AND p.agent_id = ? AND p.status = 'active'
                WHERE (e.agent_id = ? OR p.agent_id = ?)
                  AND (
                      p.access_type = 'permanent' 
                      OR (p.access_type = 'temporary' AND (p.valid_from IS NULL OR p.valid_from <= NOW()) AND (p.valid_to IS NULL OR p.valid_to >= NOW()))
                      OR (e.agent_id = ? AND p.agent_id IS NULL)
                  )
                  {$statusCond}
                ORDER BY e.`name` ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$agentId, $agentId, $agentId, $agentId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Check if a terminal point's attendance service (biometric/face) is currently active & within schedule
 */
function isAgentTerminalServiceActive($pdo, $agentId, $serviceType = 'biometric') {
    if (!$pdo || empty($agentId)) {
        return ['active' => true, 'mode' => 'always_on'];
    }

    try {
        $stmt = $pdo->prepare("SELECT id, name, username, biometric_mode, biometric_start, biometric_end, face_mode, face_start, face_end, status FROM `users` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$agentId]);
        $agent = $stmt->fetch();

        if (!$agent) {
            return ['active' => true, 'mode' => 'always_on'];
        }

        if (($agent['status'] ?? 'active') !== 'active') {
            return [
                'active' => false,
                'mode' => 'inactive',
                'reason' => "This Agent Terminal Point ({$agent['name']}) is currently DEACTIVATED."
            ];
        }

        $serviceName = ($serviceType === 'face') ? 'Live Face Recognition' : 'Biometric Fingerprint';
        $modeKey = ($serviceType === 'face') ? 'face_mode' : 'biometric_mode';
        $startKey = ($serviceType === 'face') ? 'face_start' : 'biometric_start';
        $endKey = ($serviceType === 'face') ? 'face_end' : 'biometric_end';

        $mode = $agent[$modeKey] ?? 'always_on';
        $start = $agent[$startKey] ?? '09:00:00';
        $end = $agent[$endKey] ?? '18:00:00';

        if ($mode === 'disabled') {
            return [
                'active' => false,
                'mode' => 'disabled',
                'reason' => "{$serviceName} attendance is DISABLED by Admin for this Terminal Point ({$agent['name']})."
            ];
        }

        if ($mode === 'scheduled') {
            $currentTime = date('H:i:s');
            $inWindow = false;

            if ($start <= $end) {
                $inWindow = ($currentTime >= $start && $currentTime <= $end);
            } else {
                // Overnight window (e.g. 20:00 to 06:00)
                $inWindow = ($currentTime >= $start || $currentTime <= $end);
            }

            $startFmt = date('h:i A', strtotime($start));
            $endFmt = date('h:i A', strtotime($end));

            if (!$inWindow) {
                return [
                    'active' => false,
                    'mode' => 'scheduled',
                    'start' => $start,
                    'end' => $end,
                    'start_formatted' => $startFmt,
                    'end_formatted' => $endFmt,
                    'reason' => "{$serviceName} attendance is OUTSIDE operating schedule ({$startFmt} - {$endFmt}) for Terminal Point ({$agent['name']})."
                ];
            }

            return [
                'active' => true,
                'mode' => 'scheduled',
                'start' => $start,
                'end' => $end,
                'start_formatted' => $startFmt,
                'end_formatted' => $endFmt
            ];
        }

        return ['active' => true, 'mode' => 'always_on'];
    } catch (Exception $e) {
        return ['active' => true, 'mode' => 'always_on'];
    }
}

/**
 * Fast check to avoid running DDL & full updates on every HTTP request
 */
function ensureDatabaseInitialized($pdo) {
    static $checked = false;
    if ($checked || !$pdo) {
        return;
    }
    $checked = true;

    try {
        // Quick verification: users table exists
        $pdo->query("SELECT 1 FROM `users` LIMIT 1");

        // Verify if attendance_logs has idx_emp_punch (indexes installed)
        $attIdx = array_column($pdo->query("SHOW INDEX FROM `attendance_logs`")->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
        if (!in_array('idx_emp_punch', $attIdx)) {
            initDatabaseTables($pdo);
        }
    } catch (Exception $e) {
        // Tables not present, run full initialization
        initDatabaseTables($pdo);
    }
}

// Auto Initialize safely without full DDL overhead on every request
$db = getDB();
if ($db) {
    ensureDatabaseInitialized($db);
}
