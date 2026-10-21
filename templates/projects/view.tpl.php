<?php

/**
 * One project: its description, its open (and optionally done) issues, and
 * its members.
 *
 * @var \Db\Row\ProjectRow $project
 * @var list<\Issues\BoardIssue> $issues
 * @var list<\Issues\BoardIssue> $done_issues
 * @var bool $show_done
 * @var list<\Projects\MemberView> $members
 */

?>
<div class="dashboard-container">
    <header class="dashboard-header">
        <a href="/projects/" class="btn-sm btn-back">← Back to Projects</a>
        <h1><?= htmlspecialchars($project->name) ?></h1>
    </header>

    <div class="card">
        <?php if ($project->isArchived): ?>
            <p class="archived-notice"><em>This project is archived.</em></p>
        <?php endif; ?>

        <?php if (($project->description ?? '') !== ''): ?>
            <div class="project-description">
                <?= nl2br(htmlspecialchars((string) $project->description)) ?>
            </div>
        <?php else: ?>
            <p class="empty-state"><em>No description.</em></p>
        <?php endif; ?>
    </div>

    <div class="card">
        <header class="dashboard-header">
            <h2>Open Issues</h2>
            <div class="header-actions">
                <a href="/issues/create.php?project_id=<?= $project->projectId ?>" class="btn-new-timer">+ New Issue</a>
                <?php if (!empty($show_done)): ?>
                    <a href="/projects/view.php?project_id=<?= $project->projectId ?>" class="btn-sm">Hide done</a>
                <?php else: ?>
                    <a href="/projects/view.php?project_id=<?= $project->projectId ?>&amp;show_done=1" class="btn-sm">Show done</a>
                <?php endif; ?>
            </div>
        </header>
        <?php
        $priority_rank = ['high' => 0, 'normal' => 1, 'low' => 2];
        $fmt = \Db\RowValue::DATETIME_FORMAT;
        // One table for open and done issues; done ones swap Updated for Done.
        $issue_table = function (array $rows, bool $done) use ($priority_rank, $fmt): void {
            ?>
            <table class="issue-table sortable-table">
                <thead>
                    <tr>
                        <th><button type="button" data-col="0" data-type="num" data-first="desc">#</button></th>
                        <th><button type="button" data-col="1" data-type="text">Title</button></th>
                        <th><button type="button" data-col="2" data-type="num">Status</button></th>
                        <th class="hide-narrow"><button type="button" data-col="3" data-type="num">Priority</button></th>
                        <th><button type="button" data-col="4" data-type="num" data-first="desc" title="Open sub-issues">Open subs</button></th>
                        <th class="hide-narrow"><button type="button" data-col="5" data-type="text">Assignee</button></th>
                        <th class="hide-narrow"><button type="button" data-col="6" data-type="text" data-first="desc">Created</button></th>
                        <th aria-sort="descending"><button type="button" data-col="7" data-type="text" data-first="desc"><?= $done ? 'Done' : 'Updated' ?></button></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $issue    = $row->issue;
                        $priority = $issue->priority->value;
                        $created  = $issue->createdAtUtc->format($fmt);
                        $when     = ($done ? $issue->doneAtUtc : $issue->updatedAtUtc)?->format($fmt) ?? '';
                        $subs     = $row->openChildren;
                        $assignee = $row->assigneeName ?? '';
                        ?>
                        <tr<?= $done ? ' class="issue-row-closed"' : '' ?>>
                            <td data-sort="<?= $issue->issueId ?>" class="issue-id">#<?= $issue->issueId ?></td>
                            <td data-sort="<?= htmlspecialchars(mb_strtolower($issue->title)) ?>">
                                <a href="/issues/view.php?issue_id=<?= $issue->issueId ?>"><?= htmlspecialchars($issue->title) ?></a>
                            </td>
                            <td data-sort="<?= $row->status->sortOrder ?>"><?= htmlspecialchars($row->status->label) ?></td>
                            <td data-sort="<?= $priority_rank[$priority] ?? 9 ?>" class="hide-narrow">
                                <span class="issue-priority issue-priority-<?= htmlspecialchars($priority) ?>"><?= htmlspecialchars($priority) ?></span>
                            </td>
                            <td data-sort="<?= $subs ?>" class="issue-subs"><?= $subs > 0 ? $subs : '' ?></td>
                            <td data-sort="<?= htmlspecialchars(mb_strtolower($assignee)) ?>" class="hide-narrow">
                                <?= htmlspecialchars($assignee) ?>
                            </td>
                            <td data-sort="<?= htmlspecialchars($created) ?>" class="issue-date hide-narrow" title="<?= htmlspecialchars($created) ?> UTC">
                                <?= htmlspecialchars(substr($created, 0, 10)) ?>
                            </td>
                            <td data-sort="<?= htmlspecialchars($when) ?>" class="issue-date"<?= $when !== '' ? ' title="' . htmlspecialchars($when) . ' UTC"' : '' ?>>
                                <?= $when !== '' ? htmlspecialchars(substr($when, 0, 10)) : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        };
        ?>
        <?php if (empty($issues)): ?>
            <p class="empty-state"><em>No open issues in this project.</em></p>
        <?php else: ?>
            <?php $issue_table($issues, false); ?>
        <?php endif; ?>
    </div>

    <?php if (!empty($show_done)): ?>
        <div class="card card-muted">
            <header class="dashboard-header">
                <h2>Done Issues</h2>
            </header>
            <?php if (empty($done_issues)): ?>
                <p class="empty-state"><em>No done issues in this project.</em></p>
            <?php else: ?>
                <?php $issue_table($done_issues, true); ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <script src="/js/<?= SEMVER ?>/sortable-table.js"></script>

    <div class="card">
        <header class="dashboard-header">
            <h2>Members</h2>
            <div class="header-actions">
                <a href="/projects/add-member.php?project_id=<?= $project->projectId ?>" class="btn-new-timer">+ Add Member</a>
            </div>
        </header>
        <?php if (empty($members)): ?>
            <p class="empty-state"><em>No members.</em></p>
        <?php else: ?>
            <ul class="member-list">
                <?php foreach ($members as $member): ?>
                    <li class="member-item">
                        <strong class="member-name"><?= htmlspecialchars($member->name) ?></strong>
                        <span class="member-type">(<?= htmlspecialchars($member->actorType) ?>)</span>
                        <span class="member-perms">
                            <?php if ($member->member->canRead && $member->member->canWrite): ?>
                                Read + Write
                            <?php elseif ($member->member->canRead): ?>
                                Read only
                            <?php else: ?>
                                No access
                            <?php endif; ?>
                        </span>
                        <form method="POST" action="/projects/update-member.php" class="member-perms-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="project_id" value="<?= $project->projectId ?>">
                            <input type="hidden" name="member_aiu" value="<?= $member->member->memberAiu ?>">
                            <label>
                                <input type="checkbox" name="can_read" value="1" <?= $member->member->canRead ? 'checked' : '' ?>>
                                Read
                            </label>
                            <label>
                                <input type="checkbox" name="can_write" value="1" <?= $member->member->canWrite ? 'checked' : '' ?>>
                                Write
                            </label>
                            <button type="submit" class="btn-sm">Update perms</button>
                        </form>
                        <form method="POST" action="/projects/remove-member.php" class="member-remove-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="project_id" value="<?= $project->projectId ?>">
                            <input type="hidden" name="member_aiu" value="<?= $member->member->memberAiu ?>">
                            <button type="submit" class="btn-sm">Remove</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
