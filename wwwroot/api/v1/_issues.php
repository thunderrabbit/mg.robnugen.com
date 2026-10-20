<?php

/**
 * Issues, comments, timers, commits — all nested under /issues/*.
 *
 * Issue routes:
 *   GET    /issues/list              free — ?project_id required; filters: status, assignee_aiu,
 *                                           include_terminal, parent_issue_id, limit, offset
 *   GET    /issues/get               free — ?issue_id
 *   POST   /issues/create            1    — body: {project_id, title, description?, priority?,
 *                                                  assignee_aiu?, parent_issue_id?, status_id?, encoding?}
 *   PATCH  /issues/update            1    — body: {issue_id, title?, description?, priority?,
 *                                                  assignee_aiu?, parent_issue_id?, status_id?, encoding?}
 *   DELETE /issues/delete            1    — human only
 *   GET    /issues/statuses          free — static 4-row lookup
 *
 * `encoding` ('base64' or 'gzip+base64') says the description arrives encoded, so source code
 * gets past the request firewall; mg stores the decoded text. Default 'plain'. Comments take
 * it for `body` the same way. See \Input\BodyEncoding.
 *
 * Comment routes:
 *   GET    /issues/comments/list     free — ?issue_id required
 *   POST   /issues/comments/add      1    — can_write required; author = caller
 *   PATCH  /issues/comments/edit     1    — author = caller OR human
 *   DELETE /issues/comments/delete   1    — author = caller OR human
 *
 * Timer routes:
 *   GET    /issues/timers/list       free — ?issue_id required; filters: worker_aiu, running_only
 *   POST   /issues/timers/start      1    — can_write required; worker = caller
 *   PATCH  /issues/timers/stop       1    — worker = caller OR human
 *   PATCH  /issues/timers/edit-note  1    — worker = caller OR human
 *   DELETE /issues/timers/delete     1    — worker = caller OR human
 *
 * Commit routes:
 *   GET    /issues/commits/list      free — ?issue_id required; filters: repo_id, commit_hash
 *   POST   /issues/commits/add       1    — can_write required; added_by = caller;
 *                                           repo.user_id must equal project.user_id
 *   DELETE /issues/commits/delete    1    — added_by = caller OR human
 *
 * Every route goes through Issues\IssueService, which owns the rules: who may
 * read or write, which assignees, parents and statuses fit a project, the
 * running-timer block on terminal statuses, and who may change a comment, timer
 * or commit link once it exists. Each request is a typed command, so a mistyped
 * field is a 400 naming it. A credit is charged only once every check has
 * passed, so a refused request is not billed.
 *
 * Variables from index.php: $pdo, $auth_user_id, $auth_key_id, $auth_actor, $method, $path
 */

/**
 * Set up by index.php, which includes this file.
 *
 * @var \PDO $pdo
 * @var int $auth_user_id
 * @var int $auth_key_id
 * @var array<string, mixed> $auth_actor
 * @var string $method
 * @var string $path
 */

$sub = preg_replace('#^/issues#', '', $path) ?: '/';

$issue_caller  = new \Issues\Caller(
    userId: $auth_user_id,
    aiu: \Input\Input::positiveId($auth_actor, 'aiu_id'),
    isHuman: ($auth_actor['actor_type'] ?? '') === 'human',
);
$issue_service = new \Issues\IssueService(
    new \Issues\PdoIssueStore($pdo),
    beforeWrite: static fn(string $action) => require_credit($pdo, $auth_user_id, $auth_key_id, $action),
);

// ─── Helpers (file-local) ────────────────────────────────────────────────────

/**
 * Runs one IssueService call, answering its errors with the API's usual codes.
 *
 * @param \Closure(): void $call
 */
function issues_api(\Closure $call): void
{
    try {
        $call();
    } catch (\Input\InvalidInput | \Issues\ValidationException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Issues\NotFoundException $e) {
        http_response_code(404);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Issues\AccessException $e) {
        http_response_code(403);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Issues\ConflictException $e) {
        http_response_code(409);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

/**
 * The envelope every list read answers with.
 *
 * @template T of \JsonSerializable
 * @param \Issues\Page<T> $page
 * @return array<string, mixed>
 */
function issues_page(string $key, \Issues\Page $page): array
{
    return [
        $key     => $page->items,
        'total'  => $page->total,
        'limit'  => $page->limit,
        'offset' => $page->offset,
    ];
}

// ─── Sub-route selector ──────────────────────────────────────────────────────

$is_comments = (bool) preg_match('#^/comments(/|$)#', $sub);
$is_timers   = (bool) preg_match('#^/timers(/|$)#', $sub);
$is_commits  = (bool) preg_match('#^/commits(/|$)#', $sub);

if ($is_comments) {
    $csub = preg_replace('#^/comments#', '', $sub) ?: '/';
    include __DIR__ . '/_issue_comments.php';
    return;
}
if ($is_timers) {
    $tsub = preg_replace('#^/timers#', '', $sub) ?: '/';
    include __DIR__ . '/_issue_timers.php';
    return;
}
if ($is_commits) {
    $xsub = preg_replace('#^/commits#', '', $sub) ?: '/';
    include __DIR__ . '/_issue_commits.php';
    return;
}

// ─── GET /issues/statuses ────────────────────────────────────────────────────

if ($method === 'GET' && $sub === '/statuses') {
    echo json_encode(['statuses' => $issue_service->statuses()]);
    return;
}

// ─── GET /issues/list ────────────────────────────────────────────────────────

if ($method === 'GET' && $sub === '/list') {
    issues_api(static function () use ($issue_service, $issue_caller): void {
        echo json_encode(issues_page(
            'issues',
            $issue_service->listIssues($issue_caller, \Issues\Command\ListIssues::fromInput($_GET)),
        ));
    });
    return;
}

// ─── GET /issues/get ─────────────────────────────────────────────────────────

if ($method === 'GET' && $sub === '/get') {
    issues_api(static function () use ($issue_service, $issue_caller, $pdo): void {
        $view     = $issue_service->view($issue_caller, \Input\Input::positiveId($_GET, 'issue_id'));
        $issue_id = $view->issue->issueId;

        $counts_stmt = $pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM issue_comments WHERE issue_id = ?) AS comments,
                (SELECT COUNT(*) FROM issue_timers   WHERE issue_id = ?) AS timers,
                (SELECT COUNT(*) FROM issue_timers   WHERE issue_id = ? AND stopped_at_utc IS NULL) AS running_timers,
                (SELECT COUNT(*) FROM issue_commits  WHERE issue_id = ?) AS commits"
        );
        $counts_stmt->execute([$issue_id, $issue_id, $issue_id, $issue_id]);

        echo json_encode([
            'issue' => $view->issue->jsonSerialize() + [
                'status' => [
                    'slug'        => $view->status->slug,
                    'label'       => $view->status->label,
                    'is_terminal' => (int) $view->status->isTerminal,
                ],
                'counts' => $counts_stmt->fetch(\PDO::FETCH_ASSOC),
            ],
            'my_can_read'  => (int) $view->membership->canRead,
            'my_can_write' => (int) $view->membership->canWrite,
        ]);
    });
    return;
}

// ─── POST /issues/create ─────────────────────────────────────────────────────

if ($method === 'POST' && $sub === '/create') {
    issues_api(static function () use ($issue_service, $issue_caller): void {
        $input = \Input\Input::json((string) file_get_contents('php://input'));
        $issue = $issue_service->create($issue_caller, \Issues\Command\CreateIssue::fromInput($input));

        http_response_code(201);
        echo json_encode(['issue' => $issue]);
    });
    return;
}

// ─── PATCH /issues/update ────────────────────────────────────────────────────

if ($method === 'PATCH' && $sub === '/update') {
    issues_api(static function () use ($issue_service, $issue_caller): void {
        $input  = \Input\Input::json((string) file_get_contents('php://input'));
        $update = \Issues\Command\UpdateIssue::fromInput($input);

        try {
            $issue = $issue_service->update($issue_caller, $update);
        } catch (\Issues\ConflictException $e) {
            http_response_code(409);
            echo json_encode([
                'error'          => $e->getMessage(),
                'running_timers' => $issue_service->runningTimers($issue_caller, $update->issueId),
                'hint'           => 'Stop timers via PATCH /issues/timers/stop, then retry',
            ]);
            return;
        }

        echo json_encode(['updated' => 1, 'issue' => $issue]);
    });
    return;
}

// ─── DELETE /issues/delete ───────────────────────────────────────────────────

if ($method === 'DELETE' && $sub === '/delete') {
    issues_api(static function () use ($issue_service, $issue_caller): void {
        $input = \Input\Input::json((string) file_get_contents('php://input'));

        echo json_encode([
            'deleted' => $issue_service->delete($issue_caller, \Input\Input::positiveId($input, 'issue_id')),
        ]);
    });
    return;
}

// ─── Fallthrough ─────────────────────────────────────────────────────────────

http_response_code(404);
echo json_encode([
    'error' => 'Not found',
    'hint'  => 'GET /list, /get, /statuses; POST /create; PATCH /update; DELETE /delete. Nested: /comments/*, /timers/*, /commits/*',
]);
