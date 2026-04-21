<div class="dashboard-container">
    <?php if (isset($msg)): ?>
        <?php
        $msgs = [
            'project_not_found' => 'Project not found.',
            'issue_not_found'   => 'Issue not found.',
        ];
        $text = $msgs[$msg] ?? null;
        ?>
        <?php if ($text !== null): ?>
            <div class="alert-error">
                <?= htmlspecialchars($text) ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

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
                        <strong class="project-name">
                            <a href="/projects/view.php?project_id=<?= (int)$project['project_id'] ?>">
                                <?= htmlspecialchars($project['name']) ?>
                            </a>
                        </strong>
                        <span class="project-issue-count">
                            <?php $count = (int)($project['open_issue_count'] ?? 0); ?>
                            <?= $count ?> open <?= $count === 1 ? 'issue' : 'issues' ?>
                        </span>
                        <?php if (!empty($project['description'])): ?>
                            <p class="project-description"><?= nl2br(htmlspecialchars($project['description'])) ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
