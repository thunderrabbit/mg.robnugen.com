<?php
/**
 * Exterminal (ET) — REST sub-dispatcher for per-project context docs and tasks.
 *
 * User story: agents file context and tasks against a project so all
 * collaborators share state without re-posting into every inbox thread.
 *
 * Inaugurated: 2026-06-15
 *
 * Loaded by wwwroot/api/v1/index.php when path matches /exterm/*.
 * Delegates all SQL to \Exterm\Items (classes/Exterm/Items.php).
 * Auth: caller must have can_read_project or can_write_project AND a
 *       project_members row on the target project.
 *
 * Routes:
 *   GET    /exterm/list     free — ?project_id (required); kind, status, assignee_aiu, since, limit, offset
 *   GET    /exterm/get      free — ?exterm_item_id
 *   POST   /exterm/create   1    — {project_id, kind, title, body?, risk?, assignee_aiu?}
 *   PATCH  /exterm/update   1    — {exterm_item_id, title?, body?, status?, risk?, assignee_aiu?}
 *   DELETE /exterm/delete   1    — {exterm_item_id}
 *   POST   /exterm/approve  1    — {exterm_item_id}  needs_approval → approved
 *   POST   /exterm/reject   1    — {exterm_item_id}  needs_approval → rejected
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
        echo json_encode($exterm->getItem($pdo, $id, $auth_user_id, $caller_aiu));
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

// ── POST /exterm/approve ──────────────────────────────────────────────────────
if ($sub === '/approve' && $method === 'POST') {
    $id = isset($body['exterm_item_id']) ? (int)$body['exterm_item_id'] : 0;
    if (!$id) exterm_error(400, "exterm_item_id is required");
    require_credit($auth_user_id, $auth_key_id, '/exterm/approve');
    try {
        echo json_encode($exterm->approveTask($auth_user_id, $caller_aiu, $id));
    } catch (\Exterm\AccessException|\Exterm\NotFoundException|\Exterm\ValidationException $e) {
        exterm_dispatch_exception($e);
    }
    exit;
}

// ── POST /exterm/reject ───────────────────────────────────────────────────────
if ($sub === '/reject' && $method === 'POST') {
    $id = isset($body['exterm_item_id']) ? (int)$body['exterm_item_id'] : 0;
    if (!$id) exterm_error(400, "exterm_item_id is required");
    require_credit($auth_user_id, $auth_key_id, '/exterm/reject');
    try {
        echo json_encode($exterm->rejectTask($auth_user_id, $caller_aiu, $id));
    } catch (\Exterm\AccessException|\Exterm\NotFoundException|\Exterm\ValidationException $e) {
        exterm_dispatch_exception($e);
    }
    exit;
}

exterm_error(404, "Unknown exterm endpoint: {$sub}");
