<?php
/**
 * @var list<\Auth\Device> $devices
 * @var \DateTimeImmutable $now
 * @var bool $renaming whether this device's rename form starts open
 * @var string $error_message
 * @var string $success_message
 */
$others = count(array_filter($devices, fn(\Auth\Device $d) => !$d->is_current));

/** "12 minutes ago" on the page, the exact UTC time on hover. */
$when = function (?\DateTimeImmutable $instant) use ($now): string {
    if ($instant === null) {
        return 'unknown';
    }
    return '<time datetime="' . $instant->format(DATE_ATOM) . '"'
        . ' title="' . $instant->format('Y-m-d H:i:s') . ' UTC">'
        . htmlspecialchars(\RelativeTime::describe($instant, $now))
        . '</time>';
};
?>
<div class="PagePanel">
    <div class="head"><h5 class="iUser">Signed-in Devices</h5></div>

    <?php if (!empty($success_message)): ?>
        <div class="success-message" style="color: green; padding: 10px; margin: 10px 0; border: 1px solid green; background-color: #e8f5e8;">
            <?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="error-message" style="color: red; padding: 10px; margin: 10px 0; border: 1px solid red; background-color: #ffe8e8;">
            <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <p>Each browser you log in on stays signed in on its own. Sign out any you don't recognise or no longer use.
        Click this device's name to call it something you'll recognise, like "Firefox on the laptop".
        A device can only be named from itself.</p>

    <ul class="DeviceList">
        <?php foreach ($devices as $device): ?>
            <li class="Device<?= $device->is_current ? ' current' : '' ?>">
                <div class="DeviceName">
                    <?php if ($device->is_current): ?>
                        <?php /* Only this device is renameable: a session stolen on one device may not touch the others. */ ?>
                        <a class="DeviceNameLink" href="/profile/devices/?rename=1"
                           title="Rename this device"<?= $renaming ? ' hidden' : '' ?>><?= htmlspecialchars($device->label()) ?></a>
                        <form class="DeviceRename" action="/profile/devices/" method="POST"<?= $renaming ? '' : ' hidden' ?>>
                            <?= $csrf->field() ?>
                            <input type="hidden" name="action" value="rename" />
                            <input type="text" name="device_name" maxlength="<?= \Auth\DeviceName::MAX_LENGTH ?>"
                                   value="<?= htmlspecialchars($device->device_name ?? '') ?>"
                                   placeholder="<?= htmlspecialchars($device->autoLabel()) ?>"
                                   aria-label="Device name"<?= $renaming ? ' autofocus' : '' ?> />
                            <input type="submit" value="Save" class="greyishBtn" />
                            <a class="DeviceRenameCancel" href="/profile/devices/">Cancel</a>
                        </form>
                        <span class="DeviceBadge">This device</span>
                    <?php else: ?>
                        <?= htmlspecialchars($device->label()) ?>
                    <?php endif; ?>
                </div>
                <div class="DeviceMeta">
                    <?php if ($device->isNamed()): ?>
                        <?= htmlspecialchars($device->autoLabel()) ?> ·
                    <?php endif; ?>
                    Last used <?= $when($device->lastUsed()) ?>
                    · signed in <?= $when($device->signedIn()) ?>
                    · expires <?= $when($device->expires()) ?>
                </div>
                <?php /* No button on this device: /logout/ signs out every device, not just this one. */ ?>
                <?php if (!$device->is_current): ?>
                    <form action="/profile/devices/" method="POST">
                        <?= $csrf->field() ?>
                        <input type="hidden" name="action" value="revoke" />
                        <input type="hidden" name="cookie_id" value="<?= (int) $device->cookie_id ?>" />
                        <input type="submit" value="Sign out" class="greyishBtn" />
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($others > 0): ?>
        <form action="/profile/devices/" method="POST">
            <?= $csrf->field() ?>
            <input type="hidden" name="action" value="revoke_others" />
            <input type="submit" value="Sign out every other device" class="greyishBtn" />
        </form>
    <?php endif; ?>
</div>

<script>
// Clicking this device's name opens its rename form in place; Cancel closes it
// again. Without JavaScript the name is a link that reloads the page with the
// form open (?rename=1), and Cancel a link back, so nothing here is load-bearing.
(function () {
    document.querySelectorAll('.Device.current').forEach(function (device) {
        var link = device.querySelector('.DeviceNameLink');
        var form = device.querySelector('.DeviceRename');
        var cancel = device.querySelector('.DeviceRenameCancel');
        if (!link || !form || !cancel) return;
        link.addEventListener('click', function (e) {
            e.preventDefault();
            link.hidden = true;
            form.hidden = false;
            var input = form.querySelector('input[name="device_name"]');
            input.focus();
            input.select();
        });
        cancel.addEventListener('click', function (e) {
            e.preventDefault();
            form.hidden = true;
            link.hidden = false;
        });
    });
})();
</script>
