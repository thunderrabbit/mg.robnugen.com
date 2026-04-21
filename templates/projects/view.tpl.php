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

    <div class="card">
        <header class="dashboard-header">
            <h2>Open Issues</h2>
            <div class="header-actions">
                <a href="/issues/create.php?project_id=<?= (int)$project['project_id'] ?>" class="btn-new-timer">+ New Issue</a>
            </div>
        </header>
        <?php if (empty($issues)): ?>
            <p class="empty-state"><em>No open issues in this project.</em></p>
        <?php else: ?>
            <ul class="issue-list">
                <?php foreach ($issues as $issue): ?>
                    <li class="issue-item">
                        <span class="issue-status"><?= htmlspecialchars($issue['status_label']) ?></span>
                        <span class="issue-id">#<?= (int)$issue['issue_id'] ?></span>
                        <span class="issue-title">
                            <a href="/issues/view.php?issue_id=<?= (int)$issue['issue_id'] ?>">
                                <?= htmlspecialchars($issue['title']) ?>
                            </a>
                        </span>
                        <?php if (!empty($issue['assignee_name'])): ?>
                            <span class="issue-assignee">→ <?= htmlspecialchars($issue['assignee_name']) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card">
        <header class="dashboard-header">
            <h2>Members</h2>
            <div class="header-actions">
                <a href="/projects/add-member.php?project_id=<?= (int)$project['project_id'] ?>" class="btn-new-timer">+ Add Member</a>
            </div>
        </header>
        <?php if (empty($members)): ?>
            <p class="empty-state"><em>No members.</em></p>
        <?php else: ?>
            <ul class="member-list">
                <?php foreach ($members as $member): ?>
                    <li class="member-item">
                        <strong class="member-name"><?= htmlspecialchars($member['name']) ?></strong>
                        <span class="member-type">(<?= htmlspecialchars($member['actor_type']) ?>)</span>
                        <span class="member-perms">
                            <?php if ($member['can_write']): ?>Read + Write
                            <?php elseif ($member['can_read']): ?>Read only
                            <?php else: ?>No access
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
