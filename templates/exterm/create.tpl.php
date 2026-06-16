<div class="dashboard-container">
    <header class="dashboard-header">
        <a href="/exterm/?project_id=<?= (int)$project_id ?>" class="btn-sm btn-back">← Back to <?= htmlspecialchars($project_name) ?></a>
        <h1>New Exterminal Note</h1>
    </header>

    <div class="card">
        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/exterm/create.php">
            <input type="hidden" name="project_id" value="<?= (int)$project_id ?>">

            <div class="form-field">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" maxlength="255" required
                       value="<?= htmlspecialchars($form_title ?? '') ?>">
            </div>

            <div class="form-field">
                <label for="body">Body (markdown, optional)</label>
                <textarea id="body" name="body" rows="8"><?= htmlspecialchars($form_body ?? '') ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Create Note</button>
                <a href="/exterm/?project_id=<?= (int)$project_id ?>" class="btn-cancel">Cancel</a>
            </div>
        </form>
    </div>
</div>
