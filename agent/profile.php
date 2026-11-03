<?php
require_once __DIR__ . '/../config/init.php';
$isGuestMode = isAgentGuestMode();

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

$pdo = getDB();
$agentId = $currentAgent['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($isGuestMode) {
        setFlash('danger', 'Action Denied: You are in Guest Preview Mode. Please sign in to update profile or security.');
        header('Location: profile.php');
        exit;
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Security token mismatch.');
        header('Location: profile.php');
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'update_profile' && $pdo) {
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');

        try {
            $stmt = $pdo->prepare("UPDATE `users` SET `name` = ?, `email` = ?, `phone` = ? WHERE `id` = ? AND `role` = 'agent'");
            $stmt->execute([$name, $email, $phone, $agentId]);
            $_SESSION['agent_name'] = $name;
            $_SESSION['agent_email'] = $email;
            setFlash('success', 'Profile updated successfully!');
        } catch (Exception $e) {
            setFlash('danger', 'Error updating profile: ' . $e->getMessage());
        }
        header('Location: profile.php');
        exit;
    }

    if ($action === 'change_password' && $pdo) {
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if (strlen($newPass) < 6) {
            setFlash('danger', 'Password must be at least 6 characters.');
        } elseif ($newPass !== $confirmPass) {
            setFlash('danger', 'Passwords do not match.');
        } else {
            $hashed = password_hash($newPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE `users` SET `password` = ? WHERE `id` = ? AND `role` = 'agent'");
            $stmt->execute([$hashed, $agentId]);
            setFlash('success', 'Your password has been changed successfully!');
        }
        header('Location: profile.php');
        exit;
    }
}

// Fetch Agent current details
$agentData = $currentAgent;
if (!empty($isGuestMode)) {
    $agentData = [
        'name' => 'Guest User',
        'email' => 'guest@pulsemark.com',
        'phone' => 'Not Available',
        'username' => 'guest',
        'department' => 'Guest Preview Mode'
    ];
} elseif ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `id` = ?");
        $stmt->execute([$agentId]);
        $res = $stmt->fetch();
        if ($res) $agentData = $res;
    } catch (Exception $e) {}
}

$flash = getFlash();

$pageTitle = 'Agent Profile & Security';
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
                <h1 class="page-title">Agent Profile & Account Settings</h1>
                <p class="page-subtitle">Update contact information and secure your account</p>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
            <!-- Profile Info Form -->
            <div class="glass-panel" style="padding: 24px;">
                <h3 class="card-title" style="margin-bottom: 20px;">
                    <i class="fa-solid fa-id-card" style="color: #10b981; margin-right: 8px;"></i>
                    Agent Information
                </h3>

                <form action="profile.php" method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">

                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($agentData['name'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($agentData['username'] ?? '') ?>" disabled style="opacity: 0.7;">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($agentData['email'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($agentData['phone'] ?? '') ?>">
                    </div>

                    <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #10b981, #06b6d4);">
                        Update Profile
                    </button>
                </form>
            </div>

            <!-- Password Form -->
            <div class="glass-panel" style="padding: 24px;">
                <h3 class="card-title" style="margin-bottom: 20px;">
                    <i class="fa-solid fa-shield" style="color: var(--secondary); margin-right: 8px;"></i>
                    Change Password
                </h3>

                <form action="profile.php" method="POST">
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

                    <button type="submit" class="btn btn-secondary">
                        Change Password
                    </button>
                </form>
            </div>
        </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
