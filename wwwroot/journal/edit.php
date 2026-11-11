<?php

/**
 * /journal/edit.php?jf_id=N — edit one journal file, frontmatter and all.
 *
 * GET shows the editor. POST takes JSON from the page's script:
 *   {action: "save", content: <base64>, path: <new path>}  or  {action: "discard"}
 * and answers JSON {ok, problems[], warnings[]}. The text travels base64-encoded
 * because a dream body is HTML, which ModSecurity refuses as plain text.
 *
 * A save is refused when Journal\EntryCheck finds a problem; Lemur 13 runs the
 * same check again before it writes. Renaming is only for files git has never
 * tracked. Design: docs/superpowers/specs/2026-11-05-journal-editing-design.md.
 */

declare(strict_types=1);

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 * @var \Auth\IsLoggedIn $is_logged_in
 * @var \Security\CSRFProtectaroo $csrfProtect
 */

$is_post = \Field::string($_SERVER, 'REQUEST_METHOD') === 'POST';

/**
 * Answer the page's script and stop.
 *
 * @param array<string, mixed> $body
 */
$journal_reply = static function (int $code, array $body): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
};

if (!$is_logged_in->isLoggedIn() || !$is_logged_in->isAdmin()) {
    if ($is_post) {
        $journal_reply(403, ['ok' => false, 'problems' => ['You are not logged in. Log in again, then save.']]);
    }
    header("Location: /login/");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$journal_files = new \Journal\Files(\Database\Base::getPDO($config));
$jf_id = \Field::int($_GET, 'jf_id');
$row = $journal_files->find($user_id, $jf_id);

if ($row === null) {
    if ($is_post) {
        $journal_reply(404, ['ok' => false, 'problems' => ['This file is no longer on mg.']]);
    }
    header("Location: /journal/");
    exit;
}

if ($is_post) {
    $input = \Field::jsonArray((string) file_get_contents('php://input'));
    $action = \Field::string($input, 'action');

    if ($action === 'discard') {
        $journal_files->discardEdit($user_id, $jf_id);
        $journal_reply(200, ['ok' => true, 'problems' => [], 'warnings' => []]);
    }
    if ($action !== 'save') {
        $journal_reply(400, ['ok' => false, 'problems' => ['Unknown action.']]);
    }

    try {
        $content = \Input\BodyEncoding::Base64->decode(
            \Field::string($input, 'content'),
            \Journal\Files::MAX_BYTES,
            'content'
        );
    } catch (\Input\InvalidInput $e) {
        $journal_reply(400, ['ok' => false, 'problems' => [$e->getMessage()]]);
    }
    $content = \Journal\EntryCheck::normalize($content);
    if (strlen($content) > \Journal\Files::MAX_BYTES) {
        $journal_reply(400, ['ok' => false, 'problems' => ['The file is too large.']]);
    }

    $current_path = \Field::string($row, 'path');
    $new_path = trim(\Field::string($input, 'path', $current_path));
    $problems = [];

    if ($new_path !== $current_path) {
        if (!\Journal\Listing::canRename($row)) {
            $problems[] = 'Only files that have never been committed can be renamed.';
        } elseif (($rename_problem = \Journal\EntryPath::renameProblem($new_path)) !== null) {
            $problems[] = $rename_problem;
        } elseif ($journal_files->pathTaken($user_id, $new_path, $jf_id)) {
            $problems[] = 'Another file on mg already has that name.';
        }
    }
    if ($problems === []) {
        $problems = \Journal\EntryCheck::problems($content, $new_path, \Field::string($row, 'base_content'));
    }
    if ($problems !== []) {
        $journal_reply(422, ['ok' => false, 'problems' => $problems, 'warnings' => []]);
    }

    $warnings = \Journal\EntryCheck::warnings($new_path, $current_path);
    if ($content === \Field::string($row, 'base_content') && $new_path === $current_path) {
        // Back to exactly what Lemur 13 has: nothing to send.
        $journal_files->discardEdit($user_id, $jf_id);
        $journal_reply(200, ['ok' => true, 'unchanged' => true, 'problems' => [], 'warnings' => $warnings]);
    }
    $journal_files->saveEdit($user_id, $jf_id, $content, $new_path, \Journal\Files::nowUtc());
    $journal_reply(200, ['ok' => true, 'problems' => [], 'warnings' => $warnings]);
}

$page = new \Template($config, $is_logged_in, $csrfProtect);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Edit journal — Meiso Gambare");

$inner = new \Template($config, $is_logged_in, $csrfProtect);
$inner->setTemplate("journal/edit.tpl.php");
$inner->set("jf_id", $jf_id);
$inner->set("text", \Journal\Listing::text($row));
$inner->set("path", \Journal\Listing::shownPath($row));
$inner->set("label", \Journal\Listing::label($row));
$inner->set("can_rename", \Journal\Listing::canRename($row));
$inner->set("has_edit", $row['edit_content'] !== null);

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
