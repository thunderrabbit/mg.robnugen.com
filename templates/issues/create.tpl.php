<div class="dashboard-container">
    <header class="dashboard-header">
        <a href="/projects/view.php?project_id=<?= (int)$project_id ?>" class="btn-sm btn-back">← Back to <?= htmlspecialchars($project_name) ?></a>
        <h1>New Issue in <?= htmlspecialchars($project_name) ?></h1>
    </header>

    <div class="card">
        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/issues/create.php">
            <input type="hidden" name="project_id" value="<?= (int)$project_id ?>">

            <div class="form-field">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" maxlength="255" required
                       value="<?= htmlspecialchars($form_title ?? '') ?>">
            </div>

            <div class="form-field">
                <label for="description">Description (optional)</label>
                <textarea id="description" name="description" rows="5"><?= htmlspecialchars($form_description ?? '') ?></textarea>
            </div>

            <div class="form-field">
                <label for="assignee_aiu">Assignee</label>
                <?php
                    $selected_assignee = isset($form_assignee_aiu)
                        ? ($form_assignee_aiu === '' ? null : (int)$form_assignee_aiu)
                        : null;
                ?>
                <select id="assignee_aiu" name="assignee_aiu">
                    <option value=""<?= $selected_assignee === null ? ' selected' : '' ?>>— unassigned —</option>
                    <?php foreach ($assignee_choices as $choice): ?>
                        <option value="<?= (int)$choice['aiu_id'] ?>"<?= $selected_assignee === (int)$choice['aiu_id'] ? ' selected' : '' ?>>
                            <?= htmlspecialchars($choice['name']) ?> (<?= htmlspecialchars($choice['actor_type']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-field">
                <label for="priority">Priority</label>
                <?php $sel = $form_priority ?? 'normal'; ?>
                <select id="priority" name="priority">
                    <option value="low"<?= $sel === 'low' ? ' selected' : '' ?>>Low</option>
                    <option value="normal"<?= $sel === 'normal' ? ' selected' : '' ?>>Normal</option>
                    <option value="high"<?= $sel === 'high' ? ' selected' : '' ?>>High</option>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Create Issue</button>
                <a href="/projects/view.php?project_id=<?= (int)$project_id ?>" class="btn-cancel">Cancel</a>
            </div>
        </form>
    </div>
</div>
