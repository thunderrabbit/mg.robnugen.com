<?php
/**
 * @var array{id: int, swept_on: string, previous_id: int|null, previous_on: string|null}|null $sweep
 * @var list<array<mixed>> $databases
 * @var int $empty_count
 * @var bool $empty_only
 * @var list<array<mixed>> $marked_databases
 * @var int $just_marked
 * @var int $just_refused
 */
$h = \Inventory\AdminPage::h(...);
include __DIR__ . '/_nav.tpl.php';
?>
<h1>Databases <small>(<?= count($databases) ?>)</small></h1>
<p>Biggest first. The phpMyAdmin hostnames point at the servers below and are DreamHost's to move; they are never upgrade or health-check targets.</p>
<p>
    <?php if ($empty_only): ?>
        <a href="/admin/dh/databases.php">All databases</a> &middot; <strong>Zero bytes only (<?= $empty_count ?>)</strong>
    <?php else: ?>
        <strong>All databases</strong> &middot; <a href="/admin/dh/databases.php?empty=1">Zero bytes only (<?= $empty_count ?>)</a>
    <?php endif; ?>
    <br /><small>Only a size of exactly <code>0 B</code> in the latest sweep qualifies. Anything in KB or MB is never assumed empty.</small>
</p>
<?php if ($just_marked > 0 || $just_refused > 0): ?>
    <p><strong>Marked <?= $just_marked ?> for deletion.</strong>
    <?= $just_refused > 0 ? $just_refused . ' refused because they are not 0 bytes in the latest sweep.' : '' ?></p>
<?php endif; ?>
<div class="PagePanel">
    <form method="post" action="/admin/dh/triage_databases.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="mark" />
    <table class="issue-table sortable-table">
        <thead>
            <tr><th><button type="button" data-col="0" data-type="text">Database</button></th><th><button type="button" data-col="1" data-type="text">Server</button></th><th aria-sort="descending"><button type="button" data-col="2" data-type="num" data-first="desc">Size</button></th><th><button type="button" data-col="3" data-type="num" data-first="desc">Since previous sweep</button></th><th><button type="button" data-col="4" data-type="text">MySQL users</button></th><th><button type="button" data-col="5" data-type="text">Description</button></th><th><button type="button" data-col="6" data-type="text">Triage</button></th></tr>
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
                <td data-sort="<?= $h($row['decision']) ?>">
                    <?php if ($row['decision'] === 'delete'): ?>
                        marked to delete
                    <?php elseif ($row['size_bytes'] !== null && (int) $row['size_bytes'] === 0): ?>
                        <label><input type="checkbox" name="dh_database_id[]" value="<?= $h($row['dh_database_id']) ?>" /> delete</label>
                    <?php else: ?>
                        <small><?= $h($row['decision']) ?></small>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p><button type="submit">Mark ticked databases for deletion</button>
    <small>This only records your call here. It does not touch DreamHost.</small></p>
    </form>
    <script src="/js/<?= SEMVER ?>/sortable-table.js"></script>
    <?php if ($marked_databases !== []): ?>
        <?php $present = array_values(array_filter($marked_databases, static fn(array $m): bool => $m['present'] === 1 || $m['present'] === '1')); ?>
        <h2>Marked for deletion <small>(<?= count($present) ?> still listed)</small></h2>
        <p>Delete these in the DreamHost panel yourself. The next imported sweep shows here whether each one is gone.</p>
        <table class="issue-table">
            <thead><tr><th>Database</th><th>Server</th><th>Marked</th><th>Now</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($marked_databases as $row): ?>
                <?php $listed = $row['present'] === 1 || $row['present'] === '1'; ?>
                <tr>
                    <td><?= $h($row['name']) ?></td>
                    <td><small><?= $h($row['server']) ?></small></td>
                    <td><small><?= $h($row['decided_at_utc']) ?> UTC</small></td>
                    <td>
                        <?php if (!$listed): ?>
                            <strong>gone</strong> as of the sweep of <?= $h($row['gone_on']) ?>
                        <?php elseif ($row['size_bytes'] !== null && (int) $row['size_bytes'] !== 0): ?>
                            still listed, <strong>no longer 0 bytes</strong> (<?= \Inventory\AdminPage::bytes($row['size_bytes']) ?>): look again before deleting
                        <?php else: ?>
                            still listed, 0 B
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($listed): ?>
                            <form method="post" action="/admin/dh/triage_databases.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="unmark" />
                                <input type="hidden" name="dh_database_id[]" value="<?= $h($row['dh_database_id']) ?>" />
                                <button type="submit">Take back</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($present !== []): ?>
            <p>Names to copy, one per line:</p>
            <textarea readonly rows="<?= count($present) + 1 ?>" cols="40"><?= $h(implode("\n", array_map(static fn(array $m): string => \Field::toString($m['name']), $present))) ?></textarea>
        <?php endif; ?>
    <?php endif; ?>
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
