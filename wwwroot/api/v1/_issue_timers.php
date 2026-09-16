<?php

/**
 * Issue timers — count-up only, multiple concurrent per worker allowed.
 * Included from _issues.php.
 *
 * The rules live in Issues\IssueService: starting a timer needs write access to
 * the issue, and stopping, retitling or removing one is for its worker or a
 * human on the account. Stopping a timer that already stopped is a 409 rather
 * than an answer claiming nothing happened.
 *
 * Scope from _issues.php: $method, $tsub, $issue_service, $issue_caller
 */

/**
 * Set up by index.php and _issues.php, which includes this file.
 *
 * @var string $method
 * @var string $tsub
 * @var \Issues\IssueService $issue_service
 * @var \Issues\Caller $issue_caller
 */

// ─── GET /issues/timers/list ────────────────────────────────────────────────

if ($method === 'GET' && $tsub === '/list') {
    issues_api(static function () use ($issue_service, $issue_caller): void {
        echo json_encode(issues_page(
            'timers',
            $issue_service->timers($issue_caller, \Issues\Command\ListTimers::fromInput($_GET)),
        ));
    });
    return;
}

// ─── POST /issues/timers/start ──────────────────────────────────────────────

if ($method === 'POST' && $tsub === '/start') {
    issues_api(static function () use ($issue_service, $issue_caller): void {
        $input = \Input\Input::json((string) file_get_contents('php://input'));
        $timer = $issue_service->startTimer($issue_caller, \Issues\Command\StartTimer::fromInput($input));

        http_response_code(201);
        echo json_encode(['timer' => $timer]);
    });
    return;
}

// ─── PATCH /issues/timers/stop ──────────────────────────────────────────────

if ($method === 'PATCH' && $tsub === '/stop') {
    issues_api(static function () use ($issue_service, $issue_caller): void {
        $input = \Input\Input::json((string) file_get_contents('php://input'));
        $timer = $issue_service->stopTimer($issue_caller, \Input\Input::positiveId($input, 'issue_timer_id'));

        echo json_encode(['updated' => 1, 'timer' => $timer]);
    });
    return;
}

// ─── PATCH /issues/timers/edit-note ─────────────────────────────────────────

if ($method === 'PATCH' && $tsub === '/edit-note') {
    issues_api(static function () use ($issue_service, $issue_caller): void {
        $input = \Input\Input::json((string) file_get_contents('php://input'));
        $timer = $issue_service->editTimerNote($issue_caller, \Issues\Command\EditTimerNote::fromInput($input));

        echo json_encode(['updated' => 1, 'timer' => $timer]);
    });
    return;
}

// ─── DELETE /issues/timers/delete ───────────────────────────────────────────

if ($method === 'DELETE' && $tsub === '/delete') {
    issues_api(static function () use ($issue_service, $issue_caller): void {
        $input = \Input\Input::json((string) file_get_contents('php://input'));
        $issue_service->deleteTimer($issue_caller, \Input\Input::positiveId($input, 'issue_timer_id'));

        echo json_encode(['deleted' => 1]);
    });
    return;
}

// ─── Fallthrough ─────────────────────────────────────────────────────────────

http_response_code(404);
echo json_encode([
    'error' => 'Not found',
    'hint'  => 'GET /list; POST /start; PATCH /stop, /edit-note; DELETE /delete',
]);
