<?php

/**
 * Issues, comments, timers, commits — all nested under /issues/*.
 *
 * Issue routes:
 *   GET    /issues/list              free — ?project_id required; filters: status, assignee_aiu,
 *                                           include_terminal, parent_issue_id, limit, offset
 *   GET    /issues/get               free — ?issue_id
 *   POST   /issues/create            1    — body: {project_id, title, description?, priority?,
 *                                                  assignee_aiu?, parent_issue_id?, status_id?}
 *   PATCH  /issues/update            1    — body: {issue_id, title?, description?, priority?,
 *                                                  assignee_aiu?, parent_issue_id?, status_id?}
 *   DELETE /issues/delete            1    — human only
 *   GET    /issues/statuses          free — static 4-row lookup
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
 * get, create and update go through Issues\IssueService, which owns the issue rules
 * (access, assignee and parent checks, statuses, the running-timer block on
 * terminal statuses). The rest are still inline here.
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

$sub        = preg_replace('#^/issues#', '', $path) ?: '/';
$caller_aiu = \Field::int($auth_actor, 'aiu_id');
$is_human   = ($auth_actor['actor_type'] ?? '') === 'human';

$issue_caller  = new \Issues\Caller(userId: $auth_user_id, aiu: $caller_aiu, isHuman: $is_human);
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
    }
}

/**
 * Load an issue and the caller's membership on its project in one query.
 * Returns ['issue' => row, 'role' => ['can_read','can_write']] or null if no access / not found.
 * Null is returned both for "not found" and "not a member"; caller should 404/403 uniformly
 * to avoid leaking project existence to non-members.
 *
 * @return array{issue: array<mixed>, role: array{can_read: int, can_write: int}}|null
 */
function issue_with_access(\PDO $pdo, int $issue_id, int $user_id, int $caller_aiu): ?array
{
    $stmt = $pdo->prepare(
        "SELECT i.*, pm.can_read, pm.can_write
         FROM issues i
         JOIN projects p        ON p.project_id = i.project_id
         JOIN project_members pm ON pm.project_id = i.project_id AND pm.member_aiu = ?
         WHERE i.issue_id = ? AND p.user_id = ?
         LIMIT 1"
    );
    $stmt->execute([$caller_aiu, $issue_id, $user_id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    return [
        'issue' => $row,
        'role'  => ['can_read' => \Field::int($row, 'can_read'), 'can_write' => \Field::int($row, 'can_write')],
    ];
}

/**
 * Return running timers on an issue (stopped_at_utc IS NULL).
 * Used in the 409 answer when a status change to is_terminal is blocked.
 *
 * @return array<int, array<string, mixed>>
 */
function running_timers(\PDO $pdo, int $issue_id): array
{
    $stmt = $pdo->prepare(
        "SELECT issue_timer_id, worker_aiu, started_at_utc,
                TIMESTAMPDIFF(MICROSECOND, started_at_utc, NOW(6)) / 1000.0 AS elapsed_ms
         FROM issue_timers
         WHERE issue_id = ? AND stopped_at_utc IS NULL
         ORDER BY started_at_utc ASC"
    );
    $stmt->execute([$issue_id]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
    $stmt = $pdo->prepare(
        "SELECT status_id, slug, label, sort_order, is_terminal, is_default
         FROM issue_statuses ORDER BY sort_order ASC"
    );
    $stmt->execute();
    echo json_encode(['statuses' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    return;
}

// ─── GET /issues/list ────────────────────────────────────────────────────────

if ($method === 'GET' && $sub === '/list') {
    $project_id       = \Field::int($_GET, 'project_id');
    $status_slug      = trim(\Field::string($_GET, 'status'));
    $assignee_aiu     = \Field::intOrNull($_GET, 'assignee_aiu');
    $include_terminal = \Field::int($_GET, 'include_terminal');
    $parent_filter    = isset($_GET['parent_issue_id']) ? $_GET['parent_issue_id'] : null;
    $limit            = max(1, min(200, \Field::int($_GET, 'limit', 100)));
    $offset           = max(0, \Field::int($_GET, 'offset'));

    if ($project_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id is required']);
        return;
    }

    $role = project_membership($pdo, $project_id, $auth_user_id, $caller_aiu);
    if (!$role || !$role['can_read']) {
        http_response_code(403);
        echo json_encode(['error' => 'No read access to this project']);
        return;
    }

    $where  = ['i.project_id = ?', 'p.user_id = ?'];
    $params = [$project_id, $auth_user_id];

    if ($status_slug !== '') {
        $where[] = 's.slug = ?';
        $params[] = $status_slug;
    }
    if (!$include_terminal && $status_slug === '') {
        $where[] = 's.is_terminal = 0';
    }
    if ($assignee_aiu !== null) {
        if ($assignee_aiu === 0) {
            $where[] = 'i.assignee_aiu IS NULL';
        } else {
            $where[] = 'i.assignee_aiu = ?';
            $params[] = $assignee_aiu;
        }
    }
    if ($parent_filter !== null) {
        if ($parent_filter === '' || $parent_filter === '0' || $parent_filter === 'null') {
            $where[] = 'i.parent_issue_id IS NULL';
        } else {
            $where[] = 'i.parent_issue_id = ?';
            $params[] = \Field::toInt($parent_filter);
        }
    }

    $where_sql = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT i.issue_id, i.priority, i.project_id, i.parent_issue_id,
                i.author_aiu, i.assignee_aiu,
                i.status_id, s.slug AS status_slug, s.label AS status_label, s.is_terminal,
                i.title, i.created_at_utc, i.updated_at_utc, i.done_at_utc
         FROM issues i
         JOIN projects p       ON p.project_id = i.project_id
         JOIN issue_statuses s ON s.status_id = i.status_id
         WHERE {$where_sql}
         ORDER BY FIELD(i.priority, 'high', 'normal', 'low'), i.updated_at_utc DESC
         LIMIT ? OFFSET ?"
    );
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);

    $count_stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM issues i
         JOIN projects p ON p.project_id = i.project_id
         JOIN issue_statuses s ON s.status_id = i.status_id
         WHERE {$where_sql}"
    );
    $count_stmt->execute(array_slice($params, 0, -2));

    echo json_encode([
        'issues' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
        'total'  => (int)$count_stmt->fetchColumn(),
        'limit'  => $limit,
        'offset' => $offset,
    ]);
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
        $input = \Field::jsonArray((string) file_get_contents('php://input'));
        $issue = $issue_service->create($issue_caller, \Issues\Command\CreateIssue::fromInput($input));

        http_response_code(201);
        echo json_encode(['issue' => $issue]);
    });
    return;
}

// ─── PATCH /issues/update ────────────────────────────────────────────────────

if ($method === 'PATCH' && $sub === '/update') {
    issues_api(static function () use ($issue_service, $issue_caller, $pdo): void {
        $input  = \Field::jsonArray((string) file_get_contents('php://input'));
        $update = \Issues\Command\UpdateIssue::fromInput($input);

        try {
            $issue = $issue_service->update($issue_caller, $update);
        } catch (\Issues\ConflictException $e) {
            http_response_code(409);
            echo json_encode([
                'error'          => $e->getMessage(),
                'running_timers' => running_timers($pdo, $update->issueId),
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
    if (!$is_human) {
        http_response_code(403);
        echo json_encode(['error' => 'Only humans can delete issues; use status=done instead']);
        return;
    }

    $input    = \Field::jsonArray((string) file_get_contents('php://input'));
    $issue_id = \Field::int($input, 'issue_id');
    if ($issue_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'issue_id is required']);
        return;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'issues/delete');

    $stmt = $pdo->prepare(
        "DELETE i FROM issues i
         JOIN projects p ON p.project_id = i.project_id
         WHERE i.issue_id = ? AND p.user_id = ?"
    );
    $stmt->execute([$issue_id, $auth_user_id]);

    echo json_encode(['deleted' => $stmt->rowCount()]);
    return;
}

// ─── Fallthrough ─────────────────────────────────────────────────────────────

http_response_code(404);
echo json_encode([
    'error' => 'Not found',
    'hint'  => 'GET /list, /get, /statuses; POST /create; PATCH /update; DELETE /delete. Nested: /comments/*, /timers/*, /commits/*',
]);
