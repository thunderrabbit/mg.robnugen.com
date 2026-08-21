<?php

/**
 * Stats endpoint — included by index.php, not directly accessible.
 *
 * GET /api/v1/stats     pre-computed aggregates (costs 1 credit)
 *
 * Returns:
 *   total_sessions       — count of all completed sessions
 *   total_seconds        — sum of actual_sec across all completed sessions
 *   current_streak_days  — consecutive days with at least one completed session
 *   credits_remaining    — useful for agents to know before next write
 *
 * Variables from index.php: $pdo, $auth_user_id, $method
 */

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_credit($pdo, $auth_user_id, $auth_key_id, $path);

// ── Totals ────────────────────────────────────────────────────────────────────

$stmt = $pdo->prepare("
    SELECT
        COUNT(*)             AS total_sessions,
        COALESCE(SUM(actual_sec), 0) AS total_seconds
    FROM activity_kai
    WHERE user_id = ? AND actual_sec IS NOT NULL
");
$stmt->execute([$auth_user_id]);
$totals = $stmt->fetch(\PDO::FETCH_ASSOC);

// ── Streak ────────────────────────────────────────────────────────────────────
// Fetch distinct session dates from last 90 days (covers any realistic streak).

$stmt = $pdo->prepare("
    SELECT DISTINCT DATE(start_local_dt) AS d
    FROM activity_kai
    WHERE user_id = ? AND actual_sec IS NOT NULL
      AND start_local_dt >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    ORDER BY d DESC
");
$stmt->execute([$auth_user_id]);
$date_rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);

$streak       = 0;
$today_str    = (new DateTime('today'))->format('Y-m-d');
$yesterday_str = (new DateTime('yesterday'))->format('Y-m-d');

if (!empty($date_rows)) {
    $first = $date_rows[0];

    // Streak is alive if the most recent session was today or yesterday
    if ($first === $today_str || $first === $yesterday_str) {
        $streak = 1;
        $prev   = new DateTime($first);

        for ($i = 1; $i < count($date_rows); $i++) {
            $curr     = new DateTime($date_rows[$i]);
            $expected = (clone $prev)->modify('-1 day')->format('Y-m-d');

            if ($curr->format('Y-m-d') === $expected) {
                $streak++;
                $prev = $curr;
            } else {
                break;
            }
        }
    }
}

// ── Credits remaining ─────────────────────────────────────────────────────────

$credit_stmt = $pdo->prepare(
    "SELECT credits_remaining FROM api_credits WHERE user_id = ? LIMIT 1"
);
$credit_stmt->execute([$auth_user_id]);
$credits = (int)$credit_stmt->fetchColumn();

// ── Response ──────────────────────────────────────────────────────────────────

echo json_encode([
    'stats' => [
        'total_sessions'      => (int)$totals['total_sessions'],
        'total_seconds'       => (int)$totals['total_seconds'],
        'current_streak_days' => $streak,
        'credits_remaining'   => $credits,
    ],
]);
