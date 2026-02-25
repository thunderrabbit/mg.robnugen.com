<div class="form-container">
    <h2 style="margin-top: 0;">Create Account</h2>
    <form action="" method="POST">
        <div class="form-row">
            <label for="username">Username:</label>
            <input type="text" name="username" id="username" required>
        </div>

        <div class="form-row">
            <label for="password">Password:</label>
            <input type="password" name="pass" id="password" required>
        </div>

        <div class="form-row">
            <input type="submit" id="register-submit" value="Create Account">
        </div>
    </form>
    <script>
        document.querySelector('form').addEventListener('submit', function() {
            var btn = document.getElementById('register-submit');
            btn.disabled = true;
            btn.value = 'Creating...';
        });
    </script>

    <p style="margin-top: 20px; color: #7f8c8d;">
        Already have an account? <a href="/login/" style="color: #3498db;">Log in</a>
    </p>
</div>
