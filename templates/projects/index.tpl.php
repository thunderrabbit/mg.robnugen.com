<div class="dashboard-container">
    <header class="dashboard-header">
        <h1>Projects</h1>
    </header>

    <div class="card">
        <?php if (empty($projects)): ?>
            <div class="empty-state">
                <p>You have no projects yet.</p>
            </div>
        <?php else: ?>
            <ul class="project-list">
                <?php foreach ($projects as $project): ?>
                    <li class="project-item">
                        <strong class="project-name"><?= htmlspecialchars($project['name']) ?></strong>
                        <?php if (!empty($project['description'])): ?>
                            <p class="project-description"><?= nl2br(htmlspecialchars($project['description'])) ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
