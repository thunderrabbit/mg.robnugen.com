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
    $actor_stmt = $pdo->prepare(
        "SELECT a.* FROM agent_inbox_user a
         JOIN api_keys k ON k.aiu_id = a.aiu_id AND a.user_id = k.user_id
         WHERE k.key_id = ?"
    );
    $actor_stmt->execute([$auth_key_id]);
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

    $actor_stmt = $pdo->prepare("SELECT * FROM agent_inbox_user WHERE aiu_id = ? AND user_id = ? LIMIT 1");
    $actor_stmt->execute([$token_row['aiu_id'], $token_row['user_id']]);
}
$auth_actor = $actor_stmt->fetch(\PDO::FETCH_ASSOC);
/** @var array<string, mixed> $auth_actor */
$auth_actor = is_array($auth_actor) ? $auth_actor : [];

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

// ── Credit enforcement + usage logging ───────────────────────────────────────
// Call at the top of any write endpoint before processing.
// Atomically deducts 1 credit, then logs the call to api_usage.

function require_credit(\PDO $pdo, int $user_id, ?int $key_id, string $endpoint, ?int $token_id = null): void
{
    $stmt = $pdo->prepare(
        "UPDATE api_credits
         SET credits_remaining = credits_remaining - 1
         WHERE user_id = ? AND credits_remaining > 0"
    );
    $stmt->execute([$user_id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(402);
        echo json_encode([
            'error'       => 'No credits remaining',
            'upgrade_url' => 'https://mg.robnugen.com/billing',
        ]);
        exit;
    }

    // Log to api_usage after successful deduction
    try {
        $log = $pdo->prepare(
            "INSERT INTO api_usage (user_id, key_id, token_id, endpoint) VALUES (?, ?, ?, ?)"
        );
        $log->execute([$user_id, $key_id, $token_id, $endpoint]);
    } catch (\Exception) {
        // Never fail a request over logging
    }
}

// Coarse subsystem gate for /projects, /repositories, /issues.
// GET → can_read_project; everything else (POST/PATCH/DELETE) → can_write_project.
// Fine-grained access within the subsystem continues to flow through project_members.
/**
 * @param array<mixed> $auth_actor
 */
function require_project_perm(array $auth_actor, string $method): void
{
    $needs = ($method === 'GET') ? 'can_read_project' : 'can_write_project';
    if (empty($auth_actor[$needs])) {
        http_response_code(403);
        echo json_encode(['error' => "This API key does not have permission ({$needs}) for the project subsystem"]);
        exit;
    }
}

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
    require_project_perm($auth_actor, $method);
    include __DIR__ . '/_projects.php';
} elseif ($path === '/repositories' || preg_match('#^/repositories(/|$)#', $path)) {
    require_project_perm($auth_actor, $method);
    include __DIR__ . '/_repositories.php';
} elseif ($path === '/issues' || preg_match('#^/issues(/|$)#', $path)) {
    require_project_perm($auth_actor, $method);
    include __DIR__ . '/_issues.php';
} elseif ($path === '/exterm' || preg_match('#^/exterm(/|$)#', $path)) {
    require_project_perm($auth_actor, $method);
    include __DIR__ . '/_exterm.php';
} elseif ($path === '/notify' || preg_match('#^/notify(/|$)#', $path)) {
    include __DIR__ . '/_notify.php';
} elseif ($path === '/journal-sync' || preg_match('#^/journal-sync(/|$)#', $path)) {
    include __DIR__ . '/_journal_sync.php';
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
