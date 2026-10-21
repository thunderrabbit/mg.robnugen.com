<?php

/**
 * Remove a member from a project.
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
    $project_id = \Input\Input::positiveId($_POST, 'project_id');
    $member_aiu = \Input\Input::positiveId($_POST, 'member_aiu');
    if ($caller === null) {
        throw new \Issues\AccessException('No human actor on this account');
    }
    $projects->removeMember($caller, $project_id, $member_aiu);
} catch (\Input\InvalidInput | \Issues\AccessException) {
    header("Location: /projects/?msg=project_not_found");
    exit;
}

header("Location: /projects/view.php?project_id={$project_id}");
exit;
