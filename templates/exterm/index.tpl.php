<div class="dashboard-container">
    <header class="dashboard-header">
        <h1>Exterminal (ET)</h1>
        <?php if ($project_id): ?>
            <a href="/exterm/create.php?project_id=<?= (int)$project_id ?>" class="btn-primary">+ New Item</a>
        <?php endif; ?>
    </header>

    <?php if (!empty($msg)): ?>
        <div class="alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="GET" action="/exterm/" class="form-row" style="align-items:flex-end; gap:1rem; flex-wrap:wrap;">
            <div class="form-field">
                <label for="project_id">Project</label>
                <select id="project_id" name="project_id" onchange="this.form.submit()">
                    <option value="">— select project —</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= (int)$p['project_id'] ?>"<?= (int)$p['project_id'] === $project_id ? ' selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($project_id): ?>
                <div class="form-field">
                    <label for="kind">Kind</label>
                    <select id="kind" name="kind" onchange="this.form.submit()">
                        <option value="">all</option>
                        <option value="context"<?= $filter_kind === 'context' ? ' selected' : '' ?>>context</option>
                        <option value="task"<?= $filter_kind === 'task'    ? ' selected' : '' ?>>task</option>
                    </select>
                </div>
                <div class="form-field">
                    <label for="status">Status</label>
                    <select id="status" name="status" onchange="this.form.submit()">
                        <option value="">all</option>
                        <?php foreach (['open','in_progress','needs_approval','approved','rejected','done'] as $s): ?>
                            <option value="<?= $s ?>"<?= $filter_status === $s ? ' selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="project_id" value="<?= (int)$project_id ?>">
            <?php endif; ?>
        </form>
    </div>

    <?php if ($project_id && $project_name): ?>
        <div class="card">
            <?php if (empty($items)): ?>
                <p style="color:var(--color-muted);">No items found.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Kind</th>
                            <th>Status</th>
                            <th>Risk</th>
                            <th>Author</th>
                            <th>Assignee</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><a href="/exterm/view.php?exterm_item_id=<?= (int)$item['exterm_item_id'] ?>"><?= htmlspecialchars($item['title']) ?></a></td>
                                <td><?= htmlspecialchars($item['kind']) ?></td>
                                <td><span class="status-badge status-<?= htmlspecialchars($item['status']) ?>"><?= htmlspecialchars($item['status']) ?></span></td>
                                <td><?= htmlspecialchars($item['risk']) ?></td>
                                <td><?= htmlspecialchars($item['author_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($item['assignee_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars(substr($item['updated_at_utc'], 0, 16)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php elseif ($project_id): ?>
        <div class="card"><p style="color:var(--color-muted);">Project not found.</p></div>
    <?php endif; ?>
</div>
