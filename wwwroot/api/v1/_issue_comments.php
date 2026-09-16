<?php

/**
 * Issue comments — included from _issues.php.
 *
 * The rules live in Issues\IssueService: reading a comment needs read access to
 * its issue, adding one needs write access, and changing or removing one is for
 * its author or a human on the account. Anyone else gets a 403 rather than the
 * silently empty answer this endpoint used to give.
 *
 * Scope from _issues.php: $method, $csub, $issue_service, $issue_caller
 */

/**
 * Set up by index.php and _issues.php, which includes this file.
 *
 * @var string $method
 * @var string $csub
 * @var \Issues\IssueService $issue_service
 * @var \Issues\Caller $issue_caller
 */

// ─── GET /issues/comments/list ──────────────────────────────────────────────

if ($method === 'GET' && $csub === '/list') {
    issues_api(static function () use ($issue_service, $issue_caller): void {
        echo json_encode(issues_page('comments', $issue_service->comments(
            $issue_caller,
            \Input\Input::positiveId($_GET, 'issue_id'),
            \Issues\Command\Paging::fromInput($_GET, 100, 200),
        )));
    });
    return;
}

// ─── POST /issues/comments/add ──────────────────────────────────────────────

if ($method === 'POST' && $csub === '/add') {
    issues_api(static function () use ($issue_service, $issue_caller): void {
        $input   = \Input\Input::json((string) file_get_contents('php://input'));
        $comment = $issue_service->addComment($issue_caller, \Issues\Command\AddComment::fromInput($input));

        http_response_code(201);
        echo json_encode(['comment' => $comment]);
    });
    return;
}

// ─── PATCH /issues/comments/edit ────────────────────────────────────────────

if ($method === 'PATCH' && $csub === '/edit') {
    issues_api(static function () use ($issue_service, $issue_caller): void {
        $input   = \Input\Input::json((string) file_get_contents('php://input'));
        $comment = $issue_service->editComment($issue_caller, \Issues\Command\EditComment::fromInput($input));

        echo json_encode(['updated' => 1, 'comment' => $comment]);
    });
    return;
}

// ─── DELETE /issues/comments/delete ─────────────────────────────────────────

if ($method === 'DELETE' && $csub === '/delete') {
    issues_api(static function () use ($issue_service, $issue_caller): void {
        $input = \Input\Input::json((string) file_get_contents('php://input'));
        $issue_service->deleteComment($issue_caller, \Input\Input::positiveId($input, 'issue_comment_id'));

        echo json_encode(['deleted' => 1]);
    });
    return;
}

// ─── Fallthrough ─────────────────────────────────────────────────────────────

http_response_code(404);
echo json_encode([
    'error' => 'Not found',
    'hint'  => 'GET /list; POST /add; PATCH /edit; DELETE /delete',
]);
