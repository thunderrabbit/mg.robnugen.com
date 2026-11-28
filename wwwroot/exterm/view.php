<?php

/**
 * Exterminal (ET) item detail page.
 *
 * Inaugurated: 2026-06-15
 * Part of the Exterminal feature — see classes/Exterm/Items.php.
 *
 * Entry point: /exterm/view.php?exterm_item_id=N
 * Back link:   /exterm/?project_id=N
 * Links to:    /exterm/edit.php?exterm_item_id=N  (can_write only)
 *
 * Handles the delete POST action inline (can_write only).
 */

declare(strict_types=1);

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config\Settings $config
 * @var \Auth\IsLoggedIn $is_logged_in
 * @var \Security\CSRFProtectaroo $csrfProtect
 * @var int $admin_count
 */

if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$exterm  = new \Exterm\Items(\Database\Base::getPDO($config));

$human_aiu = $exterm->humanAiu($user_id);

$exterm_item_id = \Field::int($_GET, 'exterm_item_id');
if (!$exterm_item_id) {
    header("Location: /exterm/");
    exit;
}

$item = $exterm->itemForView($exterm_item_id, $user_id, $human_aiu);

if (!$item) {
    header("Location: /exterm/?msg=not_found");
    exit;
}

// Handle delete POST action
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && \Field::int($item, 'can_write')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $exterm->removeItem($exterm_item_id);
        header("Location: /exterm/?project_id=" . \Field::int($item, 'project_id') . "&msg=deleted");
        exit;
    }
}

$page = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", htmlspecialchars(\Field::string($item, 'title')) . " — Exterminal — Meiso Gambare");

$inner = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$inner->setTemplate("exterm/view.tpl.php");
$inner->set("item", $item);
if (isset($_GET['msg'])) {
    $inner->set("msg", $_GET['msg']);
}

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
