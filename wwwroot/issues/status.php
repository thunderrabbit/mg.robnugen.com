<?php

/**
 * Change the status of an issue.
 * Accepts POST only. Gated on admin/paid; IssueService checks project write
 * access and blocks a terminal status while any timer on the issue is running.
 * Redirects back to /issues/view.php?issue_id=N on success or error.
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

// Read the issue on its own, so a rejected status still knows where to go back to.
try {
    $issue_id = \Input\Input::positiveId($_POST, 'issue_id');
} catch (\Input\InvalidInput) {
    header("Location: /projects/?msg=issue_not_found");
    exit;
}

try {
    $service->update($caller, \Issues\Command\UpdateIssue::fromInput([
        'issue_id'  => $issue_id,
        'status_id' => $_POST['status_id'] ?? null,
    ]));
    header("Location: /issues/view.php?issue_id={$issue_id}");
} catch (\Issues\ConflictException) {
    header("Location: /issues/view.php?issue_id={$issue_id}&msg=timers_running");
} catch (\Issues\AccessException) {
    header("Location: /projects/?msg=no_write_access");
} catch (\Issues\NotFoundException | \Input\InvalidInput) {
    header("Location: /projects/?msg=issue_not_found");
} catch (\Issues\ValidationException) {
    header("Location: /issues/view.php?issue_id={$issue_id}");
}
exit;
