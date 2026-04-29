<div class="dashboard-container">
    <header class="dashboard-header">
        <a href="/projects/view.php?project_id=<?= (int)$issue['project_id'] ?>" class="btn-sm btn-back">← Back to <?= htmlspecialchars($issue['project_name']) ?></a>
        <h1><span class="issue-id">#<?= (int)$issue['issue_id'] ?></span> <?= htmlspecialchars($issue['title']) ?></h1>
        <?php if (!empty($issue['can_write'])): ?>
            <div class="header-actions">
                <a href="/issues/edit.php?issue_id=<?= (int)$issue['issue_id'] ?>" class="btn-sm">Edit</a>
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
            <?php if (!empty($issue['can_write'])): ?>
                <form method="POST" action="/issues/status.php" class="issue-status-form">
                    <input type="hidden" name="issue_id" value="<?= (int)$issue['issue_id'] ?>">
                    <label for="status-picker" class="visually-hidden">Status</label>
                    <select id="status-picker" name="status_id">
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?= (int)$s['status_id'] ?>"
                                <?= (int)$s['status_id'] === (int)$issue['status_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-sm">Change status</button>
                </form>
            <?php else: ?>
                <span class="issue-status"><?= htmlspecialchars($issue['status_label']) ?></span>
            <?php endif; ?>
            <span class="issue-author">Opened by <?= htmlspecialchars($issue['author_name']) ?></span>
            <?php if (!empty($issue['assignee_name'])): ?>
                <span class="issue-assignee">assigned to <?= htmlspecialchars($issue['assignee_name']) ?></span>
            <?php else: ?>
                <span class="issue-assignee">unassigned</span>
            <?php endif; ?>
        </div>

        <?php if (!empty($issue['description'])): ?>
            <div class="issue-description">
                <?= Utilities::renderMarkdown($issue['description']) ?>
            </div>
        <?php else: ?>
            <p class="empty-state"><em>No description.</em></p>
        <?php endif; ?>
    </div>

    <div class="card" id="comments">
        <h2>Comments</h2>
        <?php if (empty($comments)): ?>
            <p class="empty-state"><em>No comments yet.</em></p>
        <?php else: ?>
            <ul class="comment-list">
                <?php foreach ($comments as $comment): ?>
                    <li class="comment-item">
                        <div class="comment-meta">
                            <strong class="comment-author"><?= htmlspecialchars($comment['author_name']) ?></strong>
                            <span class="comment-date"><?= htmlspecialchars($comment['created_at_utc']) ?></span>
                        </div>
                        <div class="comment-body">
                            <?= Utilities::renderMarkdown($comment['body']) ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (!empty($issue['can_write'])): ?>
            <form method="POST" action="/issues/comment.php" class="comment-form">
                <input type="hidden" name="issue_id" value="<?= (int)$issue['issue_id'] ?>">
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
