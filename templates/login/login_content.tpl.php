<div class="form-container">
    <h2 style="margin-top: 0;">Log In</h2>
    <?php if (isset($show_success_message) && $show_success_message): ?>
        <div style="background-color: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 20px;">
            ✓ Account created successfully! Please log in with your new credentials.
        </div>
    <?php endif; ?>
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
            <input type="submit" value="Log In">
        </div>
    </form>

    <p style="margin-top: 20px; color: #7f8c8d;">
        Don't have an account? <a href="/login/register.php" style="color: #3498db;">Create one</a>
    </p>
</div>
