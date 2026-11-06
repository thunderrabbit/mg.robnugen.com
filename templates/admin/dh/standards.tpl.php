<?php
/**
 * Which sites have done each written-up step. Calm on purpose: every site
 * here is working, so the only marks are a checkmark, an info circle and a
 * crying-laughing face for a site nobody can reach yet.
 *
 * @var array{id: int, swept_on: string, previous_id: int|null, previous_on: string|null}|null $sweep
 * @var list<array{slug: string, name: string, proven: int, sites: list<array{shortcode: string, project_id: ?int, autonomy: string, last_checked_utc: ?string, skipped_reason: ?string, report: \Inventory\Standards\SiteReport}>, ideas: list<array{track: string, title: string}>}> $standards
 */

use Inventory\Standards\Names;
use Inventory\Standards\WhoseMove;

$h = \Inventory\AdminPage::h(...);
$md = \Utilities::renderMarkdown(...);
include __DIR__ . '/_nav.tpl.php';
?>
<h1>Site standards</h1>
<p><small>Which sites have done each written-up step. A step is ticked by a check, never by hand.
Every site here is working, so nothing on this page is urgent.</small></p>

<?php if ($standards === []): ?>
    <div class="PagePanel"><p>No step files imported yet.</p></div>
<?php endif; ?>

<?php foreach ($standards as $standard): ?>
<div class="PagePanel">
    <h3><?= $h($standard['name']) ?>
        <small><?= $standard['proven'] ?> proven <?= $standard['proven'] === 1 ? 'step' : 'steps' ?></small></h3>

    <?php if ($standard['sites'] === []): ?>
        <p>No sites entered yet.</p>
    <?php endif; ?>

    <?php foreach ($standard['sites'] as $site): ?>
        <?php $report = $site['report']; ?>
        <details class="StdSite" id="site-<?= $h($site['shortcode']) ?>">
            <summary>
                <span class="<?= $report->standing->mark->cssClass() ?>"><?= $report->standing->mark->glyph() ?></span>
                <strong><?= $h($site['shortcode']) ?></strong>
                <span class="StdSummary"><?= $h($report->standing->summary) ?></span>
            </summary>

            <?php if ($report->lines === []): ?>
                <p>No proven steps yet.</p>
            <?php else: ?>
            <table class="StdSteps">
                <?php $track = null; ?>
                <?php foreach ($report->lines as $line): ?>
                    <tr>
                        <th scope="row"><?= $line->trackName === $track ? '' : $h($line->trackName) ?></th>
                        <td>
                            <span class="StdTick"><?= $line->done ? '✓' : '·' ?></span>
                            <?= $h($line->title) ?>
                            <?php if ($line->nah): ?>
                                <span class="StdNote">NAh</span>
                            <?php elseif (!$line->done): ?>
                                <span class="StdNote"><?= $line->whoseMove === WhoseMove::Rob ? 'your move' : 'an agent can do this' ?></span>
                            <?php endif; ?>
                            <?php if ($line->detail !== ''): ?>
                                <small><?= $h($line->detail) ?></small>
                            <?php endif; ?>
                            <?php foreach ($line->issues as $issue): ?>
                                <small><a href="/issues/view.php?issue_id=<?= $issue->issueId ?>">#<?= $issue->issueId ?>
                                    <?= $h($issue->statusLabel) ?></a></small>
                            <?php endforeach; ?>
                            <?php if (!$line->done): ?>
                                <div class="StdWhy"><?= $md($line->explanation) ?></div>
                                <?php if ($line->howTo !== ''): ?>
                                    <details class="StdHowTo">
                                        <summary>How to do it</summary>
                                        <?= $md($line->howTo) ?>
                                    </details>
                                <?php endif; ?>
                                <?php if ($line->issues === []): ?>
                                    <small>Notes for this site go in an issue titled
                                        <code><?= $h(Names::noteTitle($line->slug, $site['shortcode'])) ?></code>.</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php $track = $line->trackName; ?>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>

            <p class="StdFoot"><small>
                Working mode: <?= $h($site['autonomy']) ?>.
                <?php if ($site['project_id'] === null): ?>
                    No mg project linked, so no notes.
                <?php else: ?>
                    Notes live in <a href="/projects/view.php?project_id=<?= $site['project_id'] ?>">project
                    <?= $site['project_id'] ?></a>.
                <?php endif; ?>
                <?php if ($site['last_checked_utc'] === null): ?>
                    Trunk not checked yet.
                <?php else: ?>
                    Trunk last checked <?= $h($site['last_checked_utc']) ?> UTC.
                <?php endif; ?>
                <?php if ($site['skipped_reason'] !== null): ?>
                    The latest scan skipped it: <?= $h($site['skipped_reason']) ?>.
                <?php endif; ?>
            </small></p>

            <?php if ($report->strays !== []): ?>
                <p class="StdFoot"><small>Notes that match no step here:
                    <?php foreach ($report->strays as $stray): ?>
                        <a href="/issues/view.php?issue_id=<?= $stray->issueId ?>">#<?= $stray->issueId ?>
                            <?= $h($stray->title) ?></a>
                    <?php endforeach; ?>
                </small></p>
            <?php endif; ?>
        </details>
    <?php endforeach; ?>

    <?php if ($standard['ideas'] !== []): ?>
        <h4>Ideas not yet tried on any site</h4>
        <table class="StdSteps">
            <?php foreach ($standard['ideas'] as $idea): ?>
                <tr><th scope="row"><?= $h($idea['track']) ?></th><td><?= $h($idea['title']) ?></td></tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>
<?php endforeach; ?>
