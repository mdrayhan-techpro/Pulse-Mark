<?php
require_once __DIR__ . '/config/init.php';

// If already authenticated as Administrator/Manager/Supervisor, forward to Control Panel
if (isAdminLoggedIn()) {
    header('Location: control_panel/dashboard.php');
    exit;
}

// For guests and agents, load the full Agent Portal (supports Guest Preview & Authenticated Mode)
header('Location: agent/dashboard.php');
exit;
