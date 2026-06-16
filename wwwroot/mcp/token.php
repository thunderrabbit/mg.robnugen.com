<?php
/**
 * OAuth 2.0 token endpoint for the Exterminal MCP server.
 *
 * Implements RFC 6749 §4.4 — Client Credentials Grant.
 *
 * Inaugurated: 2026-06-16
 *
 * Flow
 * ----
 * 1. claude.ai reads .well-known/oauth-authorization-server to find this URL.
 * 2. It POSTs grant_type=client_credentials + client_secret=<api-key>.
 * 3. We validate the secret against the api_keys table (existing system).
 * 4. We issue a short-lived random token stored in mcp_tokens.
 * 5. claude.ai uses that token as Authorization: Bearer for MCP requests.
 *
 * Auth methods supported (token_endpoint_auth_methods_supported)
 * ---------------------------------------------------------------
 *   client_secret_post  — credentials in POST body (default for claude.ai)
 *   client_secret_basic — credentials as Basic base64(id:secret) header
 *
 * client_id is accepted but ignored; client_secret must be a valid sk_* API key.
 *
 * @see wwwroot/mcp/index.php  — validates tokens issued here
 * @see classes/Exterm/Items.php — all SQL logic
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

// ── Parse request body ────────────────────────────────────────────────────────

$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($content_type, 'application/json')) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    parse_str(file_get_contents('php://input'), $body);
    $body = array_merge($body, $_POST);
}

$grant_type = $body['grant_type'] ?? '';
if ($grant_type !== 'client_credentials') {
    http_response_code(400);
    echo json_encode(['error' => 'unsupported_grant_type']);
    exit;
}

// ── Extract credentials (post body or Basic header) ───────────────────────────

$client_secret = $body['client_secret'] ?? '';

$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Basic\s+(.+)$/i', $auth_header, $m)) {
    $decoded = base64_decode($m[1], true);
    if ($decoded !== false && str_contains($decoded, ':')) {
        [, $client_secret] = explode(':', $decoded, 2);
    }
}

if (!$client_secret) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_client', 'error_description' => 'client_secret required']);
    exit;
}

// ── Validate secret against api_keys ─────────────────────────────────────────

$pdo        = \Database\Base::getPDO($config);
$apiKeyAuth = new \Auth\ApiKey($pdo);
$auth_user_id = $apiKeyAuth->validateKey($client_secret);

if (!$auth_user_id) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_client', 'error_description' => 'Invalid or revoked API key']);
    exit;
}

$k_stmt = $pdo->prepare("SELECT aiu_id FROM api_keys WHERE key_id = ? LIMIT 1");
$k_stmt->execute([$apiKeyAuth->getLastKeyId()]);
$aiu_id = (int) $k_stmt->fetchColumn();

// ── Issue access token ────────────────────────────────────────────────────────

$raw_token  = bin2hex(random_bytes(32));  // 64-char hex string
$token_hash = hash('sha256', $raw_token);
$expires_in = 86400;                      // 24 hours

$pdo->prepare(
    "INSERT INTO mcp_tokens (aiu_id, user_id, token_hash, expires_at_utc)
     VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))"
)->execute([$aiu_id, $auth_user_id, $token_hash, $expires_in]);

// Probabilistic cleanup of expired tokens (~1% of requests)
if (random_int(1, 100) === 1) {
    $pdo->prepare("DELETE FROM mcp_tokens WHERE expires_at_utc < NOW()")->execute();
}

echo json_encode([
    'access_token' => $raw_token,
    'token_type'   => 'Bearer',
    'expires_in'   => $expires_in,
]);
