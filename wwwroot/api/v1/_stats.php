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
 * Variables from index.php: $pdo, $auth_user_id, $auth_key_id (null for Bearer callers), $auth_token_id (Bearer callers only), $method
 */

declare(strict_types=1);

/**
 * Set up by index.php, which includes this file.
 *
 * @var \PDO $pdo
 * @var int $auth_user_id
 * @var ?int $auth_key_id
 * @var ?int $auth_token_id
 * @var string $method
 * @var string $path
 * @var array<mixed> $auth_actor
 */

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Session totals, streaks and the account's credits are session data.
if (!\Field::int($auth_actor, 'can_read_sessions')) {
    http_response_code(403);
    echo json_encode(['error' => 'This API key does not have permission to read sessions']);
    exit;
}

\Auth\ApiGate::requireCredit($pdo, $auth_user_id, $auth_key_id, $path, $auth_token_id);

// ── Totals ────────────────────────────────────────────────────────────────────

$activity_kai = new \ActivityTracking\ActivityKai($pdo);
$totals = $activity_kai->getTotals($auth_user_id);

// ── Streak ────────────────────────────────────────────────────────────────────
// Fetch distinct session dates from last 90 days (covers any realistic streak).

$date_rows = $activity_kai->getRecentSessionDates($auth_user_id);

$streak       = 0;
$today_str    = new DateTime('today')->format('Y-m-d');
$yesterday_str = new DateTime('yesterday')->format('Y-m-d');

if (!empty($date_rows)) {
    $first = $date_rows[0];

    // Streak is alive if the most recent session was today or yesterday
    if ($first === $today_str || $first === $yesterday_str) {
        $streak = 1;
        $prev   = new DateTime($first);

        for ($i = 1; $i < count($date_rows); $i++) {
            $curr     = new DateTime(\Field::toString($date_rows[$i]));
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

$credits = new \Billing\Credits($pdo)->getRemaining($auth_user_id);

// ── Response ──────────────────────────────────────────────────────────────────

echo json_encode([
    'stats' => [
        'total_sessions'      => \Field::int($totals, 'total_sessions'),
        'total_seconds'       => \Field::int($totals, 'total_seconds'),
        'current_streak_days' => $streak,
        'credits_remaining'   => $credits,
    ],
]);
