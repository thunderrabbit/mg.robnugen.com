<div class="dashboard-container">
    <header class="dashboard-header">
        <a href="/exterm/?project_id=<?= (int)$project_id ?>" class="btn-sm btn-back">← Back to <?= htmlspecialchars($project_name) ?></a>
        <h1>New Exterminal Item</h1>
    </header>

    <div class="card">
        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/exterm/create.php">
            <input type="hidden" name="project_id" value="<?= (int)$project_id ?>">

            <div class="form-row">
                <div class="form-field">
                    <label for="kind">Kind</label>
                    <?php $sel_kind = $form_kind ?? 'task'; ?>
                    <select id="kind" name="kind">
                        <option value="task"<?= $sel_kind === 'task'    ? ' selected' : '' ?>>task</option>
                        <option value="context"<?= $sel_kind === 'context' ? ' selected' : '' ?>>context</option>
                    </select>
                </div>

                <div class="form-field">
                    <label for="risk">Risk</label>
                    <?php $sel_risk = $form_risk ?? 'reversible'; ?>
                    <select id="risk" name="risk">
                        <option value="reversible"<?= $sel_risk === 'reversible'   ? ' selected' : '' ?>>reversible</option>
                        <option value="irreversible"<?= $sel_risk === 'irreversible' ? ' selected' : '' ?>>irreversible</option>
                    </select>
                </div>
            </div>

            <div class="form-field">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" maxlength="255" required
                       value="<?= htmlspecialchars($form_title ?? '') ?>">
            </div>

            <div class="form-field">
                <label for="body">Body (markdown, optional)</label>
                <textarea id="body" name="body" rows="8"><?= htmlspecialchars($form_body ?? '') ?></textarea>
            </div>

            <div class="form-field">
                <label for="assignee_aiu">Assignee</label>
                <?php $sel_assignee = isset($form_assignee_aiu) && $form_assignee_aiu !== '' ? (int)$form_assignee_aiu : null; ?>
                <select id="assignee_aiu" name="assignee_aiu">
                    <option value=""<?= $sel_assignee === null ? ' selected' : '' ?>>— unassigned —</option>
                    <?php foreach ($assignee_choices as $c): ?>
                        <option value="<?= (int)$c['aiu_id'] ?>"<?= $sel_assignee === (int)$c['aiu_id'] ? ' selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['actor_type']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Create Item</button>
                <a href="/exterm/?project_id=<?= (int)$project_id ?>" class="btn-cancel">Cancel</a>
            </div>
        </form>
    </div>
</div>
