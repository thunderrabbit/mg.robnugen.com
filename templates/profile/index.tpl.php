<div class="PagePanel">
    <div class="head"><h5 class="iUser">Change Password</h5></div>

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

    <form action="/profile/" method="POST" class="mainForm" style="margin-bottom: 30px;">
        <input type="hidden" name="update_settings_action" value="1">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

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
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
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
    <p>Logged in as: <strong><?= htmlspecialchars($username) ?></strong></p>
    <a href="/logout/">Logout</a>
</div>
