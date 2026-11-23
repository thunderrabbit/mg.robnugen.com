<?php
/**
 * Menu Partial
 *
 * Logic:
 * - Admin: Home (/), Timer (/mg/), Todos (/todos/), Projects (/projects/), Repositories (/repositories/), Profile (Dropdown), Admin (/admin/), DH (/admin/dh/, user 1 only), Workers (/admin/workers)
 * - Paid: Home (/), Timer (/mg/), Todos (/todos/), Projects (/projects/), Repositories (/repositories/), Profile (Dropdown)
 * - Free: Home (/), Timer (/mg/)
 *
 * An admin's Admin link carries $admin_count when it is above 0.
 *
 * Note: The "Home" link points to `/` which handles "Welcome" or "Dashboard" based on login status.
 */

// Safe variable handling
$user_is_admin = $is_admin ?? false;
$user_is_paid = $is_paid ?? false;
$user_is_logged_in = isset($is_logged_in) && is_bool($is_logged_in) ? $is_logged_in : ($user_is_admin || $user_is_paid);

// If using the $is_logged_in object (should always be available via Template system)
// Note: In some scopes $is_logged_in is an object, in others (templates) it might be bool.
// We check for the object capability.
// We use a different variable name to avoid overwriting the object if it exists.
if (isset($is_logged_in) && is_object($is_logged_in) && method_exists($is_logged_in, 'isLoggedIn')) {
    if (!$user_is_logged_in && $is_logged_in->isLoggedIn()) $user_is_logged_in = true;
    if (!$user_is_admin && $is_logged_in->isAdmin()) $user_is_admin = true;
    if (!$user_is_paid && $is_logged_in->isPaid()) $user_is_paid = true;
}

// What waits for an admin (mg #851): pending migrations, counted by prepend.php
// and handed over by every Template. Above 0 it is drawn inside the Admin link.
$menu_admin_count = (int) ($admin_count ?? 0);


?>
<div class="NavBar" id="main-menu">
    <a href="/">Home</a> |
    <a href="/mg/">Timer</a> |

    <?php if ($user_is_admin || $user_is_paid): ?>
        <a href="/todos/">Todos</a> |
        <a href="/inbox/?sort=id&dir=desc">Inbox</a> |
        <a href="/exterm/">ET</a> |
        <a href="/projects/">Projects</a> |
        <a href="/repositories/">Repositories</a> |
    <?php endif; ?>
    <?php if ($user_is_admin): ?>
        <a href="/journal/">Journal</a> |
    <?php endif; ?>

    <?php if ($user_is_logged_in): ?>
        <div class="dropdown">
            <a href="/profile/">Profile ▾</a>
            <div class="dropdown-menu">
                <a href="/settings/">Settings</a>
                <a href="/profile/devices/">Devices</a>
                <form method="POST" action="/logout/" class="menu-logout-form">
                    <?= $csrf->field() ?>
                    <button type="submit" class="menu-link-button">Logout</button>
                </form>
            </div>
        </div>

        <?php if ($user_is_admin): ?>
            <?php if ($menu_admin_count > 0): ?>
                <?php // Inside the link, so the chip is clicked too; the label names the count for screen readers. ?>
                | <a href="/admin/" aria-label="Admin, <?= $menu_admin_count ?> waiting for an admin">Admin
                    <span class="AdminCount" aria-hidden="true"><?= $menu_admin_count ?></span></a>
            <?php else: ?>
                | <a href="/admin/">Admin</a>
            <?php endif; ?>
            <?php if (is_object($is_logged_in) && method_exists($is_logged_in, 'loggedInID') && $is_logged_in->loggedInID() === 1): ?>
                | <a href="/admin/dh/">DH</a>
            <?php endif; ?>
            <span class="PhpVersion">PHP <?= PHP_VERSION ?></span>
        <?php endif; ?>

    <?php endif; ?>

    <button id="theme-toggle" title="Toggle dark mode" aria-label="Toggle dark mode"
        style="background: none; border: none; cursor: pointer; font-size: 1.2em; padding: 4px 8px; vertical-align: middle;">
        🌙
    </button>
</div>
