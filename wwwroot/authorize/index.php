<?php

/**
 * OAuth 2.0 Authorization endpoint — Authorization Code + PKCE (RFC 6749 + RFC 7636).
 *
 * Inaugurated: 2026-06-16
 *
 * Called by claude.ai when Rob clicks "Add connector" (and by BG Bells, see
 * below). Rob must be logged in to approve. On approval, a short-lived code is issued and the browser is
 * redirected back to claude.ai with that code. claude.ai then exchanges the
 * code (+ PKCE verifier) for an access token at /mcp/token.php.
 *
 * GET  /authorize/?response_type=code&client_id=...&redirect_uri=...
 *                  &code_challenge=...&code_challenge_method=S256&state=...
 *      → if this (user, client_id, redirect_uri) was approved before
 *        (oauth_client_consents), silently reissue a code for the same
 *        agent. Otherwise show the approval form.
 *
 * POST /authorize/ (form submit with hidden params + chosen aiu_id)
 *      → generate code, remember consent → redirect to redirect_uri?code=...&state=...
 *
 * The pinned client `bgbells` (mobile app, redirect bgbells://oauth/callback)
 * skips the picker: it is bound to the logged-in user's actor named
 * "BG Bells" (mg #801 for per-user actors), always shows its own Allow / Deny
 * page and never reissues a code silently. Its token is limited to the todos
 * granted to that actor.
 *
 * @see wwwroot/mcp/token.php  — exchanges the code for an access token
 * @see wwwroot/.well-known/oauth-authorization-server  — discovery metadata
 */

declare(strict_types=1);

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 * @var \Auth\IsLoggedIn $is_logged_in
 */

// Must be logged in to authorize
if (!$is_logged_in->isLoggedIn()) {
    $return = urlencode('https://' . \Field::string($_SERVER, 'HTTP_HOST') . \Field::string($_SERVER, 'REQUEST_URI'));
    header("Location: /login/?next={$return}");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo     = \Database\Base::getPDO($config);

// ── Read OAuth params ─────────────────────────────────────────────────────────

$response_type          = \Field::string($_REQUEST, 'response_type');
$client_id              = \Field::string($_REQUEST, 'client_id');
$redirect_uri           = \Field::string($_REQUEST, 'redirect_uri');
$code_challenge         = \Field::string($_REQUEST, 'code_challenge');
$code_challenge_method  = \Field::string($_REQUEST, 'code_challenge_method');
$state                  = \Field::string($_REQUEST, 'state');

// Validate required params; show a plain error (don't redirect — redirect_uri may be bad)
$oauth_error = null;
if ($response_type !== 'code') {
    $oauth_error = "unsupported_response_type";
} elseif (!$redirect_uri) {
    $oauth_error = "invalid_request: missing redirect_uri";
} elseif (!\Auth\OAuthClients::redirectAllowed($client_id, $redirect_uri)) {
    $oauth_error = "invalid_request: redirect_uri is not registered";
} elseif (!$code_challenge) {
    $oauth_error = "invalid_request: missing code_challenge";
} elseif ($code_challenge_method !== 'S256') {
    $oauth_error = "invalid_request: only S256 supported";
}

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

// ── Pinned client (bgbells): one named actor, own Allow / Deny page ──────────

// mg #801: the pin is by (user_id, name) for the logged-in user only; a posted
// aiu_id is never trusted for a pinned client.
$pinned_name = \Auth\OAuthClients::pinnedActorName($client_id);
$pinned_aiu  = null;
if ($pinned_name !== null) {
    $pin_stmt = $pdo->prepare("SELECT aiu_id FROM agent_inbox_user WHERE user_id = ? AND name = ? AND todos_limited = 1 LIMIT 1");
    $pin_stmt->execute([$user_id, $pinned_name]);
    $pin_found = $pin_stmt->fetchColumn();
    // The pinned actor must be limited and hold at least one granted todo, so
    // the phone can never be handed a broad actor's token.
    $pin_has_grants = $pin_found
        && new \Auth\TodoGrants($pdo)->todoIds(\Field::toInt($pin_found)) !== [];
    if (!$pin_has_grants) {
        http_response_code(400);
        echo htmlspecialchars(\Auth\OAuthClients::displayName($client_id) . " isn't set up for this account.");
        exit;
    }
    $pinned_aiu = \Field::toInt($pin_found);
}

// ── Issue an auth code and redirect back to the client ────────────────────────

function issue_auth_code_and_redirect(\PDO $pdo, int $aiu_id, int $user_id, string $code_challenge, string $redirect_uri, string $state): void
{
    $raw_code  = bin2hex(random_bytes(32));
    $code_hash = hash('sha256', $raw_code);

    $pdo->prepare(
        "INSERT INTO oauth_auth_codes
             (aiu_id, user_id, code_hash, code_challenge, redirect_uri, state, expires_at_utc)
         VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
    )->execute([$aiu_id, $user_id, $code_hash, $code_challenge, $redirect_uri, $state]);

    $sep = str_contains($redirect_uri, '?') ? '&' : '?';
    header("Location: {$redirect_uri}{$sep}code={$raw_code}&state=" . urlencode($state));
    exit;
}

// ── GET: skip the form if this client+redirect was already approved ──────────

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $client_id !== '' && \Auth\OAuthClients::remembersConsent($client_id)) {
    $consent_stmt = $pdo->prepare(
        "SELECT aiu_id FROM oauth_client_consents
         WHERE user_id = ? AND client_id = ? AND redirect_uri = ?
         LIMIT 1"
    );
    $consent_stmt->execute([$user_id, $client_id, $redirect_uri]);
    $remembered_aiu = $consent_stmt->fetchColumn();

    if ($remembered_aiu !== false) {
        issue_auth_code_and_redirect($pdo, (int)$remembered_aiu, $user_id, $code_challenge, $redirect_uri, $state);
    }
}

// ── POST: issue code, remember consent, and redirect ──────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pinned_aiu !== null) {
    if (\Field::string($_POST, 'decision') === 'allow') {
        issue_auth_code_and_redirect($pdo, $pinned_aiu, $user_id, $code_challenge, $redirect_uri, $state);
    }
    // $redirect_uri already passed OAuthClients::redirectAllowed above.
    $sep = str_contains($redirect_uri, '?') ? '&' : '?';
    header("Location: {$redirect_uri}{$sep}error=access_denied&state=" . urlencode($state));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chosen_aiu = \Field::int($_POST, 'aiu_id');

    // Verify chosen aiu belongs to this user
    $valid = false;
    foreach ($agent_choices as $a) {
        if (\Field::int($a, 'aiu_id') === $chosen_aiu) {
            $valid = true;
            break;
        }
    }
    if (!$valid) {
        http_response_code(400);
        echo "Invalid agent selection.";
        exit;
    }

    if ($client_id !== '' && \Auth\OAuthClients::remembersConsent($client_id)) {
        $pdo->prepare(
            "INSERT INTO oauth_client_consents (user_id, aiu_id, client_id, redirect_uri)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE aiu_id = VALUES(aiu_id), created_at_utc = NOW()"
        )->execute([$user_id, $chosen_aiu, $client_id, $redirect_uri]);
    }

    issue_auth_code_and_redirect($pdo, $chosen_aiu, $user_id, $code_challenge, $redirect_uri, $state);
}

// ── GET: show approval form ───────────────────────────────────────────────────

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");

if ($pinned_aiu !== null) {
    $todo_ids = new \Auth\TodoGrants($pdo)->todoIds($pinned_aiu);
    $todo_titles = [];
    if ($todo_ids !== []) {
        $in = implode(',', array_fill(0, count($todo_ids), '?'));
        $title_stmt = $pdo->prepare("SELECT title FROM todos WHERE todo_id IN ({$in}) ORDER BY todo_id");
        $title_stmt->execute($todo_ids);
        foreach ($title_stmt->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $todo_titles[] = \Field::toString($t);
        }
    }
    $page->set("page_title", "Connect BG Bells — Meiso Gambare");
    $app = new \Template($config, $is_logged_in);
    $app->setTemplate("authorize/app.tpl.php");
    $app->set("app_name", \Auth\OAuthClients::displayName($client_id) ?? $client_id);
    $app->set("todo_titles", $todo_titles);
    $app->set("client_id", $client_id);
    $app->set("redirect_uri", $redirect_uri);
    $app->set("code_challenge", $code_challenge);
    $app->set("code_challenge_method", $code_challenge_method);
    $app->set("state", $state);
    $page->set("page_content", $app->grabTheGoods());
    $page->echoToScreen();
    exit;
}

$page->set("page_title", "Authorize MCP Connection — Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("authorize/index.tpl.php");
$inner->set("client_id", $client_id);
$inner->set("redirect_uri", $redirect_uri);
$inner->set("code_challenge", $code_challenge);
$inner->set("code_challenge_method", $code_challenge_method);
$inner->set("state", $state);
$inner->set("agent_choices", $agent_choices);

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
