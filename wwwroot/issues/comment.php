<?php

/**
 * Post an issue comment.
 * Accepts POST only. IssueService checks that this account's human actor may
 * write to the issue, then stores the comment with that actor as its author.
 * On success, redirects back to /issues/view.php?issue_id=N#comments.
 */

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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /projects/");
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

// Read the issue on its own, so a rejected body still knows where to go back to.
try {
    $issue_id = \Input\Input::positiveId($_POST, 'issue_id');
} catch (\Input\InvalidInput) {
    header("Location: /projects/?msg=issue_not_found");
    exit;
}

try {
    $service->addComment($caller, \Issues\Command\AddComment::fromInput($_POST));
    header("Location: /issues/view.php?issue_id={$issue_id}#comments");
} catch (\Input\InvalidInput) {
    // An empty or overlong body; the form's required and maxlength prevent both,
    // so a silent trip back to the issue is enough.
    header("Location: /issues/view.php?issue_id={$issue_id}");
} catch (\Issues\AccessException) {
    header("Location: /projects/?msg=no_write_access");
} catch (\Issues\NotFoundException) {
    header("Location: /projects/?msg=issue_not_found");
}
exit;
