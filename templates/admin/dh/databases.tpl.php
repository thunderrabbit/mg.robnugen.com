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
    <table class="issue-table sortable-table">
        <thead>
            <tr><th><button type="button" data-col="0" data-type="text">Database</button></th><th><button type="button" data-col="1" data-type="text">Server</button></th><th aria-sort="descending"><button type="button" data-col="2" data-type="num" data-first="desc">Size</button></th><th><button type="button" data-col="3" data-type="num" data-first="desc">Since previous sweep</button></th><th><button type="button" data-col="4" data-type="text">MySQL users</button></th><th><button type="button" data-col="5" data-type="text">Description</button></th></tr>
        </thead>
        <tbody>
        <?php foreach ($databases as $row): ?>
            <tr>
                <td data-sort="<?= $h($row['name']) ?>"><?= $h($row['name']) ?></td>
                <td data-sort="<?= $h($row['server']) ?>"><small><?= $h($row['server']) ?></small></td>
                <td data-sort="<?= $h($row['size_bytes']) ?>"><?= \Inventory\AdminPage::bytes($row['size_bytes']) ?></td>
                <td data-sort="<?= $h($row['growth']) ?>"><?= $row['growth'] === null ? '' : $h($row['growth'] > 0 ? '+' . \Inventory\AdminPage::bytes($row['growth']) : \Inventory\AdminPage::bytes($row['growth'])) ?></td>
                <td data-sort="<?= $h($row['db_users']) ?>"><small><?= $h($row['db_users']) ?></small></td>
                <td data-sort="<?= $h($row['description']) ?>"><small><?= $h($row['description']) ?></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <script src="/js/<?= SEMVER ?>/sortable-table.js"></script>
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
