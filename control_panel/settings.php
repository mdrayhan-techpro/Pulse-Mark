<?php
require_once __DIR__ . '/../config/init.php';
requirePermission('settings');

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Security mismatch.');
        header('Location: settings.php');
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'update_profile' && $pdo) {
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $userId = $_SESSION['user_id'] ?? 1;

        try {
            $stmt = $pdo->prepare("UPDATE `users` SET `name` = ?, `email` = ? WHERE `id` = ?");
            $stmt->execute([$name, $email, $userId]);
            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;
            setFlash('success', 'Profile information updated successfully!');
        } catch (Exception $e) {
            setFlash('danger', 'Error updating profile: ' . $e->getMessage());
        }
        header('Location: settings.php');
        exit;
    }

    if ($action === 'change_password' && $pdo) {
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';
        $userId = $_SESSION['user_id'] ?? 1;

        if (strlen($newPass) < 6) {
            setFlash('danger', 'Password must be at least 6 characters.');
        } elseif ($newPass !== $confirmPass) {
            setFlash('danger', 'Passwords do not match.');
        } else {
            $hashed = password_hash($newPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE `users` SET `password` = ? WHERE `id` = ?");
            $stmt->execute([$hashed, $userId]);
            setFlash('success', 'Password changed successfully!');
        }
        header('Location: settings.php');
        exit;
    }
}

$flash = getFlash();

$pageTitle = 'System & Admin Settings';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<main class="main-content">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-body">
        <?php if ($flash): ?>
            <div class="alert-box alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation' ?>"></i>
                <span><?= htmlspecialchars($flash['message']) ?></span>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <h1 class="page-title">System Settings & Preferences</h1>
                <p class="page-subtitle">Configure diagnostic parameters, clinic branding, and security controls</p>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
            <!-- Profile Settings -->
            <div class="glass-panel" style="padding: 24px;">
                <h3 class="card-title" style="margin-bottom: 20px;">
                    <i class="fa-regular fa-user" style="color: var(--primary); margin-right: 8px;"></i>
                    Admin Profile Settings
                </h3>
                <form action="settings.php" method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($_SESSION['user_name'] ?? 'System Administrator') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($_SESSION['user_email'] ?? 'admin@pulsemark.com') ?>" required>
                    </div>

                    <button type="submit" class="btn btn-primary">Save Profile Changes</button>
                </form>
            </div>

            <!-- Password Change -->
            <div class="glass-panel" style="padding: 24px;">
                <h3 class="card-title" style="margin-bottom: 20px;">
                    <i class="fa-solid fa-lock" style="color: var(--secondary); margin-right: 8px;"></i>
                    Security & Password
                </h3>
                <form action="settings.php" method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">

                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" placeholder="••••••••" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" placeholder="••••••••" required>
                    </div>

                    <button type="submit" class="btn btn-secondary">Update Password</button>
                </form>
            </div>

            <!-- Environment & Database Status -->
            <div class="glass-panel" style="padding: 24px; grid-column: span 2;">
                <h3 class="card-title" style="margin-bottom: 16px;">
                    <i class="fa-solid fa-server" style="color: var(--success); margin-right: 8px;"></i>
                    System Health & Environment Status
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                    <div style="background: var(--bg-surface-elevated); padding: 16px; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                        <div style="font-size: 0.8rem; color: var(--text-muted);">Database Connection</div>
                        <div style="font-weight: 700; color: <?= $pdo ? 'var(--success)' : 'var(--danger)' ?>; margin-top: 4px;">
                            <?= $pdo ? 'Connected (PDO MySQL)' : 'Offline / Standalone Demo Mode' ?>
                        </div>
                    </div>

                    <div style="background: var(--bg-surface-elevated); padding: 16px; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                        <div style="font-size: 0.8rem; color: var(--text-muted);">PHP Version</div>
                        <div style="font-weight: 700; color: var(--primary); margin-top: 4px;">
                            PHP <?= phpversion() ?>
                        </div>
                    </div>

                    <div style="background: var(--bg-surface-elevated); padding: 16px; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                        <div style="font-size: 0.8rem; color: var(--text-muted);">Application Version</div>
                        <div style="font-weight: 700; color: var(--text-main); margin-top: 4px;">
                            <?= APP_VERSION ?> (Release Candidate)
                        </div>
                    </div>

                    <div style="background: var(--bg-surface-elevated); padding: 16px; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                        <div style="font-size: 0.8rem; color: var(--text-muted);">Session Security</div>
                        <div style="font-weight: 700; color: var(--success); margin-top: 4px;">
                            Active & Protected
                        </div>
                    </div>
                </div>
            </div>
        </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
