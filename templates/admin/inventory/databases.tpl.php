<?php
/**
 * @var array{id: int, swept_on: string, previous_id: int|null, previous_on: string|null}|null $sweep
 * @var list<array<mixed>> $databases
 */
$h = \Inventory\AdminPage::h(...);
include __DIR__ . '/_nav.tpl.php';
?>
<h1>Databases <small>(<?= count($databases) ?>)</small></h1>
<p>Biggest first. The phpMyAdmin hostnames point at the servers below and are DreamHost's to move; they are never upgrade or health-check targets.</p>
<div class="PagePanel">
    <table class="issue-table">
        <tr><th>Database</th><th>Server</th><th>Size</th><th>Since previous sweep</th><th>MySQL users</th><th>Description</th></tr>
        <?php foreach ($databases as $row): ?>
            <tr>
                <td><?= $h($row['name']) ?></td>
                <td><small><?= $h($row['server']) ?></small></td>
                <td><?= \Inventory\AdminPage::bytes($row['size_bytes']) ?></td>
                <td><?= $row['growth'] === null ? '' : $h($row['growth'] > 0 ? '+' . \Inventory\AdminPage::bytes($row['growth']) : \Inventory\AdminPage::bytes($row['growth'])) ?></td>
                <td><small><?= $h($row['db_users']) ?></small></td>
                <td><small><?= $h($row['description']) ?></small></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <aside class="L2Note">
        <strong>Layer 2 idea: which site uses which database</strong>
        <p>A column <em>Used by</em> filled from the site profiles (a site lists its database), which makes the interesting list
        possible: <em>databases that nothing uses</em> (triage candidates), and <em>databases two sites both use</em>
        (the honest answer to &ldquo;how are these sites sharing data?&rdquo;). Backups could be noted here too.</p>
        <table class="issue-table L2Mock">
            <tr><th>Database</th><th>Used by</th><th>Last backup</th><th>Triage</th></tr>
            <tr><td>shared_db</td><td>games.example.com, gamet.example.com</td><td>2026-10-20</td><td>keep</td></tr>
            <tr><td>old_experiment</td><td><em>nothing found</em></td><td>&mdash;</td><td>undecided</td></tr>
        </table>
    </aside>
</div>
