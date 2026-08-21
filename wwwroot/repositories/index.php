<?php

/**
 * Repositories Index Page
 * Lists active repositories scoped to the logged-in user's account.
 * Admin or paid role required (matches /projects/ gating).
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}
if (!$is_logged_in->isAdmin() && !$is_logged_in->isPaid()) {
    header("Location: /?msg=upgrade_required");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);

$include_inactive = !empty($_GET['include_inactive']);
$where = 'user_id = ?';
$params = [$user_id];
if (!$include_inactive) {
    $where .= ' AND is_active = 1';
}

$stmt = $pdo->prepare(
    "SELECT repo_id, name, url, default_branch, is_active, created_at_utc
     FROM repositories
     WHERE {$where}
     ORDER BY name ASC"
);
$stmt->execute($params);
$repositories = $stmt->fetchAll(\PDO::FETCH_ASSOC);

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Repositories - Meiso Gambare");

$inner_page = new \Template($config, $is_logged_in);
$inner_page->setTemplate("repositories/index.tpl.php");
$inner_page->set("repositories", $repositories);
$inner_page->set("include_inactive", $include_inactive);
if (isset($_GET['msg'])) {
    $inner_page->set("msg", $_GET['msg']);
}

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
