<div class="dashboard-container">
    <header class="dashboard-header">
        <h1>Edit <span class="issue-id">#<?= (int)$issue['issue_id'] ?></span></h1>
        <div class="header-actions">
            <a href="/issues/view.php?issue_id=<?= (int)$issue['issue_id'] ?>" class="btn-sm">← Back to issue</a>
        </div>
    </header>

    <div class="card">
        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/issues/edit.php">
            <input type="hidden" name="issue_id" value="<?= (int)$issue['issue_id'] ?>">

            <div class="form-field">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" maxlength="255" required
                       value="<?= htmlspecialchars($form_title ?? $issue['title']) ?>">
            </div>

            <div class="form-field">
                <label for="description">Description (optional)</label>
                <textarea id="description" name="description" rows="6"><?= htmlspecialchars($form_description ?? $issue['description'] ?? '') ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Save changes</button>
                <a href="/issues/view.php?issue_id=<?= (int)$issue['issue_id'] ?>" class="btn-cancel">Cancel</a>
            </div>
        </form>
    </div>
</div>
