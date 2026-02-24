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
$auth_user_id = $apiKeyAuth->validateKey($raw_key);
$auth_key_id  = $apiKeyAuth->getLastKeyId();

if (!$auth_user_id) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or revoked API key']);
    exit;
}

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

// ── Route dispatch ────────────────────────────────────────────────────────────

if ($path === '/sessions' || preg_match('#^/sessions(/|$)#', $path)) {
    include __DIR__ . '/_sessions.php';
} elseif ($path === '/activities' || preg_match('#^/activities(/|$)#', $path)) {
    include __DIR__ . '/_activities.php';
} elseif ($path === '/stats') {
    include __DIR__ . '/_stats.php';
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
