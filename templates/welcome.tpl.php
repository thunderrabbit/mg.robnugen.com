<div class="description">
    <p><strong>A simple countdown timer for your meditation practice</strong></p>
    <p>Meiso Gambare helps you:</p>
    <ul class="features">
        <li>⏱️ Set a countdown timer for your meditation sessions</li>
        <li>🔔 Get notified with a gentle bell when your time is up</li>
        <li>💾 Save your meditation durations (when logged in)</li>
        <li>✨ Pro users can track different activities (sleep, work, exercise, and more)</li>
        <li>📊 Track your meditation history over time (coming soon)</li>
    </ul>
    <?php if (isset($is_logged_in) && $is_logged_in && !$is_admin): ?>
    <div style="margin-top: 20px; padding: 15px; background-color: var(--bg-panel); border-left: 4px solid var(--success);">
        <p><strong>🌟 Upgrade to Pro for More Activities!</strong></p>
        <p>Track more than just meditation:</p>
        <ul>
            <li>😴 Sleep tracking</li>
            <li>💼 Work sessions</li>
            <li>🏃 Physical activity</li>
            <li>🎨 Creative practice</li>
            <li>🤝 Networking</li>
            <li>...and more!</li>
        </ul>
    </div>
    <?php endif; ?>
</div>

<div class="cta">
    <a href="/mg/" class="btn btn-primary" style="margin-bottom: 15px; display: inline-block; font-size: 18px;">🧘 <?php echo (isset($is_logged_in) && $is_logged_in) ? 'Go to Timer' : 'Try the Timer Now'; ?> →</a>
    <br>
    <?php if (!isset($is_logged_in) || !$is_logged_in): ?>
    <a href="/login/" class="btn btn-secondary">Log In</a>
    <a href="/login/register.php" class="btn btn-secondary">Create Account</a>
    <?php endif; ?>
</div>
