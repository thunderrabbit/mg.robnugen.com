<?php
/**
 * @var array{id: int, swept_on: string, previous_id: int|null, previous_on: string|null}|null $sweep
 * @var string $filter
 * @var list<array<mixed>> $users
 */
$h = \Inventory\AdminPage::h(...);
include __DIR__ . '/_nav.tpl.php';
?>
<h1>Users <small>(<?= count($users) ?>)</small></h1>
<p>Show: <a href="/admin/dh/users.php">all</a> &middot; <a href="?show=nodomain">no domain</a> &middot; <a href="?show=shared">more than one site</a>
   <?= $filter !== '' ? '&mdash; now showing <strong>' . $h($filter) . '</strong>' : '' ?></p>
<div class="PagePanel">
    <table class="issue-table sortable-table">
        <thead>
            <tr><th aria-sort="ascending"><button type="button" data-col="0" data-type="text">User</button></th><th><button type="button" data-col="1" data-type="text">Access</button></th><th><button type="button" data-col="2" data-type="num" data-first="desc">Disk</button></th><th><button type="button" data-col="3" data-type="num" data-first="desc">Sites</button></th><th>Which</th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $row): ?>
            <tr>
                <td data-sort="<?= $h($row['username']) ?>"><a href="/admin/dh/user.php?dh_user_id=<?= (int) $row['dh_user_id'] ?>"><?= $h($row['username']) ?></a></td>
                <td data-sort="<?= $h($row['access']) ?>"><?= $h($row['access']) ?></td>
                <td data-sort="<?= $h($row['disk_bytes']) ?>"><?= \Inventory\AdminPage::bytes($row['disk_bytes']) ?></td>
                <td data-sort="<?= $h($row['sites']) ?>"><?= $h($row['sites']) ?></td>
                <td><small><?= $h($row['domains']) ?></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <script src="/js/<?= SEMVER ?>/sortable-table.js"></script>
    <aside class="L2Note">
        <strong>Layer 2 idea: can the AI log in as this user?</strong>
        <p>Only about 10 of the users have a working login so far. A column <em>Login</em> would show
        &#10003; verified (with date) / &#10007; failed / &ldquo;never tried&rdquo;, plus the ssh alias to use.
        It would also show an <em>estimated age</em> from clues found by looking around the home directory
        (oldest file, dotfile dates), which is how the unattached users get triaged: &ldquo;created 2019, last touched 2020, 2 MB.&rdquo;</p>
        <table class="issue-table L2Mock">
            <tr><th>User</th><th>Login</th><th>Age (clue)</th><th>Last touched</th><th>Triage</th></tr>
            <tr><td>olduser</td><td>&#10003; 2026-11-02</td><td>&ge; 2019 (oldest file)</td><td>2020-03</td><td>archive?</td></tr>
        </table>
    </aside>
</div>
