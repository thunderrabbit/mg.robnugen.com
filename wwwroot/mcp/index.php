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
    echo json_encode(mcp_error(null, -32600, 'Missing Bearer token'));
    exit;
}

$pdo = \Database\Base::getPDO($config);

// Try OAuth access token first (mcp_tokens table)
$token_hash   = hash('sha256', $raw_token);
$auth_user_id = null;
$auth_aiu_id  = null;

$tok_stmt = $pdo->prepare(
    "SELECT user_id, aiu_id FROM mcp_tokens
     WHERE token_hash = ? AND expires_at_utc > NOW()
     LIMIT 1"
);
$tok_stmt->execute([$token_hash]);
$tok_row = $tok_stmt->fetch(\PDO::FETCH_ASSOC);

if ($tok_row) {
    $auth_user_id = \Field::int($tok_row, 'user_id');
    $auth_aiu_id  = \Field::int($tok_row, 'aiu_id');
} else {
    // Fall back to direct API key (for non-OAuth clients, e.g. curl testing)
    $apiKeyAuth   = new \Auth\ApiKey($pdo);
    $auth_user_id = $apiKeyAuth->validateKey($raw_token);
    if ($auth_user_id) {
        $k_stmt = $pdo->prepare("SELECT aiu_id FROM api_keys WHERE key_id = ? LIMIT 1");
        $k_stmt->execute([$apiKeyAuth->getLastKeyId()]);
        $auth_aiu_id = (int) $k_stmt->fetchColumn();
    }
}

if (!$auth_user_id) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer realm="Exterminal", error="invalid_token", resource_metadata="https://mg.robnugen.com/.well-known/oauth-protected-resource"');
    echo json_encode(mcp_error(null, -32600, 'Invalid or expired token'));
    exit;
}

// The agent must belong to the token's account: a key or token bound to
// another account's agent would otherwise act with that agent's identity.
$actor_stmt = $pdo->prepare("SELECT * FROM agent_inbox_user WHERE aiu_id = ? AND user_id = ? LIMIT 1");
$actor_stmt->execute([$auth_aiu_id, $auth_user_id]);
$auth_actor  = $actor_stmt->fetch(\PDO::FETCH_ASSOC);
if (!is_array($auth_actor)) {
    http_response_code(403);
    echo json_encode(mcp_error(null, -32600, 'This token is not bound to an agent in its account'));
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
    echo json_encode(mcp_error(null, -32700, 'Invalid JSON-RPC message'));
    exit;
}

$id     = $msg['id']     ?? null;
$method = \Field::string($msg, 'method');
$params = \Field::array($msg, 'params');

// ── JSON-RPC helpers ──────────────────────────────────────────────────────────

/**
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function mcp_ok(mixed $id, array $result): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

/**
 * @return array<string, mixed>
 */
function mcp_error(mixed $id, int $code, string $message): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

/**
 * @return array<string, mixed>
 */
function mcp_tool_result(mixed $id, mixed $data): array
{
    return mcp_ok($id, ['content' => [['type' => 'text', 'text' => json_encode($data)]]]);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

switch ($method) {
    case 'initialize':
        // Negotiate protocol version — accept any 2025-x spec, respond with highest we support
        $client_version = \Field::string($params, 'protocolVersion', '2025-03-26');
        $proto = version_compare($client_version, '2025-06-18', '>=') ? '2025-06-18' : '2025-03-26';
        // Mcp-Session-Id is required by the Streamable-HTTP spec; we use the token hash as stable ID
        header('Mcp-Session-Id: ' . hash('sha256', $raw_token));
        echo json_encode(mcp_ok($id, [
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
        echo json_encode(mcp_ok($id, ['tools' => exterm_tool_defs()]));
        break;

    case 'tools/call':
        $tool = \Field::string($params, 'name');
        $args = \Field::array($params, 'arguments');

        // Every tool reaches the project subsystem, so the same bits REST
        // checks with require_project_perm() apply: reading tools need
        // can_read_project, the rest can_write_project.
        $needs = mcp_tool_reads_only($tool) ? 'can_read_project' : 'can_write_project';
        if (!\Field::int($auth_actor, $needs)) {
            echo json_encode(mcp_error($id, -32603, "This connection's agent lacks {$needs}"));
            break;
        }

        try {
            $result = exterm_dispatch(
                $exterm,
                $issue_service,
                $issue_caller,
                $auth_user_id,
                $caller_aiu,
                $tool,
                $args,
            );
            echo json_encode(mcp_tool_result($id, $result));
        } catch (\Exterm\ValidationException | \Issues\ValidationException | \Input\InvalidInput $e) {
            echo json_encode(mcp_error($id, -32602, $e->getMessage()));
        } catch (\Exterm\NotFoundException | \Issues\NotFoundException $e) {
            echo json_encode(mcp_error($id, -32603, $e->getMessage()));
        } catch (\Exterm\AccessException | \Issues\AccessException | \Issues\ConflictException $e) {
            echo json_encode(mcp_error($id, -32603, $e->getMessage()));
        } catch (\Exception $e) {
            echo json_encode(mcp_error($id, -32603, 'Internal error'));
        }
        break;

    default:
        echo json_encode(mcp_error($id, -32601, "Method not found: {$method}"));
}

// ── Tool dispatch ─────────────────────────────────────────────────────────────

/**
 * True for the tools that only read. Anything not listed counts as a write,
 * so a new tool is gated on can_write_project until it is added here.
 */
function mcp_tool_reads_only(string $tool): bool
{
    return in_array($tool, [
        'exterm_list_projects',
        'exterm_list_items',
        'exterm_get_item',
        'exterm_search_items',
        'issue_list',
        'issue_get',
        'issue_comment_list',
    ], true);
}

/**
 * @param array<mixed> $a
 */
function exterm_dispatch(
    \Exterm\Items $exterm,
    \Issues\IssueService $issueService,
    \Issues\Caller $caller,
    int $user_id,
    int $caller_aiu,
    string $tool,
    array $a,
): mixed {
    switch ($tool) {
        case 'exterm_list_projects':
            return $exterm->listProjects($user_id, $caller_aiu);

        case 'exterm_list_items':
            return $exterm->listItems($user_id, $caller_aiu, $a);

        case 'exterm_get_item':
            $id = \Field::int($a, 'exterm_item_id');
            if (!$id) {
                throw new \Exterm\ValidationException("exterm_item_id is required");
            }
            return $exterm->getItem($id, $user_id, $caller_aiu);

        case 'exterm_create_item':
            return $exterm->createItem($user_id, $caller_aiu, $a);

        case 'exterm_update_item':
            $id = \Field::int($a, 'exterm_item_id');
            if (!$id) {
                throw new \Exterm\ValidationException("exterm_item_id is required");
            }
            return $exterm->updateItem($user_id, $caller_aiu, $id, $a);

        case 'exterm_delete_item':
            $id = \Field::int($a, 'exterm_item_id');
            if (!$id) {
                throw new \Exterm\ValidationException("exterm_item_id is required");
            }
            return $exterm->deleteItem($user_id, $caller_aiu, $id);

        // ── Jikan issues (concrete task state) ────────────────────────────────
        case 'issue_list':
            return $issueService->listIssues($caller, \Issues\Command\ListIssues::fromInput($a))->items;

        case 'issue_get':
            return mcp_issue_json($issueService->view($caller, \Input\Input::positiveId($a, 'issue_id')));

        case 'issue_create':
            // The phone path doesn't set assignees, parents or statuses; laptop agents own those.
            $created = $issueService->create(
                $caller,
                \Issues\Command\CreateIssue::fromInput(mcp_only($a, ['project_id', 'title', 'description', 'encoding', 'priority'])),
            );
            return mcp_issue_json($issueService->view($caller, $created->issueId));

        case 'issue_update':
            // Status changes stay with laptop agents, so only these fields are accepted here.
            $updated = $issueService->update(
                $caller,
                \Issues\Command\UpdateIssue::fromInput(mcp_only($a, ['issue_id', 'title', 'description', 'encoding', 'priority'])),
            );
            return mcp_issue_json($issueService->view($caller, $updated->issueId));

        case 'issue_comment_add':
            return $issueService->addComment($caller, \Issues\Command\AddComment::fromInput($a));

        case 'issue_comment_list':
            $comments = $issueService->comments(
                $caller,
                \Input\Input::positiveId($a, 'issue_id'),
                \Issues\Command\Paging::fromInput($a, 100, 200),
            );
            return [
                'comments' => $comments->items,
                'total'    => $comments->total,
                'limit'    => $comments->limit,
                'offset'   => $comments->offset,
            ];

        case 'exterm_search_items':
            $query      = \Field::string($a, 'query');
            $project_id = \Field::intOrNull($a, 'project_id');
            $limit      = \Field::int($a, 'limit', 20);
            return $exterm->searchItems($user_id, $caller_aiu, $query, $project_id, $limit);

        default:
            throw new \RuntimeException("Unknown tool: {$tool}");
    }
}

/**
 * An issue as the MCP tools have always returned it: its row plus the status
 * slug, label and terminal flag.
 *
 * @return array<string, mixed>
 */
function mcp_issue_json(\Issues\IssueView $view): array
{
    return $view->issue->jsonSerialize() + [
        'status_slug'  => $view->status->slug,
        'status_label' => $view->status->label,
        'is_terminal'  => (int) $view->status->isTerminal,
    ];
}

/**
 * Only the tool arguments a phone-side issue tool may pass on.
 *
 * @param array<mixed> $a
 * @param list<string> $keys
 * @return array<mixed>
 */
function mcp_only(array $a, array $keys): array
{
    return array_intersect_key($a, array_flip($keys));
}

// ── Tool definitions (JSON Schema) ────────────────────────────────────────────

/**
 * @return array<int, array<string, mixed>>
 */
function exterm_tool_defs(): array
{
    $item_id_prop = ['type' => 'integer', 'description' => 'exterm_item_id of the note'];
    // Only for text the server's firewall refuses (source code in a description
    // or comment): the caller encodes the text and says how. Omit it otherwise.
    $encoding_prop = [
        'type'        => 'string',
        'enum'        => ['plain', 'base64', 'gzip+base64'],
        'default'     => 'plain',
        'description' => 'Optional. How the text field is encoded. Leave out unless a plain post is refused: then base64 (or gzip then base64) the text and set this.',
    ];

    return [
        [
            'name'        => 'exterm_list_projects',
            'description' => 'List all projects the caller can access. Returns project_id, name, description. Loose/greenfield ideas go in "Greenfield Harebrain Ideas" (project_id 27).',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        [
            'name'        => 'exterm_list_items',
            'description' => 'List on-the-go notes. Omit project_id to get the most recently touched notes across ALL accessible projects (use this to recall "what was I thinking about?"). Pass project_id to list one project. Returns metadata, not full body.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'project_id' => ['type' => 'integer', 'description' => 'Optional. Omit = recent across all projects.'],
                    'since'      => ['type' => 'string',  'description' => 'ISO datetime — notes updated after this'],
                    'limit'      => ['type' => 'integer', 'default' => 20],
                    'offset'     => ['type' => 'integer', 'default' => 0],
                ],
            ],
        ],
        [
            'name'        => 'exterm_get_item',
            'description' => 'Fetch one note in full, including the markdown body.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['exterm_item_id' => $item_id_prop],
                'required'   => ['exterm_item_id'],
            ],
        ],
        [
            'name'        => 'exterm_create_item',
            'description' => 'Save a new on-the-go note against a project. For a loose/greenfield idea with no home yet, use project_id 27 (Greenfield Harebrain Ideas). author is set from the connection — provenance cannot be forged. For a concrete task, use issue_create instead.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'project_id' => ['type' => 'integer', 'description' => 'Project to file under (27 for loose ideas)'],
                    'title'      => ['type' => 'string'],
                    'body'       => ['type' => 'string', 'description' => 'Markdown body (optional)'],
                ],
                'required' => ['project_id','title'],
            ],
        ],
        [
            'name'        => 'exterm_update_item',
            'description' => 'Update a note. Set project_id to re-file the note into another project (e.g. promoting an idea out of Greenfield Harebrain into its own project).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'exterm_item_id' => $item_id_prop,
                    'title'          => ['type' => 'string'],
                    'body'           => ['type' => 'string'],
                    'project_id'     => ['type' => 'integer', 'description' => 'Move the note to this project'],
                ],
                'required' => ['exterm_item_id'],
            ],
        ],
        [
            'name'        => 'exterm_delete_item',
            'description' => 'Permanently delete a note.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['exterm_item_id' => $item_id_prop],
                'required'   => ['exterm_item_id'],
            ],
        ],
        [
            'name'        => 'exterm_search_items',
            'description' => 'Full-text search over note titles and bodies. Optionally scoped to one project.',
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

        // ── Jikan issues — concrete task state for a project ──────────────────
        [
            'name'        => 'issue_list',
            'description' => 'List a project\'s issues (concrete tasks) for context. Excludes done issues unless include_terminal=1 or a status slug is given. Read this to understand current project state before capturing a new idea.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'project_id'       => ['type' => 'integer', 'description' => 'Project whose issues to list (required)'],
                    'status'           => ['type' => 'string',  'description' => 'Filter by status slug: open, in_progress, blocked, done'],
                    'include_terminal' => ['type' => 'integer', 'description' => '1 to include done/terminal issues'],
                    'limit'            => ['type' => 'integer', 'default' => 100],
                    'offset'           => ['type' => 'integer', 'default' => 0],
                ],
                'required' => ['project_id'],
            ],
        ],
        [
            'name'        => 'issue_get',
            'description' => 'Fetch one issue in full, including description and status.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['issue_id' => ['type' => 'integer', 'description' => 'issue_id of the issue']],
                'required'   => ['issue_id'],
            ],
        ],
        [
            'name'        => 'issue_create',
            'description' => 'Create a jikan issue when an idea is a concrete, focused task for an existing project. author is set from the connection. Status defaults to the project\'s default (open).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'project_id'  => ['type' => 'integer'],
                    'title'       => ['type' => 'string'],
                    'description' => ['type' => 'string', 'description' => 'Markdown body (optional)'],
                    'encoding'    => $encoding_prop,
                    'priority'    => ['type' => 'string', 'enum' => ['low','normal','high'], 'default' => 'normal'],
                ],
                'required' => ['project_id','title'],
            ],
        ],
        [
            'name'        => 'issue_update',
            'description' => 'Edit an issue\'s title, description, or priority. Status changes are handled by laptop agents — not available from the phone.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'issue_id'    => ['type' => 'integer'],
                    'title'       => ['type' => 'string', 'description' => '1-255 characters'],
                    'description' => ['type' => 'string', 'description' => 'Markdown body; pass empty string to clear'],
                    'encoding'    => $encoding_prop,
                    'priority'    => ['type' => 'string', 'enum' => ['low','normal','high']],
                ],
                'required' => ['issue_id'],
            ],
        ],
        [
            'name'        => 'issue_comment_add',
            'description' => 'Append a timestamped note or update to an issue without changing the issue fields. Good for logging progress, blockers, or context mid-task.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'issue_id' => ['type' => 'integer'],
                    'body'     => ['type' => 'string', 'description' => 'Markdown comment body'],
                    'encoding' => $encoding_prop,
                ],
                'required' => ['issue_id','body'],
            ],
        ],
        [
            'name'        => 'issue_comment_list',
            'description' => 'List comments on an issue, oldest first. Use after issue_get to read the full discussion thread.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'issue_id' => ['type' => 'integer'],
                    'limit'    => ['type' => 'integer', 'default' => 100],
                    'offset'   => ['type' => 'integer', 'default' => 0],
                ],
                'required' => ['issue_id'],
            ],
        ],
    ];
}
