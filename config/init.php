<?php
if (!ob_get_level()) {
    ob_start();
}

date_default_timezone_set('Asia/Dhaka');

// Error reporting based on environment
$appEnv = getenv('APP_ENV') ?: 'production';
$appDebug = filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN);

if ($appEnv === 'production' && !$appDebug) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// App Constants
define('APP_NAME', 'Pulse Mark');
define('APP_TAGLINE', 'Dual Portal Health & Operations System');
define('APP_VERSION', '1.2.0');

// Base URLs
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
if (preg_match('#/(control_panel|agent)$#', $scriptDir)) {
    $basePath = dirname($scriptDir);
} else {
    $basePath = $scriptDir;
}
$basePath = trim($basePath, '/');
$appPath = $basePath !== '' ? '/' . $basePath . '/' : '/';

define('BASE_URL', rtrim($protocol . $host, '/') . $appPath);
define('CONTROL_PANEL_URL', rtrim(BASE_URL, '/') . '/control_panel/');
define('AGENT_URL', rtrim(BASE_URL, '/') . '/agent/');
define('ASSETS_URL', rtrim(BASE_URL, '/') . '/assets/');
define('LOGIN_URL', rtrim(BASE_URL, '/') . '/login.php');

// Helper for active link
function isActivePage($pageName) {
    $currentPage = basename($_SERVER['PHP_SELF'], '.php');
    return ($currentPage === $pageName) ? 'active' : '';
}
