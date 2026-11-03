<?php
require_once __DIR__ . '/../../config/init.php';

$isGuestMode = isAgentGuestMode();

// Handle in-page login action from universal modal in Guest Mode
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['is_guest_login_action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Security session expired. Please refresh and try again.');
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($username) || empty($password)) {
            setFlash('danger', 'Please enter both username and password.');
        } else {
            $pdo = getDB();
            if ($pdo) {
                $stmt = $pdo->prepare("SELECT * FROM `users` WHERE (`username` = ? OR `email` = ?) LIMIT 1");
                $stmt->execute([$username, $username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    if (($user['status'] ?? 'active') !== 'active') {
                        setFlash('danger', 'Your account has been deactivated. Please contact administrator.');
                    } else {
                        session_regenerate_id(true);
                        $_SESSION = [];

                        if (in_array($user['role'], ['admin', 'manager', 'supervisor'])) {
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['user_name'] = $user['name'];
                            $_SESSION['user_username'] = $user['username'];
                            $_SESSION['user_email'] = $user['email'];
                            $_SESSION['user_role'] = $user['role'];
                            $_SESSION['agent_id'] = $user['id'];
                            $_SESSION['agent_name'] = $user['name'];
                            $_SESSION['agent_role'] = 'agent';
                            $_SESSION['user_dept'] = $user['department'] ?? 'Executive Management';

                            if ($user['role'] === 'admin') {
                                $_SESSION['user_permissions'] = array_keys(AVAILABLE_PERMISSIONS);
                            } else {
                                $permStmt = $pdo->prepare("SELECT permission_key FROM user_permissions WHERE user_id = ?");
                                $permStmt->execute([$user['id']]);
                                $_SESSION['user_permissions'] = $permStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
                            }
                        } elseif ($user['role'] === 'agent') {
                            $_SESSION['agent_id'] = $user['id'];
                            $_SESSION['agent_name'] = $user['name'];
                            $_SESSION['agent_username'] = $user['username'];
                            $_SESSION['agent_email'] = $user['email'];
                            $_SESSION['agent_role'] = 'agent';
                            $_SESSION['agent_dept'] = $user['department'] ?? 'Field Operations';
                        }

                        setFlash('success', 'Authentication successful! Welcome, ' . htmlspecialchars($user['name']));
                        header('Location: ' . $_SERVER['REQUEST_URI']);
                        exit;
                    }
                } else {
                    setFlash('danger', 'Invalid username or password credentials.');
                }
            }
        }
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// Block any mutating POST requests in Guest Mode
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isGuestMode) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'message' => 'Action Denied: You are in Guest Preview Mode. Please sign in to make changes.'
        ]);
        exit;
    }
    setFlash('danger', 'Action Denied: You are in Guest Preview Mode. Please sign in to perform this operation.');
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// Configure $currentAgent context
if ($isGuestMode) {
    $currentAgent = [
        'id' => 1,
        'name' => 'Guest Terminal',
        'username' => 'guest',
        'email' => 'guest@pulsemark.com',
        'dept' => 'Guest Preview Mode',
        'is_guest' => true
    ];
} else {
    $currentAgent = [
        'id' => $_SESSION['agent_id'] ?? ($_SESSION['user_id'] ?? 1),
        'name' => $_SESSION['agent_name'] ?? ($_SESSION['user_name'] ?? 'Agent User'),
        'username' => $_SESSION['agent_username'] ?? ($_SESSION['user_username'] ?? 'agent'),
        'email' => $_SESSION['agent_email'] ?? ($_SESSION['user_email'] ?? 'agent@pulsemark.com'),
        'dept' => $_SESSION['agent_dept'] ?? 'Field Operations',
        'is_guest' => false
    ];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Agent Workspace' ?> &bull; <?= APP_NAME ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../assets/pulsemark_logo.png">
    <link rel="apple-touch-icon" href="../assets/pulsemark_logo.png">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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