<div class="dashboard-container">
    <header class="dashboard-header">
        <h1>Authorize MCP Connection</h1>
    </header>

    <div class="card">
        <p><strong><?= htmlspecialchars($client_id) ?></strong> is requesting access to your Exterminal data.</p>
        <p>Redirecting to: <code><?= htmlspecialchars($redirect_uri) ?></code></p>

        <form method="POST">
            <input type="hidden" name="response_type"         value="code">
            <input type="hidden" name="client_id"             value="<?= htmlspecialchars($client_id) ?>">
            <input type="hidden" name="redirect_uri"          value="<?= htmlspecialchars($redirect_uri) ?>">
            <input type="hidden" name="code_challenge"        value="<?= htmlspecialchars($code_challenge) ?>">
            <input type="hidden" name="code_challenge_method" value="<?= htmlspecialchars($code_challenge_method) ?>">
            <input type="hidden" name="state"                 value="<?= htmlspecialchars($state) ?>">

            <div class="form-field">
                <label for="aiu_id">Act as agent</label>
                <select id="aiu_id" name="aiu_id">
                    <?php foreach ($agent_choices as $a): ?>
                        <option value="<?= (int)$a['aiu_id'] ?>"
                            <?= (stripos($a['name'], 'phone') !== false) ? ' selected' : '' ?>>
                            <?= htmlspecialchars($a['name']) ?>
                            (<?= htmlspecialchars($a['actor_type']) ?>)
                            <?php if ($a['description']): ?>
                                — <?= htmlspecialchars($a['description']) ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Authorize</button>
                <a href="/" class="btn-cancel">Deny</a>
            </div>
        </form>
    </div>
</div>
