<div class="dashboard-container">
    <header class="dashboard-header">
        <h1>Create Project</h1>
        <div class="header-actions">
            <a href="/projects/" class="btn-sm">← Back to Projects</a>
        </div>
    </header>

    <div class="card">
        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/projects/create.php">
            <div class="form-field">
                <label for="name">Project name</label>
                <input type="text" id="name" name="name" maxlength="100" required
                       value="<?= htmlspecialchars($form_name ?? '') ?>" />
            </div>

            <div class="form-field">
                <label for="description">Description (optional)</label>
                <textarea id="description" name="description" rows="4"><?= htmlspecialchars($form_description ?? '') ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Create Project</button>
                <a href="/projects/" class="btn-cancel">Cancel</a>
            </div>
        </form>
    </div>
</div>
