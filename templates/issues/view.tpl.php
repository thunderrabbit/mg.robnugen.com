<?php

/**
 * Issue detail.
 *
 * @var \Issues\IssueDetail $issue
 * @var list<\Issues\CommentView> $comments
 * @var list<\Issues\IssueListItem> $children
 * @var list<\Db\Row\IssueStatusRow> $statuses
 * @var string $msg
 */

?>
<div class="dashboard-container">
    <header class="dashboard-header">
        <a href="/projects/view.php?project_id=<?= $issue->projectId ?>" class="btn-sm btn-back">← Back to <?= htmlspecialchars($issue->projectName) ?></a>
        <h1><span class="issue-id">#<?= $issue->issueId ?></span> <?= htmlspecialchars($issue->title) ?></h1>
        <?php if ($issue->canWrite): ?>
            <div class="header-actions">
                <a href="/issues/create.php?project_id=<?= $issue->projectId ?>" class="btn-sm">+ New Issue</a>
                <a href="/issues/edit.php?issue_id=<?= $issue->issueId ?>" class="btn-sm">Edit</a>
            </div>
        <?php endif; ?>
    </header>

    <?php if (isset($msg)): ?>
        <?php
        $msg_text = [
            'timers_running' => 'Cannot close this issue while timers are running. Stop them first.',
        ][$msg] ?? null;
        ?>
        <?php if ($msg_text !== null): ?>
            <div class="alert-error"><?= htmlspecialchars($msg_text) ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="card">
        <div class="issue-meta">
            <?php if ($issue->canWrite): ?>
                <form method="POST" action="/issues/status.php" class="issue-status-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="issue_id" value="<?= $issue->issueId ?>">
                    <label for="status-picker" class="visually-hidden">Status</label>
                    <select id="status-picker" name="status_id">
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?= $status->statusId ?>"
                                <?= $status->statusId === $issue->statusId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($status->label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-sm">Change status</button>
                </form>
            <?php else: ?>
                <span class="issue-status"><?= htmlspecialchars($issue->statusLabel) ?></span>
            <?php endif; ?>
            <span class="issue-author">Opened by <?= htmlspecialchars($issue->authorName) ?></span>
            <?php if ($issue->assigneeName !== null): ?>
                <span class="issue-assignee">assigned to <?= htmlspecialchars($issue->assigneeName) ?></span>
            <?php else: ?>
                <span class="issue-assignee">unassigned</span>
            <?php endif; ?>
        </div>

        <?php if (($issue->description ?? '') !== ''): ?>
            <div class="issue-description">
                <?= Utilities::renderMarkdown((string) $issue->description) ?>
            </div>
        <?php else: ?>
            <p class="empty-state"><em>No description.</em></p>
        <?php endif; ?>
    </div>

    <?php if ($children !== []): ?>
        <?php
        $priority_rank = ['high' => 0, 'normal' => 1, 'low' => 2];
        $fmt = \Db\RowValue::DATETIME_FORMAT;
        // One filter box per status the children use, in status order.
        $child_statuses = [];
        foreach ($children as $child) {
            $id = $child->status->statusId;
            $child_statuses[$id] ??= ['status' => $child->status, 'count' => 0];
            $child_statuses[$id]['count']++;
        }
        uasort($child_statuses, fn ($a, $b) => $a['status']->sortOrder <=> $b['status']->sortOrder);
        ?>
        <div class="card" id="sub-issues">
            <h2>Sub-issues <span class="sub-issue-count">(<span class="sub-issue-shown"><?= count($children) ?></span> of <?= count($children) ?>)</span></h2>
            <div class="sub-issue-filter">
                <?php foreach ($child_statuses as $id => $entry): ?>
                    <label>
                        <input type="checkbox" value="<?= $id ?>" <?= $entry['status']->isTerminal ? '' : 'checked' ?>>
                        <?= htmlspecialchars($entry['status']->label) ?> (<?= $entry['count'] ?>)
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="empty-state sub-issue-none" hidden><em>No sub-issues with the ticked statuses.</em></p>
            <table class="sub-issue-table">
                <thead>
                    <tr>
                        <th aria-sort="ascending"><button type="button" data-col="0" data-type="num">#</button></th>
                        <th><button type="button" data-col="1" data-type="text">Title</button></th>
                        <th><button type="button" data-col="2" data-type="num">Status</button></th>
                        <th><button type="button" data-col="3" data-type="num">Priority</button></th>
                        <th><button type="button" data-col="4" data-type="text">Created</button></th>
                        <th><button type="button" data-col="5" data-type="text">Done</button></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($children as $child): ?>
                        <?php
                        $row      = $child->issue;
                        $created  = $row->createdAtUtc->format($fmt);
                        $done     = $row->doneAtUtc?->format($fmt) ?? '';
                        $priority = $row->priority->value;
                        ?>
                        <tr class="<?= $child->status->isTerminal ? 'sub-issue-closed' : '' ?>" data-status="<?= $child->status->statusId ?>">
                            <td data-sort="<?= $row->issueId ?>" class="issue-id">#<?= $row->issueId ?></td>
                            <td data-sort="<?= htmlspecialchars(mb_strtolower($row->title)) ?>">
                                <a href="/issues/view.php?issue_id=<?= $row->issueId ?>"><?= htmlspecialchars($row->title) ?></a>
                            </td>
                            <td data-sort="<?= $child->status->sortOrder ?>">
                                <?= htmlspecialchars($child->status->label) ?>
                            </td>
                            <td data-sort="<?= $priority_rank[$priority] ?? 9 ?>">
                                <span class="issue-priority issue-priority-<?= htmlspecialchars($priority) ?>"><?= htmlspecialchars($priority) ?></span>
                            </td>
                            <td data-sort="<?= htmlspecialchars($created) ?>" class="issue-date" title="<?= htmlspecialchars($created) ?> UTC">
                                <?= htmlspecialchars(substr($created, 0, 16)) ?>
                            </td>
                            <td data-sort="<?= htmlspecialchars($done) ?>" class="issue-date"<?= $done !== '' ? ' title="' . htmlspecialchars($done) . ' UTC"' : '' ?>>
                                <?= $done !== '' ? htmlspecialchars(substr($done, 0, 16)) : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <script>
            // Click a header to sort by it; click again to reverse. Each cell's
            // data-sort holds the key, so dates sort as text and ids as numbers.
            document.querySelectorAll('.sub-issue-table').forEach(function (table) {
                var body = table.tBodies[0];
                var headers = table.querySelectorAll('th');
                table.querySelectorAll('th button').forEach(function (button) {
                    button.addEventListener('click', function () {
                        var th = button.parentElement;
                        var col = Number(button.dataset.col);
                        var numeric = button.dataset.type === 'num';
                        var dir = th.getAttribute('aria-sort') === 'ascending' ? -1 : 1;
                        var rows = Array.prototype.slice.call(body.rows);
                        rows.sort(function (a, b) {
                            var x = a.cells[col].dataset.sort;
                            var y = b.cells[col].dataset.sort;
                            var cmp = numeric ? Number(x) - Number(y) : x.localeCompare(y);
                            // Ties fall back to the issue id so the order is stable.
                            return dir * (cmp || Number(a.cells[0].dataset.sort) - Number(b.cells[0].dataset.sort));
                        });
                        rows.forEach(function (row) { body.appendChild(row); });
                        headers.forEach(function (h) { h.removeAttribute('aria-sort'); });
                        th.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
                    });
                });
            });

            // Show only rows whose status is ticked. Terminal statuses start unticked.
            document.querySelectorAll('#sub-issues').forEach(function (card) {
                var boxes = card.querySelectorAll('.sub-issue-filter input');
                var table = card.querySelector('.sub-issue-table');
                function applyFilter() {
                    var shown = {};
                    boxes.forEach(function (box) { shown[box.value] = box.checked; });
                    var visible = 0;
                    Array.prototype.forEach.call(table.tBodies[0].rows, function (row) {
                        row.hidden = !shown[row.dataset.status];
                        if (!row.hidden) {
                            visible++;
                        }
                    });
                    table.hidden = visible === 0;
                    card.querySelector('.sub-issue-none').hidden = visible !== 0;
                    card.querySelector('.sub-issue-shown').textContent = visible;
                }
                boxes.forEach(function (box) { box.addEventListener('change', applyFilter); });
                applyFilter();
            });
        </script>
    <?php endif; ?>

    <div class="card" id="comments">
        <h2>Comments</h2>
        <?php if ($comments === []): ?>
            <p class="empty-state"><em>No comments yet.</em></p>
        <?php else: ?>
            <ul class="comment-list">
                <?php foreach ($comments as $view): ?>
                    <li class="comment-item">
                        <div class="comment-meta">
                            <strong class="comment-author"><?= htmlspecialchars($view->authorName) ?></strong>
                            <span class="comment-date"><?= htmlspecialchars($view->comment->createdAtUtc->format(\Db\RowValue::DATETIME_FORMAT)) ?></span>
                        </div>
                        <div class="comment-body">
                            <?= Utilities::renderMarkdown($view->comment->body) ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($issue->canWrite): ?>
            <form method="POST" action="/issues/comment.php" class="comment-form">
                <?= csrf_field() ?>
                <input type="hidden" name="issue_id" value="<?= $issue->issueId ?>">
                <div class="form-field">
                    <label for="comment-body">Add a comment</label>
                    <textarea id="comment-body" name="body" rows="3" required maxlength="65535"></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-primary">Post comment</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
