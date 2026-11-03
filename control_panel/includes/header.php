<?php
require_once __DIR__ . '/../../config/init.php';
requireAdminAuth();

$currentUser = [
    'name' => $_SESSION['user_name'] ?? 'System Admin',
    'role' => $_SESSION['user_role'] ?? 'admin',
    'email' => $_SESSION['user_email'] ?? 'admin@pulsemark.com'
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Dashboard' ?> | <?= APP_NAME ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../assets/pulsemark_logo.png">
    <link rel="apple-touch-icon" href="../assets/pulsemark_logo.png">
    
    <!-- Google Fonts: Inter & Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Custom CSS with cache-busting -->
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../../assets/css/admin.css') ?>">
    <script>
        (function() {
            const savedTheme = localStorage.getItem('pulse_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
</head>
<body>
<div class="app-container">
