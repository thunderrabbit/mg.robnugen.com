<?php

/**
 * /journal/ — recent journal entries mirrored from Lemur 13, newest first.
 * Rob taps one to edit it. Design: docs/superpowers/specs/2026-11-05-journal-editing-design.md.
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
if (!$is_logged_in->isAdmin()) {
    header("Location: /");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$journal_files = new \Journal\Files(\Database\Base::getPDO($config));

$days = [];
foreach ($journal_files->all($user_id) as $row) {
    $shown_path = \Journal\Listing::shownPath($row);
    $days[\Journal\Listing::dayHeading($shown_path)][] = [
        'jf_id' => \Field::int($row, 'jf_id'),
        'title' => \Journal\Listing::title(\Journal\Listing::text($row), $shown_path),
        'path' => $shown_path,
        'label' => \Journal\Listing::label($row),
    ];
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Journal — Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("journal/index.tpl.php");
$inner->set("days", $days);
$inner->set("last_synced", $journal_files->lastSynced($user_id));

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
