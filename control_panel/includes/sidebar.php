<aside class="sidebar">
    <div class="sidebar-header">
        <a href="dashboard.php" class="brand-logo" style="display: flex; align-items: center; gap: 12px; text-decoration: none;">
            <img src="../assets/pulsemark_logo.png" alt="Pulse Mark" style="height: 42px; width: auto; object-fit: contain;">
            <div style="display: flex; flex-direction: column; line-height: 1.15;">
                <span style="font-size: 1.25rem; font-weight: 800; font-family: 'Outfit', sans-serif; letter-spacing: -0.02em;">
                    <span style="color: #38bdf8;">Pulse</span><span style="color: #f97316;">Mark</span>
                </span>
                <span style="font-size: 0.68rem; color: #94a3b8; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase;">Control Panel</span>
            </div>
        </a>
        <button type="button" class="sidebar-close-btn" onclick="toggleMobileSidebar()" aria-label="Close Sidebar">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <div class="sidebar-menu">
        <div class="menu-category">Main Operations</div>
        
        <?php if (hasPermission('dashboard')): ?>
        <a href="dashboard.php" class="nav-link <?= isActivePage('dashboard') || isActivePage('index') ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-pie"></i>
            <span>Dashboard</span>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('employee')): ?>
        <a href="employee.php" class="nav-link <?= isActivePage('employee') || isActivePage('employees') ? 'active' : '' ?>">
            <i class="fa-solid fa-id-badge"></i>
            <span>Employee</span>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('agents')): ?>
        <a href="agents.php" class="nav-link <?= isActivePage('agents') ?>">
            <i class="fa-solid fa-user-shield"></i>
            <span>Agent</span>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('biometric_entry')): ?>
        <a href="biometric_entry.php" class="nav-link <?= isActivePage('biometric_entry') ? 'active' : '' ?>">
            <i class="fa-solid fa-fingerprint"></i>
            <span>Biometric Entry (ZK)</span>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('face_entry')): ?>
        <a href="face_entry.php" class="nav-link <?= isActivePage('face_entry') ? 'active' : '' ?>">
            <i class="fa-solid fa-camera"></i>
            <span>Face Entry (AI)</span>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('attendance_report')): ?>
        <a href="attendance_report.php" class="nav-link <?= isActivePage('attendance_report') ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-column"></i>
            <span>Attendance Report</span>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('users') || hasPermission('audit_logs') || hasPermission('settings')): ?>
        <div class="menu-category">System</div>
        <?php endif; ?>

        <?php if (hasPermission('users')): ?>
        <a href="users.php" class="nav-link <?= isActivePage('users') ?>">
            <i class="fa-solid fa-users-gear"></i>
            <span>System Users & Permissions</span>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('audit_logs')): ?>
        <a href="audit_logs.php" class="nav-link <?= isActivePage('audit_logs') ?>">
            <i class="fa-solid fa-clipboard-list"></i>
            <span>Audit Logs</span>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('settings')): ?>
        <a href="settings.php" class="nav-link <?= isActivePage('settings') ?>">
            <i class="fa-solid fa-sliders"></i>
            <span>Settings</span>
        </a>
        <?php endif; ?>

        <a href="logout.php" class="nav-link" style="color: var(--danger); margin-top: auto;">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>

    <div class="sidebar-footer">
        <div class="user-profile-badge">
            <div class="avatar"><?= strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)) ?></div>
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($currentUser['name'] ?? 'Admin') ?></div>
                <div class="user-role"><?= htmlspecialchars($currentUser['role'] ?? 'Administrator') ?></div>
            </div>
        </div>
    </div>
</aside>
