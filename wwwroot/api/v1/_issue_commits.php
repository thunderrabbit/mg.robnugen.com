<?php

/**
 * Issue → commit links. Included from _issues.php.
 *
 * The rules live in Issues\IssueService: linking a commit needs write access to
 * the issue and a repository in the same account, and removing a link is for
 * whoever added it or a human on the account.
 *
 * Scope from _issues.php: $method, $xsub, $issue_service, $issue_caller
 */

declare(strict_types=1);

/**
 * Set up by index.php and _issues.php, which includes this file.
 *
 * @var string $method
 * @var string $xsub
 * @var \Issues\IssueService $issue_service
 * @var \Issues\Caller $issue_caller
 */

// ─── GET /issues/commits/list ───────────────────────────────────────────────

if ($method === 'GET' && $xsub === '/list') {
    issues_api(static function () use ($issue_service, $issue_caller): void {
        echo json_encode(issues_page(
            'commits',
            $issue_service->commits($issue_caller, \Issues\Command\ListCommits::fromInput($_GET)),
        ));
    });
    return;
}

// ─── POST /issues/commits/add ───────────────────────────────────────────────

if ($method === 'POST' && $xsub === '/add') {
    issues_api(static function () use ($issue_service, $issue_caller): void {
        $input  = \Input\Input::json((string) file_get_contents('php://input'));
        $commit = $issue_service->addCommit($issue_caller, \Issues\Command\AddCommit::fromInput($input));

        http_response_code(201);
        echo json_encode(['commit' => $commit]);
    });
    return;
}

// ─── DELETE /issues/commits/delete ──────────────────────────────────────────

if ($method === 'DELETE' && $xsub === '/delete') {
    issues_api(static function () use ($issue_service, $issue_caller): void {
        $input = \Input\Input::json((string) file_get_contents('php://input'));
        $issue_service->deleteCommit($issue_caller, \Input\Input::positiveId($input, 'issue_commit_id'));

        echo json_encode(['deleted' => 1]);
    });
    return;
}

// ─── Fallthrough ─────────────────────────────────────────────────────────────

http_response_code(404);
echo json_encode([
    'error' => 'Not found',
    'hint'  => 'GET /list; POST /add; DELETE /delete',
]);
