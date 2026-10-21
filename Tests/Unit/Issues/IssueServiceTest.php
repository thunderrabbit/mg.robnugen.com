<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Db\Enum\IssuePriority;
use Db\Row\IssueStatusRow;
use Issues\AccessException;
use Issues\Command\AddComment;
use Issues\Command\AddCommit;
use Issues\Command\CreateIssue;
use Issues\Command\UpdateIssue;
use Issues\ConflictException;
use Issues\IssueService;
use Issues\NotFoundException;

/**
 * The issue rules the API, the MCP tools and the web pages each used to
 * re-implement, checked once against an in-memory store.
 */
final class IssueServiceTest extends IssueCase
{
    // === create() ===

    public function testCreateUsesTheDefaultStatusAndTheCallerAsAuthor(): void
    {
        $issue = $this->create();

        $this->assertSame(1, $issue->statusId);
        $this->assertSame(35, $issue->authorAiu);
        $this->assertSame(IssuePriority::Normal, $issue->priority);
        $this->assertNull($issue->doneAtUtc);
    }

    public function testCreateInATerminalStatusIsAlreadyDone(): void
    {
        $this->assertNotNull($this->create(['status_id' => 4])->doneAtUtc);
    }

    public function testCreateNeedsWriteAccessToTheProject(): void
    {
        $this->assertThrows(AccessException::class, fn() => $this->create(caller: $this->reader));
        $this->assertThrows(NotFoundException::class, fn() => $this->create(caller: $this->stranger));
        $this->assertThrows(NotFoundException::class, fn() => $this->create(['project_id' => 9]));
    }

    public function testCreateChecksAssigneeParentAndStatus(): void
    {
        $other = $this->create(['project_id' => 8]);

        $this->assertValidation(
            'assignee_aiu is not a member of this project',
            fn() => $this->create(['assignee_aiu' => 99]),
        );
        $this->assertValidation(
            'parent_issue_id must be an issue in the same project',
            fn() => $this->create(['parent_issue_id' => $other->issueId]),
        );
        $this->assertValidation('status_id is not a valid status', fn() => $this->create(['status_id' => 99]));

        $this->assertSame(36, $this->create(['assignee_aiu' => 36])->assigneeAiu);
    }

    // === update() ===

    public function testUpdateChangesOnlyWhatWasSent(): void
    {
        $issue = $this->create(['description' => 'keep me', 'priority' => 'high']);

        $updated = $this->update($issue, ['title' => 'Renamed']);

        $this->assertSame('Renamed', $updated->title);
        $this->assertSame('keep me', $updated->description);
        $this->assertSame(IssuePriority::High, $updated->priority);
    }

    public function testUpdateCanClearAnAssigneeAndADescription(): void
    {
        $issue = $this->create(['assignee_aiu' => 36, 'description' => 'old']);

        $updated = $this->update($issue, ['assignee_aiu' => null, 'description' => '']);

        $this->assertNull($updated->assigneeAiu);
        $this->assertNull($updated->description);
    }

    public function testUpdateRejectsAnIssueAsItsOwnParent(): void
    {
        $issue = $this->create();

        $this->assertValidation(
            'an issue cannot be its own parent',
            fn() => $this->update($issue, ['parent_issue_id' => $issue->issueId]),
        );
    }

    public function testUpdateToATerminalStatusWaitsForRunningTimers(): void
    {
        $issue = $this->create();
        $this->store->startTimer($issue->issueId);

        $this->assertThrows(ConflictException::class, fn() => $this->update($issue, ['status_id' => 4]));
    }

    public function testUpdateMarksDoneAndReopens(): void
    {
        $issue = $this->create();

        $done = $this->update($issue, ['status_id' => 4]);
        $this->assertNotNull($done->doneAtUtc);

        $reopened = $this->update($done, ['status_id' => 1]);
        $this->assertNull($reopened->doneAtUtc);
    }

    public function testUpdateNeedsWriteAccess(): void
    {
        $issue = $this->create();

        $this->assertThrows(AccessException::class, fn() => $this->update($issue, ['title' => 'x'], $this->reader));
        $this->assertThrows(NotFoundException::class, fn() => $this->update($issue, ['title' => 'x'], $this->stranger));
    }

    // === delete() ===

    public function testOnlyAHumanMayDeleteAnIssue(): void
    {
        $issue = $this->create();

        $this->assertThrows(AccessException::class, fn() => $this->service->delete($this->writer, $issue->issueId));
        $this->assertSame(1, $this->service->delete($this->human, $issue->issueId));
        $this->assertSame(0, $this->service->delete($this->human, $issue->issueId));
    }

    // === beforeWrite ===

    public function testBeforeWriteRunsOnlyAfterTheChecksPass(): void
    {
        $charged = [];
        $service = new IssueService($this->store, beforeWrite: static function (string $action) use (&$charged): void {
            $charged[] = $action;
        });

        try {
            $service->create($this->reader, CreateIssue::fromInput(['project_id' => 7, 'title' => 'x']));
        } catch (AccessException) {
        }
        $this->assertSame([], $charged);

        $issue = $service->create($this->writer, CreateIssue::fromInput(['project_id' => 7, 'title' => 'x']));
        $service->update($this->writer, UpdateIssue::fromInput(['issue_id' => $issue->issueId, 'title' => 'y']));
        $this->assertSame(['issues/create', 'issues/update'], $charged);
    }

    public function testADeleteAnAgentIsRefusedIsNeverBilled(): void
    {
        $charged = [];
        $service = new IssueService($this->store, beforeWrite: static function (string $action) use (&$charged): void {
            $charged[] = $action;
        });
        $issue = $this->create();

        try {
            $service->delete($this->writer, $issue->issueId);
        } catch (AccessException) {
        }

        $this->assertSame([], $charged);
    }

    // === view() ===

    public function testViewAddsTheStatusAndTheCallersRights(): void
    {
        $issue = $this->create(['status_id' => 4]);

        $view = $this->service->view($this->reader, $issue->issueId);

        $this->assertSame($issue->issueId, $view->issue->issueId);
        $this->assertSame('done', $view->status->slug);
        $this->assertTrue($view->status->isTerminal);
        $this->assertTrue($view->membership->canRead);
        $this->assertFalse($view->membership->canWrite);
        $this->assertThrows(NotFoundException::class, fn() => $this->service->view($this->stranger, $issue->issueId));
    }

    // === counts() ===

    public function testCountsAreThisIssuesOwnAndKeepTheApiKeys(): void
    {
        $issue = $this->create();
        $other = $this->create();
        foreach ([$issue, $issue, $other] as $target) {
            $this->service->addComment($this->writer, AddComment::fromInput(['issue_id' => $target->issueId, 'body' => 'Note']));
        }
        $this->store->stopTimer($this->store->startTimer($issue->issueId));
        $this->store->startTimer($issue->issueId);
        $this->service->addCommit($this->writer, AddCommit::fromInput([
            'issue_id' => $issue->issueId, 'repo_id' => 4, 'commit_hash' => 'f8cbf42',
        ]));

        $counts = $this->service->counts($this->service->view($this->reader, $issue->issueId));

        $this->assertSame(
            ['comments' => 2, 'timers' => 2, 'running_timers' => 1, 'commits' => 1],
            $counts->jsonSerialize(),
        );
        $this->assertSame(
            ['comments' => 1, 'timers' => 0, 'running_timers' => 0, 'commits' => 0],
            $this->service->counts($this->service->view($this->reader, $other->issueId))->jsonSerialize(),
        );
    }

    // === get() ===

    public function testGetHidesIssuesTheCallerCannotRead(): void
    {
        $issue = $this->create();

        $this->assertSame($issue->issueId, $this->service->get($this->reader, $issue->issueId)->issueId);
        $this->assertThrows(NotFoundException::class, fn() => $this->service->get($this->stranger, $issue->issueId));
        $this->assertThrows(NotFoundException::class, fn() => $this->service->get($this->writer, 12345));
    }

    // === detail() ===

    public function testDetailFlattensWhatTheIssuePagePrints(): void
    {
        $issue = $this->create(['assignee_aiu' => 36, 'description' => 'A body']);

        $detail = $this->service->detail($this->writer, $issue->issueId);

        $this->assertSame($issue->issueId, $detail->issueId);
        $this->assertSame('jikan', $detail->projectName);
        $this->assertSame('Typed issues', $detail->title);
        $this->assertSame('A body', $detail->description);
        $this->assertSame('Open', $detail->statusLabel);
        $this->assertSame('mgClaude', $detail->authorName);
        $this->assertSame(36, $detail->assigneeAiu);
        $this->assertSame('Boss Claude', $detail->assigneeName);
        $this->assertTrue($detail->canWrite);

        $readerSees = $this->service->detail($this->reader, $issue->issueId);
        $this->assertFalse($readerSees->canWrite);
        $this->assertThrows(NotFoundException::class, fn() => $this->service->detail($this->stranger, $issue->issueId));
    }

    // === statuses() ===

    public function testStatusesComeBackInSortOrder(): void
    {
        $slugs = array_map(static fn(IssueStatusRow $status): string => $status->slug, $this->service->statuses());

        $this->assertSame(['open', 'in_progress', 'done'], $slugs);
    }
}
