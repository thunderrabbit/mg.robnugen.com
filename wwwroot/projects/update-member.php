<?php

/**
 * Update a project member's can_read / can_write bits.
 * POST only. Admin/paid + project must be in the user's account.
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

$projects = new \Projects\ProjectService(new \Projects\PdoProjectStore(\Database\Base::getPDO($config)));
$caller   = $projects->humanCaller($is_logged_in->loggedInID());

try {
    $changes = \Projects\Command\UpdateMember::fromInput([
        'project_id' => $_POST['project_id'] ?? null,
        'member_aiu' => $_POST['member_aiu'] ?? null,
        'can_read'   => !empty($_POST['can_read']),
        'can_write'  => !empty($_POST['can_write']),
    ]);
    if ($caller === null) {
        throw new \Issues\AccessException('No human actor on this account');
    }
    $projects->updateMember($caller, $changes);
} catch (\Input\InvalidInput | \Issues\AccessException) {
    header("Location: /projects/?msg=project_not_found");
    exit;
}

header("Location: /projects/view.php?project_id={$changes->projectId}");
exit;
