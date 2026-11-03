<?php
require_once __DIR__ . '/config/init.php';

// If already authenticated, redirect to appropriate workspace
if (isAdminLoggedIn()) {
    header('Location: control_panel/dashboard.php');
    exit;
}
if (isAgentLoggedIn()) {
    header('Location: agent/dashboard.php');
    exit;
}

$error = '';
$prefillUsername = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired or invalid token. Please refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $prefillUsername = $username;

        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password.';
        } else {
            $pdo = getDB();
            $authenticated = false;

            if ($pdo) {
                $stmt = $pdo->prepare("SELECT * FROM `users` WHERE (`username` = ? OR `email` = ?) LIMIT 1");
                $stmt->execute([$username, $username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    if (($user['status'] ?? 'active') !== 'active') {
                        $error = 'Your account has been deactivated. Please contact the administrator.';
                    } else {
                        $authenticated = true;
                        // Prevent session fixation
                        session_regenerate_id(true);
                        $_SESSION = [];

                        if (in_array($user['role'], ['admin', 'manager', 'supervisor'])) {
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['user_name'] = $user['name'];
                            $_SESSION['user_username'] = $user['username'];
                            $_SESSION['user_email'] = $user['email'];
                            $_SESSION['user_role'] = $user['role'];
                            
                            $defaultDept = 'Executive Management';
                            if ($user['role'] === 'manager') $defaultDept = 'Human Resources';
                            if ($user['role'] === 'supervisor') $defaultDept = 'Floor Supervision';
                            $_SESSION['user_dept'] = $user['department'] ?? $defaultDept;

                            // Load user permissions from user_permissions table
                            if ($user['role'] === 'admin') {
                                $_SESSION['user_permissions'] = array_keys(AVAILABLE_PERMISSIONS);
                            } else {
                                $userPerms = getUserPermissions($pdo, $user['id']);
                                if (empty($userPerms)) {
                                    $userPerms = getDefaultRolePermissions($user['role']);
                                    setUserPermissions($pdo, $user['id'], $userPerms);
                                }
                                $_SESSION['user_permissions'] = $userPerms;
                            }

                            logAudit($pdo, 'USER_LOGIN', 'Authentication', ucfirst($user['role']) . " '{$user['name']}' logged into Control Panel.", $user['id'], $user['name'], $user['role']);

                            header('Location: control_panel/' . getFirstPermittedPage());
                            exit;
                        } else if ($user['role'] === 'agent') {
                            $_SESSION['agent_id'] = $user['id'];
                            $_SESSION['agent_name'] = $user['name'];
                            $_SESSION['agent_username'] = $user['username'];
                            $_SESSION['agent_email'] = $user['email'];
                            $_SESSION['agent_role'] = 'agent';
                            $_SESSION['agent_dept'] = $user['department'] ?? 'Field Operations';

                            logAudit($pdo, 'AGENT_LOGIN', 'Authentication', "Agent '{$user['name']}' logged into Field Workspace.", $user['id'], $user['name'], 'agent');

                            header('Location: agent/dashboard.php');
                            exit;
                        } else {
                            $error = 'Unrecognized user role. Contact administrator.';
                        }
                    }
                }
            }

            if (!$authenticated && empty($error)) {
                $error = 'Invalid credentials. Please verify your username and password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login &bull; <?= APP_NAME ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/pulsemark_logo.png">
    <link rel="apple-touch-icon" href="assets/pulsemark_logo.png">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Design System CSS -->
    <link rel="stylesheet" href="assets/css/admin.css">
    
    <script>
        (function() {
            const savedTheme = localStorage.getItem('pulse_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
    
    <style>
        .login-page-wrapper {
            min-height: 100vh;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            padding: 24px 16px;
            background: radial-gradient(circle at 20% 20%, rgba(14, 165, 233, 0.15) 0%, transparent 45%),
                        radial-gradient(circle at 80% 80%, rgba(16, 185, 129, 0.15) 0%, transparent 45%),
                        var(--bg-main);
            overflow: hidden;
        }

        /* Ambient Glow spheres */
        .ambient-sphere {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.35;
            pointer-events: none;
            z-index: 1;
            animation: floatGlow 14s ease-in-out infinite alternate;
        }
        .sphere-1 {
            width: 380px;
            height: 380px;
            background: #0284c7;
            top: -100px;
            left: -100px;
        }
        .sphere-2 {
            width: 340px;
            height: 340px;
            background: #10b981;
            bottom: -90px;
            right: -90px;
            animation-delay: -7s;
        }

        @keyframes floatGlow {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(40px, 30px) scale(1.12); }
        }

        .login-card-container {
            width: 100%;
            max-width: 430px;
            position: relative;
            z-index: 10;
        }

        .login-glass-card {
            background: rgba(17, 24, 39, 0.88);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 42px 36px 38px;
            box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.65), 0 0 0 1px rgba(255, 255, 255, 0.05);
            transition: all 0.3s ease;
        }

        [data-theme="light"] .login-glass-card {
            background: rgba(255, 255, 255, 0.94);
            border: 1px solid rgba(0, 0, 0, 0.08);
            box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.12), 0 0 0 1px rgba(0, 0, 0, 0.04);
        }

        .brand-header {
            text-align: center;
            margin-bottom: 28px;
        }

        .brand-logo-img {
            max-height: 70px;
            max-width: 190px;
            object-fit: contain;
            margin-bottom: 12px;
            filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.25));
        }

        .brand-title {
            font-size: 1.7rem;
            font-weight: 800;
            font-family: 'Outfit', sans-serif;
            letter-spacing: -0.02em;
            color: var(--text-main);
            margin: 0;
        }

        /* Form elements */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            font-size: 0.86rem;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 8px;
            display: block;
        }

        .input-group-custom {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-group-custom .icon-lead {
            position: absolute;
            left: 14px;
            color: var(--text-dim);
            font-size: 0.95rem;
            pointer-events: none;
            transition: color 0.2s;
        }

        .form-control-custom {
            width: 100%;
            height: 48px;
            background: var(--bg-surface-elevated);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 0 42px 0 40px;
            font-size: 0.94rem;
            color: var(--text-main);
            outline: none;
            transition: all 0.2s;
            font-family: inherit;
        }

        .form-control-custom:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.2);
            background: var(--bg-surface);
        }

        .form-control-custom:focus + .icon-lead {
            color: var(--primary);
        }

        .toggle-pass-btn {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            color: var(--text-dim);
            cursor: pointer;
            padding: 6px;
            font-size: 0.95rem;
            transition: color 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .toggle-pass-btn:hover {
            color: var(--text-main);
        }

        .btn-submit-login {
            width: 100%;
            height: 50px;
            border-radius: 12px;
            border: none;
            background: linear-gradient(135deg, #0ea5e9, #0284c7 60%, #10b981);
            color: white;
            font-size: 1.02rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 8px 20px -4px rgba(14, 165, 233, 0.45);
            transition: all 0.25s ease;
            margin-top: 10px;
        }

        .btn-submit-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 26px -4px rgba(14, 165, 233, 0.55);
            background: linear-gradient(135deg, #0284c7, #0369a1 60%, #059669);
        }

        .btn-submit-login:active {
            transform: translateY(0);
        }

        /* Top Bar Floating Theme Toggle & Showcase Link */
        .top-corner-left {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 20;
        }

        .demo-back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: var(--bg-surface-elevated);
            border: 1px solid var(--border-color);
            border-radius: 999px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.84rem;
            font-weight: 600;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .demo-back-link:hover {
            color: var(--primary);
            border-color: var(--primary);
            transform: translateX(-2px);
        }

        .top-corner-bar {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 20;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .theme-round-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 1px solid var(--border-color);
            background: var(--bg-surface-elevated);
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .theme-round-btn:hover {
            color: var(--primary);
            border-color: var(--primary);
            transform: rotate(20deg);
        }

        /* Error alert */
        .login-error-alert {
            background: rgba(244, 63, 94, 0.12);
            border: 1px solid rgba(244, 63, 94, 0.35);
            border-radius: 12px;
            padding: 12px 14px;
            color: #fb7185;
            font-size: 0.85rem;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: shakeAlert 0.35s ease;
        }

        @keyframes shakeAlert {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-4px); }
            40%, 80% { transform: translateX(4px); }
        }
    </style>
</head>
<body>
    <div class="login-page-wrapper">
        <!-- Floating Showcase Link -->
        <div class="top-corner-left">
            <a href="index.php" class="demo-back-link">
                <i class="fa-solid fa-desktop"></i>
                <span>Live Showcase Demo</span>
            </a>
        </div>

        <!-- Floating Theme Switcher -->
        <div class="top-corner-bar">
            <button type="button" class="theme-round-btn" id="themeToggleBtn" title="Toggle Theme" aria-label="Toggle Theme">
                <i class="fa-solid fa-sun" id="themeIcon"></i>
            </button>
        </div>

        <!-- Ambient glow decorative spheres -->
        <div class="ambient-sphere sphere-1"></div>
        <div class="ambient-sphere sphere-2"></div>

        <div class="login-card-container">
            <div class="login-glass-card">
                
                <!-- Brand Header -->
                <div class="brand-header">
                    <div>
                        <img src="assets/pulsemark_logo.png" alt="<?= APP_NAME ?> Logo" class="brand-logo-img">
                    </div>
                    <h1 class="brand-title"><?= APP_NAME ?></h1>
                </div>

                <!-- Error feedback -->
                <?php if (!empty($error)): ?>
                    <div class="login-error-alert">
                        <i class="fa-solid fa-circle-exclamation" style="font-size: 1rem; flex-shrink: 0;"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Main Login Form -->
                <form method="POST" action="login.php" id="loginForm">
                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                    <div class="form-group">
                        <label class="form-label" for="usernameInput">Username</label>
                        <div class="input-group-custom">
                            <input 
                                type="text" 
                                name="username" 
                                id="usernameInput" 
                                class="form-control-custom" 
                                placeholder="Enter your username" 
                                value="<?= htmlspecialchars($prefillUsername) ?>" 
                                required 
                                autofocus
                            >
                            <i class="fa-regular fa-user icon-lead"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="passwordInput">Password</label>
                        <div class="input-group-custom">
                            <input 
                                type="password" 
                                name="password" 
                                id="passwordInput" 
                                class="form-control-custom" 
                                placeholder="Enter your password" 
                                required
                            >
                            <i class="fa-solid fa-lock icon-lead"></i>
                            <button type="button" class="toggle-pass-btn" id="togglePassBtn" aria-label="Toggle password visibility">
                                <i class="fa-regular fa-eye" id="passEyeIcon"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit-login" id="submitBtn">
                        <span>Sign In</span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </form>

                <div style="text-align: center; margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--border-color);">
                    <a href="index.php" style="color: var(--text-muted); font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: color 0.2s;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text-muted)'">
                        <i class="fa-solid fa-arrow-left"></i> Return to Live Terminal Showcase
                    </a>
                </div>

            </div>
        </div>
    </div>

    <!-- Theme & Password Visibility Interactions -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Theme toggling
            const themeToggleBtn = document.getElementById('themeToggleBtn');
            const themeIcon = document.getElementById('themeIcon');
            
            function updateThemeIcon() {
                const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
                if (themeIcon) {
                    themeIcon.className = currentTheme === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
                }
            }
            updateThemeIcon();

            if (themeToggleBtn) {
                themeToggleBtn.addEventListener('click', function () {
                    const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
                    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                    document.documentElement.setAttribute('data-theme', newTheme);
                    localStorage.setItem('pulse_theme', newTheme);
                    updateThemeIcon();
                });
            }

            // Password visibility toggle
            const togglePassBtn = document.getElementById('togglePassBtn');
            const passwordInput = document.getElementById('passwordInput');
            const passEyeIcon = document.getElementById('passEyeIcon');

            if (togglePassBtn && passwordInput && passEyeIcon) {
                togglePassBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    const isPass = passwordInput.getAttribute('type') === 'password';
                    passwordInput.setAttribute('type', isPass ? 'text' : 'password');
                    passEyeIcon.className = isPass ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
                    passEyeIcon.style.color = isPass ? 'var(--primary)' : 'var(--text-dim)';
                });
            }
        });
    </script>
</body>
</html>
