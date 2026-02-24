<div class="PagePanel">
    <div class="head"><h5 class="iUser">Change Password</h5></div>

    <?php if (!empty($success_message)): ?>
        <div class="success-message" style="color: green; padding: 10px; margin: 10px 0; border: 1px solid green; background-color: #e8f5e8;">
            <?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="error-message" style="color: red; padding: 10px; margin: 10px 0; border: 1px solid red; background-color: #ffe8e8;">
            <?= $error_message ?>
        </div>
    <?php endif; ?>

    <form action="/profile/" method="POST" class="mainForm" style="margin-bottom: 30px;">
        <input type="hidden" name="update_settings_action" value="1">

        <fieldset>
            <legend>Navigation Arrow Colors</legend>
            <div class="PageRow noborder">
                <label for="arrow_color_older">Older Arrow Color:</label>
                <div class="PageInput">
                    <input type="color" id="arrow_color_older" name="arrow_color_older" value="<?= htmlspecialchars($arrow_color_older) ?>" style="height: 40px; width: 60px; padding: 2px;">
                </div>
                <div class="fix"></div>
            </div>

            <div class="PageRow noborder">
                <label for="arrow_color_newer">Newer Arrow Color:</label>
                <div class="PageInput">
                    <input type="color" id="arrow_color_newer" name="arrow_color_newer" value="<?= htmlspecialchars($arrow_color_newer) ?>" style="height: 40px; width: 60px; padding: 2px;">
                </div>
                <div class="fix"></div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Site Customization</legend>
            <div class="PageRow noborder">
                <label for="site_title">Site Title:</label>
                <div class="PageInput">
                    <input type="text" name="site_title" id="site_title" value="<?= htmlspecialchars($site_title === 'Meiso Gambare' ? '' : $site_title) ?>" placeholder="Meiso Gambare" />
                </div>
                <div class="fix"></div>
            </div>

            <div class="PageRow noborder">
                <label for="site_subtitle">Site Subtitle:</label>
                <div class="PageInput">
                    <input type="text" name="site_subtitle" id="site_subtitle" value="<?= htmlspecialchars($site_subtitle === 'Your simple meditation timer' ? '' : $site_subtitle) ?>" placeholder="Your simple meditation timer" />
                </div>
                <div class="fix"></div>
            </div>

            <div class="PageRow noborder">
                <input type="submit" value="Save Settings" class="greyishBtn submitForm" />
                <div class="fix"></div>
            </div>
        </fieldset>
    </form>

    <form action="/profile/" method="POST" class="mainForm">
        <input type="hidden" name="change_password_action" value="1">
        <fieldset>
            <legend>Change Password</legend>
            <div class="PageRow noborder">
                <label for="current_password">Current Password:</label>
                <div class="PageInput">
                    <input type="password" name="current_password" id="current_password" class="validate[required]" required />
                </div>
                <div class="fix"></div>
            </div>

            <div class="PageRow noborder">
                <label for="new_password">New Password:</label>
                <div class="PageInput">
                    <input type="password" name="new_password" id="new_password" class="validate[required]" required />
                </div>
                <div class="fix"></div>
            </div>

            <div class="PageRow noborder">
                <label for="confirm_password">Confirm New Password:</label>
                <div class="PageInput">
                    <input type="password" name="confirm_password" id="confirm_password" class="validate[required]" required />
                </div>
                <div class="fix"></div>
            </div>

            <div class="PageRow noborder">
                <input type="submit" value="Change Password" class="greyishBtn submitForm" />
                <div class="fix"></div>
            </div>
        </fieldset>
    </form>
</div>

<div class="PagePanel">
    <div class="head"><h5>API Keys</h5></div>

    <p>Credits remaining: <strong><?= (int)$credits_remaining ?></strong></p>

    <?php if (!empty($new_api_key)): ?>
        <div class="success-message" style="color: green; padding: 10px; margin: 10px 0; border: 1px solid green; background-color: #e8f5e8;">
            <strong>Your new API key (copy it now — not shown again):</strong><br>
            <code style="font-size: 1em; word-break: break-all;"><?= htmlspecialchars($new_api_key) ?></code>
            <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($new_api_key, ENT_QUOTES) ?>'); this.textContent='Copied!';" style="margin-left: 10px;">Copy</button>
        </div>
    <?php endif; ?>

    <form action="/profile/" method="POST" class="mainForm" style="margin-bottom: 20px;">
        <input type="hidden" name="api_key_action" value="generate">
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
                <input type="submit" value="Generate API Key" class="greyishBtn submitForm" />
                <div class="fix"></div>
            </div>
        </fieldset>
    </form>

    <?php if (!empty($api_keys)): ?>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="text-align: left; padding: 6px; border-bottom: 1px solid #ccc;">Label</th>
                    <th style="text-align: left; padding: 6px; border-bottom: 1px solid #ccc;">Created</th>
                    <th style="text-align: left; padding: 6px; border-bottom: 1px solid #ccc;">Last used</th>
                    <th style="padding: 6px; border-bottom: 1px solid #ccc;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($api_keys as $key): ?>
                <tr>
                    <td style="padding: 6px;"><?= htmlspecialchars($key['label'] ?: '—') ?></td>
                    <td style="padding: 6px;"><?= htmlspecialchars($key['created_at']) ?></td>
                    <td style="padding: 6px;"><?= htmlspecialchars($key['last_used'] ?: 'never') ?></td>
                    <td style="padding: 6px;">
                        <form action="/profile/" method="POST" style="display:inline;" onsubmit="return confirm('Revoke this key? Any agent using it will immediately lose access.');">
                            <input type="hidden" name="api_key_action" value="revoke">
                            <input type="hidden" name="key_id" value="<?= (int)$key['key_id'] ?>">
                            <input type="submit" value="Revoke" class="greyishBtn" style="background:#c00; color:#fff;">
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="color: #666;">No active API keys. Generate one above.</p>
    <?php endif; ?>
</div>

<div class="PagePanel">
    <p>Logged in as: <strong><?= htmlspecialchars($username) ?></strong></p>
    <a href="/logout/">Logout</a>
</div>
