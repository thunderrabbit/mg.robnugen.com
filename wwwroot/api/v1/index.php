<?php

/**
 * mg.robnugen.com Behavioral Session Ledger API v1
 *
 * Authentication: X-API-Key: sk_...  (or, for todos_limited actors only,
 * Authorization: Bearer <OAuth access token>)
 *
 * Write endpoints cost 1 credit:
 *   POST /api/v1/sessions   — start a session
 *   GET  /api/v1/stats      — pre-computed aggregates
 *
 * All other endpoints are free (reads).
 */

declare(strict_types=1);

header('Content-Type: application/json');

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 */

// ── Authentication ────────────────────────────────────────────────────────────

$raw_key = \Field::string($_SERVER, 'HTTP_X_API_KEY');
$pdo = \Database\Base::getPDO($config);
$bearer_token = new \Auth\BearerToken($pdo)->fromHeaders($_SERVER);
if (empty($raw_key) && $bearer_token === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing X-API-Key or Bearer token']);
    exit;
}

$guards = new \Auth\Guards();   // shared by handler includes (e.g. _inbox.php)
$auth_key_id   = null;   // ?int: set only for X-API-Key callers
$auth_token_id = null;   // ?int: set only for Bearer callers

if (!empty($raw_key)) {
    // ── X-API-Key ─────────────────────────────────────────────────────────────
    $apiKeyAuth = new \Auth\ApiKey($pdo);
    $auth_user_id = $apiKeyAuth->validateKey($raw_key);
    $auth_key_id  = $apiKeyAuth->getLastKeyId();

    if (!$auth_user_id) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or revoked API key']);
        exit;
    }

    // Fetch the agent_inbox_user row for this API key's aiu_id.
    // Available to all handler files as $auth_actor.
    $auth_actor = $auth_key_id === null ? null : new \Auth\Actors($pdo)->getActorOfKey($auth_key_id);
} else {
    // ── Bearer token (OAuth access token from /mcp/token.php) ────────────────
    // Only a todos_limited actor (BG Bells, mg #801) may use one here.
    $token_row = new \Auth\BearerToken($pdo)->resolve((string) $bearer_token);
    if ($token_row === null) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired token']);
        exit;
    }
    $auth_user_id  = $token_row['user_id'];
    $auth_token_id = $token_row['token_id'];

    $auth_actor = new \Auth\Actors($pdo)->getActor($token_row['aiu_id'], $token_row['user_id']);
}
$auth_actor ??= [];

if ($auth_token_id !== null && !$guards->isLimited($auth_actor)) {
    http_response_code(401);
    echo json_encode(['error' => 'Use X-API-Key']);
    exit;
}

// ── Route parsing ─────────────────────────────────────────────────────────────

$method    = \Field::string($_SERVER, 'REQUEST_METHOD');
$uri_path  = (string) parse_url(\Field::string($_SERVER, 'REQUEST_URI'), PHP_URL_PATH);
$path      = rtrim(preg_replace('#^/api/v1#', '', $uri_path) ?? '', '/') ?: '/';

// ── Limited actors (BG Bells, mg #801) ───────────────────────────────────────
// A todos_limited actor may only reach the routes Guards::limitedRoute lists.
$limited_verdict = $guards->limitedRoute($auth_actor, $method, $path);
if ($limited_verdict !== null) {
    http_response_code($limited_verdict['code']);
    echo json_encode(['error' => $limited_verdict['error']]);
    exit;
}
// ── Journal sync (Lemur 13) ─────────────────────────────────────────────────
// The sync key reaches only /journal-sync; no other key reaches it.
$journal_verdict = $guards->journalRoute($auth_actor, $path);
if ($journal_verdict !== null) {
    http_response_code($journal_verdict['code']);
    echo json_encode(['error' => $journal_verdict['error']]);
    exit;
}
$todo_grants = new \Auth\TodoGrants($pdo);   // shared by handler includes

// ── Route dispatch ────────────────────────────────────────────────────────────

if ($path === '/sessions' || preg_match('#^/sessions(/|$)#', $path)) {
    include __DIR__ . '/_sessions.php';
} elseif ($path === '/activities' || preg_match('#^/activities(/|$)#', $path)) {
    include __DIR__ . '/_activities.php';
} elseif ($path === '/stats') {
    include __DIR__ . '/_stats.php';
} elseif ($path === '/emotions' || preg_match('#^/emotions(/|$)#', $path)) {
    include __DIR__ . '/_emotions.php';
} elseif ($path === '/todos' || preg_match('#^/todos(/|$)#', $path)) {
    include __DIR__ . '/_todos.php';
} elseif ($path === '/inbox' || preg_match('#^/inbox(/|$)#', $path)) {
    include __DIR__ . '/_inbox.php';
} elseif ($path === '/agents' || preg_match('#^/agents(/|$)#', $path)) {
    include __DIR__ . '/_agents.php';
} elseif ($path === '/projects' || preg_match('#^/projects(/|$)#', $path)) {
    \Auth\ApiGate::requireProjectPerm($auth_actor, $method);
    include __DIR__ . '/_projects.php';
} elseif ($path === '/repositories' || preg_match('#^/repositories(/|$)#', $path)) {
    \Auth\ApiGate::requireProjectPerm($auth_actor, $method);
    include __DIR__ . '/_repositories.php';
} elseif ($path === '/issues' || preg_match('#^/issues(/|$)#', $path)) {
    \Auth\ApiGate::requireProjectPerm($auth_actor, $method);
    include __DIR__ . '/_issues.php';
} elseif ($path === '/exterm' || preg_match('#^/exterm(/|$)#', $path)) {
    \Auth\ApiGate::requireProjectPerm($auth_actor, $method);
    include __DIR__ . '/_exterm.php';
} elseif ($path === '/standards' || preg_match('#^/standards(/|$)#', $path)) {
    \Auth\ApiGate::requireProjectPerm($auth_actor, $method);
    include __DIR__ . '/_standards.php';
} elseif ($path === '/notify' || preg_match('#^/notify(/|$)#', $path)) {
    include __DIR__ . '/_notify.php';
} elseif ($path === '/journal-sync' || preg_match('#^/journal-sync(/|$)#', $path)) {
    include __DIR__ . '/_journal_sync.php';
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
