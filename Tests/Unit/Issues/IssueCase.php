<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Codeception\Test\Unit;
use Db\Row\IssueRow;
use Issues\Caller;
use Issues\Command\CreateIssue;
use Issues\Command\UpdateIssue;
use Issues\IssueService;
use Issues\ValidationException;

/**
 * One account's worth of fixtures for the IssueService tests: project 7 with a
 * writer, a reader, a member with no read access and a human, project 8 for
 * cross-project checks, and repository 4 in the account with 9 outside it.
 *
 * Not named *Test, so Codeception treats it as support rather than a suite.
 */
abstract class IssueCase extends Unit
{
    protected FakeIssueStore $store;
    protected IssueService $service;

    /** Agent with read and write on projects 7 and 8. */
    protected Caller $writer;

    /** Agent that may read project 7 but not write it. */
    protected Caller $reader;

    /** Member of project 7 whose read access was taken away. */
    protected Caller $blocked;

    /** Agent with no membership anywhere. */
    protected Caller $stranger;

    /** Rob, who may change anything on the account. */
    protected Caller $human;

    protected function _before(): void
    {
        $this->store = new FakeIssueStore();
        $this->store->addStatus(1, 'open', isTerminal: false, isDefault: true);
        $this->store->addStatus(2, 'in_progress', isTerminal: false, isDefault: false);
        $this->store->addStatus(4, 'done', isTerminal: true, isDefault: false);

        $this->store->addMember(projectId: 7, aiu: 35, canRead: true, canWrite: true);
        $this->store->addMember(projectId: 7, aiu: 36, canRead: true, canWrite: false);
        $this->store->addMember(projectId: 7, aiu: 37, canRead: false, canWrite: false);
        $this->store->addMember(projectId: 7, aiu: 1, canRead: true, canWrite: true);
        $this->store->addMember(projectId: 8, aiu: 35, canRead: true, canWrite: true);

        $this->store->addActor(1, 'Rob');
        $this->store->addActor(35, 'mgClaude');
        $this->store->addActor(36, 'Boss Claude');
        $this->store->addProjectName(7, 'jikan');
        $this->store->addRepo(repoId: 4, userId: 1);
        $this->store->addRepo(repoId: 9, userId: 2);
        $this->store->addHuman(userId: 1, aiu: 1);

        $this->writer = new Caller(userId: 1, aiu: 35, isHuman: false);
        $this->reader = new Caller(userId: 1, aiu: 36, isHuman: false);
        $this->blocked = new Caller(userId: 1, aiu: 37, isHuman: false);
        $this->stranger = new Caller(userId: 1, aiu: 99, isHuman: false);
        $this->human = new Caller(userId: 1, aiu: 1, isHuman: true);

        $this->service = new IssueService($this->store);
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function create(array $fields = [], ?Caller $caller = null): IssueRow
    {
        return $this->service->create(
            $caller ?? $this->writer,
            CreateIssue::fromInput(['project_id' => 7, 'title' => 'Typed issues', ...$fields]),
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function update(IssueRow $issue, array $fields, ?Caller $caller = null): IssueRow
    {
        return $this->service->update(
            $caller ?? $this->writer,
            UpdateIssue::fromInput(['issue_id' => $issue->issueId, ...$fields]),
        );
    }

    /**
     * @param class-string<\Throwable> $class
     */
    protected function assertThrows(string $class, \Closure $action): void
    {
        try {
            $action();
            $this->fail("Expected {$class}");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
        }
    }

    protected function assertValidation(string $message, \Closure $action): void
    {
        try {
            $action();
            $this->fail("Expected ValidationException: {$message}");
        } catch (ValidationException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
