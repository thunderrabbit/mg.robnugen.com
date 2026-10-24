<?php
/**
 * @var array{id: int, swept_on: string, previous_id: int|null, previous_on: string|null}|null $sweep
 * @var array<mixed> $domain
 * @var list<array<mixed>> $sites
 * @var list<array<mixed>> $certs
 * @var list<array<mixed>> $registrations
 */
$h = \Inventory\AdminPage::h(...);
include __DIR__ . '/_nav.tpl.php';
$span = static fn (array $row): string => $h($row['from_on']) . ' &rarr; ' . ($row['until_on'] === null ? '<em>now</em>' : $h($row['until_on']));
?>
<h1><?= $h($domain['domain']) ?></h1>
<p>Part of <strong><?= $h($domain['base_domain']) ?></strong>.
   <?= $domain['registered_on'] === null ? 'Not registered through DreamHost.' : 'First registered ' . $h($domain['registered_on']) . '.' ?></p>

<div class="PagePanel">
    <h3>Website history</h3>
    <?php if ($sites === []): ?>
        <p>Never listed on Manage Websites.</p>
    <?php else: ?>
        <p><small>Each row holds from the first sweep shown until the sweep it changed in. A new row means something changed.</small></p>
        <table class="issue-table">
            <tr><th>Sweeps</th><th>Type</th><th>Owner</th><th>PHP</th><th>Server</th><th>Directory</th></tr>
            <?php foreach ($sites as $row): ?>
                <tr>
                    <td><?= $span($row) ?></td>
                    <td><?= $h($row['type']) ?></td>
                    <td><?php if ($row['dh_user_id'] !== null): ?><a href="/admin/inventory/user.php?dh_user_id=<?= (int) $row['dh_user_id'] ?>"><?= $h($row['username']) ?></a><?php endif; ?></td>
                    <td><?= $h($row['php_version']) ?></td>
                    <td><?= $h($row['server']) ?></td>
                    <td><small><?= $h($row['web_directory']) ?></small></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>

<div class="PagePanel">
    <h3>Certificate history</h3>
    <table class="issue-table">
        <tr><th>Sweeps</th><th>HTTPS</th><th>Expires</th></tr>
        <?php foreach ($certs as $row): ?>
            <tr><td><?= $span($row) ?></td><td><?= $h($row['https']) ?></td><td><?= $h($row['cert_expires']) ?></td></tr>
        <?php endforeach; ?>
    </table>
</div>

<?php if ($registrations !== []): ?>
<div class="PagePanel">
    <h3>Registration history</h3>
    <table class="issue-table">
        <tr><th>Sweeps</th><th>Registry</th><th>Expires</th><th>Auto-renew</th><th>Lock</th><th>Privacy</th><th>Status</th></tr>
        <?php foreach ($registrations as $row): ?>
            <tr>
                <td><?= $span($row) ?></td>
                <td><?= $h($row['registry']) ?></td>
                <td><?= $h($row['expires']) ?></td>
                <td><?= $row['auto_renew'] ? 'on (' . $h($row['auto_renew_on']) . ')' : '<strong>OFF</strong>' ?></td>
                <td><?= $row['transfer_lock'] ? 'yes' : 'no' ?></td>
                <td><?= $row['privacy'] ? 'yes' : 'no' ?></td>
                <td><?= $h($row['status']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<div class="PagePanel">
    <h3>What layer 2 could say about this site</h3>
    <aside class="L2Note">
        <strong>Site profile <small>(hand-filled once, then versioned like everything else)</small></strong>
        <table class="issue-table L2Mock">
            <tr><th>Stack</th><td>template descendant (<code>new-DH-user-site</code> 0.49) &mdash; or WordPress, MediaWiki, static&hellip;</td></tr>
            <tr><td>Source</td><td>GitHub repo and the branch production serves, or &ldquo;server only &mdash; no copy on Lemur&rdquo;</td></tr>
            <tr><td>Deploy / rollback</td><td>git push + ssh pull / how to undo it</td></tr>
            <tr><td>Login</td><td>ssh alias <code>ssh barefoot_rob</code> &mdash; last verified 2026-10-25 &#10003;</td></tr>
            <tr><td>Uses</td><td>database <code>site_db</code>, cron &ldquo;rebuild site&rdquo;, bucket <code>my-bucket</code></td></tr>
            <tr><td>Triage</td><td>keep &middot; note &middot; linked mg issue</td></tr>
        </table>
    </aside>
    <aside class="L2Note">
        <strong>Smoke test <small>(must be written per site)</small></strong>
        <table class="issue-table L2Mock">
            <tr><th>Check</th><th>Expect</th><th>Last run</th></tr>
            <tr><td>GET <code>/</code></td><td>200, contains &ldquo;Rob Nugen&rdquo;</td><td>&#10003; 2026-11-03 09:12</td></tr>
            <tr><td>GET <code>/login/</code></td><td>200, a login form</td><td>&#10003; 2026-11-03 09:12</td></tr>
            <tr><td>error log, last hour</td><td>no PHP fatal</td><td>&#10003;</td></tr>
        </table>
    </aside>
    <aside class="L2Note">
        <strong>PHP trials and update rounds</strong>
        <table class="issue-table L2Mock">
            <tr><th>When</th><th>What</th><th>Result</th></tr>
            <tr><td>2026-11-03</td><td>PHP 8.3 &rarr; 8.5</td><td>pass; one deprecation fixed</td></tr>
            <tr><td>2026-11-10</td><td>security round: CSRF tokens</td><td>done</td></tr>
        </table>
    </aside>
</div>
