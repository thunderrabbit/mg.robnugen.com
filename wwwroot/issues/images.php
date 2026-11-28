<?php

/**
 * Add pictures to an issue, or take one off it.
 * Accepts POST only. action=add stores the posted files (images[]) on the
 * issue; action=remove unlinks one picture from it (the file stays on disk).
 * Issues\Images\ImageService checks that this account's human actor may write
 * the issue. Either way the browser goes back to the issue's Images card,
 * which shows a line for each file that was not simply stored.
 */

declare(strict_types=1);

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config\Settings $config
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

$pdo      = \Database\Base::getPDO($config);
$caller   = new \Issues\PdoIssueStore($pdo)->humanCaller($is_logged_in->loggedInID());
$pictures = \Issues\Images\ImageService::forConfig($config, $pdo);

try {
    $issue_id = \Input\Input::positiveId($_POST, 'issue_id');
} catch (\Input\InvalidInput) {
    header("Location: /projects/?msg=issue_not_found");
    exit;
}
if ($caller === null || !$pictures->enabled()) {
    header("Location: /issues/view.php?issue_id={$issue_id}");
    exit;
}

try {
    if (\Field::string($_POST, 'action') === 'remove') {
        $pictures->unlink($caller, \Input\Input::positiveId($_POST, 'issue_image_id'), $issue_id);
    } else {
        $posted = \Issues\Images\PostedFiles::read(
            $_FILES['images'] ?? null,
            \Issues\Images\PostedFiles::MAX_PER_POST,
            is_uploaded_file(...),
        );
        $results = $pictures->upload($caller, $issue_id, $posted->files, new \DateTimeImmutable());
        $_SESSION[\Issues\Images\PostedFiles::SESSION_KEY] = [...$posted->notes, ...\Issues\Images\PostedFiles::notes($results)];
    }
    header("Location: /issues/view.php?issue_id={$issue_id}#images");
} catch (\Input\InvalidInput) {
    header("Location: /issues/view.php?issue_id={$issue_id}#images");
} catch (\Issues\AccessException) {
    header("Location: /projects/?msg=no_write_access");
} catch (\Issues\NotFoundException) {
    header("Location: /projects/?msg=issue_not_found");
}
exit;
