<nav class="top-navbar">
    <div style="display: flex; align-items: center; gap: 16px;">
        <button class="nav-btn" onclick="toggleMobileSidebar()" id="mobileMenuBtn" aria-label="Toggle Navigation">
            <i class="fa-solid fa-bars"></i>
        </button>
        <div class="nav-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" placeholder="Quick search employee, ID, agent...">
        </div>
    </div>

    <div class="nav-actions">
        <!-- Live System Clock -->
        <div style="display: flex; align-items: center; gap: 8px; font-size: 0.88rem; color: var(--text-muted); background: var(--bg-surface-elevated); padding: 8px 14px; border-radius: var(--radius-full); border: 1px solid var(--border-color);">
            <i class="fa-regular fa-clock" style="color: var(--primary);"></i>
            <span id="liveClock">--:--:--</span>
        </div>

        <!-- Theme Toggle -->
        <button class="nav-btn" id="themeToggleBtn" title="Toggle Dark/Light Mode">
            <i class="fa-solid fa-sun"></i>
        </button>

        <!-- Attendance Shortcut -->
        <a href="attendance_report.php" class="btn btn-primary btn-sm" style="text-decoration: none;">
            <i class="fa-solid fa-chart-column"></i>
            <span>Reports</span>
        </a>
    </div>
</nav>
