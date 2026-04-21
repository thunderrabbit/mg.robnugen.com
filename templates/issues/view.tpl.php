<div class="dashboard-container">
    <header class="dashboard-header">
        <h1><?= htmlspecialchars($issue['title']) ?></h1>
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
</div>
