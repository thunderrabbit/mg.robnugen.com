<div class="dashboard-container">
    <header class="dashboard-header">
        <a href="/exterm/view.php?exterm_item_id=<?= (int)$item['exterm_item_id'] ?>" class="btn-sm btn-back">← Back</a>
        <h1>Edit: <?= htmlspecialchars($item['title']) ?></h1>
    </header>

    <div class="card">
        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/exterm/edit.php?exterm_item_id=<?= (int)$item['exterm_item_id'] ?>">

            <div class="form-field">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" maxlength="255" required
                       value="<?= htmlspecialchars($item['title']) ?>">
            </div>

            <div class="form-field">
                <label for="body">Body (markdown)</label>
                <textarea id="body" name="body" rows="10"><?= htmlspecialchars($item['body'] ?? '') ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <?php foreach (['open','in_progress','needs_approval','approved','rejected','done'] as $s): ?>
                            <option value="<?= $s ?>"<?= $item['status'] === $s ? ' selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="risk">Risk</label>
                    <select id="risk" name="risk">
                        <option value="reversible"<?= $item['risk'] === 'reversible'   ? ' selected' : '' ?>>reversible</option>
                        <option value="irreversible"<?= $item['risk'] === 'irreversible' ? ' selected' : '' ?>>irreversible</option>
                    </select>
                </div>

                <div class="form-field">
                    <label for="assignee_aiu">Assignee</label>
                    <?php $cur_assignee = $item['assignee_aiu'] ? (int)$item['assignee_aiu'] : null; ?>
                    <select id="assignee_aiu" name="assignee_aiu">
                        <option value=""<?= $cur_assignee === null ? ' selected' : '' ?>>— unassigned —</option>
                        <?php foreach ($assignee_choices as $c): ?>
                            <option value="<?= (int)$c['aiu_id'] ?>"<?= $cur_assignee === (int)$c['aiu_id'] ? ' selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['actor_type']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Save Changes</button>
                <a href="/exterm/view.php?exterm_item_id=<?= (int)$item['exterm_item_id'] ?>" class="btn-cancel">Cancel</a>
            </div>
        </form>
    </div>
</div>
