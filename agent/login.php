<?php
require_once __DIR__ . '/../config/init.php';

if (isAgentLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

header('Location: ' . getLoginUrl());
exit;
