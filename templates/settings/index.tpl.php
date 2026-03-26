<div class="PagePanel">
    <div class="head"><h5>Agents</h5></div>

    <?php if (!empty($success_message)): ?>
        <div class="success-message" style="color: var(--alert-success-text); padding: 10px; margin: 10px 0; border: 1px solid var(--alert-success-border); background-color: var(--alert-success-bg);">
            <?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="error-message" style="color: var(--alert-error-text); padding: 10px; margin: 10px 0; border: 1px solid var(--alert-error-border); background-color: var(--alert-error-bg);">
            <?= $error_message ?>
        </div>
    <?php endif; ?>

    <div class="agent-tabs" style="display: flex; gap: 0; border-bottom: 2px solid var(--border-light); margin-bottom: 15px;">
        <button type="button" class="agent-tab agent-tab-active" onclick="showAgentTab('existing')" id="tab-existing" style="padding: 8px 16px; border: none; background: none; cursor: pointer; font-weight: 600; border-bottom: 2px solid var(--primary); margin-bottom: -2px;">Existing Agents</button>
        <button type="button" class="agent-tab" onclick="showAgentTab('create')" id="tab-create" style="padding: 8px 16px; border: none; background: none; cursor: pointer; color: var(--text-faint); margin-bottom: -2px;">+ Create Agent</button>
    </div>

    <div id="agent-panel-existing">
        <?php if (!empty($actors)): ?>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="text-align: left; padding: 6px; border-bottom: 1px solid var(--border-light);">Name</th>
                    <th style="text-align: left; padding: 6px; border-bottom: 1px solid var(--border-light);">Type</th>
                    <th style="text-align: left; padding: 6px; border-bottom: 1px solid var(--border-light);">Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($actors as $actor): ?>
                <tr>
                    <td style="padding: 6px;"><?= htmlspecialchars($actor['name']) ?></td>
                    <td style="padding: 6px;"><?= htmlspecialchars($actor['actor_type']) ?></td>
                    <td style="padding: 6px; font-size: 0.85em; color: var(--text-faint);"><?= htmlspecialchars($actor['description'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p style="color: var(--text-faint);">No agents yet. Create one using the tab above.</p>
        <?php endif; ?>
    </div>

    <div id="agent-panel-create" style="display: none;">
        <form action="/settings/" method="POST" class="mainForm">
            <input type="hidden" name="api_key_action" value="create_agent">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="PageRow noborder">
                <label for="agent_name">Name:</label>
                <div class="PageInput">
                    <input type="text" name="agent_name" id="agent_name" placeholder="e.g. Carrie" maxlength="100" required />
                </div>
                <div class="fix"></div>
            </div>
            <div class="PageRow noborder">
                <label for="agent_description">Description:</label>
                <div class="PageInput">
                    <input type="text" name="agent_description" id="agent_description" placeholder="e.g. Hourly inbox agent" maxlength="255" />
                </div>
                <div class="fix"></div>
            </div>
            <div class="PageRow noborder">
                <label>Permissions:</label>
                <div class="PageInput" style="display: grid; grid-template-columns: 1fr 1fr; gap: 4px 20px;">
                    <label><input type="checkbox" name="can_read_inbox" checked> Read Inbox</label>
                    <label><input type="checkbox" name="can_write_inbox"> Write Inbox</label>
                    <label><input type="checkbox" name="can_read_todos"> Read Todos</label>
                    <label><input type="checkbox" name="can_write_todos"> Write Todos</label>
                    <label><input type="checkbox" name="can_read_sessions"> Read Sessions</label>
                    <label><input type="checkbox" name="can_write_sessions"> Write Sessions</label>
                    <label><input type="checkbox" name="can_read_emotions"> Read Notebook</label>
                    <label><input type="checkbox" name="can_write_emotions"> Write Notebook</label>
                </div>
                <div class="fix"></div>
            </div>
            <div class="PageRow noborder">
                <input type="submit" value="Create Agent" class="greyishBtn submitForm" />
                <div class="fix"></div>
            </div>
        </form>
    </div>

    <script>
    function showAgentTab(which) {
        document.getElementById('agent-panel-existing').style.display = which === 'existing' ? 'block' : 'none';
        document.getElementById('agent-panel-create').style.display = which === 'create' ? 'block' : 'none';
        document.getElementById('tab-existing').style.borderBottom = which === 'existing' ? '2px solid var(--primary)' : 'none';
        document.getElementById('tab-existing').style.color = which === 'existing' ? '' : 'var(--text-faint)';
        document.getElementById('tab-existing').style.fontWeight = which === 'existing' ? '600' : '';
        document.getElementById('tab-create').style.borderBottom = which === 'create' ? '2px solid var(--primary)' : 'none';
        document.getElementById('tab-create').style.color = which === 'create' ? '' : 'var(--text-faint)';
        document.getElementById('tab-create').style.fontWeight = which === 'create' ? '600' : '';
    }
    </script>
</div>

<div class="PagePanel">
    <div class="head"><h5>API Keys</h5></div>

    <p>Credits remaining: <strong><?= (int)$credits_remaining ?></strong>
        &nbsp; <a href="/billing/">Buy more</a>
    </p>

    <?php if (!empty($new_api_key)): ?>
        <div class="success-message" style="color: var(--alert-success-text); padding: 10px; margin: 10px 0; border: 1px solid var(--alert-success-border); background-color: var(--alert-success-bg);">
            <strong>Your new API key (copy it now — not shown again):</strong><br>
            <code style="font-size: 1em; word-break: break-all;"><?= htmlspecialchars($new_api_key) ?></code>
            <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($new_api_key, ENT_QUOTES) ?>'); this.textContent='Copied!';" style="margin-left: 10px;">Copy</button>
        </div>
    <?php endif; ?>

    <form action="/settings/" method="POST" class="mainForm" style="margin-bottom: 20px;">
        <input type="hidden" name="api_key_action" value="generate">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <fieldset>
            <legend>Generate New Key</legend>
            <div class="PageRow noborder">
                <label for="api_key_label">Label (optional):</label>
                <div class="PageInput">
                    <input type="text" name="api_key_label" id="api_key_label" placeholder="e.g. my sleep agent" maxlength="255" />
                </div>
                <div class="fix"></div>
            </div>
            <div class="PageRow noborder">
                <label for="gen_aiu_id">Actor:</label>
                <div class="PageInput">
                    <select name="aiu_id" id="gen_aiu_id">
                        <?php foreach ($actors as $a): ?>
                        <option value="<?= (int)$a['aiu_id'] ?>"><?= htmlspecialchars($a['name']) ?> (<?= $a['actor_type'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fix"></div>
            </div>
            <div class="PageRow noborder">
                <input type="submit" value="Generate API Key" class="greyishBtn submitForm" />
                <div class="fix"></div>
            </div>
        </fieldset>
    </form>

    <?php if (!empty($api_keys)): ?>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="text-align: left; padding: 6px; border-bottom: 1px solid var(--border-light);">Label</th>
                    <th style="text-align: left; padding: 6px; border-bottom: 1px solid var(--border-light);">Actor</th>
                    <th style="text-align: left; padding: 6px; border-bottom: 1px solid var(--border-light);">Created</th>
                    <th style="text-align: left; padding: 6px; border-bottom: 1px solid var(--border-light);">Last used</th>
                    <th style="padding: 6px; border-bottom: 1px solid var(--border-light);"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($api_keys as $key): ?>
                <tr>
                    <td style="padding: 6px;"><?= htmlspecialchars($key['label'] ?: '—') ?></td>
                    <td style="padding: 6px;">
                        <form action="/settings/" method="POST" style="display:inline;">
                            <input type="hidden" name="api_key_action" value="assign_actor">
                            <input type="hidden" name="key_id" value="<?= (int)$key['key_id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <select name="aiu_id" onchange="this.form.submit();">
                                <?php foreach ($actors as $a): ?>
                                <option value="<?= (int)$a['aiu_id'] ?>" <?= ((int)$key['aiu_id'] === (int)$a['aiu_id']) ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td style="padding: 6px;"><?= htmlspecialchars($key['created_at']) ?></td>
                    <td style="padding: 6px;"><?= htmlspecialchars($key['last_used'] ?: 'never') ?></td>
                    <td style="padding: 6px;">
                        <form action="/settings/" method="POST" style="display:inline;" onsubmit="return confirm('Revoke this key? Any agent using it will immediately lose access.');">
                            <input type="hidden" name="api_key_action" value="revoke">
                            <input type="hidden" name="key_id" value="<?= (int)$key['key_id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="submit" value="Revoke" class="greyishBtn" style="background: var(--danger); color: var(--text-on-accent);">
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="color: var(--text-faint);">No active API keys. Generate one above.</p>
    <?php endif; ?>

    <p style="margin-top: 20px; font-size: 0.9em; color: var(--text-faint);">
        API documentation: <a href="/api/v1/openapi.yaml">openapi.yaml</a>
    </p>
</div>
