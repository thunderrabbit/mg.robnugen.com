<div class="dashboard-container">
    <header class="dashboard-header">
        <a href="/projects/" class="btn-sm btn-back">← Back to Projects</a>
        <h1><?= htmlspecialchars($project['name']) ?></h1>
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
                <?php if (!empty($show_done)): ?>
                    <a href="/projects/view.php?project_id=<?= (int)$project['project_id'] ?>" class="btn-sm">Hide done</a>
                <?php else: ?>
                    <a href="/projects/view.php?project_id=<?= (int)$project['project_id'] ?>&amp;show_done=1" class="btn-sm">Show done</a>
                <?php endif; ?>
            </div>
        </header>
        <?php
            $sort_cols = [
                ['key' => 'updated',  'label' => 'Updated',  'default_dir' => 'desc'],
                ['key' => 'created',  'label' => 'Created',  'default_dir' => 'desc'],
                ['key' => 'status',   'label' => 'Status',   'default_dir' => 'asc'],
                ['key' => 'title',    'label' => 'Title',    'default_dir' => 'asc'],
                ['key' => 'priority', 'label' => 'Priority', 'default_dir' => 'desc'],
            ];
            $base_q = '?project_id=' . (int)$project['project_id']
                . (!empty($show_done) ? '&amp;show_done=1' : '');
        ?>
        <div class="issue-list-controls">
            <span class="sort-label">Sort by:</span>
            <?php foreach ($sort_cols as $col): ?>
                <?php
                    $is_active = ($sort === $col['key']);
                    $link_dir = $is_active
                        ? ($dir === 'asc' ? 'desc' : 'asc')
                        : $col['default_dir'];
                    $arrow = $is_active ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
                ?>
                <a class="sort-link<?= $is_active ? ' sort-link-active' : '' ?>"
                   href="<?= $base_q ?>&amp;sort=<?= htmlspecialchars($col['key']) ?>&amp;dir=<?= htmlspecialchars($link_dir) ?>">
                    <?= htmlspecialchars($col['label']) ?><?= $arrow ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if (empty($issues)): ?>
            <p class="empty-state"><em>No open issues in this project.</em></p>
        <?php else: ?>
            <ul class="issue-list">
                <?php foreach ($issues as $issue): ?>
                    <li class="issue-item">
                        <span class="issue-status"><?= htmlspecialchars($issue['status_label']) ?></span>
                        <span class="issue-priority issue-priority-<?= htmlspecialchars($issue['priority']) ?>"><?= htmlspecialchars($issue['priority']) ?></span>
                        <span class="issue-id">#<?= (int)$issue['issue_id'] ?></span>
                        <span class="issue-title">
                            <a href="/issues/view.php?issue_id=<?= (int)$issue['issue_id'] ?>">
                                <?= htmlspecialchars($issue['title']) ?>
                            </a>
                        </span>
                        <span class="issue-date" title="Created <?= htmlspecialchars($issue['created_at_utc']) ?> UTC">
                            <?= htmlspecialchars(substr($issue['created_at_utc'], 0, 10)) ?>
                        </span>
                        <?php if (!empty($issue['assignee_name'])): ?>
                            <span class="issue-assignee">→ <?= htmlspecialchars($issue['assignee_name']) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php if (!empty($show_done)): ?>
        <div class="card card-muted">
            <header class="dashboard-header">
                <h2>Done Issues</h2>
            </header>
            <?php if (empty($done_issues)): ?>
                <p class="empty-state"><em>No done issues in this project.</em></p>
            <?php else: ?>
                <ul class="issue-list issue-list-done">
                    <?php foreach ($done_issues as $issue): ?>
                        <li class="issue-item issue-item-done">
                            <span class="issue-status"><?= htmlspecialchars($issue['status_label']) ?></span>
                            <span class="issue-priority issue-priority-<?= htmlspecialchars($issue['priority']) ?>"><?= htmlspecialchars($issue['priority']) ?></span>
                            <span class="issue-id">#<?= (int)$issue['issue_id'] ?></span>
                            <span class="issue-title">
                                <a href="/issues/view.php?issue_id=<?= (int)$issue['issue_id'] ?>">
                                    <?= htmlspecialchars($issue['title']) ?>
                                </a>
                            </span>
                            <span class="issue-date" title="Created <?= htmlspecialchars($issue['created_at_utc']) ?> UTC">
                                <?= htmlspecialchars(substr($issue['created_at_utc'], 0, 10)) ?>
                            </span>
                            <?php if (!empty($issue['assignee_name'])): ?>
                                <span class="issue-assignee">→ <?= htmlspecialchars($issue['assignee_name']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

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
                            <?php if ($member['can_read'] && $member['can_write']): ?>
                                Read + Write
                            <?php elseif ($member['can_read']): ?>
                                Read only
                            <?php else: ?>
                                No access
                            <?php endif; ?>
                        </span>
                        <form method="POST" action="/projects/update-member.php" class="member-perms-form">
                            <input type="hidden" name="project_id" value="<?= (int)$project['project_id'] ?>">
                            <input type="hidden" name="member_aiu" value="<?= (int)$member['member_aiu'] ?>">
                            <label>
                                <input type="checkbox" name="can_read" value="1" <?= $member['can_read'] ? 'checked' : '' ?>>
                                Read
                            </label>
                            <label>
                                <input type="checkbox" name="can_write" value="1" <?= $member['can_write'] ? 'checked' : '' ?>>
                                Write
                            </label>
                            <button type="submit" class="btn-sm">Update perms</button>
                        </form>
                        <form method="POST" action="/projects/remove-member.php" class="member-remove-form">
                            <input type="hidden" name="project_id" value="<?= (int)$project['project_id'] ?>">
                            <input type="hidden" name="member_aiu" value="<?= (int)$member['member_aiu'] ?>">
                            <button type="submit" class="btn-sm">Remove</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
