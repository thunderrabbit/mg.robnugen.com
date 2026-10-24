<?php
/**
 * @var array{id: int, swept_on: string, previous_id: int|null, previous_on: string|null}|null $sweep
 * @var array<mixed> $user
 * @var list<array<mixed>> $states
 * @var list<array<mixed>> $usage
 * @var list<array<mixed>> $sites
 * @var list<array<mixed>> $crons
 * @var list<array<mixed>> $clues
 */
$h = \Inventory\AdminPage::h(...);
include __DIR__ . '/_nav.tpl.php';
?>
<h1><?= $h($user['username']) ?></h1>

<div class="PagePanel">
    <h3>Sites this user owns now (<?= count($sites) ?>)</h3>
    <?php if ($sites === []): ?>
        <p>None. This user has no domain attached.</p>
    <?php else: ?>
        <table class="issue-table">
            <tr><th>Domain</th><th>PHP</th><th>Directory</th></tr>
            <?php foreach ($sites as $row): ?>
                <tr>
                    <td><a href="/admin/inventory/domain.php?dh_domain_id=<?= (int) $row['dh_domain_id'] ?>"><?= $h($row['domain']) ?></a></td>
                    <td><?= $h($row['php_version']) ?></td>
                    <td><small><?= $h($row['web_directory']) ?></small></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
    <?php if (count($sites) > 1): ?>
        <aside class="L2Note">
            <strong>Layer 2 idea: these sites share one home directory</strong>
            <p>The AI could list what each site actually shares with the others (files, a database, a cron job) and say
            <em>&ldquo;<?= $h($sites[0]['domain']) ?> and <?= $h($sites[1]['domain']) ?> share nothing &mdash; safe to give each its own user&rdquo;</em>,
            or propose a shared database with per-site grants where they do share.</p>
        </aside>
    <?php endif; ?>
</div>

<div class="PagePanel">
    <h3>Access history</h3>
    <table class="issue-table">
        <tr><th>Sweeps</th><th>Access</th></tr>
        <?php foreach ($states as $row): ?>
            <tr><td><?= $h($row['from_on']) ?> &rarr; <?= $row['until_on'] === null ? '<em>now</em>' : $h($row['until_on']) ?></td><td><?= $h($row['access']) ?></td></tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="PagePanel">
    <h3>Disk use over time</h3>
    <table class="issue-table">
        <tr><th>Swept</th><th>Disk</th></tr>
        <?php foreach ($usage as $row): ?>
            <tr><td><?= $h($row['swept_on']) ?></td><td><?= \Inventory\AdminPage::bytes($row['disk_bytes']) ?></td></tr>
        <?php endforeach; ?>
    </table>
</div>

<?php if ($crons !== []): ?>
<div class="PagePanel">
    <h3>Panel cron jobs</h3>
    <table class="issue-table">
        <tr><th>Title</th><th>Active</th><th>Output emailed to</th></tr>
        <?php foreach ($crons as $row): ?>
            <tr><td><?= $h($row['title']) ?></td><td><?= $row['active'] ? 'yes' : 'no' ?></td><td><?= $h($row['output_emailed_to']) ?></td></tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<div class="PagePanel">
    <h3>Age clues</h3>
    <?php if ($clues === []): ?>
        <p>None gathered yet.</p>
    <?php else: ?>
        <table class="issue-table">
            <tr><th>Existed by</th><th>Kind</th><th>Note</th></tr>
            <?php foreach ($clues as $row): ?>
                <tr><td><?= $h($row['existed_by']) ?></td><td><?= $h($row['clue_kind']) ?></td><td><?= $h($row['note']) ?></td></tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
    <aside class="L2Note">
        <strong>Layer 2 idea: logging in and looking around</strong>
        <p>Once <code>ssh <?= $h($user['username']) ?></code> is verified, a read-only look at the home directory fills this in:
        oldest and newest file dates, which web directories exist, whether anything is still being written, and
        &ldquo;is there old information here I was looking for?&rdquo; (a search over file names, never contents unless Rob asks).
        Each login test is recorded with its date so a stale login shows up.</p>
        <table class="issue-table L2Mock">
            <tr><th>Login</th><td>alias <code>ssh <?= $h($user['username']) ?></code> &mdash; verified 2026-10-25 &#10003;</td></tr>
            <tr><td>Looked around</td><td>oldest file 2019-04, newest 2020-03, 2 MB, 14 files</td></tr>
            <tr><td>Triage</td><td>archive &mdash; &ldquo;nothing live; download first&rdquo;</td></tr>
        </table>
    </aside>
</div>
