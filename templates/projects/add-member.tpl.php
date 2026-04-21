<div class="dashboard-container">
    <header class="dashboard-header">
        <h1>Add Member to <?= htmlspecialchars($project['name']) ?></h1>
        <div class="header-actions">
            <a href="/projects/view.php?project_id=<?= (int)$project['project_id'] ?>" class="btn-sm">← Back to <?= htmlspecialchars($project['name']) ?></a>
        </div>
    </header>

    <div class="card">
        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (empty($available)): ?>
            <p class="empty-state"><em>No actors in your account are available to add — everyone is already a member of this project.</em></p>
        <?php else: ?>
            <form method="POST" action="/projects/add-member.php">
                <input type="hidden" name="project_id" value="<?= (int)$project['project_id'] ?>">

                <div class="form-field">
                    <label for="member_aiu">Actor</label>
                    <select id="member_aiu" name="member_aiu" required>
                        <option value="">— select —</option>
                        <?php foreach ($available as $a): ?>
                            <option value="<?= (int)$a['aiu_id'] ?>">
                                <?= htmlspecialchars($a['name']) ?> (<?= htmlspecialchars($a['actor_type']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label>
                        <input type="checkbox" name="can_read" value="1" checked> Can read
                    </label>
                </div>

                <div class="form-field">
                    <label>
                        <input type="checkbox" name="can_write" value="1"> Can write
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary">Add Member</button>
                    <a href="/projects/view.php?project_id=<?= (int)$project['project_id'] ?>" class="btn-cancel">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
