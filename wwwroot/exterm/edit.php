<?php

/**
 * Exterminal (ET) note editor — title and body.
 *
 * Inaugurated: 2026-06-15. Simplified to plain notes: 2026-06-16.
 * Part of the Exterminal feature — see classes/Exterm/Items.php.
 *
 * Entry point: /exterm/edit.php?exterm_item_id=N  (can_write required)
 * On success:  redirects to /exterm/view.php?exterm_item_id=N
 * On failure:  re-renders form with $error message.
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

$exterm_item_id = \Field::int($_REQUEST, 'exterm_item_id');
if (!$exterm_item_id) {
    header("Location: /exterm/");
    exit;
}

$item = $exterm->itemForEdit($exterm_item_id, $user_id, $human_aiu);

if (!$item) {
    header("Location: /exterm/?msg=not_found");
    exit;
}
if (!\Field::int($item, 'can_write')) {
    header("Location: /exterm/view.php?exterm_item_id={$exterm_item_id}&msg=no_write");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_title = trim(\Field::string($_POST, 'title'));
    $new_body  = trim(\Field::string($_POST, 'body')) ?: null;

    if (!$new_title) {
        $error = "Title is required.";
    }

    if (!$error) {
        $exterm->saveTitleAndBody($exterm_item_id, $new_title, $new_body);
        header("Location: /exterm/view.php?exterm_item_id={$exterm_item_id}");
        exit;
    }
}

$page = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Edit ET Note — Meiso Gambare");

$inner = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$inner->setTemplate("exterm/edit.tpl.php");
$inner->set("item", $item);
if ($error !== null) {
    $inner->set("error", $error);
}

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
