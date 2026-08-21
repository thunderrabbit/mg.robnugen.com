<?php

/**
 * Account-scoped git repositories, referenced by issue_commits.
 *
 * Routes:
 *   GET    /repositories/list         free — account-scoped, excludes deactivated by default
 *   POST   /repositories/create       1 credit, human only
 *   PATCH  /repositories/update       1 credit, human only
 *   PATCH  /repositories/deactivate   1 credit, human only (soft — preserves FKs)
 *
 * No hard delete — deactivation preserves issue_commits FK validity.
 * Hard deletes stay in the "ask Rob / mysql magic" bucket per Rob's decision.
 *
 * Variables from index.php: $pdo, $auth_user_id, $auth_key_id, $auth_actor, $method, $path
 */

$sub = preg_replace('#^/repositories#', '', $path) ?: '/';
$is_human = ($auth_actor['actor_type'] ?? '') === 'human';

// ── GET /repositories/list ───────────────────────────────────────────────────

if ($method === 'GET' && $sub === '/list') {
    $include_inactive = (int)($_GET['include_inactive'] ?? 0);

    $where = ['user_id = ?'];
    $params = [$auth_user_id];
    if (!$include_inactive) {
        $where[] = 'is_active = 1';
    }
    $where_sql = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT repo_id, name, url, default_branch, is_active, created_at_utc
         FROM repositories
         WHERE {$where_sql}
         ORDER BY name ASC"
    );
    $stmt->execute($params);

    echo json_encode(['repositories' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    return;
}

// ── POST /repositories/create ────────────────────────────────────────────────

if ($method === 'POST' && $sub === '/create') {
    if (!$is_human) {
        http_response_code(403);
        echo json_encode(['error' => 'Only humans can create repositories']);
        return;
    }

    $input          = json_decode(file_get_contents('php://input'), true) ?? [];
    $name           = trim($input['name'] ?? '');
    $url            = trim($input['url'] ?? '') ?: null;
    $default_branch = trim($input['default_branch'] ?? '') ?: 'master';

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

    $dup = $pdo->prepare("SELECT repo_id FROM repositories WHERE user_id = ? AND name = ?");
    $dup->execute([$auth_user_id, $name]);
    if ($dup->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => "Repository '$name' already exists"]);
        return;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'repositories/create');

    $stmt = $pdo->prepare(
        "INSERT INTO repositories (user_id, name, url, default_branch)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$auth_user_id, $name, $url, $default_branch]);
    $repo_id = (int) $pdo->lastInsertId();

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
    if (!$is_human) {
        http_response_code(403);
        echo json_encode(['error' => 'Only humans can update repositories']);
        return;
    }

    $input          = json_decode(file_get_contents('php://input'), true) ?? [];
    $repo_id        = (int)($input['repo_id'] ?? 0);
    $name           = array_key_exists('name', $input)           ? trim((string)$input['name'])           : null;
    $url            = array_key_exists('url', $input)            ? trim((string)$input['url'])            : 'NOT_SET';
    $default_branch = array_key_exists('default_branch', $input) ? trim((string)$input['default_branch']) : null;

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

    $sets = [];
    $params = [];
    if ($name !== null) {
        if ($name === '' || mb_strlen($name) > 100) {
            http_response_code(400);
            echo json_encode(['error' => 'name must be 1-100 characters']);
            return;
        }
        $sets[] = 'name = ?';
        $params[] = $name;
    }
    if ($url !== 'NOT_SET') {
        if ($url !== '' && mb_strlen($url) > 500) {
            http_response_code(400);
            echo json_encode(['error' => 'url must be 500 characters or fewer']);
            return;
        }
        $sets[] = 'url = ?';
        $params[] = ($url === '') ? null : $url;
    }
    if ($default_branch !== null) {
        if ($default_branch === '' || mb_strlen($default_branch) > 100) {
            http_response_code(400);
            echo json_encode(['error' => 'default_branch must be 1-100 characters']);
            return;
        }
        $sets[] = 'default_branch = ?';
        $params[] = $default_branch;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'repositories/update');

    $params[] = $repo_id;
    $params[] = $auth_user_id;
    $set_sql = implode(', ', $sets);
    $stmt = $pdo->prepare("UPDATE repositories SET {$set_sql} WHERE repo_id = ? AND user_id = ?");
    $stmt->execute($params);

    echo json_encode(['updated' => $stmt->rowCount()]);
    return;
}

// ── PATCH /repositories/deactivate ───────────────────────────────────────────

if ($method === 'PATCH' && $sub === '/deactivate') {
    if (!$is_human) {
        http_response_code(403);
        echo json_encode(['error' => 'Only humans can deactivate repositories']);
        return;
    }

    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $repo_id = (int)($input['repo_id'] ?? 0);

    if ($repo_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'repo_id is required']);
        return;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'repositories/deactivate');

    $stmt = $pdo->prepare(
        "UPDATE repositories SET is_active = 0 WHERE repo_id = ? AND user_id = ?"
    );
    $stmt->execute([$repo_id, $auth_user_id]);

    echo json_encode(['updated' => $stmt->rowCount()]);
    return;
}

// ── Fallthrough ──────────────────────────────────────────────────────────────

http_response_code(404);
echo json_encode([
    'error' => 'Not found',
    'hint'  => 'GET /list; POST /create; PATCH /update, /deactivate',
]);
