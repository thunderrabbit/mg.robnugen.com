<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Codeception\Test\Unit;
use Db\Enum\IssuePriority;
use Input\Absent;
use Input\InvalidInput;
use Issues\Command\AddComment;
use Issues\Command\AddCommit;
use Issues\Command\CreateIssue;
use Issues\Command\EditComment;
use Issues\Command\EditTimerNote;
use Issues\Command\ListCommits;
use Issues\Command\ListIssues;
use Issues\Command\ListTimers;
use Issues\Command\StartTimer;
use Issues\Command\UpdateIssue;

/**
 * The typed commands every surface builds from its request: a JSON body, a
 * form post or MCP tool arguments.
 */
final class IssueCommandsTest extends Unit
{
    public function testCreateIssueFillsDefaults(): void
    {
        $create = CreateIssue::fromInput(['project_id' => '7', 'title' => ' Typed issues ']);

        $this->assertSame(7, $create->projectId);
        $this->assertSame('Typed issues', $create->title);
        $this->assertNull($create->description);
        $this->assertSame(IssuePriority::Normal, $create->priority);
        $this->assertNull($create->assigneeAiu);
        $this->assertNull($create->parentIssueId);
        $this->assertNull($create->statusId);
    }

    public function testCreateIssueRejectsBadFields(): void
    {
        $this->assertInvalid('project_id is required', static fn() => CreateIssue::fromInput(['title' => 'x']));
        $this->assertInvalid('title is required', static fn() => CreateIssue::fromInput(['project_id' => 7]));
        $this->assertInvalid(
            "priority must be 'low', 'normal', or 'high'",
            static fn() => CreateIssue::fromInput(['project_id' => 7, 'title' => 'x', 'priority' => 'urgent']),
        );
    }

    public function testUpdateIssueLeavesUnsentFieldsAbsent(): void
    {
        $update = UpdateIssue::fromInput(['issue_id' => 5, 'description' => '', 'assignee_aiu' => 0]);

        $this->assertSame(5, $update->issueId);
        $this->assertSame(Absent::Field, $update->title);
        $this->assertSame(Absent::Field, $update->priority);
        $this->assertSame(Absent::Field, $update->statusId);
        $this->assertSame(Absent::Field, $update->parentIssueId);
        $this->assertNull($update->description);
        $this->assertNull($update->assigneeAiu);
    }

    public function testUpdateIssueNeedsSomethingToChange(): void
    {
        $this->assertInvalid(
            'provide at least one field to update',
            static fn() => UpdateIssue::fromInput(['issue_id' => 5]),
        );
        $this->assertInvalid(
            'title is required',
            static fn() => UpdateIssue::fromInput(['issue_id' => 5, 'title' => '  ']),
        );
    }

    // === ListIssues ===

    public function testListIssuesDefaultsToTheOpenIssuesOfOnePage(): void
    {
        $query = ListIssues::fromInput(['project_id' => '7']);

        $this->assertSame(7, $query->projectId);
        $this->assertNull($query->statusSlug);
        $this->assertFalse($query->includeTerminal);
        $this->assertSame(Absent::Field, $query->assigneeAiu);
        $this->assertSame(Absent::Field, $query->parentIssueId);
        $this->assertSame(100, $query->paging->limit);
        $this->assertSame(0, $query->paging->offset);
    }

    public function testListIssuesReadsAnUnassignedOrTopLevelFilterAsNull(): void
    {
        $query = ListIssues::fromInput([
            'project_id'      => 7,
            'assignee_aiu'    => 0,
            'parent_issue_id' => 'null',
            'status'          => ' in_progress ',
            'include_terminal' => '1',
        ]);

        $this->assertNull($query->assigneeAiu);
        $this->assertNull($query->parentIssueId);
        $this->assertSame('in_progress', $query->statusSlug);
        $this->assertTrue($query->includeTerminal);
    }

    public function testListIssuesHoldsTheLimitInsideItsCeiling(): void
    {
        $this->assertSame(200, ListIssues::fromInput(['project_id' => 7, 'limit' => 9999])->paging->limit);
        $this->assertSame(1, ListIssues::fromInput(['project_id' => 7, 'limit' => 0])->paging->limit);
        $this->assertSame(0, ListIssues::fromInput(['project_id' => 7, 'offset' => -5])->paging->offset);
    }

    public function testListIssuesRejectsAFlagThatIsNotOneOrZero(): void
    {
        $this->assertInvalid(
            'include_terminal must be 1 or 0',
            static fn() => ListIssues::fromInput(['project_id' => 7, 'include_terminal' => 'yes']),
        );
    }

    // === Comments ===

    public function testAddCommentTrimsTheBody(): void
    {
        $add = AddComment::fromInput(['issue_id' => '4', 'body' => "  Progress note  "]);

        $this->assertSame(4, $add->issueId);
        $this->assertSame('Progress note', $add->body);
    }

    public function testAddCommentHoldsTheBodyToTheSameBytesAsBefore(): void
    {
        $this->assertInvalid(
            'body is required',
            static fn() => AddComment::fromInput(['issue_id' => 4, 'body' => '   ']),
        );
        $this->assertInvalid(
            'body must be 65535 bytes or fewer',
            static fn() => AddComment::fromInput(['issue_id' => 4, 'body' => str_repeat('a', 65_536)]),
        );
    }

    public function testEditCommentNeedsTheCommentAndABody(): void
    {
        $edit = EditComment::fromInput(['issue_comment_id' => 9, 'body' => 'Reworded']);
        $this->assertSame(9, $edit->issueCommentId);
        $this->assertSame('Reworded', $edit->body);

        $this->assertInvalid(
            'issue_comment_id is required',
            static fn() => EditComment::fromInput(['body' => 'x']),
        );
    }

    // === Timers ===

    public function testStartTimerTrimsANoteAndTreatsAnEmptyOneAsNone(): void
    {
        $this->assertSame('Batch run', StartTimer::fromInput(['issue_id' => 4, 'note' => ' Batch run '])->note);
        $this->assertNull(StartTimer::fromInput(['issue_id' => 4, 'note' => '  '])->note);
        $this->assertNull(StartTimer::fromInput(['issue_id' => 4])->note);
    }

    public function testStartTimerHoldsTheNoteTo255Characters(): void
    {
        $this->assertInvalid(
            'note must be 255 characters or fewer',
            static fn() => StartTimer::fromInput(['issue_id' => 4, 'note' => str_repeat('n', 256)]),
        );
    }

    public function testEditTimerNoteClearsTheNoteWhenNoneIsSent(): void
    {
        $this->assertNull(EditTimerNote::fromInput(['issue_timer_id' => 3])->note);
        $this->assertSame('Rewritten', EditTimerNote::fromInput([
            'issue_timer_id' => 3,
            'note'           => 'Rewritten',
        ])->note);
    }

    public function testListTimersReadsWorkerZeroAsEveryWorker(): void
    {
        $query = ListTimers::fromInput(['issue_id' => 4, 'worker_aiu' => 0, 'running_only' => 1]);

        $this->assertSame(4, $query->issueId);
        $this->assertNull($query->workerAiu);
        $this->assertTrue($query->runningOnly);
        $this->assertSame(200, $query->paging->limit);
        $this->assertSame(500, ListTimers::fromInput(['issue_id' => 4, 'limit' => 9999])->paging->limit);
    }

    // === Commits ===

    public function testAddCommitTakesARepoAndAHash(): void
    {
        $add = AddCommit::fromInput([
            'issue_id'    => 4,
            'repo_id'     => '4',
            'commit_hash' => ' f8cbf42 ',
            'note'        => '',
        ]);

        $this->assertSame(4, $add->repoId);
        $this->assertSame('f8cbf42', $add->commitHash);
        $this->assertNull($add->note);
    }

    public function testAddCommitRejectsAMissingOrOverlongHash(): void
    {
        $this->assertInvalid(
            'commit_hash is required',
            static fn() => AddCommit::fromInput(['issue_id' => 4, 'repo_id' => 4]),
        );
        $this->assertInvalid(
            'commit_hash must be 64 characters or fewer',
            static fn() => AddCommit::fromInput([
                'issue_id'    => 4,
                'repo_id'     => 4,
                'commit_hash' => str_repeat('f', 65),
            ]),
        );
    }

    public function testListCommitsLeavesUnsentFiltersOff(): void
    {
        $query = ListCommits::fromInput(['issue_id' => 4]);

        $this->assertNull($query->repoId);
        $this->assertNull($query->commitHash);
        $this->assertSame(200, $query->paging->limit);

        $filtered = ListCommits::fromInput(['issue_id' => 4, 'repo_id' => 4, 'commit_hash' => ' abc123 ']);
        $this->assertSame(4, $filtered->repoId);
        $this->assertSame('abc123', $filtered->commitHash);
    }

    private function assertInvalid(string $message, \Closure $build): void
    {
        try {
            $build();
            $this->fail("Expected InvalidInput: {$message}");
        } catch (InvalidInput $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
