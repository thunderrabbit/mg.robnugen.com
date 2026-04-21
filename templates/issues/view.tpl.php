<div class="dashboard-container">
    <header class="dashboard-header">
        <h1><span class="issue-id">#<?= (int)$issue['issue_id'] ?></span> <?= htmlspecialchars($issue['title']) ?></h1>
        <div class="header-actions">
            <a href="/projects/view.php?project_id=<?= (int)$issue['project_id'] ?>" class="btn-sm">← Back to <?= htmlspecialchars($issue['project_name']) ?></a>
        </div>
    </header>

    <div class="card">
        <div class="issue-meta">
            <span class="issue-status"><?= htmlspecialchars($issue['status_label']) ?></span>
            <span class="issue-author">Opened by <?= htmlspecialchars($issue['author_name']) ?></span>
            <?php if (!empty($issue['assignee_name'])): ?>
                <span class="issue-assignee">assigned to <?= htmlspecialchars($issue['assignee_name']) ?></span>
            <?php else: ?>
                <span class="issue-assignee">unassigned</span>
            <?php endif; ?>
        </div>

        <?php if (!empty($issue['description'])): ?>
            <div class="issue-description">
                <?= nl2br(htmlspecialchars($issue['description'])) ?>
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
                            <?= nl2br(htmlspecialchars($comment['body'])) ?>
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
