<?php

/**
 * Add a member to a project.
 * GET with ?project_id=N shows a form listing aiu_ids in the account not yet members.
 * POST inserts a project_members row. Admin/paid only; project must be in account.
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

$projects = new \Projects\ProjectService(new \Projects\PdoProjectStore(\Database\Base::getPDO($config)));
$caller   = $projects->humanCaller($is_logged_in->loggedInID());

try {
    $project_id = \Input\Input::positiveId($_REQUEST, 'project_id');
    if ($caller === null) {
        throw new \Issues\NotFoundException('No human actor on this account');
    }
    $project = $projects->forAdmin($caller, $project_id);
} catch (\Input\InvalidInput | \Issues\NotFoundException | \Issues\AccessException) {
    header("Location: /projects/?msg=project_not_found");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = [];
    foreach ((array)($_POST['member_aiu'] ?? []) as $raw) {
        try {
            $submitted[] = \Input\Input::positiveId(['member_aiu' => $raw], 'member_aiu');
        } catch (\Input\InvalidInput) {
            continue;
        }
    }
    $submitted = array_values(array_unique($submitted));

    if ($submitted === []) {
        $error = "Select at least one actor to add.";
    } else {
        $added = 0;
        foreach ($submitted as $member_aiu) {
            try {
                $projects->addMember($caller, new \Projects\Command\AddMember(
                    projectId: $project_id,
                    memberAiu: $member_aiu,
                    canRead: !empty($_POST['can_read']),
                    canWrite: !empty($_POST['can_write']),
                ));
                $added++;
            } catch (\Issues\ValidationException | \Issues\ConflictException) {
                continue; // not this account's actor, or already a member
            }
        }

        if ($added > 0) {
            header("Location: /projects/view.php?project_id={$project_id}&msg=members_added");
            exit;
        }
        $error = "No actors were added — they may already be members.";
    }
}

// Most-used actors first; the usual ones come pre-checked, and test_* and
// auto_* actors go behind "show all".
$available = [];
$hidden    = [];
foreach ($projects->candidates($caller, $project_id) as $actor) {
    if (str_starts_with($actor->name, 'test_') || str_starts_with($actor->name, 'auto_')) {
        $hidden[] = $actor;
    } else {
        $available[] = $actor;
    }
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Add Member to {$project->name} - Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("projects/add-member.tpl.php");
$inner->set("project", $project);
$inner->set("available", $available);
$inner->set("hidden", $hidden);
if ($error !== null) {
    $inner->set("error", $error);
}

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
