<?php
require_once __DIR__ . '/../config/init.php';

// Unset all session variables
$_SESSION = [];

// Destroy session cookie if exists
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, '/');
    if (!empty($params['path']) && $params['path'] !== '/') {
        setcookie(session_name(), '', time() - 42000, $params['path']);
    }
}

// Destroy session
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

header('Location: ' . getLoginUrl());
exit;
