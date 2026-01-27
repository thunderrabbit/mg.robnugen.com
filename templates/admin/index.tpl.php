<div class="PagePanel">
    What's up <?= $username ?>? <br />
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
    <p>Sentimental version: <?= $site_version ?></p>
</div>
