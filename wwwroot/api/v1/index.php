<?php

/**
 * mg.robnugen.com Behavioral Session Ledger API v1
 *
 * Authentication: X-API-Key: sk_...
 *
 * Write endpoints cost 1 credit:
 *   POST /api/v1/sessions   — start a session
 *   GET  /api/v1/stats      — pre-computed aggregates
 *
 * All other endpoints are free (reads).
 */

header('Content-Type: application/json');

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

// ── Authentication ────────────────────────────────────────────────────────────

$raw_key = $_SERVER['HTTP_X_API_KEY'] ?? null;
if (empty($raw_key)) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing X-API-Key header']);
    exit;
}

$pdo = \Database\Base::getPDO($config);
$apiKeyAuth = new \Auth\ApiKey($pdo);
$guards = new \Auth\Guards();   // shared by handler includes (e.g. _inbox.php)
$auth_user_id = $apiKeyAuth->validateKey($raw_key);
$auth_key_id  = $apiKeyAuth->getLastKeyId();

if (!$auth_user_id) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or revoked API key']);
    exit;
}

// ── Actor identity lookup ────────────────────────────────────────────────────
// Fetch the agent_inbox_user row for this API key's aiu_id.
// Available to all handler files as $auth_actor.
$actor_stmt = $pdo->prepare(
    "SELECT a.* FROM agent_inbox_user a
     JOIN api_keys k ON k.aiu_id = a.aiu_id
     WHERE k.key_id = ?"
);
$actor_stmt->execute([$auth_key_id]);
$auth_actor = $actor_stmt->fetch(\PDO::FETCH_ASSOC);

// ── Route parsing ─────────────────────────────────────────────────────────────

$method    = $_SERVER['REQUEST_METHOD'];
$uri_path  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path      = rtrim(preg_replace('#^/api/v1#', '', $uri_path), '/') ?: '/';

// ── Credit enforcement + usage logging ───────────────────────────────────────
// Call at the top of any write endpoint before processing.
// Atomically deducts 1 credit, then logs the call to api_usage.

function require_credit(\PDO $pdo, int $user_id, int $key_id, string $endpoint): void
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
            "INSERT INTO api_usage (user_id, key_id, endpoint) VALUES (?, ?, ?)"
        );
        $log->execute([$user_id, $key_id, $endpoint]);
    } catch (\Exception $e) {
        // Never fail a request over logging
    }
}

// Coarse subsystem gate for /projects, /repositories, /issues.
// GET → can_read_project; everything else (POST/PATCH/DELETE) → can_write_project.
// Fine-grained access within the subsystem continues to flow through project_members.
function require_project_perm(array $auth_actor, string $method): void
{
    $needs = ($method === 'GET') ? 'can_read_project' : 'can_write_project';
    if (empty($auth_actor[$needs])) {
        http_response_code(403);
        echo json_encode(['error' => "This API key does not have permission ({$needs}) for the project subsystem"]);
        exit;
    }
}

// Project membership lookup used by the issue tracker endpoints.
// Returns ['can_read'=>int,'can_write'=>int] if the caller is a member of the project
// AND the project belongs to the authed account. Returns null for "no access."
// Project admin ops (create/update/delete project, membership CRUD) do NOT use this —
// they gate on actor_type='human' plus account ownership, per Rob's rule that humans
// can admin any project in their account but still need membership to read contents.
function project_membership(\PDO $pdo, int $project_id, int $user_id, int $caller_aiu): ?array
{
    $stmt = $pdo->prepare(
        "SELECT pm.can_read, pm.can_write
         FROM project_members pm
         JOIN projects p ON p.project_id = pm.project_id
         WHERE pm.project_id = ? AND pm.member_aiu = ? AND p.user_id = ?
         LIMIT 1"
    );
    $stmt->execute([$project_id, $caller_aiu, $user_id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return ['can_read' => (int)$row['can_read'], 'can_write' => (int)$row['can_write']];
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
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
