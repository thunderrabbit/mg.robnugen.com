<?php
/**
 * OAuth 2.0 token endpoint for the Exterminal MCP server.
 *
 * Inaugurated: 2026-06-16
 *
 * Supports three grant types:
 *
 *   authorization_code (primary — used by claude.ai connector)
 *   -------------------------------------------------------
 *   POST /mcp/token.php
 *   grant_type=authorization_code
 *   &code=<code from /authorize>
 *   &code_verifier=<PKCE verifier>
 *   &redirect_uri=<must match what was used at /authorize>
 *   → validates PKCE S256, marks code used, issues access + refresh token
 *
 *   refresh_token (silent renewal — used by claude.ai connector)
 *   -------------------------------------------------------
 *   POST /mcp/token.php
 *   grant_type=refresh_token
 *   &refresh_token=<refresh token from a prior token response>
 *   → rotates: issues a new access + refresh token, invalidates the old refresh token
 *
 *   client_credentials (fallback — useful for curl testing)
 *   -------------------------------------------------------
 *   POST /mcp/token.php
 *   grant_type=client_credentials
 *   &client_secret=<api-key>
 *   → validates API key directly, issues access token (no refresh token —
 *     the caller already holds the long-lived secret and can re-request anytime)
 *
 * Access tokens are stored in mcp_tokens (24-hour lifetime).
 * Refresh tokens live alongside them (400-day sliding lifetime, rotated on
 * each use) so the authorization_code flow never has to re-prompt for
 * interactive approval as long as it's used at least once a year.
 * Auth codes are stored in oauth_auth_codes (10-minute lifetime, single-use).
 *
 * @see wwwroot/authorize/index.php  — issues the auth code
 * @see wwwroot/mcp/index.php        — validates tokens issued here
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
$pdo        = \Database\Base::getPDO($config);

// ── Authorization Code + PKCE ─────────────────────────────────────────────────

if ($grant_type === 'authorization_code') {

    $raw_code     = $body['code']          ?? '';
    $code_verifier = $body['code_verifier'] ?? '';
    $redirect_uri  = $body['redirect_uri']  ?? '';

    if (!$raw_code || !$code_verifier || !$redirect_uri) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request', 'error_description' => 'code, code_verifier, and redirect_uri are required']);
        exit;
    }

    $code_hash = hash('sha256', $raw_code);

    $code_stmt = $pdo->prepare(
        "SELECT * FROM oauth_auth_codes
         WHERE code_hash = ? AND expires_at_utc > NOW() AND used_at_utc IS NULL
         LIMIT 1"
    );
    $code_stmt->execute([$code_hash]);
    $code_row = $code_stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$code_row) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant', 'error_description' => 'Code not found, expired, or already used']);
        exit;
    }

    // Verify redirect_uri matches
    if ($code_row['redirect_uri'] !== $redirect_uri) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant', 'error_description' => 'redirect_uri mismatch']);
        exit;
    }

    // Verify PKCE S256: base64url(SHA-256(code_verifier)) == stored code_challenge
    $computed_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');
    if (!hash_equals($code_row['code_challenge'], $computed_challenge)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed']);
        exit;
    }

    // Mark code used (single-use)
    $pdo->prepare("UPDATE oauth_auth_codes SET used_at_utc = NOW() WHERE code_id = ?")
        ->execute([$code_row['code_id']]);

    $aiu_id           = (int) $code_row['aiu_id'];
    $auth_user_id     = (int) $code_row['user_id'];
    $existing_token_id = null;

// ── Refresh Token (silent renewal) ───────────────────────────────────────────

} elseif ($grant_type === 'refresh_token') {

    $raw_refresh = $body['refresh_token'] ?? '';

    if (!$raw_refresh) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request', 'error_description' => 'refresh_token is required']);
        exit;
    }

    $refresh_hash = hash('sha256', $raw_refresh);

    $refresh_stmt = $pdo->prepare(
        "SELECT * FROM mcp_tokens
         WHERE refresh_token_hash = ? AND refresh_expires_at_utc > NOW()
         LIMIT 1"
    );
    $refresh_stmt->execute([$refresh_hash]);
    $refresh_row = $refresh_stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$refresh_row) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant', 'error_description' => 'Refresh token not found, expired, or already rotated']);
        exit;
    }

    $aiu_id            = (int) $refresh_row['aiu_id'];
    $auth_user_id      = (int) $refresh_row['user_id'];
    $existing_token_id = (int) $refresh_row['token_id'];

// ── Client Credentials (fallback) ────────────────────────────────────────────

} elseif ($grant_type === 'client_credentials') {

    $client_secret = $body['client_secret'] ?? '';

    // Also accept Basic auth header
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

    $apiKeyAuth   = new \Auth\ApiKey($pdo);
    $auth_user_id = $apiKeyAuth->validateKey($client_secret);

    if (!$auth_user_id) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_client', 'error_description' => 'Invalid or revoked API key']);
        exit;
    }

    $k_stmt = $pdo->prepare("SELECT aiu_id FROM api_keys WHERE key_id = ? LIMIT 1");
    $k_stmt->execute([$apiKeyAuth->getLastKeyId()]);
    $aiu_id = (int) $k_stmt->fetchColumn();
    $existing_token_id = null;

} else {
    http_response_code(400);
    echo json_encode(['error' => 'unsupported_grant_type']);
    exit;
}

// ── Issue access token (+ refresh token, except for client_credentials) ───────

$raw_token  = bin2hex(random_bytes(32));
$token_hash = hash('sha256', $raw_token);
$expires_in = 86400; // 24 hours

// client_credentials callers already hold the long-lived API key and can
// re-request an access token anytime, so they get no refresh token.
$issue_refresh   = $grant_type !== 'client_credentials';
$raw_refresh     = null;
$refresh_hash    = null;
$refresh_ttl_days = 400; // sliding window; rotated on every refresh

if ($issue_refresh) {
    $raw_refresh  = bin2hex(random_bytes(32));
    $refresh_hash = hash('sha256', $raw_refresh);
}

if ($existing_token_id) {
    // Rotation: reuse the row, replace both tokens.
    $pdo->prepare(
        "UPDATE mcp_tokens
         SET token_hash = ?, expires_at_utc = DATE_ADD(NOW(), INTERVAL ? SECOND),
             refresh_token_hash = ?, refresh_expires_at_utc = DATE_ADD(NOW(), INTERVAL ? DAY)
         WHERE token_id = ?"
    )->execute([$token_hash, $expires_in, $refresh_hash, $refresh_ttl_days, $existing_token_id]);
} else {
    $pdo->prepare(
        "INSERT INTO mcp_tokens (aiu_id, user_id, token_hash, expires_at_utc, refresh_token_hash, refresh_expires_at_utc)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, DATE_ADD(NOW(), INTERVAL ? DAY))"
    )->execute([$aiu_id, $auth_user_id, $token_hash, $expires_in, $refresh_hash, $issue_refresh ? $refresh_ttl_days : null]);
}

// Probabilistic cleanup of expired tokens and used auth codes (~1% of requests)
if (random_int(1, 100) === 1) {
    $pdo->prepare("DELETE FROM mcp_tokens WHERE expires_at_utc < NOW() AND (refresh_expires_at_utc IS NULL OR refresh_expires_at_utc < NOW())")->execute();
    $pdo->prepare("DELETE FROM oauth_auth_codes WHERE expires_at_utc < DATE_SUB(NOW(), INTERVAL 1 DAY)")->execute();
}

$response = [
    'access_token' => $raw_token,
    'token_type'   => 'Bearer',
    'expires_in'   => $expires_in,
];
if ($raw_refresh) {
    $response['refresh_token'] = $raw_refresh;
}

echo json_encode($response);
