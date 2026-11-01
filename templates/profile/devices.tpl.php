<?php
/**
 * @var list<\Auth\Device> $devices
 * @var \DateTimeImmutable $now
 * @var int $renaming cookie_id whose rename form starts open, 0 for none
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
        Click a device's name to call it something you'll recognise, like "Firefox on the laptop".</p>

    <ul class="DeviceList">
        <?php foreach ($devices as $device): ?>
            <li class="Device<?= $device->is_current ? ' current' : '' ?>">
                <?php $open = $renaming === $device->cookie_id; ?>
                <div class="DeviceName">
                    <a class="DeviceNameLink" href="/profile/devices/?rename=<?= (int) $device->cookie_id ?>"
                       title="Rename this device"<?= $open ? ' hidden' : '' ?>><?= htmlspecialchars($device->label()) ?></a>
                    <form class="DeviceRename" action="/profile/devices/" method="POST"<?= $open ? '' : ' hidden' ?>>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="rename" />
                        <input type="hidden" name="cookie_id" value="<?= (int) $device->cookie_id ?>" />
                        <input type="text" name="device_name" maxlength="<?= \Auth\DeviceName::MAX_LENGTH ?>"
                               value="<?= htmlspecialchars($device->device_name ?? '') ?>"
                               placeholder="<?= htmlspecialchars($device->autoLabel()) ?>"
                               aria-label="Device name"<?= $open ? ' autofocus' : '' ?> />
                        <input type="submit" value="Save" class="greyishBtn" />
                        <a class="DeviceRenameCancel" href="/profile/devices/">Cancel</a>
                    </form>
                    <?php if ($device->is_current): ?>
                        <span class="DeviceBadge">This device</span>
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
                        <?= csrf_field() ?>
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
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="revoke_others" />
            <input type="submit" value="Sign out every other device" class="greyishBtn" />
        </form>
    <?php endif; ?>
</div>

<script>
// Clicking a device's name opens its rename form in place; Cancel closes it
// again. Without JavaScript the name is a link that reloads the page with the
// form open (?rename=), and Cancel a link back, so nothing here is load-bearing.
(function () {
    document.querySelectorAll('.Device').forEach(function (device) {
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
