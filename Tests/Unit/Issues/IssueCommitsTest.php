<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Db\Row\IssueRow;
use Issues\AccessException;
use Issues\Caller;
use Issues\Command\AddCommit;
use Issues\Command\ListCommits;
use Issues\IssueService;
use Issues\NotFoundException;
use Issues\Page;
use Issues\ValidationException;

/**
 * Commit links on an issue, and the account invariant that keeps a link from
 * pointing at another account's repository.
 */
final class IssueCommitsTest extends IssueCase
{
    /**
     * @param array<string, mixed> $fields
     */
    private function link(IssueRow $issue, array $fields = [], ?Caller $caller = null): int
    {
        return $this->service->addCommit(
            $caller ?? $this->writer,
            AddCommit::fromInput([
                'issue_id'    => $issue->issueId,
                'repo_id'     => 4,
                'commit_hash' => 'f8cbf42',
                ...$fields,
            ]),
        )->issueCommitId;
    }

    /**
     * @param array<string, mixed> $filters
     * @return Page<\Issues\CommitView>
     */
    private function commits(int $issueId, array $filters = []): Page
    {
        return $this->service->commits(
            $this->writer,
            ListCommits::fromInput(['issue_id' => $issueId, ...$filters]),
        );
    }

    public function testALinkRecordsTheHashTheNoteAndWhoAddedIt(): void
    {
        $issue = $this->create();

        $commit = $this->service->addCommit($this->writer, AddCommit::fromInput([
            'issue_id'    => $issue->issueId,
            'repo_id'     => '4',
            'commit_hash' => ' f8cbf42 ',
            'note'        => ' range_start ',
        ]));

        $this->assertSame($issue->issueId, $commit->issueId);
        $this->assertSame(4, $commit->repoId);
        $this->assertSame('f8cbf42', $commit->commitHash);
        $this->assertSame('range_start', $commit->note);
        $this->assertSame(35, $commit->addedByAiu);
    }

    public function testARepositoryFromAnotherAccountIsRefused(): void
    {
        $issue = $this->create();

        $this->assertValidation(
            'repo_id does not belong to this account',
            fn() => $this->link($issue, ['repo_id' => 9]),
        );
        $this->assertValidation(
            'repo_id does not belong to this account',
            fn() => $this->link($issue, ['repo_id' => 4242]),
        );
    }

    public function testLinkingNeedsWriteAccessToTheProject(): void
    {
        $issue = $this->create();

        $this->assertThrows(AccessException::class, fn() => $this->link($issue, caller: $this->reader));
        $this->assertThrows(NotFoundException::class, fn() => $this->link($issue, caller: $this->stranger));
    }

    public function testLinksComeBackOldestFirstWithTheirRepositoryAndAuthor(): void
    {
        $issue = $this->create();
        $this->link($issue);
        $this->store->advance('PT1M');
        $this->link($issue, ['commit_hash' => '190d704'], $this->human);

        $page = $this->commits($issue->issueId);

        $this->assertSame(2, $page->total);
        $this->assertSame('f8cbf42', $page->items[0]->commit->commitHash);
        $this->assertSame('mgClaude', $page->items[0]->addedByName);
        $this->assertSame('Rob', $page->items[1]->addedByName);
        $this->assertSame('repo 4', $page->items[1]->repoName);
    }

    public function testARepositoryOrHashFilterNarrowsTheList(): void
    {
        $issue = $this->create();
        $this->link($issue);
        $this->link($issue, ['commit_hash' => '190d704']);

        $byHash = $this->commits($issue->issueId, ['commit_hash' => '190d704']);
        $this->assertSame(1, $byHash->total);
        $this->assertSame('190d704', $byHash->items[0]->commit->commitHash);

        $this->assertSame(2, $this->commits($issue->issueId, ['repo_id' => 4])->total);
        $this->assertSame(0, $this->commits($issue->issueId, ['repo_id' => 9])->total);
    }

    public function testLinksAreHiddenWithTheIssue(): void
    {
        $issue = $this->create();

        $this->assertThrows(
            NotFoundException::class,
            fn() => $this->service->commits(
                $this->stranger,
                ListCommits::fromInput(['issue_id' => $issue->issueId]),
            ),
        );
    }

    public function testALinkIsRemovedByWhoeverAddedItOrByAHuman(): void
    {
        $issue = $this->create();
        $mine = $this->link($issue);
        $theirs = $this->link($issue, ['commit_hash' => '190d704'], $this->human);

        $this->service->deleteCommit($this->writer, $mine);
        $this->service->deleteCommit($this->human, $theirs);

        $this->assertSame(0, $this->commits($issue->issueId)->total);
    }

    public function testAnotherAgentMayNotRemoveSomeoneElsesLink(): void
    {
        $issue = $this->create();
        $theirs = $this->link($issue, [], $this->human);

        $this->assertThrows(AccessException::class, fn() => $this->service->deleteCommit($this->writer, $theirs));
        $this->assertThrows(NotFoundException::class, fn() => $this->service->deleteCommit($this->writer, 4242));
    }

    public function testOnlyASuccessfulLinkWriteIsBilled(): void
    {
        $charged = [];
        $service = new IssueService($this->store, beforeWrite: static function (string $action) use (&$charged): void {
            $charged[] = $action;
        });
        $issue = $this->create();

        try {
            $service->addCommit($this->writer, AddCommit::fromInput([
                'issue_id'    => $issue->issueId,
                'repo_id'     => 9,
                'commit_hash' => 'f8cbf42',
            ]));
        } catch (ValidationException) {
        }
        $this->assertSame([], $charged);

        $commit = $service->addCommit($this->writer, AddCommit::fromInput([
            'issue_id'    => $issue->issueId,
            'repo_id'     => 4,
            'commit_hash' => 'f8cbf42',
        ]));
        $service->deleteCommit($this->writer, $commit->issueCommitId);

        $this->assertSame(['issues/commits/add', 'issues/commits/delete'], $charged);
    }
}
