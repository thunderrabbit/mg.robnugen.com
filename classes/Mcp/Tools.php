<?php

/**
 * The tools of the MCP endpoint (wwwroot/mcp/index.php): what they are,
 * which only read, and what each one does.
 */

declare(strict_types=1);

namespace Mcp;

class Tools
{
    /**
     * True for the tools that only read. Anything not listed counts as a write,
     * so a new tool is gated on can_write_project until it is added here.
     */
    public static function readsOnly(string $tool): bool
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
    public static function dispatch(
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
                return self::issueJson($issueService->view($caller, \Input\Input::positiveId($a, 'issue_id')));

            case 'issue_create':
                // The phone path doesn't set assignees, parents or statuses; laptop agents own those.
                $created = $issueService->create(
                    $caller,
                    \Issues\Command\CreateIssue::fromInput(self::only($a, ['project_id', 'title', 'description', 'encoding', 'priority'])),
                );
                return self::issueJson($issueService->view($caller, $created->issueId));

            case 'issue_update':
                // Status changes stay with laptop agents, so only these fields are accepted here.
                $updated = $issueService->update(
                    $caller,
                    \Issues\Command\UpdateIssue::fromInput(self::only($a, ['issue_id', 'title', 'description', 'encoding', 'priority'])),
                );
                return self::issueJson($issueService->view($caller, $updated->issueId));

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
    private static function issueJson(\Issues\IssueView $view): array
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
    private static function only(array $a, array $keys): array
    {
        return array_intersect_key($a, array_flip($keys));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function defs(): array
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
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
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
}
