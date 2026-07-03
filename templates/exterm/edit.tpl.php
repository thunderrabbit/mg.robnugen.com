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

            <div class="form-actions">
                <button type="submit" class="btn-primary">Save Changes</button>
                <a href="/exterm/view.php?exterm_item_id=<?= (int)$item['exterm_item_id'] ?>" class="btn-cancel">Cancel</a>
            </div>
        </form>
    </div>
</div>
