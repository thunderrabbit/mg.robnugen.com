<?php
/**
 * Shared header for the DreamHost inventory pages (mg #775).
 *
 * @var array{id: int, swept_on: string, previous_id: int|null, previous_on: string|null}|null $sweep
 */
?>
<div class="PagePanel">
    <strong>DreamHost inventory</strong> &middot;
    <a href="/admin/inventory/">overview</a> &middot;
    <a href="/admin/inventory/domains.php">domains</a> &middot;
    <a href="/admin/inventory/users.php">users</a> &middot;
    <a href="/admin/inventory/databases.php">databases</a> &middot;
    <a href="/admin/inventory/changes.php">changes</a>
    <br />
    <?php if ($sweep === null): ?>
        <em>No sweep imported yet.</em>
    <?php else: ?>
        <small>Showing the sweep of <strong><?= $sweep['swept_on'] ?></strong>
        <?= $sweep['previous_on'] === null ? '(the first one)' : '(previous: ' . $sweep['previous_on'] . ')' ?>.
        Read-only: nothing here changes DreamHost.</small>
    <?php endif; ?>
</div>
