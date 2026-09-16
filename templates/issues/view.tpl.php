<?php

/**
 * Issue detail.
 *
 * @var \Issues\IssueDetail $issue
 * @var list<\Issues\CommentView> $comments
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
