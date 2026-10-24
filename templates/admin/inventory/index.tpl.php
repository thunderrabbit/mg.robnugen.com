<?php
/**
 * @var array{id: int, swept_on: string, previous_id: int|null, previous_on: string|null}|null $sweep
 * @var array<string, int> $counts
 * @var list<array<mixed>> $php_summary
 * @var list<array<mixed>> $cert_problems
 * @var list<array<mixed>> $cert_expiries
 * @var list<array<mixed>> $registrations
 * @var list<array<mixed>> $shared_users
 */
$h = \Inventory\AdminPage::h(...);
include __DIR__ . '/_nav.tpl.php';
?>
<h1>DreamHost inventory</h1>

<div class="PagePanel">
    <h3>The account at a glance</h3>
    <ul>
        <?php foreach ($counts as $what => $n): ?>
            <li><strong><?= $n ?></strong> <?= $h($what) ?></li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="PagePanel">
    <h3>1. Hosted sites by PHP version <small>(oldest first)</small></h3>
    <table class="issue-table">
        <tr><th>PHP</th><th>Sites</th><th>Which</th></tr>
        <?php foreach ($php_summary as $row): ?>
            <tr>
                <td><a href="/admin/inventory/domains.php?type=hosted&amp;php=<?= urlencode((string) $row['php_version']) ?>"><?= $h($row['php_version']) ?></a></td>
                <td><?= $h($row['sites']) ?></td>
                <td><small><?= $h($row['domains']) ?></small></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <aside class="L2Note">
        <strong>Layer 2 idea: test every site on the new PHP</strong>
        <p>Each row becomes a progress line: <em>"33 sites on 8.3 &rarr; 5 tested on 8.5 (4 pass, 1 fail), 28 untested"</em>,
        with a button per site that runs its own smoke test on 8.5, flips the version only after Rob says go, re-tests,
        and rolls back on a failure. The result is a <em>PHP trial</em> row (site, version, date, pass/fail, note) that this page reads back.</p>
        <table class="issue-table L2Mock">
            <tr><th>Site</th><th>Now</th><th>Tried 8.5</th><th>Smoke test</th><th></th></tr>
            <tr><td>robnugen.com</td><td>8.3</td><td>2026-11-03 pass</td><td>3 checks &#10003;</td><td>[switch]</td></tr>
            <tr><td>wiki.robnugen.com</td><td>8.3</td><td>never</td><td>none written yet</td><td>[write test]</td></tr>
        </table>
    </aside>
</div>

<div class="PagePanel">
    <h3>2. Certificates</h3>
    <?php if ($cert_problems === []): ?>
        <p>Every hosted site has a real certificate.</p>
    <?php else: ?>
        <p><strong><?= count($cert_problems) ?></strong> hosted site(s) without a real certificate:</p>
        <ul>
            <?php foreach ($cert_problems as $row): ?>
                <li><a href="/admin/inventory/domain.php?dh_domain_id=<?= (int) $row['dh_domain_id'] ?>"><?= $h($row['domain']) ?></a>
                    (<?= $h($row['https']) ?>)</li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <p>Next Let's Encrypt expiries, counted from the sweep:</p>
    <table class="issue-table">
        <tr><th>Domain</th><th>Expires</th><th>Days</th></tr>
        <?php foreach ($cert_expiries as $row): ?>
            <tr>
                <td><a href="/admin/inventory/domain.php?dh_domain_id=<?= (int) $row['dh_domain_id'] ?>"><?= $h($row['domain']) ?></a></td>
                <td><?= $h($row['cert_expires']) ?></td>
                <td><?= $h($row['days_left']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <aside class="L2Note">
        <strong>Layer 2 idea: security updates across every site</strong>
        <p>The panel can't see software patch levels. Layer 2 would record each site's <em>stack and version</em>
        (template descendant, WordPress, MediaWiki, static) so "a new security release is out" becomes a query:
        <em>"11 sites run the affected version"</em>, then one playbook run per site (like the auth-hardening rounds),
        each recording done / failed / skipped. A certificate that fails to renew could also nudge the phone.</p>
    </aside>
</div>

<div class="PagePanel">
    <h3>3. Domain registrations</h3>
    <table class="issue-table">
        <tr><th>Domain</th><th>Expires</th><th>Days</th><th>Auto-renew</th><th>Lock</th><th>Privacy</th></tr>
        <?php foreach ($registrations as $row): ?>
            <tr>
                <td><a href="/admin/inventory/domain.php?dh_domain_id=<?= (int) $row['dh_domain_id'] ?>"><?= $h($row['domain']) ?></a></td>
                <td><?= $h($row['expires']) ?></td>
                <td><?= $h($row['days_left']) ?></td>
                <td><?= $row['auto_renew'] ? 'on' : '<strong>OFF</strong>' ?></td>
                <td><?= $row['transfer_lock'] ? 'yes' : 'no' ?></td>
                <td><?= $row['privacy'] ? 'yes' : 'no' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <aside class="L2Note">
        <strong>Layer 2 idea: renewals you can trust</strong>
        <p>Each renewal already leaves a new registration state, so after the next sweeps this page can show
        <em>"renewed on time"</em> history. Domains registered <em>elsewhere</em> (not DreamHost) are invisible to the sweep;
        layer 2 could hold those by hand: registrar, expiry, who to ask.</p>
    </aside>
</div>

<div class="PagePanel">
    <h3>4. Users who share a home directory</h3>
    <table class="issue-table">
        <tr><th>User</th><th>Sites</th><th>PHP</th><th>Which</th></tr>
        <?php foreach ($shared_users as $row): ?>
            <tr>
                <td><a href="/admin/inventory/user.php?dh_user_id=<?= (int) $row['dh_user_id'] ?>"><?= $h($row['username']) ?></a></td>
                <td><?= $h($row['sites']) ?></td>
                <td><?= $h($row['php_versions']) ?></td>
                <td><small><?= $h($row['domains']) ?></small></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p><a href="/admin/inventory/users.php?show=nodomain">Users with no domain at all</a> &middot;
       <a href="/admin/inventory/users.php?show=shared">only the shared ones</a></p>
    <aside class="L2Note">
        <strong>Layer 2 idea: a better way for sites to share information</strong>
        <p>Sites under one user can read each other's files, which is the only reason some of them are together.
        Layer 2 would note, per site, <em>what it actually shares with its neighbours</em> (files, a database, a cron)
        and suggest the least-privilege alternative: one user per site plus a shared MySQL database with separate grants
        (the grants table already models that), a small HTTP API between sites, or a DreamObjects bucket.
        It would also say which sites <em>could be split today</em> because they share nothing.</p>
    </aside>
</div>

<div class="PagePanel">
    <aside class="L2Note">
        <strong>Layer 2 idea: triage, and creating new sites</strong>
        <p>Each thing here could carry a keep / archive / delete decision (the <code>dh_triage</code> table exists, empty),
        and a site that is archived or deleted drops out of the PHP and security work above.
        A brand-new site, once set up, would register itself here (stack, repo, owner, smoke test) so it
        is part of the next PHP or security round from day one.</p>
    </aside>
</div>
