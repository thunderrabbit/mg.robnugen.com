<?php
/**
 * Exterminal (ET) — REST sub-dispatcher for per-project on-the-go notes.
 *
 * User story: capture an idea as a plain note filed against a project; read it
 * back later from another device. Concrete task state lives in jikan ISSUES,
 * not here (see classes/Issues/Issues.php).
 *
 * Inaugurated: 2026-06-15. Simplified to plain notes: 2026-06-16.
 *
 * Loaded by wwwroot/api/v1/index.php when path matches /exterm/*.
 * Delegates all SQL to \Exterm\Items (classes/Exterm/Items.php).
 * Auth: caller must have can_read_project or can_write_project AND a
 *       project_members row on the target project.
 *
 * Routes:
 *   GET    /exterm/list     free — ?project_id (optional; omit = recent across projects); since, limit, offset
 *   GET    /exterm/get      free — ?exterm_item_id
 *   POST   /exterm/create   1    — {project_id, title, body?}
 *   PATCH  /exterm/update   1    — {exterm_item_id, title?, body?, project_id?}  (project_id re-files / promotes)
 *   DELETE /exterm/delete   1    — {exterm_item_id}
 *   GET    /exterm/search   free — ?query (required); project_id?, limit?
 *
 * Variables from index.php: $pdo, $auth_user_id, $auth_key_id, $auth_actor, $method, $path
 */

$sub        = preg_replace('#^/exterm#', '', $path) ?: '/';
$caller_aiu = (int) $auth_actor['aiu_id'];
$exterm     = new \Exterm\Items($pdo);

function exterm_error(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function exterm_dispatch_exception(\Exterm\AccessException|\Exterm\NotFoundException|\Exterm\ValidationException $e): void {
    if ($e instanceof \Exterm\NotFoundException)   exterm_error(404, $e->getMessage());
    if ($e instanceof \Exterm\AccessException)     exterm_error(403, $e->getMessage());
    if ($e instanceof \Exterm\ValidationException) exterm_error(400, $e->getMessage());
}

// ── GET /exterm/list ──────────────────────────────────────────────────────────
if ($sub === '/list' && $method === 'GET') {
    try {
        $items = $exterm->listItems($auth_user_id, $caller_aiu, $_GET);
        echo json_encode($items);
    } catch (\Exterm\AccessException|\Exterm\NotFoundException|\Exterm\ValidationException $e) {
        exterm_dispatch_exception($e);
    }
    exit;
}

// ── GET /exterm/get ───────────────────────────────────────────────────────────
if ($sub === '/get' && $method === 'GET') {
    $id = isset($_GET['exterm_item_id']) ? (int)$_GET['exterm_item_id'] : 0;
    if (!$id) exterm_error(400, "exterm_item_id is required");
    try {
        echo json_encode($exterm->getItem($id, $auth_user_id, $caller_aiu));
    } catch (\Exterm\AccessException|\Exterm\NotFoundException|\Exterm\ValidationException $e) {
        exterm_dispatch_exception($e);
    }
    exit;
}

// ── GET /exterm/search ────────────────────────────────────────────────────────
if ($sub === '/search' && $method === 'GET') {
    $query      = trim($_GET['query'] ?? '');
    $project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
    $limit      = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    try {
        echo json_encode($exterm->searchItems($auth_user_id, $caller_aiu, $query, $project_id, $limit));
    } catch (\Exterm\AccessException|\Exterm\NotFoundException|\Exterm\ValidationException $e) {
        exterm_dispatch_exception($e);
    }
    exit;
}

// ── Writes (cost 1 credit) ────────────────────────────────────────────────────
$body_raw = file_get_contents('php://input');
$body     = json_decode($body_raw, true) ?? [];

// ── POST /exterm/create ───────────────────────────────────────────────────────
if ($sub === '/create' && $method === 'POST') {
    require_credit($auth_user_id, $auth_key_id, '/exterm/create');
    try {
        $item = $exterm->createItem($auth_user_id, $caller_aiu, $body);
        http_response_code(201);
        echo json_encode($item);
    } catch (\Exterm\AccessException|\Exterm\NotFoundException|\Exterm\ValidationException $e) {
        exterm_dispatch_exception($e);
    }
    exit;
}

// ── PATCH /exterm/update ──────────────────────────────────────────────────────
if ($sub === '/update' && $method === 'PATCH') {
    $id = isset($body['exterm_item_id']) ? (int)$body['exterm_item_id'] : 0;
    if (!$id) exterm_error(400, "exterm_item_id is required");
    require_credit($auth_user_id, $auth_key_id, '/exterm/update');
    try {
        echo json_encode($exterm->updateItem($auth_user_id, $caller_aiu, $id, $body));
    } catch (\Exterm\AccessException|\Exterm\NotFoundException|\Exterm\ValidationException $e) {
        exterm_dispatch_exception($e);
    }
    exit;
}

// ── DELETE /exterm/delete ─────────────────────────────────────────────────────
if ($sub === '/delete' && $method === 'DELETE') {
    $id = isset($body['exterm_item_id']) ? (int)$body['exterm_item_id'] : 0;
    if (!$id) exterm_error(400, "exterm_item_id is required");
    require_credit($auth_user_id, $auth_key_id, '/exterm/delete');
    try {
        echo json_encode($exterm->deleteItem($auth_user_id, $caller_aiu, $id));
    } catch (\Exterm\AccessException|\Exterm\NotFoundException|\Exterm\ValidationException $e) {
        exterm_dispatch_exception($e);
    }
    exit;
}

exterm_error(404, "Unknown exterm endpoint: {$sub}");
