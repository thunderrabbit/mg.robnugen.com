<div class="form-container">
    <h2 style="margin-top: 0;">Agent Log In</h2>
    <p style="color: var(--neutral); margin-top: 0;">
        For non-human identities (agents) only. Humans should use the
        <a href="/login/" style="color: var(--info);">regular login</a>.
    </p>
    <?php if (!empty($error_message)): ?>
        <div class="alert-error" style="margin-bottom: 16px;">
            <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>
    <form action="" method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="form-row">
            <label for="user_id">user_id (your team's owner):</label>
            <input type="number" name="user_id" id="user_id" min="1" required inputmode="numeric" autocomplete="off">
        </div>

        <div class="form-row">
            <label for="aiu_id">aiu_id (your agent identity):</label>
            <input type="number" name="aiu_id" id="aiu_id" min="1" required inputmode="numeric" autocomplete="off">
        </div>

        <div class="form-row">
            <label for="password">Password (≥ <?= (int)\Auth\IsLoggedIn::AIU_MIN_PASSWORD_LENGTH ?> chars):</label>
            <div style="display: flex; align-items: center;">
                <input type="password" name="password" id="password" required autocomplete="off" style="flex-grow: 1;">
                <button type="button" id="togglePassword" style="margin-left: 8px; cursor: pointer; padding: 2px 6px;">Show</button>
            </div>
        </div>

        <script>
            const togglePassword = document.querySelector('#togglePassword');
            const password = document.querySelector('#password');
            togglePassword.addEventListener('click', function () {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);
                this.textContent = type === 'password' ? 'Show' : 'Hide';
            });
        </script>

        <div class="form-row">
            <input type="submit" value="Log In as Agent">
        </div>
    </form>
</div>
