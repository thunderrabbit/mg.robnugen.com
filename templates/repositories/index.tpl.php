<div class="dashboard-container">
    <?php if (isset($msg)): ?>
        <?php
        $msgs = [
            'repository_not_found' => 'Repository not found.',
            'repository_created'   => 'Repository created.',
            'repository_updated'   => 'Repository updated.',
        ];
        $text = $msgs[$msg] ?? null;
        ?>
        <?php if ($text !== null): ?>
            <div class="alert-info"><?= htmlspecialchars($text) ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <header class="dashboard-header">
        <h1>Repositories</h1>
        <div class="header-actions">
            <a href="/repositories/create.php" class="btn-new-timer">+ New Repository</a>
        </div>
    </header>

    <div class="card">
        <?php if (empty($repositories)): ?>
            <div class="empty-state">
                <p>You have no repositories yet.</p>
            </div>
        <?php else: ?>
            <ul class="repository-list">
                <?php foreach ($repositories as $repo): ?>
                    <li class="repository-item">
                        <strong class="repository-name">
                            <?= htmlspecialchars($repo['name']) ?>
                        </strong>
                        <?php if (!empty($repo['url'])): ?>
                            <span class="repository-url">
                                <?= htmlspecialchars($repo['url']) ?>
                            </span>
                        <?php endif; ?>
                        <span class="repository-branch">
                            default branch: <?= htmlspecialchars($repo['default_branch'] ?? 'master') ?>
                        </span>
                        <?php if (empty($repo['is_active'])): ?>
                            <span class="repository-status">(inactive)</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
