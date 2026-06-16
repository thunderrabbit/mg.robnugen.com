<?php
/**
 * Exterminal MCP server — MCP Streamable-HTTP transport (2025-03-26 spec).
 *
 * Claude Android connector URL: https://mg.robnugen.com/mcp/
 * Auth: Authorization: Bearer <api-key>
 *
 * Exposes 8 tools: exterm_list_items, exterm_get_item, exterm_create_item,
 * exterm_update_item, exterm_delete_item, exterm_approve_task,
 * exterm_reject_task, exterm_search_items.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

// ── Auth ──────────────────────────────────────────────────────────────────────

$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$raw_key     = '';
if (preg_match('/^Bearer\s+(\S+)$/i', $auth_header, $m)) {
    $raw_key = $m[1];
}

if (!$raw_key) {
    http_response_code(401);
    echo json_encode(mcp_error(null, -32600, 'Missing Bearer token in Authorization header'));
    exit;
}

$pdo         = \Database\Base::getPDO($config);
$apiKeyAuth  = new \Auth\ApiKey($pdo);
$auth_user_id = $apiKeyAuth->validateKey($raw_key);
$auth_key_id  = $apiKeyAuth->getLastKeyId();

if (!$auth_user_id) {
    http_response_code(401);
    echo json_encode(mcp_error(null, -32600, 'Invalid or revoked API key'));
    exit;
}

$actor_stmt = $pdo->prepare(
    "SELECT a.* FROM agent_inbox_user a
     JOIN api_keys k ON k.aiu_id = a.aiu_id
     WHERE k.key_id = ?"
);
$actor_stmt->execute([$auth_key_id]);
$auth_actor  = $actor_stmt->fetch(\PDO::FETCH_ASSOC);
$caller_aiu  = (int) $auth_actor['aiu_id'];
$exterm      = new \Exterm\Items($pdo);

// ── Request parsing ───────────────────────────────────────────────────────────

$raw_body = file_get_contents('php://input');
$msg      = json_decode($raw_body, true);

if (!is_array($msg) || !isset($msg['method'])) {
    http_response_code(400);
    echo json_encode(mcp_error(null, -32700, 'Invalid JSON-RPC message'));
    exit;
}

$id     = $msg['id']     ?? null;
$method = $msg['method'] ?? '';
$params = $msg['params'] ?? [];

// ── JSON-RPC helpers ──────────────────────────────────────────────────────────

function mcp_ok($id, array $result): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

function mcp_error($id, int $code, string $message): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

function mcp_tool_result($id, $data): array {
    return mcp_ok($id, ['content' => [['type' => 'text', 'text' => json_encode($data)]]]);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

switch ($method) {

    case 'initialize':
        echo json_encode(mcp_ok($id, [
            'protocolVersion' => '2025-03-26',
            'capabilities'    => ['tools' => new stdClass()],
            'serverInfo'      => ['name' => 'Exterminal', 'version' => '1.0.0'],
        ]));
        break;

    case 'notifications/initialized':
    case 'notifications/cancelled':
        http_response_code(202);
        exit;

    case 'tools/list':
        echo json_encode(mcp_ok($id, ['tools' => exterm_tool_defs()]));
        break;

    case 'tools/call':
        $tool = $params['name']      ?? '';
        $args = $params['arguments'] ?? [];

        try {
            $result = exterm_dispatch($exterm, $auth_user_id, $caller_aiu, $tool, $args);
            echo json_encode(mcp_tool_result($id, $result));
        } catch (\Exterm\ValidationException $e) {
            echo json_encode(mcp_error($id, -32602, $e->getMessage()));
        } catch (\Exterm\NotFoundException $e) {
            echo json_encode(mcp_error($id, -32603, $e->getMessage()));
        } catch (\Exterm\AccessException $e) {
            echo json_encode(mcp_error($id, -32603, $e->getMessage()));
        } catch (\Exception $e) {
            echo json_encode(mcp_error($id, -32603, 'Internal error'));
        }
        break;

    default:
        echo json_encode(mcp_error($id, -32601, "Method not found: {$method}"));
}

// ── Tool dispatch ─────────────────────────────────────────────────────────────

function exterm_dispatch(\Exterm\Items $exterm, int $user_id, int $caller_aiu, string $tool, array $a): mixed
{
    switch ($tool) {
        case 'exterm_list_items':
            return $exterm->listItems($user_id, $caller_aiu, $a);

        case 'exterm_get_item':
            $id = isset($a['exterm_item_id']) ? (int)$a['exterm_item_id'] : 0;
            if (!$id) throw new \Exterm\ValidationException("exterm_item_id is required");
            return $exterm->getItem($id, $user_id, $caller_aiu);

        case 'exterm_create_item':
            return $exterm->createItem($user_id, $caller_aiu, $a);

        case 'exterm_update_item':
            $id = isset($a['exterm_item_id']) ? (int)$a['exterm_item_id'] : 0;
            if (!$id) throw new \Exterm\ValidationException("exterm_item_id is required");
            return $exterm->updateItem($user_id, $caller_aiu, $id, $a);

        case 'exterm_delete_item':
            $id = isset($a['exterm_item_id']) ? (int)$a['exterm_item_id'] : 0;
            if (!$id) throw new \Exterm\ValidationException("exterm_item_id is required");
            return $exterm->deleteItem($user_id, $caller_aiu, $id);

        case 'exterm_approve_task':
            $id = isset($a['exterm_item_id']) ? (int)$a['exterm_item_id'] : 0;
            if (!$id) throw new \Exterm\ValidationException("exterm_item_id is required");
            return $exterm->approveTask($user_id, $caller_aiu, $id);

        case 'exterm_reject_task':
            $id = isset($a['exterm_item_id']) ? (int)$a['exterm_item_id'] : 0;
            if (!$id) throw new \Exterm\ValidationException("exterm_item_id is required");
            return $exterm->rejectTask($user_id, $caller_aiu, $id);

        case 'exterm_search_items':
            $query      = $a['query']      ?? '';
            $project_id = isset($a['project_id']) ? (int)$a['project_id'] : null;
            $limit      = isset($a['limit'])      ? (int)$a['limit']      : 20;
            return $exterm->searchItems($user_id, $caller_aiu, $query, $project_id, $limit);

        default:
            throw new \RuntimeException("Unknown tool: {$tool}");
    }
}

// ── Tool definitions (JSON Schema) ────────────────────────────────────────────

function exterm_tool_defs(): array
{
    $status_enum  = ['open','in_progress','needs_approval','approved','rejected','done'];
    $risk_enum    = ['reversible','irreversible'];
    $kind_enum    = ['context','task'];
    $item_id_prop = ['type' => 'integer', 'description' => 'exterm_item_id of the item'];

    return [
        [
            'name'        => 'exterm_list_items',
            'description' => 'List Exterminal items for a project. Returns metadata (no body). Filterable by kind, status, assignee, and since datetime.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'project_id'   => ['type' => 'integer', 'description' => 'Project to list (required)'],
                    'kind'         => ['type' => 'string',  'enum' => $kind_enum],
                    'status'       => ['type' => 'string',  'enum' => $status_enum],
                    'assignee_aiu' => ['type' => 'integer', 'description' => 'Filter by assignee aiu_id'],
                    'since'        => ['type' => 'string',  'description' => 'ISO datetime — items updated after this'],
                    'limit'        => ['type' => 'integer', 'default' => 20],
                    'offset'       => ['type' => 'integer', 'default' => 0],
                ],
                'required' => ['project_id'],
            ],
        ],
        [
            'name'        => 'exterm_get_item',
            'description' => 'Fetch one Exterminal item in full, including markdown body.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['exterm_item_id' => $item_id_prop],
                'required'   => ['exterm_item_id'],
            ],
        ],
        [
            'name'        => 'exterm_create_item',
            'description' => 'Create a new Exterminal context doc or task. author_aiu is set automatically from the API key — callers cannot forge provenance.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'project_id'   => ['type' => 'integer'],
                    'kind'         => ['type' => 'string', 'enum' => $kind_enum],
                    'title'        => ['type' => 'string'],
                    'body'         => ['type' => 'string', 'description' => 'Markdown body (optional)'],
                    'risk'         => ['type' => 'string', 'enum' => $risk_enum, 'default' => 'reversible'],
                    'assignee_aiu' => ['type' => 'integer', 'description' => 'aiu_id of the intended agent; null = unassigned'],
                ],
                'required' => ['project_id','kind','title'],
            ],
        ],
        [
            'name'        => 'exterm_update_item',
            'description' => 'Update an Exterminal item. Status transitions are enforced: irreversible tasks cannot jump directly to done.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'exterm_item_id' => $item_id_prop,
                    'title'          => ['type' => 'string'],
                    'body'           => ['type' => 'string'],
                    'status'         => ['type' => 'string', 'enum' => $status_enum],
                    'risk'           => ['type' => 'string', 'enum' => $risk_enum],
                    'assignee_aiu'   => ['type' => ['integer','null']],
                ],
                'required' => ['exterm_item_id'],
            ],
        ],
        [
            'name'        => 'exterm_delete_item',
            'description' => 'Permanently delete an Exterminal item.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['exterm_item_id' => $item_id_prop],
                'required'   => ['exterm_item_id'],
            ],
        ],
        [
            'name'        => 'exterm_approve_task',
            'description' => 'Approve an irreversible task. Moves status from needs_approval → approved. This is the human gate — only Rob should call this.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['exterm_item_id' => $item_id_prop],
                'required'   => ['exterm_item_id'],
            ],
        ],
        [
            'name'        => 'exterm_reject_task',
            'description' => 'Reject an irreversible task. Moves status from needs_approval → rejected.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['exterm_item_id' => $item_id_prop],
                'required'   => ['exterm_item_id'],
            ],
        ],
        [
            'name'        => 'exterm_search_items',
            'description' => 'Full-text search over Exterminal item titles and bodies. Optionally scoped to one project.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'query'      => ['type' => 'string'],
                    'project_id' => ['type' => 'integer', 'description' => 'Scope to this project (optional)'],
                    'limit'      => ['type' => 'integer', 'default' => 20],
                ],
                'required' => ['query'],
            ],
        ],
    ];
}
