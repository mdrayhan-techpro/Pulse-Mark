<?php
require_once __DIR__ . '/../config/init.php';

if (isAdminLoggedIn()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
