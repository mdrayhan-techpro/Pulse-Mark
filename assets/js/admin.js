/**
 * Pulse Mark - Admin Dashboard Core JavaScript
 */

document.addEventListener('DOMContentLoaded', () => {
    // Theme Toggle Initialization
    initTheme();

    // Live Clock
    initLiveClock();

    // Modals
    initModals();

    // Table Search Filter
    initTableSearch();
});

/**
 * Theme Switcher
 */
function initTheme() {
    const themeBtn = document.getElementById('themeToggleBtn');
    const savedTheme = localStorage.getItem('pulse_theme') || 'dark';
    
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeIcon(savedTheme);

    if (themeBtn) {
        themeBtn.addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('pulse_theme', newTheme);
            updateThemeIcon(newTheme);
        });
    }
}

function updateThemeIcon(theme) {
    const themeBtn = document.getElementById('themeToggleBtn');
    if (themeBtn) {
        const icon = themeBtn.querySelector('i');
        if (icon) {
            icon.className = theme === 'light' ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
        }
    }
}

/**
 * Real-time Clock
 */
function initLiveClock() {
    const clockEl = document.getElementById('liveClock');
    if (!clockEl) return;

    function update() {
        const now = new Date();
        const timeString = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        clockEl.textContent = timeString;
    }
    update();
    setInterval(update, 1000);
}

/**
 * Modal System
 */
function initModals() {
    // Open modal triggers
    document.querySelectorAll('[data-modal-target]').forEach(trigger => {
        trigger.addEventListener('click', (e) => {
            e.preventDefault();
            const modalId = trigger.getAttribute('data-modal-target');
            const modal = document.getElementById(modalId);
            if (modal) modal.classList.add('active');
        });
    });

    // Close modal buttons
    document.querySelectorAll('.close-modal, [data-modal-close]').forEach(btn => {
        btn.addEventListener('click', () => {
            const modal = btn.closest('.modal-backdrop');
            if (modal) modal.classList.remove('active');
        });
    });

    // Close on backdrop click
    document.querySelectorAll('.modal-backdrop').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    });
}

/**
 * Table Search Filter
 */
function initTableSearch() {
    const searchInputs = document.querySelectorAll('.table-search-input');
    searchInputs.forEach(input => {
        const targetTableId = input.getAttribute('data-target-table');
        const table = document.getElementById(targetTableId);
        if (!table) return;

        input.addEventListener('input', () => {
            const filter = input.value.toLowerCase();
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });
    });
}

/**
 * Mobile Sidebar Drawer & Backdrop System
 */
function toggleMobileSidebar() {
    const sidebar = document.querySelector('.sidebar');
    let backdrop = document.getElementById('sidebarBackdrop');
    
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.id = 'sidebarBackdrop';
        backdrop.className = 'sidebar-backdrop';
        backdrop.addEventListener('click', () => {
            if (sidebar) sidebar.classList.remove('open');
            backdrop.classList.remove('active');
            document.body.style.overflow = '';
        });
        document.body.appendChild(backdrop);
    }

    if (sidebar) {
        const isOpen = sidebar.classList.toggle('open');
        if (isOpen) {
            backdrop.classList.add('active');
            if (window.innerWidth <= 992) {
                document.body.style.overflow = 'hidden';
            }
        } else {
            backdrop.classList.remove('active');
            document.body.style.overflow = '';
        }
    }
}

// Global listeners for responsive drawer navigation
document.addEventListener('DOMContentLoaded', () => {
    // Auto-close drawer when clicking on any menu link on mobile
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 992) {
                const sidebar = document.querySelector('.sidebar');
                const backdrop = document.getElementById('sidebarBackdrop');
                if (sidebar) sidebar.classList.remove('open');
                if (backdrop) backdrop.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    });

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const sidebar = document.querySelector('.sidebar');
            const backdrop = document.getElementById('sidebarBackdrop');
            if (sidebar && sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
                if (backdrop) backdrop.classList.remove('active');
                document.body.style.overflow = '';
            }
        }
    });

    // Reset body overflow on window resize if scaling up to desktop
    window.addEventListener('resize', () => {
        if (window.innerWidth > 992) {
            document.body.style.overflow = '';
            const backdrop = document.getElementById('sidebarBackdrop');
            if (backdrop) backdrop.classList.remove('active');
        }
    });
});

/**
 * ==========================================================================
 * PulseMark Ultra-Modern Dialog & Notification Modal System
 * ==========================================================================
 */

let pulseDialogContainer = null;

function ensurePulseDialogContainer() {
    if (!pulseDialogContainer) {
        pulseDialogContainer = document.createElement('div');
        pulseDialogContainer.id = 'pulseDialogBackdrop';
        pulseDialogContainer.className = 'pulse-dialog-backdrop';
        document.body.appendChild(pulseDialogContainer);
    }
    return pulseDialogContainer;
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/**
 * Show Modern Pulse Alert Dialog
 * @param {string|object} options 
 * @returns {Promise<boolean>}
 */
function showPulseAlert(options) {
    return new Promise((resolve) => {
        let title = 'Notification';
        let message = '';
        let type = 'info';
        let buttonText = 'OK';
        let customIcon = '';

        if (typeof options === 'string') {
            const rawMsg = options.trim();
            const lines = rawMsg.split('\n').map(l => l.trim()).filter(Boolean);

            if (lines.length > 1) {
                title = lines[0].replace(/^[❌✓⚠️ℹ️!* ]+/, '').trim();
                message = lines.slice(1).join('<br>');
            } else {
                message = rawMsg.replace(/^[❌✓⚠️ℹ️!* ]+/, '').trim();
            }

            // Detect type from content
            const lower = rawMsg.toLowerCase();
            if (rawMsg.includes('❌') || lower.includes('blocked') || lower.includes('error') || lower.includes('failed') || lower.includes('cannot') || lower.includes('invalid') || lower.includes('already registered')) {
                type = 'error';
                if (!title || title === 'Notification') title = 'Action Blocked';
            } else if (rawMsg.includes('✓') || lower.includes('success') || lower.includes('completed') || lower.includes('enrolled') || lower.includes('saved')) {
                type = 'success';
                if (!title || title === 'Notification') title = 'Successful!';
            } else if (rawMsg.includes('⚠️') || lower.includes('warning') || lower.includes('please') || lower.includes('select') || lower.includes('choose')) {
                type = 'warning';
                if (!title || title === 'Notification') title = 'Attention Required';
            }
        } else if (typeof options === 'object' && options !== null) {
            title = options.title || 'Notification';
            message = options.message || '';
            type = options.type || 'info';
            buttonText = options.buttonText || 'OK';
            customIcon = options.icon || '';
        }

        // Auto-format employee codes like #M4204 or #EMP-001 with glowing chips
        message = message.replace(/(#([A-Za-z0-9_-]+))/g, '<span class="emp-chip">$1</span>');

        // Choose icon
        let iconClass = 'fa-solid fa-circle-info';
        if (customIcon) {
            iconClass = customIcon;
        } else if (type === 'error') {
            if (message.toLowerCase().includes('fingerprint') || title.toLowerCase().includes('fingerprint')) {
                iconClass = 'fa-solid fa-fingerprint';
            } else if (message.toLowerCase().includes('face') || title.toLowerCase().includes('face')) {
                iconClass = 'fa-solid fa-user-xmark';
            } else {
                iconClass = 'fa-solid fa-circle-xmark';
            }
        } else if (type === 'success') {
            iconClass = 'fa-solid fa-circle-check';
        } else if (type === 'warning') {
            iconClass = 'fa-solid fa-triangle-exclamation';
        }

        const container = ensurePulseDialogContainer();
        container.innerHTML = `
            <div class="pulse-dialog-card type-${type}">
                <div class="pulse-dialog-icon-wrapper">
                    <i class="${iconClass}"></i>
                </div>
                <div class="pulse-dialog-title">${escapeHtml(title)}</div>
                <div class="pulse-dialog-message">${message}</div>
                <div class="pulse-dialog-actions">
                    <button type="button" class="pulse-dialog-btn pulse-dialog-btn-primary" id="pulseDialogOkBtn">
                        <i class="fa-solid fa-check"></i>
                        <span>${escapeHtml(buttonText)}</span>
                    </button>
                </div>
            </div>
        `;

        container.classList.add('active');
        const okBtn = document.getElementById('pulseDialogOkBtn');
        if (okBtn) okBtn.focus();

        const closeDialog = () => {
            container.classList.remove('active');
            document.removeEventListener('keydown', keyHandler);
            resolve(true);
        };

        if (okBtn) okBtn.addEventListener('click', closeDialog);

        const keyHandler = (e) => {
            if (e.key === 'Enter' || e.key === 'Escape' || e.key === ' ') {
                e.preventDefault();
                closeDialog();
            }
        };
        document.addEventListener('keydown', keyHandler);
    });
}

/**
 * Show Modern Pulse Confirm Dialog
 * @param {string|object} options 
 * @returns {Promise<boolean>}
 */
function showPulseConfirm(options) {
    return new Promise((resolve) => {
        let title = 'Confirm Action';
        let message = 'Are you sure you want to proceed?';
        let confirmText = 'Confirm';
        let cancelText = 'Cancel';
        let type = 'warning';
        let confirmBtnClass = 'pulse-dialog-btn-primary';

        if (typeof options === 'string') {
            message = options;
            if (message.toLowerCase().includes('delete')) {
                title = 'Confirm Deletion';
                confirmText = 'Yes, Delete';
                type = 'error';
            }
        } else if (typeof options === 'object' && options !== null) {
            title = options.title || 'Confirm Action';
            message = options.message || '';
            confirmText = options.confirmText || 'Confirm';
            cancelText = options.cancelText || 'Cancel';
            type = options.type || 'warning';
        }

        const container = ensurePulseDialogContainer();
        container.innerHTML = `
            <div class="pulse-dialog-card type-${type}">
                <div class="pulse-dialog-icon-wrapper">
                    <i class="fa-solid ${type === 'error' ? 'fa-trash-can' : 'fa-triangle-exclamation'}"></i>
                </div>
                <div class="pulse-dialog-title">${escapeHtml(title)}</div>
                <div class="pulse-dialog-message">${message}</div>
                <div class="pulse-dialog-actions">
                    <button type="button" class="pulse-dialog-btn pulse-dialog-btn-secondary" id="pulseDialogCancelBtn">
                        <span>${escapeHtml(cancelText)}</span>
                    </button>
                    <button type="button" class="pulse-dialog-btn ${confirmBtnClass}" id="pulseDialogConfirmBtn">
                        <i class="fa-solid ${type === 'error' ? 'fa-trash' : 'fa-check'}"></i>
                        <span>${escapeHtml(confirmText)}</span>
                    </button>
                </div>
            </div>
        `;

        container.classList.add('active');
        const confirmBtn = document.getElementById('pulseDialogConfirmBtn');
        const cancelBtn = document.getElementById('pulseDialogCancelBtn');

        if (confirmBtn) confirmBtn.focus();

        const closeAndResolve = (result) => {
            container.classList.remove('active');
            document.removeEventListener('keydown', keyHandler);
            resolve(result);
        };

        if (confirmBtn) confirmBtn.addEventListener('click', () => closeAndResolve(true));
        if (cancelBtn) cancelBtn.addEventListener('click', () => closeAndResolve(false));

        const keyHandler = (e) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                closeAndResolve(false);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                closeAndResolve(true);
            }
        };
        document.addEventListener('keydown', keyHandler);
    });
}

// Override default browser alert with the gorgeous PulseMark Dialog
window.alert = function(msg) {
    showPulseAlert(msg);
};
