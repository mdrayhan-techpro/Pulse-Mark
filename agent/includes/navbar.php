<nav class="top-navbar">
    <div style="display: flex; align-items: center; gap: 16px;">
        <button class="nav-btn" onclick="toggleMobileSidebar()" id="mobileMenuBtn" aria-label="Toggle Navigation">
            <i class="fa-solid fa-bars"></i>
        </button>
        <div style="display: flex; align-items: center; gap: 8px;">
            <?php if (!empty($isGuestMode)): ?>
                <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.35); font-weight: 700;">
                    <i class="fa-solid fa-eye"></i>
                    <span>Guest Preview Mode</span>
                </span>
                <span style="font-size: 0.85rem; color: var(--text-muted); display: none; @media(min-width: 640px){ display: inline; }">
                    Read-Only
                </span>
            <?php else: ?>
                <span class="badge badge-normal" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
                    <i class="fa-solid fa-signal fa-xs"></i>
                    <span>Agent Online</span>
                </span>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Dept: <?= htmlspecialchars($currentAgent['dept']) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="nav-actions">
        <!-- Live Clock -->
        <div style="display: flex; align-items: center; gap: 8px; font-size: 0.88rem; color: var(--text-muted); background: var(--bg-surface-elevated); padding: 8px 14px; border-radius: var(--radius-full); border: 1px solid var(--border-color);">
            <i class="fa-regular fa-clock" style="color: #10b981;"></i>
            <span id="liveClock">--:--:--</span>
        </div>

        <!-- Theme Toggle -->
        <button class="nav-btn" id="themeToggleBtn" title="Toggle Theme">
            <i class="fa-solid fa-sun"></i>
        </button>

        <?php if (!empty($isGuestMode)): ?>
            <!-- Sign In in Guest Mode -->
            <button type="button" class="btn btn-primary btn-sm" onclick="openLoginModal()" style="background: linear-gradient(135deg, #0ea5e9, #10b981); box-shadow: 0 4px 14px rgba(14, 165, 233, 0.35);">
                <i class="fa-solid fa-arrow-right-to-bracket"></i>
                <span>Sign In</span>
            </button>
        <?php else: ?>
            <!-- Quick Attendance for Authorized Agent -->
            <a href="biometric_attendance.php" class="btn btn-primary btn-sm" style="background: linear-gradient(135deg, #10b981, #06b6d4);">
                <i class="fa-solid fa-fingerprint"></i>
                <span>Live Attendance</span>
            </a>
        <?php endif; ?>
    </div>
</nav>