<?php

/**
 * Projects + project members + dashboard.
 *
 * Routes (all writes cost 1 credit):
 *   GET    /projects/list               free — projects where caller is a member; ?include_archived=1
 *   GET    /projects/get                free — details for one project (member required)
 *   POST   /projects/create             1    — human only; auto-adds creator as RW member
 *   PATCH  /projects/update             1    — human only; rename/description/archive
 *   DELETE /projects/delete             1    — human only; cascades to all contents
 *   GET    /projects/members            free — list members of a project (member required)
 *   POST   /projects/add-member         1    — human only; member_aiu must share account
 *   PATCH  /projects/update-member      1    — human only
 *   DELETE /projects/remove-member      1    — human only
 *   GET    /projects/dashboard          free — my assigned + unassigned queue, all projects
 *
 * Every route goes through Projects\ProjectService, which owns the permission
 * model: reading a project's content needs can_read on a project_members row
 * (humans too), and administering projects and members is for the account's
 * human, member or not. A credit is charged only once every check has passed.
 *
 * Variables from index.php: $pdo, $auth_user_id, $auth_key_id, $auth_actor, $method, $path
 */

declare(strict_types=1);

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

$sub = preg_replace('#^/projects#', '', $path) ?: '/';

$project_caller  = new \Issues\Caller(
    userId: $auth_user_id,
    aiu: \Input\Input::positiveId($auth_actor, 'aiu_id'),
    isHuman: ($auth_actor['actor_type'] ?? '') === 'human',
);
$project_service = new \Projects\ProjectService(
    new \Projects\PdoProjectStore($pdo),
    beforeWrite: static fn(string $action) => require_credit($pdo, $auth_user_id, $auth_key_id, $action),
);

/**
 * Runs one ProjectService call, answering its errors with the API's usual codes.
 *
 * @param \Closure(): void $call
 */
function projects_api(\Closure $call): void
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
 * The decoded JSON body of a write.
 *
 * @return array<mixed>
 */
function projects_body(): array
{
    return \Input\Input::json((string) file_get_contents('php://input'));
}

// ─── GET /projects/list ─────────────────────────────────────────────────────

if ($method === 'GET' && $sub === '/list') {
    projects_api(static function () use ($project_service, $project_caller): void {
        $include_archived = isset($_GET['include_archived']) && \Input\Input::flag($_GET, 'include_archived');
        echo json_encode(['projects' => $project_service->list($project_caller, $include_archived)]);
    });
    return;
}

// ─── GET /projects/get ──────────────────────────────────────────────────────

if ($method === 'GET' && $sub === '/get') {
    projects_api(static function () use ($project_service, $project_caller): void {
        $project_id = \Input\Input::positiveId($_GET, 'project_id');
        echo json_encode(['project' => $project_service->get($project_caller, $project_id)]);
    });
    return;
}

// ─── POST /projects/create ──────────────────────────────────────────────────

if ($method === 'POST' && $sub === '/create') {
    projects_api(static function () use ($project_service, $project_caller): void {
        $project = $project_service->create(
            $project_caller,
            \Projects\Command\CreateProject::fromInput(projects_body()),
        );
        http_response_code(201);
        echo json_encode([
            'project' => [
                'project_id'  => $project->projectId,
                'name'        => $project->name,
                'description' => $project->description,
            ],
        ]);
    });
    return;
}

// ─── PATCH /projects/update ─────────────────────────────────────────────────

if ($method === 'PATCH' && $sub === '/update') {
    projects_api(static function () use ($project_service, $project_caller): void {
        $changes = \Projects\Command\UpdateProject::fromInput(projects_body());
        echo json_encode(['updated' => $project_service->update($project_caller, $changes)]);
    });
    return;
}

// ─── DELETE /projects/delete ────────────────────────────────────────────────

if ($method === 'DELETE' && $sub === '/delete') {
    projects_api(static function () use ($project_service, $project_caller): void {
        $project_id = \Input\Input::positiveId(projects_body(), 'project_id');
        echo json_encode(['deleted' => $project_service->delete($project_caller, $project_id)]);
    });
    return;
}

// ─── GET /projects/members ──────────────────────────────────────────────────

if ($method === 'GET' && $sub === '/members') {
    projects_api(static function () use ($project_service, $project_caller): void {
        $project_id = \Input\Input::positiveId($_GET, 'project_id');
        echo json_encode(['members' => $project_service->members($project_caller, $project_id)]);
    });
    return;
}

// ─── POST /projects/add-member ──────────────────────────────────────────────

if ($method === 'POST' && $sub === '/add-member') {
    projects_api(static function () use ($project_service, $project_caller): void {
        $member = \Projects\Command\AddMember::fromInput(projects_body());
        $project_service->addMember($project_caller, $member);
        http_response_code(201);
        echo json_encode([
            'member' => [
                'project_id' => $member->projectId,
                'member_aiu' => $member->memberAiu,
                'can_read'   => (int) $member->canRead,
                'can_write'  => (int) $member->canWrite,
            ],
        ]);
    });
    return;
}

// ─── PATCH /projects/update-member ──────────────────────────────────────────

if ($method === 'PATCH' && $sub === '/update-member') {
    projects_api(static function () use ($project_service, $project_caller): void {
        $changes = \Projects\Command\UpdateMember::fromInput(projects_body());
        echo json_encode(['updated' => $project_service->updateMember($project_caller, $changes)]);
    });
    return;
}

// ─── DELETE /projects/remove-member ─────────────────────────────────────────

if ($method === 'DELETE' && $sub === '/remove-member') {
    projects_api(static function () use ($project_service, $project_caller): void {
        $input = projects_body();
        $removed = $project_service->removeMember(
            $project_caller,
            \Input\Input::positiveId($input, 'project_id'),
            \Input\Input::positiveId($input, 'member_aiu'),
        );
        echo json_encode(['deleted' => $removed]);
    });
    return;
}

// ─── GET /projects/dashboard ────────────────────────────────────────────────
// My assigned issues across all projects + unassigned queue across my
// projects. Both exclude terminal statuses and need can_read.

if ($method === 'GET' && $sub === '/dashboard') {
    projects_api(static function () use ($project_service, $project_caller): void {
        $dashboard = $project_service->dashboard($project_caller);
        echo json_encode([
            'my_assigned_issues' => $dashboard['mine'],
            'unassigned_queue'   => $dashboard['queue'],
        ]);
    });
    return;
}

// ─── Fallthrough ─────────────────────────────────────────────────────────────

http_response_code(404);
echo json_encode([
    'error' => 'Not found',
    'hint'  => 'GET /list, /get, /members, /dashboard; POST /create, /add-member; PATCH /update, /update-member; DELETE /delete, /remove-member',
]);
