        </div> <!-- /page-body -->
    </main> <!-- /main-content -->
</div> <!-- /app-container -->

<!-- Universal Guest Mode Login Modal -->
<div class="portal-modal-backdrop" id="guestLoginModal" style="position: fixed; inset: 0; background: rgba(4, 8, 18, 0.82); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); z-index: 1050; display: none; align-items: center; justify-content: center; padding: 16px;" onclick="if(event.target===this) closeLoginModal();">
    <div class="portal-modal-card" style="background: rgba(17, 24, 39, 0.95); border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 20px; width: 100%; max-width: 440px; padding: 32px 28px 28px; box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.75); position: relative;">
        
        <!-- Modal Close Button -->
        <button type="button" onclick="closeLoginModal()" style="position: absolute; top: 18px; right: 18px; background: none; border: none; color: var(--text-muted); font-size: 1.2rem; cursor: pointer; padding: 4px;" aria-label="Close">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <!-- Brand & Title -->
        <div style="text-align: center; margin-bottom: 22px;">
            <img src="../assets/pulsemark_logo.png" alt="Pulse Mark" style="height: 48px; width: auto; object-fit: contain; margin-bottom: 10px;">
            <h2 style="font-size: 1.35rem; font-weight: 800; font-family: 'Outfit', sans-serif; color: var(--text-main); margin: 0;">
                Sign In to Terminal
            </h2>
            <p id="guestModalActionPrompt" style="font-size: 0.84rem; color: var(--text-muted); margin-top: 6px; line-height: 1.4;">
                Enter your credentials to unlock terminal management and operations.
            </p>
        </div>

        <!-- Modal Login Form -->
        <form method="POST" action="" id="guestLoginForm">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="is_guest_login_action" value="1">

            <div class="form-group" style="margin-bottom: 16px;">
                <label style="font-size: 0.84rem; font-weight: 600; color: var(--text-muted); display: block; margin-bottom: 6px;">Username or Email</label>
                <div style="position: relative;">
                    <input 
                        type="text" 
                        name="username" 
                        id="guestLoginUser" 
                        class="form-control" 
                        placeholder="Enter username" 
                        required 
                        style="width: 100%; height: 46px; font-size: 0.92rem; padding-left: 38px;"
                    >
                    <i class="fa-regular fa-user" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-dim);"></i>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 22px;">
                <label style="font-size: 0.84rem; font-weight: 600; color: var(--text-muted); display: block; margin-bottom: 6px;">Password</label>
                <div style="position: relative;">
                    <input 
                        type="password" 
                        name="password" 
                        id="guestLoginPass" 
                        class="form-control" 
                        placeholder="Enter password" 
                        required 
                        style="width: 100%; height: 46px; font-size: 0.92rem; padding-left: 38px; padding-right: 42px;"
                    >
                    <i class="fa-solid fa-lock" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-dim);"></i>
                    <button type="button" onclick="toggleGuestPass('guestLoginPass', this)" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--text-dim); cursor: pointer; padding: 4px;" aria-label="Toggle password">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; height: 46px; font-weight: 700; font-size: 0.95rem; background: linear-gradient(135deg, #0284c7, #10b981); box-shadow: 0 4px 14px rgba(14, 165, 233, 0.4);">
                <span>Sign In to Terminal</span>
                <i class="fa-solid fa-arrow-right"></i>
            </button>
        </form>

        <div style="text-align: center; margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border-color);">
            <a href="../login.php" style="color: var(--text-muted); font-size: 0.82rem; text-decoration: none;">
                Prefer full sign-in page? <strong style="color: #38bdf8;">Go to Login Portal &rarr;</strong>
            </a>
        </div>
    </div>
</div>

<script src="../assets/js/admin.js"></script>
<script src="../assets/js/app.js"></script>

<script>
const IS_GUEST_MODE = <?= !empty($isGuestMode) ? 'true' : 'false' ?>;

function checkGuestAction(actionName) {
    if (IS_GUEST_MODE) {
        openLoginModal(actionName);
        return false;
    }
    return true;
}

function openLoginModal(actionName) {
    const modal = document.getElementById('guestLoginModal');
    const prompt = document.getElementById('guestModalActionPrompt');
    if (prompt) {
        if (actionName) {
            prompt.innerHTML = `You are browsing in <strong>Guest Preview Mode</strong> (Read-Only). Please sign in to <strong style="color: #38bdf8;">${actionName}</strong>.`;
        } else {
            prompt.textContent = 'Enter your credentials to unlock terminal management and operations.';
        }
    }
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        const userField = document.getElementById('guestLoginUser');
        if (userField) setTimeout(() => userField.focus(), 150);
    }
}

function closeLoginModal() {
    const modal = document.getElementById('guestLoginModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

function toggleGuestPass(fieldId, btn) {
    const field = document.getElementById(fieldId);
    if (!field) return;
    const isPass = field.getAttribute('type') === 'password';
    field.setAttribute('type', isPass ? 'text' : 'password');
    const icon = btn.querySelector('i');
    if (icon) {
        icon.className = isPass ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
        icon.style.color = isPass ? 'var(--primary)' : 'var(--text-dim)';
    }
}

// Global auto-interceptor for forms in Guest Mode
document.addEventListener('DOMContentLoaded', () => {
    if (IS_GUEST_MODE) {
        document.querySelectorAll('form:not(#guestLoginForm)').forEach(form => {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                e.stopPropagation();
                openLoginModal('submit changes or perform operations');
                return false;
            });
        });
    }
});
</script>
</body>
</html>