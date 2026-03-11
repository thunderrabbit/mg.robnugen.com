<?php
/**
 * Session endpoints — included by index.php, not directly accessible.
 *
 * GET    /api/v1/sessions                         list sessions
 * POST   /api/v1/sessions                         start a session  (costs 1 credit)
 * POST   /api/v1/sessions/backfill                create a past session with start/end times (costs 1 credit)
 * GET    /api/v1/sessions/{ak_id}                 get single session
 * PATCH  /api/v1/sessions/{ak_id}/stop            stop a session (server computes duration)
 * DELETE /api/v1/sessions/{ak_id}                 delete a session
 *
 * Variables from index.php: $pdo, $auth_user_id, $method, $path
 */

$ak_id      = null;
$sub_action = null;
$is_backfill = ($path === '/sessions/backfill');

if (!$is_backfill) {
    if (preg_match('#^/sessions/(\d+)/(\w+)$#', $path, $m)) {
        $ak_id      = (int)$m[1];
        $sub_action = $m[2];
    } elseif (preg_match('#^/sessions/(\d+)$#', $path, $m)) {
        $ak_id = (int)$m[1];
    }
}

$activityHelper = new \ActivityTracking\ActivityKai($pdo);

// ── GET /sessions ─────────────────────────────────────────────────────────────

if ($method === 'GET' && $ak_id === null && !$is_backfill) {
    $from        = $_GET['from'] ?? null;
    $to          = $_GET['to']   ?? null;
    $activity_id = isset($_GET['activity_id']) ? (int)$_GET['activity_id'] : null;
    $is_active   = isset($_GET['is_active']) ? (int)$_GET['is_active'] : null;
    $limit       = min((int)($_GET['limit']  ?? 20), 50);
    $offset      = max((int)($_GET['offset'] ?? 0),   0);

    $where  = ['ak.user_id = ?'];
    $params = [$auth_user_id];

    if ($from) {
        $where[]  = 'DATE(ak.start_local_dt) >= ?';
        $params[] = $from;
    }
    if ($to) {
        $where[]  = 'DATE(ak.start_local_dt) <= ?';
        $params[] = $to;
    }
    if ($activity_id) {
        $where[]  = 'ak.activity_id = ?';
        $params[] = $activity_id;
    }
    if ($is_active === 1) {
        $where[]  = 'ak.actual_sec IS NULL';
    } elseif ($is_active === 0) {
        $where[]  = 'ak.actual_sec IS NOT NULL';
    }

    $where_sql = implode(' AND ', $where);
    $params[]  = $limit;
    $params[]  = $offset;

    $stmt = $pdo->prepare("
        SELECT ak.ak_id, ak.activity_id, a.activity_name,
               ak.start_local_dt, ak.intended_sec, ak.actual_sec, ak.bonus_sec,
               t.iana_name AS timezone,
               CASE WHEN ak.actual_sec IS NULL THEN 1 ELSE 0 END AS is_active
        FROM activity_kai ak
        JOIN activities a  ON ak.activity_id  = a.activity_id
        JOIN timezones  t  ON ak.timezone_id  = t.timezone_id
        WHERE {$where_sql}
        ORDER BY ak.start_local_dt DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);

    echo json_encode(['sessions' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    exit;
}

// ── GET /sessions/{ak_id} ────────────────────────────────────────────────────

if ($method === 'GET' && $ak_id !== null && $sub_action === null) {
    $stmt = $pdo->prepare("
        SELECT ak.ak_id, ak.activity_id, a.activity_name,
               ak.start_local_dt, ak.intended_sec, ak.actual_sec, ak.bonus_sec,
               t.iana_name AS timezone,
               CASE WHEN ak.actual_sec IS NULL THEN 1 ELSE 0 END AS is_active,
               CASE WHEN ak.actual_sec IS NULL
                    THEN TIMESTAMPDIFF(SECOND, ak.created_at_utc, UTC_TIMESTAMP())
                    ELSE NULL END AS elapsed_sec
        FROM activity_kai ak
        JOIN activities a ON ak.activity_id = a.activity_id
        JOIN timezones  t ON ak.timezone_id  = t.timezone_id
        WHERE ak.ak_id = ? AND ak.user_id = ?
    ");
    $stmt->execute([$ak_id, $auth_user_id]);
    $session = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$session) {
        http_response_code(404);
        echo json_encode(['error' => 'Session not found']);
        exit;
    }

    echo json_encode(['session' => $session]);
    exit;
}

// ── POST /sessions/backfill ──────────────────────────────────────────────────

if ($method === 'POST' && $is_backfill) {
    require_credit($pdo, $auth_user_id, $auth_key_id, $path);

    $input        = json_decode(file_get_contents('php://input'), true) ?? [];
    $activity_id  = (int)($input['activity_id']  ?? 1);
    $timezone_str = trim($input['timezone'] ?? 'UTC');
    $start_time   = trim($input['start_time'] ?? '');
    $end_time     = trim($input['end_time']   ?? '');

    if (!$start_time || !$end_time) {
        http_response_code(400);
        echo json_encode(['error' => 'start_time and end_time are required']);
        exit;
    }

    // Validate timezone
    try {
        $tz = new DateTimeZone($timezone_str);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid timezone: ' . $timezone_str]);
        exit;
    }

    // Parse start and end times
    try {
        $dtStart = new DateTime($start_time, $tz);
        $dtEnd   = new DateTime($end_time, $tz);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid datetime format: ' . $e->getMessage()]);
        exit;
    }

    $actual_sec = $dtEnd->getTimestamp() - $dtStart->getTimestamp();
    if ($actual_sec <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'end_time must be after start_time']);
        exit;
    }

    $start_local_dt = $dtStart->format('Y-m-d H:i:s');

    $timezoneHelper = new \ActivityTracking\Timezone($pdo);
    $timezone_id    = $timezoneHelper->getTimezoneId($timezone_str);

    // Create the session with start time
    $new_ak_id = $activityHelper->startActivity(
        $auth_user_id,
        $activity_id,
        null,
        $actual_sec,        // intended_sec = actual_sec for backfills
        $timezone_id,
        $start_local_dt
    );

    // Immediately stop it with the computed duration
    $activityHelper->stopActivity($new_ak_id, $auth_user_id, $actual_sec, 0);

    // Generate session key
    $sessionKeyHelper = new \ActivityTracking\SessionKey($pdo);
    $session_key = $sessionKeyHelper->createSessionKey($new_ak_id);

    http_response_code(201);
    echo json_encode([
        'session' => [
            'ak_id'          => $new_ak_id,
            'activity_id'    => $activity_id,
            'start_local_dt' => $start_local_dt,
            'timezone'       => $timezone_str,
            'actual_sec'     => $actual_sec,
            'is_active'      => false,
            'session_key'    => $session_key,
        ],
    ]);
    exit;
}

// ── POST /sessions ───────────────────────────────────────────────────────────

if ($method === 'POST' && $ak_id === null) {
    require_credit($pdo, $auth_user_id, $auth_key_id, $path);

    $input        = json_decode(file_get_contents('php://input'), true) ?? [];
    $activity_id  = (int)($input['activity_id']  ?? 1);
    $timezone_str = trim($input['timezone'] ?? 'UTC');
    $intended_sec = (int)($input['intended_sec']  ?? 0);

    // Validate timezone using PHP's DateTimeZone (handles America/New_York etc.)
    try {
        $tz             = new DateTimeZone($timezone_str);
        $dt             = new DateTime('now', $tz);
        $start_local_dt = $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid timezone: ' . $timezone_str]);
        exit;
    }

    $timezoneHelper = new \ActivityTracking\Timezone($pdo);
    $timezone_id    = $timezoneHelper->getTimezoneId($timezone_str);

    $new_ak_id = $activityHelper->startActivity(
        $auth_user_id,
        $activity_id,
        null,           // todo_id: not applicable for agent use
        $intended_sec,
        $timezone_id,
        $start_local_dt
    );

    // Generate session key so the dashboard can link to /mg/{key}
    $sessionKeyHelper = new \ActivityTracking\SessionKey($pdo);
    $session_key = $sessionKeyHelper->createSessionKey($new_ak_id);

    http_response_code(201);
    echo json_encode([
        'session' => [
            'ak_id'          => $new_ak_id,
            'activity_id'    => $activity_id,
            'start_local_dt' => $start_local_dt,
            'timezone'       => $timezone_str,
            'intended_sec'   => $intended_sec,
            'is_active'      => true,
            'session_key'    => $session_key,
        ],
    ]);
    exit;
}

// ── PATCH /sessions/{ak_id}/stop ─────────────────────────────────────────────

if ($method === 'PATCH' && $ak_id !== null && $sub_action === 'stop') {
    // Fetch elapsed seconds atomically — also confirms session is active
    $stmt = $pdo->prepare("
        SELECT TIMESTAMPDIFF(SECOND, created_at_utc, UTC_TIMESTAMP()) AS elapsed
        FROM activity_kai
        WHERE ak_id = ? AND user_id = ? AND actual_sec IS NULL
    ");
    $stmt->execute([$ak_id, $auth_user_id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(409);
        echo json_encode(['error' => 'Session not found, already stopped, or not yours']);
        exit;
    }

    $actual_sec = max(0, (int)$row['elapsed']);
    $activityHelper->stopActivity($ak_id, $auth_user_id, $actual_sec, 0);

    echo json_encode([
        'session' => [
            'ak_id'      => $ak_id,
            'actual_sec' => $actual_sec,
            'is_active'  => false,
        ],
    ]);
    exit;
}

// ── DELETE /sessions/{ak_id} ─────────────────────────────────────────────────

if ($method === 'DELETE' && $ak_id !== null && $sub_action === null) {
    if (!$activityHelper->verifyOwnership($ak_id, $auth_user_id)) {
        http_response_code(404);
        echo json_encode(['error' => 'Session not found']);
        exit;
    }

    $activityHelper->deleteActivity($ak_id, $auth_user_id);
    http_response_code(204);
    exit;
}

// ── Fallthrough ───────────────────────────────────────────────────────────────

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
