<?php

/**
 * Pick actors to add to a project.
 *
 * @var \Db\Row\ProjectRow $project
 * @var list<\Projects\Actor> $available Shown up front, most-used first.
 * @var list<\Projects\Actor> $hidden test_* / auto_* actors behind "show all".
 * @var string $error
 */

?>
<div class="dashboard-container">
    <header class="dashboard-header">
        <a href="/projects/view.php?project_id=<?= $project->projectId ?>" class="btn-sm btn-back">← Back to <?= htmlspecialchars($project->name) ?></a>
        <h1>Add Member to <?= htmlspecialchars($project->name) ?></h1>
    </header>

    <div class="card">
        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (empty($available) && empty($hidden)): ?>
            <p class="empty-state"><em>No actors in your account are available to add — everyone is already a member of this project.</em></p>
        <?php else: ?>
            <form method="POST" action="/projects/add-member.php">
                <?= csrf_field() ?>
                <input type="hidden" name="project_id" value="<?= $project->projectId ?>">

                <div class="form-field">
                    <label>Actors <span class="hint">(check all you want to add)</span></label>
                    <div class="member-pick-list">
                        <?php foreach ($available as $a): ?>
                            <label class="member-pick">
                                <input type="checkbox" name="member_aiu[]" value="<?= $a->aiu ?>"<?= $a->usual ? ' checked' : '' ?>>
                                <?= htmlspecialchars($a->name) ?> (<?= htmlspecialchars($a->actorType) ?>)
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($hidden)): ?>
                        <details>
                            <summary>Show all (<?= count($hidden) ?> more: test_ and auto_ actors)</summary>
                            <div class="member-pick-list">
                                <?php foreach ($hidden as $a): ?>
                                    <label class="member-pick">
                                        <input type="checkbox" name="member_aiu[]" value="<?= $a->aiu ?>">
                                        <?= htmlspecialchars($a->name) ?> (<?= htmlspecialchars($a->actorType) ?>)
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
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
                    <a href="/projects/view.php?project_id=<?= $project->projectId ?>" class="btn-cancel">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
