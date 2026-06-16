<?php
/**
 * OAuth 2.0 Authorization endpoint — Authorization Code + PKCE (RFC 6749 + RFC 7636).
 *
 * Inaugurated: 2026-06-16
 *
 * Called by claude.ai when Rob clicks "Add connector". Rob must be logged in
 * to approve. On approval, a short-lived code is issued and the browser is
 * redirected back to claude.ai with that code. claude.ai then exchanges the
 * code (+ PKCE verifier) for an access token at /mcp/token.php.
 *
 * GET  /authorize/?response_type=code&client_id=...&redirect_uri=...
 *                  &code_challenge=...&code_challenge_method=S256&state=...
 *      → show approval form
 *
 * POST /authorize/ (form submit with hidden params + chosen aiu_id)
 *      → generate code → redirect to redirect_uri?code=...&state=...
 *
 * @see wwwroot/mcp/token.php  — exchanges the code for an access token
 * @see wwwroot/.well-known/oauth-authorization-server  — discovery metadata
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

// Must be logged in to authorize
if (!$is_logged_in->isLoggedIn()) {
    $return = urlencode('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    header("Location: /login/?next={$return}");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo     = \Database\Base::getPDO($config);

// ── Read OAuth params ─────────────────────────────────────────────────────────

$response_type          = $_REQUEST['response_type']          ?? '';
$client_id              = $_REQUEST['client_id']              ?? '';
$redirect_uri           = $_REQUEST['redirect_uri']           ?? '';
$code_challenge         = $_REQUEST['code_challenge']         ?? '';
$code_challenge_method  = $_REQUEST['code_challenge_method']  ?? '';
$state                  = $_REQUEST['state']                  ?? '';

// Validate required params; show a plain error (don't redirect — redirect_uri may be bad)
$oauth_error = null;
if ($response_type !== 'code')                 $oauth_error = "unsupported_response_type";
elseif (!$redirect_uri)                        $oauth_error = "invalid_request: missing redirect_uri";
elseif (!$code_challenge)                      $oauth_error = "invalid_request: missing code_challenge";
elseif ($code_challenge_method !== 'S256')     $oauth_error = "invalid_request: only S256 supported";

if ($oauth_error) {
    http_response_code(400);
    echo htmlspecialchars("OAuth error: {$oauth_error}");
    exit;
}

// ── Load agent identities Rob can grant ──────────────────────────────────────

$aiu_stmt = $pdo->prepare(
    "SELECT aiu_id, name, actor_type, description
     FROM agent_inbox_user
     WHERE user_id = ? AND (can_read_project = 1 OR can_write_project = 1)
     ORDER BY actor_type DESC, name ASC"
);
$aiu_stmt->execute([$user_id]);
$agent_choices = $aiu_stmt->fetchAll(\PDO::FETCH_ASSOC);

// ── POST: issue code and redirect ─────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chosen_aiu = (int)($_POST['aiu_id'] ?? 0);

    // Verify chosen aiu belongs to this user
    $valid = false;
    foreach ($agent_choices as $a) {
        if ((int)$a['aiu_id'] === $chosen_aiu) { $valid = true; break; }
    }
    if (!$valid) {
        http_response_code(400);
        echo "Invalid agent selection.";
        exit;
    }

    $raw_code  = bin2hex(random_bytes(32));
    $code_hash = hash('sha256', $raw_code);

    $pdo->prepare(
        "INSERT INTO oauth_auth_codes
             (aiu_id, user_id, code_hash, code_challenge, redirect_uri, state, expires_at_utc)
         VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
    )->execute([$chosen_aiu, $user_id, $code_hash, $code_challenge, $redirect_uri, $state]);

    $sep = str_contains($redirect_uri, '?') ? '&' : '?';
    header("Location: {$redirect_uri}{$sep}code={$raw_code}&state=" . urlencode($state));
    exit;
}

// ── GET: show approval form ───────────────────────────────────────────────────

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Authorize MCP Connection — Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("authorize/index.tpl.php");
$inner->set("client_id",             $client_id);
$inner->set("redirect_uri",          $redirect_uri);
$inner->set("code_challenge",        $code_challenge);
$inner->set("code_challenge_method", $code_challenge_method);
$inner->set("state",                 $state);
$inner->set("agent_choices",         $agent_choices);

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
