<?php if (!empty($omg_alerts)): ?>
<div class="OmgAlertBanner">
    <strong>&#9888; <?= count($omg_alerts) ?> system alert<?= count($omg_alerts) > 1 ? 's' : '' ?> need<?= count($omg_alerts) === 1 ? 's' : '' ?> your attention</strong>
    <ul>
        <?php foreach ($omg_alerts as $alert): ?>
        <li>
            <span class="OmgAlertContext"><?= htmlspecialchars($alert['context']) ?></span>
            &mdash;
            <?= htmlspecialchars($alert['message']) ?>
            <span class="OmgAlertTime">(<?= htmlspecialchars($alert['created_at']) ?>)</span>
            <form method="POST" action="/admin/" class="OmgDismissInline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="action" value="dismiss_alert">
                <input type="hidden" name="omg_id" value="<?= (int) $alert['omg_id'] ?>">
                <button type="submit" class="OmgDismissOne">&times;</button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
    <form method="POST" action="/admin/">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="action" value="dismiss_alerts">
        <button type="submit" class="OmgDismissButton">Dismiss all</button>
    </form>
</div>
<?php endif; ?>

<div class="PagePanel">
    What's up <?= htmlspecialchars($username) ?>? <br />
</div>
<h1>Welcome to the Meiso Gambare Admin Dashboard</h1>
<p>This page can show numbers of workers, parts, snippets, etc</p>

<div class="PagePanel">
    <h3>Admin Tools</h3>
    <ul>
        <li><a href="/admin/auth_log.php">View Authentication Log</a> - Monitor login activity and diagnose logout issues</li>
    </ul>
</div>

<?php
if ($has_pending_migrations) {
        echo "<h3>Pending DB Migrations</h3>";
        echo "<a href='/admin/migrate_tables.php'>Click here to migrate tables</a>";
    }
?>

<div class="fix">
    <p>Sentimental version: <?= htmlspecialchars($site_version) ?></p>
</div>
