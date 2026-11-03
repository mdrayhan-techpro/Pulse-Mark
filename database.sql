-- =========================================================================
-- Pulse Mark - Database Schema & Initial Data
-- =========================================================================

CREATE DATABASE IF NOT EXISTS `pulse_mark` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `pulse_mark`;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'doctor', 'staff') DEFAULT 'admin',
    `avatar` VARCHAR(255) DEFAULT 'default-avatar.png',
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Patients Table
CREATE TABLE IF NOT EXISTS `patients` (
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
    `temperature` DECIMAL(4,1) DEFAULT 98.6,
    `condition_status` ENUM('Normal', 'Attention', 'Critical') DEFAULT 'Normal',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Appointments Table
CREATE TABLE IF NOT EXISTS `appointments` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Vitals Logs Table
CREATE TABLE IF NOT EXISTS `vitals_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `patient_id` INT NOT NULL,
    `pulse_rate` INT NOT NULL,
    `systolic` INT NOT NULL,
    `diastolic` INT NOT NULL,
    `spo2` INT NOT NULL,
    `stress_level` INT DEFAULT 20,
    `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. User Permissions Table (RBAC for Control Panel)
CREATE TABLE IF NOT EXISTS `user_permissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `permission` VARCHAR(60) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `idx_user_perm` (`user_id`, `permission`),
    INDEX `idx_perm_user` (`user_id`),
    CONSTRAINT `fk_user_perm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Seed Super User Admin (Password: RhbL2025#)
INSERT INTO `users` (`name`, `username`, `email`, `password`, `role`) 
VALUES ('Md Rayhan', 'rayhan', 'mdrayhan.tech.pro@gmail.com', '$2y$10$tZ25VfQ3nIoxe2lGj8G00eJvJ9t81p9Vq76b/a8B73gGZ/N3yIq2e', 'admin')
ON DUPLICATE KEY UPDATE `username`=`username`;

-- 6. Seed Sample Patients
INSERT INTO `patients` (`patient_code`, `name`, `age`, `gender`, `blood_group`, `phone`, `email`, `address`, `pulse_rate`, `blood_pressure`, `oxygen_level`, `temperature`, `condition_status`) VALUES
('PM-1001', 'Rahim Ahmed', 34, 'Male', 'A+', '01711223344', 'rahim@example.com', 'Dhaka, Bangladesh', 72, '120/80', 99, 98.4, 'Normal'),
('PM-1002', 'Nusrat Jahan', 28, 'Female', 'B+', '01822334455', 'nusrat@example.com', 'Chittagong, Bangladesh', 88, '135/90', 97, 99.1, 'Attention'),
('PM-1003', 'Kamal Hossain', 58, 'Male', 'O+', '01933445566', 'kamal@example.com', 'Sylhet, Bangladesh', 105, '150/95', 94, 100.2, 'Critical'),
('PM-1004', 'Farzana Akter', 42, 'Female', 'AB+', '01644556677', 'farzana@example.com', 'Rajshahi, Bangladesh', 75, '118/78', 99, 98.6, 'Normal'),
('PM-1005', 'Tanvir Hasan', 24, 'Male', 'O-', '01555667788', 'tanvir@example.com', 'Khulna, Bangladesh', 68, '115/75', 98, 98.2, 'Normal')
ON DUPLICATE KEY UPDATE `patient_code`=`patient_code`;
