<div class="dashboard-container">
    <header class="dashboard-header">
        <h1><?= htmlspecialchars($project['name']) ?></h1>
        <div class="header-actions">
            <a href="/projects/" class="btn-sm">← Back to Projects</a>
        </div>
    </header>

    <div class="card">
        <?php if (!empty($project['is_archived'])): ?>
            <p class="archived-notice"><em>This project is archived.</em></p>
        <?php endif; ?>

        <?php if (!empty($project['description'])): ?>
            <div class="project-description">
                <?= nl2br(htmlspecialchars($project['description'])) ?>
            </div>
        <?php else: ?>
            <p class="empty-state"><em>No description.</em></p>
        <?php endif; ?>
    </div>
</div>
