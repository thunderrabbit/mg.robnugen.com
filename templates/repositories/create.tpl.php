<div class="dashboard-container">
    <header class="dashboard-header">
        <h1>Create Repository</h1>
        <div class="header-actions">
            <a href="/repositories/" class="btn-sm">← Back to Repositories</a>
        </div>
    </header>

    <div class="card">
        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/repositories/create.php">
            <div class="form-field">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" maxlength="100" required
                       value="<?= htmlspecialchars($form_name ?? '') ?>" />
            </div>

            <div class="form-field">
                <label for="url">Clone URL (optional)</label>
                <input type="text" id="url" name="url" maxlength="500"
                       placeholder="git@github.com:user/repo.git"
                       value="<?= htmlspecialchars($form_url ?? '') ?>" />
            </div>

            <div class="form-field">
                <label for="default_branch">Default branch</label>
                <input type="text" id="default_branch" name="default_branch" maxlength="100"
                       value="<?= htmlspecialchars($form_default_branch ?? 'main') ?>" />
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Create Repository</button>
                <a href="/repositories/" class="btn-cancel">Cancel</a>
            </div>
        </form>
    </div>
</div>
