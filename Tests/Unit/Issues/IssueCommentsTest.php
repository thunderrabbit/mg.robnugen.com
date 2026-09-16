<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Db\Row\IssueRow;
use Issues\AccessException;
use Issues\Caller;
use Issues\Command\AddComment;
use Issues\Command\EditComment;
use Issues\Command\Paging;
use Issues\IssueService;
use Issues\NotFoundException;

/**
 * Who may comment on an issue, and who may change a comment afterwards.
 */
final class IssueCommentsTest extends IssueCase
{
    private function comment(IssueRow $issue, string $body = 'Progress note', ?Caller $caller = null): int
    {
        return $this->service->addComment(
            $caller ?? $this->writer,
            AddComment::fromInput(['issue_id' => $issue->issueId, 'body' => $body]),
        )->issueCommentId;
    }

    public function testACommentIsStoredAgainstTheCallerAsAuthor(): void
    {
        $issue = $this->create();

        $comment = $this->service->addComment(
            $this->writer,
            AddComment::fromInput(['issue_id' => $issue->issueId, 'body' => ' Progress note ']),
        );

        $this->assertSame($issue->issueId, $comment->issueId);
        $this->assertSame(35, $comment->authorAiu);
        $this->assertSame('Progress note', $comment->body);
    }

    public function testCommentingNeedsWriteAccessToTheProject(): void
    {
        $issue = $this->create();

        $this->assertThrows(AccessException::class, fn() => $this->comment($issue, caller: $this->reader));
        $this->assertThrows(NotFoundException::class, fn() => $this->comment($issue, caller: $this->stranger));
    }

    public function testCommentsComeBackOldestFirstWithTheirAuthorsNames(): void
    {
        $issue = $this->create();
        $this->comment($issue, 'First');
        $this->store->advance('PT1M');
        $this->comment($issue, 'Second', $this->human);

        $page = $this->service->comments($this->reader, $issue->issueId, new Paging(limit: 100));

        $this->assertSame(2, $page->total);
        $this->assertSame('First', $page->items[0]->comment->body);
        $this->assertSame('mgClaude', $page->items[0]->authorName);
        $this->assertSame('Second', $page->items[1]->comment->body);
        $this->assertSame('Rob', $page->items[1]->authorName);
    }

    public function testCommentsArePagedAgainstTheFullTotal(): void
    {
        $issue = $this->create();
        $this->comment($issue, 'First');
        $this->store->advance('PT1M');
        $this->comment($issue, 'Second');

        $page = $this->service->comments($this->writer, $issue->issueId, new Paging(limit: 1, offset: 1));

        $this->assertCount(1, $page->items);
        $this->assertSame('Second', $page->items[0]->comment->body);
        $this->assertSame(2, $page->total);
    }

    public function testCommentsAreHiddenWithTheIssue(): void
    {
        $issue = $this->create();

        $this->assertThrows(
            NotFoundException::class,
            fn() => $this->service->comments($this->stranger, $issue->issueId, new Paging(limit: 100)),
        );
    }

    public function testAnAuthorOrAHumanMayEditAComment(): void
    {
        $issue = $this->create();
        $id = $this->comment($issue);

        $edited = $this->service->editComment(
            $this->writer,
            EditComment::fromInput(['issue_comment_id' => $id, 'body' => 'Reworded']),
        );
        $this->assertSame('Reworded', $edited->body);

        $byRob = $this->service->editComment(
            $this->human,
            EditComment::fromInput(['issue_comment_id' => $id, 'body' => 'Rob had the last word']),
        );
        $this->assertSame('Rob had the last word', $byRob->body);
    }

    public function testAnotherAgentMayNotEditSomeoneElsesComment(): void
    {
        $issue = $this->create();
        $id = $this->comment($issue);
        $other = new Caller(userId: 1, aiu: 36, isHuman: false);

        $this->assertThrows(
            AccessException::class,
            fn() => $this->service->editComment(
                $other,
                EditComment::fromInput(['issue_comment_id' => $id, 'body' => 'not mine to edit']),
            ),
        );
    }

    public function testEditingAnUnknownCommentIsNotFound(): void
    {
        $this->assertThrows(
            NotFoundException::class,
            fn() => $this->service->editComment(
                $this->writer,
                EditComment::fromInput(['issue_comment_id' => 4242, 'body' => 'nothing there']),
            ),
        );
    }

    public function testAnAuthorOrAHumanMayDeleteAComment(): void
    {
        $issue = $this->create();
        $mine = $this->comment($issue);
        $theirs = $this->comment($issue, 'Rob wrote this', $this->human);

        $this->service->deleteComment($this->writer, $mine);
        $this->service->deleteComment($this->human, $theirs);

        $this->assertSame(0, $this->service->comments($this->writer, $issue->issueId, new Paging(limit: 100))->total);
    }

    public function testAnotherAgentMayNotDeleteSomeoneElsesComment(): void
    {
        $issue = $this->create();
        $id = $this->comment($issue, 'Rob wrote this', $this->human);

        $this->assertThrows(AccessException::class, fn() => $this->service->deleteComment($this->writer, $id));
    }

    public function testOnlyASuccessfulCommentWriteIsBilled(): void
    {
        $charged = [];
        $service = new IssueService($this->store, beforeWrite: static function (string $action) use (&$charged): void {
            $charged[] = $action;
        });
        $issue = $this->create();

        try {
            $service->addComment($this->reader, AddComment::fromInput([
                'issue_id' => $issue->issueId,
                'body'     => 'no write access',
            ]));
        } catch (AccessException) {
        }
        $this->assertSame([], $charged);

        $comment = $service->addComment($this->writer, AddComment::fromInput([
            'issue_id' => $issue->issueId,
            'body'     => 'logged',
        ]));
        $service->editComment($this->writer, EditComment::fromInput([
            'issue_comment_id' => $comment->issueCommentId,
            'body'             => 'logged again',
        ]));
        $service->deleteComment($this->writer, $comment->issueCommentId);

        $this->assertSame(
            ['issues/comments/add', 'issues/comments/edit', 'issues/comments/delete'],
            $charged,
        );
    }
}
