<div class="PagePanel">
    <h1>Authentication Log</h1>
    <p>Showing last <?= count($log_lines) ?> entries (newest first)</p>
    <p><small>Log file: <code><?= htmlspecialchars($log_file) ?></code></small></p>
</div>

<div class="PagePanel">
    <?php if (empty($log_lines)): ?>
        <p><em>No log entries found.</em></p>
    <?php else: ?>
        <div style="font-family: monospace; font-size: 12px; background: var(--bg-muted); padding: 15px; border-radius: 4px; overflow-x: auto;">
            <?php foreach ($log_lines as $line): ?>
                <div style="padding: 2px 0; white-space: pre;"><?= htmlspecialchars($line) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="PagePanel">
    <p><a href="/admin/">← Back to Admin Dashboard</a></p>
</div>
