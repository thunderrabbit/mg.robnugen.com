<div class="dashboard-container">
    <header class="dashboard-header">
        <a href="/projects/view.php?project_id=<?= (int)$project['project_id'] ?>" class="btn-sm btn-back">← Back to <?= htmlspecialchars($project['name']) ?></a>
        <h1>Add Member to <?= htmlspecialchars($project['name']) ?></h1>
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
                    <label>Actors <span class="hint">(check all you want to add)</span></label>
                    <div class="member-pick-list">
                        <?php foreach ($available as $a): ?>
                            <label class="member-pick">
                                <input type="checkbox" name="member_aiu[]" value="<?= (int)$a['aiu_id'] ?>">
                                <?= htmlspecialchars($a['name']) ?> (<?= htmlspecialchars($a['actor_type']) ?>)
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-field">
                    <label>Permissions for all checked actors</label>
                    <label>
                        <input type="checkbox" name="can_read" value="1" checked> Can read
                    </label>
                    <label>
                        <input type="checkbox" name="can_write" value="1" checked> Can write
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
