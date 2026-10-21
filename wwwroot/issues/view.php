<?php

/**
 * Issue Detail Page
 * Shows a single issue and its comments. Gated on admin/paid; IssueService then
 * decides whether this account's human actor may read the issue at all.
 * Cross-account attempts redirect to /projects/?msg=issue_not_found
 * (same response whether the issue doesn't exist or isn't owned — don't leak existence).
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

if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}
if (!$is_logged_in->isAdmin() && !$is_logged_in->isPaid()) {
    header("Location: /?msg=upgrade_required");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo     = \Database\Base::getPDO($config);
$store   = new \Issues\PdoIssueStore($pdo);
$service = new \Issues\IssueService($store);

$caller = $store->humanCaller($user_id);
if ($caller === null) {
    header("Location: /projects/?msg=issue_not_found");
    exit;
}

try {
    $issue_id = \Input\Input::positiveId($_GET, 'issue_id');
    $issue    = $service->detail($caller, $issue_id);
    // The page shows every comment it loads; no issue here is anywhere near 500.
    $comments = $service->comments($caller, $issue_id, new \Issues\Command\Paging(limit: 500))->items;
} catch (\Input\InvalidInput | \Issues\NotFoundException | \Issues\AccessException) {
    header("Location: /projects/?msg=issue_not_found");
    exit;
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", $issue->title . " - Meiso Gambare");

$inner_page = new \Template($config, $is_logged_in);
$inner_page->setTemplate("issues/view.tpl.php");
$inner_page->set("issue", $issue);
$inner_page->set("comments", $comments);
$inner_page->set("statuses", $service->statuses());
if (isset($_GET['msg']) && is_string($_GET['msg'])) {
    $inner_page->set("msg", $_GET['msg']);
}

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
