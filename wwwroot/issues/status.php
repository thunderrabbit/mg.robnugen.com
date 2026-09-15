<?php

/**
 * Change the status of an issue.
 * Accepts POST only. Gated on admin/paid; IssueService checks project write
 * access and blocks a terminal status while any timer on the issue is running.
 * Redirects back to /issues/view.php?issue_id=N on success or error.
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

$issue_id = \Field::int($_POST, 'issue_id');
if ($issue_id <= 0) {
    header("Location: /projects/?msg=issue_not_found");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);

$aiu_stmt = $pdo->prepare(
    "SELECT aiu_id FROM agent_inbox_user
     WHERE user_id = ? AND actor_type = 'human' LIMIT 1"
);
$aiu_stmt->execute([$user_id]);
$human_aiu = (int) $aiu_stmt->fetchColumn();
if ($human_aiu <= 0) {
    header("Location: /projects/?msg=issue_not_found");
    exit;
}

$service = new \Issues\IssueService(new \Issues\PdoIssueStore($pdo));
$caller  = new \Issues\Caller(userId: $user_id, aiu: $human_aiu, isHuman: true);

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
