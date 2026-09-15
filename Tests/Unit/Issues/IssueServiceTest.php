<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Codeception\Test\Unit;
use Db\Enum\IssuePriority;
use Db\Row\IssueRow;
use Issues\AccessException;
use Issues\Caller;
use Issues\Command\CreateIssue;
use Issues\Command\UpdateIssue;
use Issues\ConflictException;
use Issues\IssueService;
use Issues\NotFoundException;
use Issues\ValidationException;

/**
 * The issue rules the API, the MCP tools and the web pages each used to
 * re-implement, checked once against an in-memory store.
 */
final class IssueServiceTest extends Unit
{
    private FakeIssueStore $store;
    private IssueService $service;
    private Caller $writer;
    private Caller $reader;
    private Caller $stranger;

    protected function _before(): void
    {
        $this->store = new FakeIssueStore();
        $this->store->addStatus(1, 'open', isTerminal: false, isDefault: true);
        $this->store->addStatus(4, 'done', isTerminal: true, isDefault: false);
        $this->store->addMember(projectId: 7, aiu: 35, canRead: true, canWrite: true);
        $this->store->addMember(projectId: 7, aiu: 36, canRead: true, canWrite: false);
        $this->store->addMember(projectId: 8, aiu: 35, canRead: true, canWrite: true);

        $this->writer = new Caller(userId: 1, aiu: 35, isHuman: false);
        $this->reader = new Caller(userId: 1, aiu: 36, isHuman: false);
        $this->stranger = new Caller(userId: 1, aiu: 99, isHuman: false);
        $this->service = new IssueService($this->store);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function create(array $fields = [], ?Caller $caller = null): IssueRow
    {
        return $this->service->create(
            $caller ?? $this->writer,
            CreateIssue::fromInput(['project_id' => 7, 'title' => 'Typed issues', ...$fields]),
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function update(IssueRow $issue, array $fields, ?Caller $caller = null): IssueRow
    {
        return $this->service->update(
            $caller ?? $this->writer,
            UpdateIssue::fromInput(['issue_id' => $issue->issueId, ...$fields]),
        );
    }

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

    // === get() ===

    public function testGetHidesIssuesTheCallerCannotRead(): void
    {
        $issue = $this->create();

        $this->assertSame($issue->issueId, $this->service->get($this->reader, $issue->issueId)->issueId);
        $this->assertThrows(NotFoundException::class, fn() => $this->service->get($this->stranger, $issue->issueId));
        $this->assertThrows(NotFoundException::class, fn() => $this->service->get($this->writer, 12345));
    }

    /**
     * @param class-string<\Throwable> $class
     */
    private function assertThrows(string $class, \Closure $action): void
    {
        try {
            $action();
            $this->fail("Expected {$class}");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
        }
    }

    private function assertValidation(string $message, \Closure $action): void
    {
        try {
            $action();
            $this->fail("Expected ValidationException: {$message}");
        } catch (ValidationException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
