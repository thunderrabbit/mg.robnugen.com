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
        &mdash; <a href="/admin/dh/domains.php">show all</a>
    <?php else: ?>
        Filters: <a href="?type=hosted">hosted</a> &middot; <a href="?type=hosted&amp;cert=problem">no real certificate</a> &middot;
        <a href="?type=dns_only">dns only</a> &middot; <a href="?type=redirect">redirects</a>
    <?php endif; ?>
</p>
<div class="PagePanel">
    <table class="issue-table sortable-table">
        <thead>
            <tr><th><button type="button" data-col="0" data-type="text">Domain</button></th><th><button type="button" data-col="1" data-type="text">Type</button></th><th><button type="button" data-col="2" data-type="text">Owner</button></th><th><button type="button" data-col="3" data-type="text">PHP</button></th><th><button type="button" data-col="4" data-type="text">HTTPS</button></th><th><button type="button" data-col="5" data-type="text">Cert expires</button></th><th><button type="button" data-col="6" data-type="text">Registered until</button></th></tr>
        </thead>
        <tbody>
        <?php foreach ($domains as $row): ?>
            <tr>
                <td data-sort="<?= $h($row['domain']) ?>"><a href="/admin/dh/domain.php?dh_domain_id=<?= (int) $row['dh_domain_id'] ?>"><?= $h($row['domain']) ?></a>
                    <a href="<?= $row['https'] === 'lets_encrypt' ? 'https' : 'http' ?>://<?= $h($row['domain']) ?>/" target="<?= $h('dh-visit-' . $row['domain']) ?>" title="Open <?= $h($row['domain']) ?> in its own tab">&#x2197;</a></td>
                <td data-sort="<?= $h($row['type']) ?>"><?= $h($row['type']) ?></td>
                <td data-sort="<?= $h($row['username']) ?>"><?php if ($row['dh_user_id'] !== null): ?><a href="/admin/dh/user.php?dh_user_id=<?= (int) $row['dh_user_id'] ?>"><?= $h($row['username']) ?></a><?php endif; ?></td>
                <td data-sort="<?= $h($row['php_version']) ?>"><?= $h($row['php_version']) ?></td>
                <td data-sort="<?= $h($row['https']) ?>"><?= $h($row['https']) ?></td>
                <td data-sort="<?= $h($row['cert_expires']) ?>"><?= $h($row['cert_expires']) ?></td>
                <td data-sort="<?= $h($row['reg_expires']) ?>"><?= $h($row['reg_expires']) ?><?= $row['reg_expires'] !== null && !$row['auto_renew'] ? ' <strong>(no auto-renew)</strong>' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <script src="/js/<?= SEMVER ?>/sortable-table.js"></script>
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
