<aside class="sidebar">
    <div class="sidebar-header">
        <a href="dashboard.php" class="brand-logo" style="display: flex; align-items: center; gap: 12px; text-decoration: none;">
            <img src="../assets/pulsemark_logo.png" alt="Pulse Mark" style="height: 42px; width: auto; object-fit: contain;">
            <div style="display: flex; flex-direction: column; line-height: 1.15;">
                <span style="font-size: 1.25rem; font-weight: 800; font-family: 'Outfit', sans-serif; letter-spacing: -0.02em;">
                    <span style="color: #38bdf8;">Pulse</span><span style="color: #f97316;">Mark</span>
                </span>
                <span style="font-size: 0.68rem; color: #10b981; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase;">Agent Portal</span>
            </div>
        </a>
        <button type="button" class="sidebar-close-btn" onclick="toggleMobileSidebar()" aria-label="Close Sidebar">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <div class="sidebar-menu">
        <div class="menu-category">Workspace</div>
        
        <a href="dashboard.php" class="nav-link <?= isActivePage('dashboard') || isActivePage('index') ? 'active' : '' ?>">
            <i class="fa-solid fa-gauge-high"></i>
            <span>Dashboard</span>
        </a>

        <a href="registered_users.php" class="nav-link <?= isActivePage('registered_users') ? 'active' : '' ?>">
            <i class="fa-solid fa-users-gear"></i>
            <span>Registered Employees</span>
        </a>

        <a href="face_attendance.php" class="nav-link <?= isActivePage('face_attendance') ? 'active' : '' ?>">
            <i class="fa-solid fa-camera"></i>
            <span>Face Attendance</span>
        </a>

        <a href="biometric_attendance.php" class="nav-link <?= isActivePage('biometric_attendance') ? 'active' : '' ?>">
            <i class="fa-solid fa-fingerprint"></i>
            <span>Biometric Attendance</span>
        </a>

        <a href="biometric_entry.php" class="nav-link <?= isActivePage('biometric_entry') ? 'active' : '' ?>">
            <i class="fa-solid fa-fingerprint"></i>
            <span>New Biometric Entry</span>
        </a>

        <a href="face_entry.php" class="nav-link <?= isActivePage('face_entry') ? 'active' : '' ?>">
            <i class="fa-solid fa-camera"></i>
            <span>New Face Entry</span>
        </a>

        <a href="attendance_report.php" class="nav-link <?= isActivePage('attendance_report') ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-column"></i>
            <span>Attendance Report</span>
        </a>

        <div class="menu-category">Account</div>

        <a href="profile.php" class="nav-link <?= isActivePage('profile') ? 'active' : '' ?>">
            <i class="fa-solid fa-id-badge"></i>
            <span>My Profile</span>
        </a>

        <?php if (!empty($isGuestMode)): ?>
            <a href="#" onclick="openLoginModal(); return false;" class="nav-link" style="color: #38bdf8; font-weight: 700; margin-top: auto;">
                <i class="fa-solid fa-arrow-right-to-bracket"></i>
                <span>Sign In / Login</span>
            </a>
        <?php else: ?>
            <a href="logout.php" class="nav-link" style="color: var(--danger); margin-top: auto;">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span>Sign Out</span>
            </a>
        <?php endif; ?>
    </div>

    <div class="sidebar-footer" onclick="<?= !empty($isGuestMode) ? 'openLoginModal()' : '' ?>" style="<?= !empty($isGuestMode) ? 'cursor: pointer;' : '' ?>" title="<?= !empty($isGuestMode) ? 'Click to Sign In' : '' ?>">
        <div class="user-profile-badge">
            <div class="avatar" style="background: <?= !empty($isGuestMode) ? 'linear-gradient(135deg, #0ea5e9, #6366f1)' : 'linear-gradient(135deg, #10b981, #06b6d4)' ?>;">
                <?php if (!empty($isGuestMode)): ?>
                    <i class="fa-solid fa-user-lock" style="font-size: 0.95rem;"></i>
                <?php else: ?>
                    <?= strtoupper(substr($currentAgent['name'] ?? 'A', 0, 1)) ?>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($currentAgent['name'] ?? 'Agent') ?></div>
                <div class="user-role" style="color: <?= !empty($isGuestMode) ? '#38bdf8' : '#10b981' ?>;">
                    <?= !empty($isGuestMode) ? '<i class="fa-solid fa-key fa-xs"></i> Click to Sign In' : 'Field Agent' ?>
                </div>
            </div>
        </div>
    </div>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleMobileSidebar()"></div>