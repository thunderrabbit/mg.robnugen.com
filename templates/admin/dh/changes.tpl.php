<?php
/**
 * @var array{id: int, swept_on: string, previous_id: int|null, previous_on: string|null}|null $sweep
 * @var list<array<mixed>> $sweeps
 * @var int $chosen
 * @var list<array<mixed>> $changes
 */
$h = \Inventory\AdminPage::h(...);
include __DIR__ . '/_nav.tpl.php';
?>
<h1>What changed</h1>
<p>Pick a sweep: 
    <?php foreach ($sweeps as $row): ?>
        <?php if ((int) $row['dh_sweep_id'] === $chosen): ?>
            <strong><?= $h($row['swept_on']) ?></strong>
        <?php else: ?>
            <a href="?dh_sweep_id=<?= (int) $row['dh_sweep_id'] ?>"><?= $h($row['swept_on']) ?></a>
        <?php endif; ?>
        <?= $row['provenance'] === 'reconstructed' ? '(reconstructed)' : '' ?> &middot;
    <?php endforeach; ?>
</p>
<div class="PagePanel">
    <?php if ($changes === []): ?>
        <p>Nothing opened or closed in this sweep.</p>
    <?php else: ?>
        <p><small>&ldquo;closed&rdquo; = stopped being true as of this sweep; &ldquo;opened&rdquo; = started being true. A change shows as a closed row and an opened row.
        In the very first sweep everything is opened.</small></p>
        <table class="issue-table">
            <tr><th>What</th><th>Thing</th><th></th><th>Detail</th></tr>
            <?php foreach ($changes as $row): ?>
                <tr>
                    <td><?= $h($row['family']) ?></td>
                    <td><?= $h($row['label']) ?></td>
                    <td><?= $row['what'] === 'closed' ? '<strong>closed</strong>' : 'opened' ?></td>
                    <td><small><?= $h($row['detail']) ?></small></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
    <aside class="L2Note">
        <strong>Layer 2 idea: connect a change to its cause</strong>
        <p>A closed/opened pair like <em>robnugen.com PHP 8.3 &rarr; 8.5</em> could link to the PHP trial and mg issue behind it
        (&ldquo;caused by round 2026-11-03&rdquo;), and a change nobody asked for &mdash; a cert that lapsed, a registration whose auto-renew turned off &mdash;
        could be flagged, or sent to Rob's phone.</p>
    </aside>
</div>
