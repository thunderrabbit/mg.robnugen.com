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

declare(strict_types=1);

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

/**
 * Set up by prepend.php.
 *
 * @var \Config\Settings $config
 */

// ── Parse request body ────────────────────────────────────────────────────────

$content_type = \Field::string($_SERVER, 'CONTENT_TYPE');
if (str_contains($content_type, 'application/json')) {
    $body = \Field::jsonArray((string) file_get_contents('php://input'));
} else {
    parse_str((string) file_get_contents('php://input'), $body);
    $body = array_merge($body, $_POST);
}

$grant_type = $body['grant_type'] ?? '';
$pdo        = \Database\Base::getPDO($config);
$mcp_tokens = new \Auth\McpTokens($pdo);
$auth_codes = new \Auth\AuthCodes($pdo);

// ── Authorization Code + PKCE ─────────────────────────────────────────────────

if ($grant_type === 'authorization_code') {
    $raw_code     = \Field::string($body, 'code');
    $code_verifier = \Field::string($body, 'code_verifier');
    $redirect_uri  = \Field::string($body, 'redirect_uri');

    if (!$raw_code || !$code_verifier || !$redirect_uri) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request', 'error_description' => 'code, code_verifier, and redirect_uri are required']);
        exit;
    }

    $code_hash = hash('sha256', $raw_code);

    $code_row = $auth_codes->getUsable($code_hash);

    if ($code_row === null) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant', 'error_description' => 'Code not found, expired, or already used']);
        exit;
    }

    // Verify redirect_uri matches
    if (\Field::string($code_row, 'redirect_uri') !== $redirect_uri) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant', 'error_description' => 'redirect_uri mismatch']);
        exit;
    }

    // Verify PKCE S256: base64url(SHA-256(code_verifier)) == stored code_challenge
    $computed_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');
    if (!hash_equals(\Field::string($code_row, 'code_challenge'), $computed_challenge)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed']);
        exit;
    }

    // Mark code used (single-use). The claim is atomic: of two requests
    // racing with the same code, only one gets a token.
    if (!$auth_codes->markUsed(\Field::int($code_row, 'code_id'))) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant', 'error_description' => 'Authorization code already used']);
        exit;
    }

    $aiu_id           = \Field::int($code_row, 'aiu_id');
    $auth_user_id     = \Field::int($code_row, 'user_id');
    $existing_token_id = null;

    // ── Refresh Token (silent renewal) ───────────────────────────────────────────
} elseif ($grant_type === 'refresh_token') {
    $raw_refresh = \Field::string($body, 'refresh_token');

    if (!$raw_refresh) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request', 'error_description' => 'refresh_token is required']);
        exit;
    }

    $refresh_hash = hash('sha256', $raw_refresh);

    $refresh_row = $mcp_tokens->getByRefreshHash($refresh_hash);

    if ($refresh_row === null) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant', 'error_description' => 'Refresh token not found, expired, or already rotated']);
        exit;
    }

    $aiu_id            = \Field::int($refresh_row, 'aiu_id');
    $auth_user_id      = \Field::int($refresh_row, 'user_id');
    $existing_token_id = \Field::int($refresh_row, 'token_id');
    $old_refresh_hash  = $refresh_hash;

    // ── Client Credentials (fallback) ────────────────────────────────────────────
} elseif ($grant_type === 'client_credentials') {
    $client_secret = \Field::string($body, 'client_secret');

    // Also accept Basic auth header
    $auth_header = \Field::string($_SERVER, 'HTTP_AUTHORIZATION');
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

    $aiu_id = $apiKeyAuth->aiuIdOfKey($apiKeyAuth->getLastKeyId());
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
// Sliding window, restarted on every refresh; limited apps get a shorter one.
$token_actor = new \Auth\Actors($pdo)->getActor($aiu_id, $auth_user_id);
$refresh_ttl_days = \Auth\TokenLifetime::refreshDays($token_actor ?? []);

if ($issue_refresh) {
    $raw_refresh  = bin2hex(random_bytes(32));
    $refresh_hash = hash('sha256', $raw_refresh);
}

if ($existing_token_id) {
    // Rotation: reuse the row, replace both tokens. The swap is atomic: if
    // two requests race with the same refresh token, only the first rotates
    // and the second is refused.
    $rotated = $mcp_tokens->rotate(
        $existing_token_id,
        $old_refresh_hash,
        $token_hash,
        $expires_in,
        $refresh_hash,
        $refresh_ttl_days
    );
    if (!$rotated) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant', 'error_description' => 'Refresh token already rotated']);
        exit;
    }
} else {
    $mcp_tokens->addToken(
        $aiu_id,
        $auth_user_id,
        $token_hash,
        $expires_in,
        $refresh_hash,
        $issue_refresh ? $refresh_ttl_days : null
    );
}

// Probabilistic cleanup of expired tokens and used auth codes (~1% of requests)
if (random_int(1, 100) === 1) {
    $mcp_tokens->deleteExpired();
    $auth_codes->deleteOld();
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
