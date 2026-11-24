<?php

/**
 * Account-scoped git repositories, referenced by issue_commits.
 *
 * Routes:
 *   GET    /repositories/list         free — account-scoped, excludes deactivated by default
 *   POST   /repositories/create       1 credit, admin human or can_write_repos
 *   PATCH  /repositories/update       1 credit, admin human or can_write_repos
 *   PATCH  /repositories/deactivate   1 credit, admin human or can_write_repos (soft — preserves FKs)
 *
 * No hard delete — deactivation preserves issue_commits FK validity.
 * Hard deletes stay in the "ask Rob / mysql magic" bucket per Rob's decision.
 *
 * Variables from index.php: $pdo, $auth_user_id, $auth_key_id (null for Bearer callers), $auth_token_id (Bearer callers only), $auth_actor, $method, $path
 */

declare(strict_types=1);

/**
 * Set up by index.php, which includes this file.
 *
 * @var \PDO $pdo
 * @var \Auth\Guards $guards
 * @var int $auth_user_id
 * @var ?int $auth_key_id
 * @var ?int $auth_token_id
 * @var string $method
 * @var string $path
 * @var array<string, mixed> $auth_actor
 */

$sub = preg_replace('#^/repositories#', '', $path) ?: '/';
$is_admin_account = $method !== 'GET' && \Auth\AccountRole::isAdmin($pdo, $auth_user_id);

// ── GET /repositories/list ───────────────────────────────────────────────────

if ($method === 'GET' && $sub === '/list') {
    $include_inactive = \Field::int($_GET, 'include_inactive');

    $repositories = new \Issues\Repositories($pdo)->getRepositories($auth_user_id, $include_inactive !== 0);

    echo json_encode(['repositories' => $repositories]);
    return;
}

// ── POST /repositories/create ────────────────────────────────────────────────

if ($method === 'POST' && $sub === '/create') {
    if ($guard = $guards->repoWrite($auth_actor, $is_admin_account, 'create repositories')) {
        http_response_code($guard['code']);
        echo json_encode(['error' => $guard['error']]);
        return;
    }

    $input          = \Field::jsonArray((string) file_get_contents('php://input'));
    $name           = trim(\Field::string($input, 'name'));
    $url            = trim(\Field::string($input, 'url')) ?: null;
    $default_branch = trim(\Field::string($input, 'default_branch')) ?: 'master';

    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'name is required']);
        return;
    }
    if (mb_strlen($name) > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'name must be 100 characters or fewer']);
        return;
    }
    if ($url !== null && mb_strlen($url) > 500) {
        http_response_code(400);
        echo json_encode(['error' => 'url must be 500 characters or fewer']);
        return;
    }
    if (mb_strlen($default_branch) > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'default_branch must be 100 characters or fewer']);
        return;
    }

    $repositories = new \Issues\Repositories($pdo);
    if ($repositories->repoIdOfName($auth_user_id, $name) !== null) {
        http_response_code(409);
        echo json_encode(['error' => "Repository '$name' already exists"]);
        return;
    }

    \Auth\ApiGate::requireCredit($pdo, $auth_user_id, $auth_key_id, 'repositories/create', $auth_token_id);

    $repo_id = $repositories->addRepository($auth_user_id, $name, $url, $default_branch);

    http_response_code(201);
    echo json_encode([
        'repository' => [
            'repo_id'        => $repo_id,
            'name'           => $name,
            'url'            => $url,
            'default_branch' => $default_branch,
        ],
    ]);
    return;
}

// ── PATCH /repositories/update ───────────────────────────────────────────────

if ($method === 'PATCH' && $sub === '/update') {
    if ($guard = $guards->repoWrite($auth_actor, $is_admin_account, 'update repositories')) {
        http_response_code($guard['code']);
        echo json_encode(['error' => $guard['error']]);
        return;
    }

    $input          = \Field::jsonArray((string) file_get_contents('php://input'));
    $repo_id        = \Field::int($input, 'repo_id');
    $name           = array_key_exists('name', $input)           ? trim(\Field::string($input, 'name'))           : null;
    $url            = array_key_exists('url', $input)            ? trim(\Field::string($input, 'url'))            : 'NOT_SET';
    $default_branch = array_key_exists('default_branch', $input) ? trim(\Field::string($input, 'default_branch')) : null;

    if ($repo_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'repo_id is required']);
        return;
    }
    if ($name === null && $url === 'NOT_SET' && $default_branch === null) {
        http_response_code(400);
        echo json_encode(['error' => 'provide name, url, and/or default_branch to update']);
        return;
    }

    $changes = [];
    if ($name !== null) {
        if ($name === '' || mb_strlen($name) > 100) {
            http_response_code(400);
            echo json_encode(['error' => 'name must be 1-100 characters']);
            return;
        }
        $changes['name'] = $name;
    }
    if ($url !== 'NOT_SET') {
        if ($url !== '' && mb_strlen($url) > 500) {
            http_response_code(400);
            echo json_encode(['error' => 'url must be 500 characters or fewer']);
            return;
        }
        $changes['url'] = ($url === '') ? null : $url;
    }
    if ($default_branch !== null) {
        if ($default_branch === '' || mb_strlen($default_branch) > 100) {
            http_response_code(400);
            echo json_encode(['error' => 'default_branch must be 1-100 characters']);
            return;
        }
        $changes['default_branch'] = $default_branch;
    }

    \Auth\ApiGate::requireCredit($pdo, $auth_user_id, $auth_key_id, 'repositories/update', $auth_token_id);

    $updated = new \Issues\Repositories($pdo)->updateRepository($repo_id, $auth_user_id, $changes);

    echo json_encode(['updated' => $updated]);
    return;
}

// ── PATCH /repositories/deactivate ───────────────────────────────────────────

if ($method === 'PATCH' && $sub === '/deactivate') {
    if ($guard = $guards->repoWrite($auth_actor, $is_admin_account, 'deactivate repositories')) {
        http_response_code($guard['code']);
        echo json_encode(['error' => $guard['error']]);
        return;
    }

    $input   = \Field::jsonArray((string) file_get_contents('php://input'));
    $repo_id = \Field::int($input, 'repo_id');

    if ($repo_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'repo_id is required']);
        return;
    }

    \Auth\ApiGate::requireCredit($pdo, $auth_user_id, $auth_key_id, 'repositories/deactivate', $auth_token_id);

    $updated = new \Issues\Repositories($pdo)->deactivate($repo_id, $auth_user_id);

    echo json_encode(['updated' => $updated]);
    return;
}

// ── Fallthrough ──────────────────────────────────────────────────────────────

http_response_code(404);
echo json_encode([
    'error' => 'Not found',
    'hint'  => 'GET /list; POST /create; PATCH /update, /deactivate',
]);
