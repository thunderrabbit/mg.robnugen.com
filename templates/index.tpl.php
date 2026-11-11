<div class="PagePanel">
    Welcome back, <?= htmlspecialchars($username) ?>!
</div>

<h1>Slide Chat Forest</h1>

<div class="PagePanel">
    <h2>Quick Actions</h2>
    <ul>
        <li><a href="/mg">Meditation Timer</a></li>
        <?php if (!empty($is_admin)): ?>
            <li><a href="/admin/">Admin Dashboard</a></li>
        <?php endif; ?>
        <li><a href="/profile/">Profile Settings</a></li>
    </ul>
</div>

<div class="PagePanel">
    <h2>Site Status</h2>
    <p>Everything is running smoothly.</p>
    <?php if (isset($site_version)): ?>
        <p><small>Version: <?= htmlspecialchars($site_version) ?></small></p>
    <?php endif; ?>
</div>
