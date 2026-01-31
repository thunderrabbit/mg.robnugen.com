<?php
/**
 * Menu Partial
 *
 * Logic:
 * - Admin: Home (/), Timer (/mg/), Profile (Dropdown), Admin (/admin/), Workers (/admin/workers)
 * - Paid: Home (/), Timer (/mg/), Profile (Dropdown)
 * - Free: Home (/), Timer (/mg/)
 *
 * Note: The "Home" link points to `/` which handles "Welcome" or "Dashboard" based on login status.
 */

// Safe variable handling
$user_is_admin = $is_admin ?? false;
$user_is_paid = $is_paid ?? false;
$user_is_logged_in = isset($is_logged_in) && is_bool($is_logged_in) ? $is_logged_in : ($user_is_admin || $user_is_paid);

// If using the global $is_logged_in object (or local in scope)
// Note: In some scopes $is_logged_in is an object, in others (templates) it might be bool.
// We check for the object capability.
// We use a different variable name to avoid overwriting the object if it exists.
if (isset($is_logged_in) && is_object($is_logged_in) && method_exists($is_logged_in, 'isLoggedIn')) {
    if (!$user_is_logged_in && $is_logged_in->isLoggedIn()) $user_is_logged_in = true;
    if (!$user_is_admin && $is_logged_in->isAdmin()) $user_is_admin = true;
    if (!$user_is_paid && $is_logged_in->isPaid()) $user_is_paid = true;
} elseif (isset($GLOBALS['is_logged_in']) && is_object($GLOBALS['is_logged_in'])) {
    if (!$user_is_logged_in && $GLOBALS['is_logged_in']->isLoggedIn()) $user_is_logged_in = true;
    if (!$user_is_admin && $GLOBALS['is_logged_in']->isAdmin()) $user_is_admin = true;
    if (!$user_is_paid && $GLOBALS['is_logged_in']->isPaid()) $user_is_paid = true;
}

?>
<div class="NavBar" id="main-menu">
    <a href="/">Home</a> |
    <a href="/mg/">Timer</a> |

    <?php if ($user_is_logged_in): ?>
        <div class="dropdown">
            <a href="/profile/">Profile ▾</a>
            <div class="dropdown-menu">
                <a href="/logout/">Logout</a>
            </div>
        </div>

        <?php if ($user_is_admin): ?>
            | <a href="/admin/">Admin</a>
        <?php endif; ?>

    <?php endif; ?>
</div>
