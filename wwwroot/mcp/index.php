<?php

/**
 * Exterminal MCP server — MCP Streamable-HTTP transport (2025-03-26 spec).
 *
 * User story: Rob's phone (Claude Android) connects here to read and write
 * Exterminal items without a laptop intermediary.
 *
 * Inaugurated: 2026-06-15
 *
 * When to use
 * -----------
 * Phone / remote Claude client → use this endpoint (Authorization: Bearer).
 * Laptop agents                → use jikan exterm_* tools (stdio, no HTTP).
 *
 * Connector URL:  https://mg.robnugen.com/mcp/
 * Auth:           OAuth 2.0 Client Credentials (token_endpoint: /mcp/token.php)
 *                 Falls back to direct Bearer API key for curl/testing.
 * Protocol:       JSON-RPC 2.0, POST only, no streaming
 *
 * Note tools (Exterm\Items): exterm_list_projects, exterm_list_items,
 *   exterm_get_item, exterm_create_item, exterm_update_item, exterm_delete_item,
 *   exterm_search_items.
 * Issue tools (Issues\IssueService): issue_list, issue_get, issue_create, issue_update,
 *   issue_comment_add, issue_comment_list — concrete task state; ET notes are the
 *   loose capture layer. Status changes stay on the laptop path.
 *
 * SQL delegated to \Exterm\Items and \Issues\IssueService.
 *
 * Phone connector setup
 * ---------------------
 * See ~/.claude/projects/.../memory/project_exterminal.md § Phone connector setup.
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Session-Id');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Expose-Headers: Mcp-Session-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 */

// ── Auth ──────────────────────────────────────────────────────────────────────
// Accepts OAuth access tokens (issued by mcp/token.php) or direct API keys.

// DreamHost FastCGI may deliver this as REDIRECT_HTTP_AUTHORIZATION
$auth_header = \Field::string($_SERVER, 'HTTP_AUTHORIZATION', \Field::string($_SERVER, 'REDIRECT_HTTP_AUTHORIZATION'));
$raw_token = '';
if (preg_match('/^Bearer\s+(\S+)$/i', $auth_header, $m)) {
    $raw_token = $m[1];
}

if (!$raw_token) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer realm="Exterminal", resource_metadata="https://mg.robnugen.com/.well-known/oauth-protected-resource"');
    echo json_encode(\Mcp\JsonRpc::error(null, -32600, 'Missing Bearer token'));
    exit;
}

$pdo = \Database\Base::getPDO($config);

// Try OAuth access token first (mcp_tokens table)
$token_hash   = hash('sha256', $raw_token);
$auth_user_id = null;
$auth_aiu_id  = null;

$tok_row = new \Auth\McpTokens($pdo)->userAndActorOf($token_hash);

if ($tok_row !== null) {
    $auth_user_id = \Field::int($tok_row, 'user_id');
    $auth_aiu_id  = \Field::int($tok_row, 'aiu_id');
} else {
    // Fall back to direct API key (for non-OAuth clients, e.g. curl testing)
    $apiKeyAuth   = new \Auth\ApiKey($pdo);
    $auth_user_id = $apiKeyAuth->validateKey($raw_token);
    if ($auth_user_id) {
        $auth_aiu_id = $apiKeyAuth->aiuIdOfKey($apiKeyAuth->getLastKeyId());
    }
}

if (!$auth_user_id) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer realm="Exterminal", error="invalid_token", resource_metadata="https://mg.robnugen.com/.well-known/oauth-protected-resource"');
    echo json_encode(\Mcp\JsonRpc::error(null, -32600, 'Invalid or expired token'));
    exit;
}

// The agent must belong to the token's account: a key or token bound to
// another account's agent would otherwise act with that agent's identity.
$auth_actor = $auth_aiu_id === null ? null : new \Auth\Actors($pdo)->getActor($auth_aiu_id, $auth_user_id);
if ($auth_actor === null) {
    http_response_code(403);
    echo json_encode(\Mcp\JsonRpc::error(null, -32600, 'This token is not bound to an agent in its account'));
    exit;
}
// Limited apps (e.g. BG Bells) hold a token only for their granted todos.
if (new \Auth\Guards()->isLimited($auth_actor)) {
    http_response_code(403);
    echo json_encode(\Mcp\JsonRpc::error(null, -32600, 'This app may not use MCP'));
    exit;
}
$caller_aiu  = \Field::int($auth_actor, 'aiu_id');
$exterm      = new \Exterm\Items($pdo);

$issue_service = new \Issues\IssueService(new \Issues\PdoIssueStore($pdo));
$issue_caller  = new \Issues\Caller(
    userId: $auth_user_id,
    aiu: $caller_aiu,
    isHuman: \Field::string($auth_actor, 'actor_type') === 'human',
);

// ── Request parsing ───────────────────────────────────────────────────────────

$raw_body = (string) file_get_contents('php://input');
$msg      = \Field::jsonArray($raw_body);

if (!isset($msg['method'])) {
    http_response_code(400);
    echo json_encode(\Mcp\JsonRpc::error(null, -32700, 'Invalid JSON-RPC message'));
    exit;
}

$id     = $msg['id']     ?? null;
$method = \Field::string($msg, 'method');
$params = \Field::array($msg, 'params');

// ── Dispatch ──────────────────────────────────────────────────────────────────

switch ($method) {
    case 'initialize':
        // Negotiate protocol version — accept any 2025-x spec, respond with highest we support
        $client_version = \Field::string($params, 'protocolVersion', '2025-03-26');
        $proto = version_compare($client_version, '2025-06-18', '>=') ? '2025-06-18' : '2025-03-26';
        // Mcp-Session-Id is required by the Streamable-HTTP spec; we use the token hash as stable ID
        header('Mcp-Session-Id: ' . hash('sha256', $raw_token));
        echo json_encode(\Mcp\JsonRpc::ok($id, [
            'protocolVersion' => $proto,
            'capabilities'    => ['tools' => new stdClass()],
            'serverInfo'      => ['name' => 'Exterminal', 'version' => '2.0.0'],
        ]));
        break;

    case 'notifications/initialized':
    case 'notifications/cancelled':
        http_response_code(202);
        exit;

    case 'tools/list':
        echo json_encode(\Mcp\JsonRpc::ok($id, ['tools' => \Mcp\Tools::defs()]));
        break;

    case 'tools/call':
        $tool = \Field::string($params, 'name');
        $args = \Field::array($params, 'arguments');

        // Every tool reaches the project subsystem, so the same bits REST
        // checks with \Auth\ApiGate::requireProjectPerm() apply: reading tools need
        // can_read_project, the rest can_write_project.
        $needs = \Mcp\Tools::readsOnly($tool) ? 'can_read_project' : 'can_write_project';
        if (!\Field::int($auth_actor, $needs)) {
            echo json_encode(\Mcp\JsonRpc::error($id, -32603, "This connection's agent lacks {$needs}"));
            break;
        }

        try {
            $result = \Mcp\Tools::dispatch(
                $exterm,
                $issue_service,
                $issue_caller,
                $auth_user_id,
                $caller_aiu,
                $tool,
                $args,
            );
            echo json_encode(\Mcp\JsonRpc::toolResult($id, $result));
        } catch (\Exterm\ValidationException | \Issues\ValidationException | \Input\InvalidInput $e) {
            echo json_encode(\Mcp\JsonRpc::error($id, -32602, $e->getMessage()));
        } catch (\Exterm\NotFoundException | \Issues\NotFoundException | \Exterm\AccessException | \Issues\AccessException | \Issues\ConflictException $e) {
            echo json_encode(\Mcp\JsonRpc::error($id, -32603, $e->getMessage()));
        } catch (\Exception) {
            echo json_encode(\Mcp\JsonRpc::error($id, -32603, 'Internal error'));
        }
        break;

    default:
        echo json_encode(\Mcp\JsonRpc::error($id, -32601, "Method not found: {$method}"));
}
