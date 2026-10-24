<?php
/**
 * @var array{id: int, swept_on: string, previous_id: int|null, previous_on: string|null}|null $sweep
 * @var string $filter_type
 * @var string $filter_php
 * @var bool $filter_cert
 * @var list<array<mixed>> $domains
 */
$h = \Inventory\AdminPage::h(...);
include __DIR__ . '/_nav.tpl.php';
?>
<h1>Domains <small>(<?= count($domains) ?>)</small></h1>
<p>
    <?php if ($filter_type !== '' || $filter_php !== '' || $filter_cert): ?>
        Filtered
        <?= $filter_type !== '' ? 'by type <strong>' . $h($filter_type) . '</strong> ' : '' ?>
        <?= $filter_php !== '' ? 'by PHP <strong>' . $h($filter_php) . '</strong> ' : '' ?>
        <?= $filter_cert ? 'to certificate problems ' : '' ?>
        &mdash; <a href="/admin/inventory/domains.php">show all</a>
    <?php else: ?>
        Filters: <a href="?type=hosted">hosted</a> &middot; <a href="?type=hosted&amp;cert=problem">no real certificate</a> &middot;
        <a href="?type=dns_only">dns only</a> &middot; <a href="?type=redirect">redirects</a>
    <?php endif; ?>
</p>
<div class="PagePanel">
    <table class="issue-table">
        <tr><th>Domain</th><th>Type</th><th>Owner</th><th>PHP</th><th>HTTPS</th><th>Cert expires</th><th>Registered until</th></tr>
        <?php foreach ($domains as $row): ?>
            <tr>
                <td><a href="/admin/inventory/domain.php?dh_domain_id=<?= (int) $row['dh_domain_id'] ?>"><?= $h($row['domain']) ?></a></td>
                <td><?= $h($row['type']) ?></td>
                <td><?php if ($row['dh_user_id'] !== null): ?><a href="/admin/inventory/user.php?dh_user_id=<?= (int) $row['dh_user_id'] ?>"><?= $h($row['username']) ?></a><?php endif; ?></td>
                <td><?= $h($row['php_version']) ?></td>
                <td><?= $h($row['https']) ?></td>
                <td><?= $h($row['cert_expires']) ?></td>
                <td><?= $h($row['reg_expires']) ?><?= $row['reg_expires'] !== null && !$row['auto_renew'] ? ' <strong>(no auto-renew)</strong>' : '' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <aside class="L2Note">
        <strong>Layer 2 idea: the columns this table is missing</strong>
        <p>The panel knows how a site is hosted, not what it is. Layer 2 would add columns like these,
        each filterable, so "show me every WordPress site not yet tested on 8.5" is one click:</p>
        <table class="issue-table L2Mock">
            <tr><th>Domain</th><th>Stack</th><th>Source</th><th>Last smoke test</th><th>PHP trial</th><th>Triage</th></tr>
            <tr><td>robnugen.com</td><td>template descendant 0.49</td><td>GitHub &middot; master</td><td>&#10003; 2026-11-03</td><td>8.5 pass</td><td>keep</td></tr>
            <tr><td>wiki.robnugen.com</td><td>MediaWiki 1.4x</td><td>server only</td><td>none</td><td>untested</td><td>undecided</td></tr>
        </table>
    </aside>
</div>
