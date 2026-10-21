<?php

/**
 * Projects Index Page
 * Lists projects the logged-in user owns and is a member of.
 * Admin or paid role required (matches /todos/ gating).
 */

declare(strict_types=1);

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 * @var \Auth\IsLoggedIn $is_logged_in
 */

// Auth gate — admin or paid only
if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}
if (!$is_logged_in->isAdmin() && !$is_logged_in->isPaid()) {
    header("Location: /?msg=upgrade_required");
    exit;
}

$projects = new \Projects\ProjectService(new \Projects\PdoProjectStore(\Database\Base::getPDO($config)));
$caller   = $projects->humanCaller($is_logged_in->loggedInID());

// The account's active projects its human is a member of, with open issue counts.
$summaries = $caller === null ? [] : $projects->summaries($caller);

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Projects - Meiso Gambare");

$inner_page = new \Template($config, $is_logged_in);
$inner_page->setTemplate("projects/index.tpl.php");
$inner_page->set("projects", $summaries);
if (isset($_GET['msg'])) {
    $inner_page->set("msg", $_GET['msg']);
}

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
