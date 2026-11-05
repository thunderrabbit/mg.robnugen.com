<?php

/**
 * /journal-sync — Lemur 13 mirrors recent journal files here and fetches
 * Rob's edits back. Design: docs/superpowers/specs/2026-11-05-journal-editing-design.md.
 *
 * Only the sync key reaches this file (Guards::journalRoute), and only on an
 * admin account: these are Rob's private entries. Lemur 13 starts every
 * exchange; mg never calls out.
 *
 *   GET  /journal-sync/files         what mg holds: path, base_hash, git_state, status
 *   POST /journal-sync/files         upload one file {path, content (base64), git_state}
 *   POST /journal-sync/files/remove  {path, deleted: bool}
 *   GET  /journal-sync/edits         edits to write, and conflicts to check on
 *   POST /journal-sync/results       {path, outcome, edited_at, note, applied_path, content (base64)}
 *   POST /journal-sync/done          record that a run finished
 *
 * File contents travel base64-encoded both ways: a dream body is HTML, which
 * DreamHost's ModSecurity would refuse as plain text (see Input\BodyEncoding).
 * No credit is charged: this is Rob's own machine syncing his own files.
 */

declare(strict_types=1);

/**
 * Set up by index.php, which includes this file.
 *
 * @var string $method
 * @var string $path
 * @var \PDO $pdo
 * @var int $auth_user_id
 */

$sub = preg_replace('#^/journal-sync#', '', $path) ?: '/';

if (!\Auth\AccountRole::isAdmin($pdo, $auth_user_id)) {
    http_response_code(403);
    echo json_encode(['error' => 'Journal sync is only for the admin account']);
    return;
}

$journal_files = new \Journal\Files($pdo);
$now = \Journal\Files::nowUtc();

/**
 * Stop the request with an error.
 */
$journal_fail = static function (int $code, string $error): never {
    http_response_code($code);
    echo json_encode(['error' => $error]);
    exit;
};

/**
 * A base64 field of the request, decoded, or a 400.
 *
 * @param array<mixed> $input
 */
$journal_content = static function (array $input) use ($journal_fail): string {
    try {
        $text = \Input\BodyEncoding::Base64->decode(
            \Field::string($input, 'content'),
            \Journal\Files::MAX_BYTES,
            'content'
        );
    } catch (\Input\InvalidInput $e) {
        $journal_fail(400, $e->getMessage());
    }
    if (strlen($text) > \Journal\Files::MAX_BYTES) {
        $journal_fail(400, 'content is too large');
    }
    return $text;
};

/**
 * A path field that is an entry path, or a 400.
 *
 * @param array<mixed> $input
 */
$journal_path = static function (array $input, string $field) use ($journal_fail): string {
    $value = \Field::string($input, $field);
    if (\Journal\EntryPath::parse($value) === null) {
        $journal_fail(400, "$field is not a journal entry path");
    }
    return $value;
};

$input = $method === 'POST' ? \Field::jsonArray((string) file_get_contents('php://input')) : [];

if ($method === 'GET' && $sub === '/files') {
    echo json_encode(['files' => $journal_files->manifest($auth_user_id)]);
    return;
}

if ($method === 'POST' && $sub === '/files') {
    $file_path = $journal_path($input, 'path');
    $git_state = \Field::string($input, 'git_state');
    if (!in_array($git_state, \Journal\Files::GIT_STATES, true)) {
        $journal_fail(400, 'git_state must be untracked, modified or committed');
    }
    $journal_files->upload($auth_user_id, $file_path, $journal_content($input), $git_state, $now);
    echo json_encode(['ok' => true]);
    return;
}

if ($method === 'POST' && $sub === '/files/remove') {
    $file_path = $journal_path($input, 'path');
    $journal_files->remove($auth_user_id, $file_path, !empty($input['deleted']));
    echo json_encode(['ok' => true]);
    return;
}

if ($method === 'GET' && $sub === '/edits') {
    $edits = [];
    foreach ($journal_files->pending($auth_user_id) as $row) {
        $edits[] = [
            'path' => \Field::string($row, 'path'),
            'edit_path' => \Field::string($row, 'edit_path'),
            'content' => base64_encode(\Field::string($row, 'edit_content')),
            'edited_at' => \Field::string($row, 'edited_at_utc'),
            'base_hash' => \Field::string($row, 'base_hash'),
            'git_state' => \Field::string($row, 'git_state'),
            'status' => \Field::string($row, 'status'),
        ];
    }
    echo json_encode(['edits' => $edits]);
    return;
}

if ($method === 'POST' && $sub === '/results') {
    $file_path = $journal_path($input, 'path');
    $outcome = \Field::string($input, 'outcome');
    if (!in_array($outcome, ['applied', 'conflict', 'refused', 'resolved'], true)) {
        $journal_fail(400, 'outcome must be applied, conflict, refused or resolved');
    }
    $applied_path = null;
    $applied_content = null;
    if ($outcome === 'applied') {
        $applied_path = $journal_path($input, 'applied_path');
        $applied_content = $journal_content($input);
        $taken = $journal_files->findByPath($auth_user_id, $applied_path);
        if ($applied_path !== $file_path && $taken !== null) {
            $journal_fail(409, 'applied_path is already another file on mg');
        }
    }
    $found = $journal_files->result(
        $auth_user_id,
        $file_path,
        $outcome,
        \Field::string($input, 'edited_at'),
        \Field::string($input, 'note'),
        $applied_path,
        $applied_content,
        $now,
    );
    if (!$found) {
        $journal_fail(404, 'No such file on mg');
    }
    echo json_encode(['ok' => true]);
    return;
}

if ($method === 'POST' && $sub === '/done') {
    $journal_files->markSynced($auth_user_id, $now);
    echo json_encode(['ok' => true, 'synced_at_utc' => $now]);
    return;
}

http_response_code(404);
echo json_encode(['error' => 'Not found']);
