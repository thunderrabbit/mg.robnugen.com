<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Db\Row\IssueCommentRow;
use Db\Row\IssueCommitRow;
use Db\Row\IssueRow;
use Db\Row\IssueStatusRow;
use Db\Row\IssueTimerRow;
use Input\Absent;
use Issues\Caller;
use Issues\Command\AddComment;
use Issues\Command\AddCommit;
use Issues\Command\CreateIssue;
use Issues\Command\EditComment;
use Issues\Command\EditTimerNote;
use Issues\Command\ListCommits;
use Issues\Command\ListIssues;
use Issues\Command\ListTimers;
use Issues\Command\Paging;
use Issues\Command\StartTimer;
use Issues\Command\UpdateIssue;
use Issues\CommentView;
use Issues\CommitView;
use Issues\IssueCounts;
use Issues\IssueListItem;
use Issues\IssueNames;
use Issues\Membership;
use Issues\IssueStore;
use Issues\Page;
use Issues\TimerView;

/**
 * In-memory IssueStore for hermetic IssueService tests: one account, rows kept
 * as the same generated row objects the PDO store returns, and the same
 * filtering and ordering its SQL asks MySQL for.
 */
final class FakeIssueStore implements IssueStore
{
    /** @var array<int, IssueRow> */
    private array $issues = [];

    /** @var array<string, Membership> */
    private array $members = [];

    /** @var array<int, IssueStatusRow> */
    private array $statuses = [];

    /** @var array<int, IssueCommentRow> */
    private array $comments = [];

    /** @var array<int, IssueTimerRow> */
    private array $timers = [];

    /** @var array<int, IssueCommitRow> */
    private array $commits = [];

    /** @var array<int, string> aiu => display name */
    private array $actorNames = [];

    /** @var array<int, string> project_id => name */
    private array $projectNames = [];

    /** @var array<int, int> repo_id => user_id */
    private array $repos = [];

    /** @var array<int, int> user_id => human aiu */
    private array $humans = [];

    private int $nextIssueId = 1;
    private int $nextCommentId = 1;
    private int $nextTimerId = 1;
    private int $nextCommitId = 1;

    private \DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new \DateTimeImmutable('2026-09-16 09:00:00', new \DateTimeZone('UTC'));
    }

    // ─── Fixtures ────────────────────────────────────────────────────────────

    public function addStatus(int $statusId, string $slug, bool $isTerminal, bool $isDefault): void
    {
        $this->statuses[$statusId] = new IssueStatusRow(
            statusId: $statusId,
            slug: $slug,
            label: ucfirst($slug),
            sortOrder: $statusId * 10,
            isTerminal: $isTerminal,
            isDefault: $isDefault,
            createdAtUtc: new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')),
        );
    }

    public function addMember(int $projectId, int $aiu, bool $canRead, bool $canWrite): void
    {
        $this->members["{$projectId}:{$aiu}"] = new Membership(canRead: $canRead, canWrite: $canWrite);
    }

    public function addActor(int $aiu, string $name): void
    {
        $this->actorNames[$aiu] = $name;
    }

    public function addProjectName(int $projectId, string $name): void
    {
        $this->projectNames[$projectId] = $name;
    }

    public function addRepo(int $repoId, int $userId): void
    {
        $this->repos[$repoId] = $userId;
    }

    public function addHuman(int $userId, int $aiu): void
    {
        $this->humans[$userId] = $aiu;
    }

    /**
     * Starts a timer the way a worker would, and answers its id so a test can
     * stop or edit it. The command is built from input, as every surface builds
     * it, so a test may hand this an id straight off a row.
     */
    public function startTimer(int $issueId, int $workerAiu = 35, ?string $note = null): int
    {
        return $this->insertTimer(
            StartTimer::fromInput(['issue_id' => $issueId, 'note' => $note]),
            new Caller(userId: 1, aiu: $workerAiu, isHuman: false),
        );
    }

    /**
     * Moves the fake's clock, so an elapsed time is something a test can state.
     */
    public function advance(string $interval): void
    {
        $this->now = $this->now->add(new \DateInterval($interval));
    }

    // ─── Projects and issues ─────────────────────────────────────────────────

    #[\Override]
    public function membership(int $projectId, Caller $caller): ?Membership
    {
        return $this->members["{$projectId}:{$caller->aiu}"] ?? null;
    }

    #[\Override]
    public function isMember(int $projectId, int $aiu): bool
    {
        return isset($this->members["{$projectId}:{$aiu}"]);
    }

    #[\Override]
    public function humanCaller(int $userId): ?Caller
    {
        $aiu = $this->humans[$userId] ?? null;
        return $aiu === null ? null : new Caller(userId: $userId, aiu: $aiu, isHuman: true);
    }

    #[\Override]
    public function issue(int $issueId, Caller $caller): ?IssueRow
    {
        return $this->issues[$issueId] ?? null;
    }

    #[\Override]
    public function issueNames(int $issueId): ?IssueNames
    {
        $issue = $this->issues[$issueId] ?? null;
        if ($issue === null) {
            return null;
        }
        return new IssueNames(
            projectName: $this->projectNames[$issue->projectId] ?? "Project {$issue->projectId}",
            authorName: $this->actorName($issue->authorAiu),
            assigneeName: $issue->assigneeAiu === null ? null : $this->actorName($issue->assigneeAiu),
        );
    }

    #[\Override]
    public function counts(int $issueId): IssueCounts
    {
        return new IssueCounts(
            comments: count(array_filter($this->comments, static fn(IssueCommentRow $c): bool => $c->issueId === $issueId)),
            timers: count(array_filter($this->timers, static fn(IssueTimerRow $t): bool => $t->issueId === $issueId)),
            runningTimers: $this->runningTimerCount($issueId),
            commits: count(array_filter($this->commits, static fn(IssueCommitRow $c): bool => $c->issueId === $issueId)),
        );
    }

    #[\Override]
    public function status(int $statusId): ?IssueStatusRow
    {
        return $this->statuses[$statusId] ?? null;
    }

    #[\Override]
    public function statuses(): array
    {
        $statuses = array_values($this->statuses);
        usort($statuses, static fn(IssueStatusRow $a, IssueStatusRow $b): int => $a->sortOrder <=> $b->sortOrder);
        return $statuses;
    }

    #[\Override]
    public function defaultStatus(): IssueStatusRow
    {
        foreach ($this->statuses as $status) {
            if ($status->isDefault) {
                return $status;
            }
        }
        throw new \RuntimeException('no default status');
    }

    #[\Override]
    public function listIssues(ListIssues $query, Caller $caller): Page
    {
        $matching = [];
        foreach ($this->issues as $issue) {
            if ($this->matchesQuery($issue, $query)) {
                $matching[] = $issue;
            }
        }

        // FIELD(priority, 'high', 'normal', 'low'), then updated_at_utc DESC.
        $rank = ['high' => 0, 'normal' => 1, 'low' => 2];
        usort($matching, static fn(IssueRow $a, IssueRow $b): int
            => [$rank[$a->priority->value], $b->updatedAtUtc] <=> [$rank[$b->priority->value], $a->updatedAtUtc]);

        $items = array_map(
            fn(IssueRow $issue): IssueListItem => new IssueListItem(
                $issue,
                $this->statuses[$issue->statusId] ?? throw new \LogicException('unknown status'),
            ),
            array_slice($matching, $query->paging->offset, $query->paging->limit),
        );

        return new Page(
            items: $items,
            total: count($matching),
            limit: $query->paging->limit,
            offset: $query->paging->offset,
        );
    }

    #[\Override]
    public function insertIssue(CreateIssue $issue, Caller $author, IssueStatusRow $status): int
    {
        $id = $this->nextIssueId++;
        $this->issues[$id] = new IssueRow(
            issueId: $id,
            priority: $issue->priority,
            projectId: $issue->projectId,
            parentIssueId: $issue->parentIssueId,
            authorAiu: $author->aiu,
            assigneeAiu: $issue->assigneeAiu,
            statusId: $status->statusId,
            title: $issue->title,
            description: $issue->description,
            createdAtUtc: $this->now,
            updatedAtUtc: $this->now,
            doneAtUtc: $status->isTerminal ? $this->now : null,
        );
        return $id;
    }

    #[\Override]
    public function updateIssue(UpdateIssue $changes, ?IssueStatusRow $status): void
    {
        $old = $this->issues[$changes->issueId];
        $this->issues[$changes->issueId] = new IssueRow(
            issueId: $old->issueId,
            priority: $changes->priority instanceof Absent ? $old->priority : $changes->priority,
            projectId: $old->projectId,
            parentIssueId: $changes->parentIssueId instanceof Absent ? $old->parentIssueId : $changes->parentIssueId,
            authorAiu: $old->authorAiu,
            assigneeAiu: $changes->assigneeAiu instanceof Absent ? $old->assigneeAiu : $changes->assigneeAiu,
            statusId: $status->statusId ?? $old->statusId,
            title: $changes->title instanceof Absent ? $old->title : $changes->title,
            description: $changes->description instanceof Absent ? $old->description : $changes->description,
            createdAtUtc: $old->createdAtUtc,
            updatedAtUtc: $this->now,
            doneAtUtc: $status === null ? $old->doneAtUtc : ($status->isTerminal ? $this->now : null),
        );
    }

    #[\Override]
    public function deleteIssue(int $issueId, Caller $caller): int
    {
        if (!isset($this->issues[$issueId])) {
            return 0;
        }
        unset($this->issues[$issueId]);
        return 1;
    }

    // ─── Comments ────────────────────────────────────────────────────────────

    #[\Override]
    public function comments(int $issueId, Paging $paging): Page
    {
        $matching = array_values(array_filter(
            $this->comments,
            static fn(IssueCommentRow $comment): bool => $comment->issueId === $issueId,
        ));
        usort(
            $matching,
            static fn(IssueCommentRow $a, IssueCommentRow $b): int => $a->createdAtUtc <=> $b->createdAtUtc,
        );

        return new Page(
            items: array_map(
                fn(IssueCommentRow $comment): CommentView
                    => new CommentView($comment, $this->actorName($comment->authorAiu)),
                array_slice($matching, $paging->offset, $paging->limit),
            ),
            total: count($matching),
            limit: $paging->limit,
            offset: $paging->offset,
        );
    }

    #[\Override]
    public function comment(int $commentId, Caller $caller): ?IssueCommentRow
    {
        return $this->comments[$commentId] ?? null;
    }

    #[\Override]
    public function insertComment(AddComment $comment, Caller $author): int
    {
        $id = $this->nextCommentId++;
        $this->comments[$id] = new IssueCommentRow(
            issueCommentId: $id,
            issueId: $comment->issueId,
            authorAiu: $author->aiu,
            body: $comment->body,
            createdAtUtc: $this->now,
            updatedAtUtc: $this->now,
        );
        return $id;
    }

    #[\Override]
    public function updateComment(EditComment $edit): void
    {
        $old = $this->comments[$edit->issueCommentId];
        $this->comments[$edit->issueCommentId] = new IssueCommentRow(
            issueCommentId: $old->issueCommentId,
            issueId: $old->issueId,
            authorAiu: $old->authorAiu,
            body: $edit->body,
            createdAtUtc: $old->createdAtUtc,
            updatedAtUtc: $this->now,
        );
    }

    #[\Override]
    public function deleteComment(int $commentId): void
    {
        unset($this->comments[$commentId]);
    }

    // ─── Timers ──────────────────────────────────────────────────────────────

    #[\Override]
    public function timers(ListTimers $query): Page
    {
        $matching = [];
        foreach ($this->timers as $timer) {
            if ($timer->issueId !== $query->issueId) {
                continue;
            }
            if ($query->workerAiu !== null && $timer->workerAiu !== $query->workerAiu) {
                continue;
            }
            if ($query->runningOnly && $timer->stoppedAtUtc !== null) {
                continue;
            }
            $matching[] = $timer;
        }
        usort($matching, static fn(IssueTimerRow $a, IssueTimerRow $b): int => $b->startedAtUtc <=> $a->startedAtUtc);

        return new Page(
            items: $this->timerViews(array_slice($matching, $query->paging->offset, $query->paging->limit)),
            total: count($matching),
            limit: $query->paging->limit,
            offset: $query->paging->offset,
        );
    }

    #[\Override]
    public function runningTimers(int $issueId): array
    {
        $running = [];
        foreach ($this->timers as $timer) {
            if ($timer->issueId === $issueId && $timer->stoppedAtUtc === null) {
                $running[] = $timer;
            }
        }
        usort($running, static fn(IssueTimerRow $a, IssueTimerRow $b): int => $a->startedAtUtc <=> $b->startedAtUtc);

        return $this->timerViews($running);
    }

    #[\Override]
    public function runningTimerCount(int $issueId): int
    {
        return count($this->runningTimers($issueId));
    }

    #[\Override]
    public function timer(int $timerId, Caller $caller): ?IssueTimerRow
    {
        return $this->timers[$timerId] ?? null;
    }

    #[\Override]
    public function insertTimer(StartTimer $timer, Caller $worker): int
    {
        $id = $this->nextTimerId++;
        $this->timers[$id] = new IssueTimerRow(
            issueTimerId: $id,
            issueId: $timer->issueId,
            workerAiu: $worker->aiu,
            startedAtUtc: $this->now,
            stoppedAtUtc: null,
            note: $timer->note,
        );
        return $id;
    }

    #[\Override]
    public function stopTimer(int $timerId): void
    {
        $old = $this->timers[$timerId];
        if ($old->stoppedAtUtc !== null) {
            return;
        }
        $this->timers[$timerId] = new IssueTimerRow(
            issueTimerId: $old->issueTimerId,
            issueId: $old->issueId,
            workerAiu: $old->workerAiu,
            startedAtUtc: $old->startedAtUtc,
            stoppedAtUtc: $this->now,
            note: $old->note,
        );
    }

    #[\Override]
    public function updateTimerNote(EditTimerNote $note): void
    {
        $old = $this->timers[$note->issueTimerId];
        $this->timers[$note->issueTimerId] = new IssueTimerRow(
            issueTimerId: $old->issueTimerId,
            issueId: $old->issueId,
            workerAiu: $old->workerAiu,
            startedAtUtc: $old->startedAtUtc,
            stoppedAtUtc: $old->stoppedAtUtc,
            note: $note->note,
        );
    }

    #[\Override]
    public function deleteTimer(int $timerId): void
    {
        unset($this->timers[$timerId]);
    }

    // ─── Commits ─────────────────────────────────────────────────────────────

    #[\Override]
    public function commits(ListCommits $query): Page
    {
        $matching = [];
        foreach ($this->commits as $commit) {
            if ($commit->issueId !== $query->issueId) {
                continue;
            }
            if ($query->repoId !== null && $commit->repoId !== $query->repoId) {
                continue;
            }
            if ($query->commitHash !== null && $commit->commitHash !== $query->commitHash) {
                continue;
            }
            $matching[] = $commit;
        }
        usort($matching, static fn(IssueCommitRow $a, IssueCommitRow $b): int => $a->createdAtUtc <=> $b->createdAtUtc);

        return new Page(
            items: array_map(
                fn(IssueCommitRow $commit): CommitView => new CommitView(
                    $commit,
                    "repo {$commit->repoId}",
                    null,
                    $this->actorName($commit->addedByAiu),
                ),
                array_slice($matching, $query->paging->offset, $query->paging->limit),
            ),
            total: count($matching),
            limit: $query->paging->limit,
            offset: $query->paging->offset,
        );
    }

    #[\Override]
    public function commit(int $commitId, Caller $caller): ?IssueCommitRow
    {
        return $this->commits[$commitId] ?? null;
    }

    #[\Override]
    public function insertCommit(AddCommit $commit, Caller $addedBy): int
    {
        $id = $this->nextCommitId++;
        $this->commits[$id] = new IssueCommitRow(
            issueCommitId: $id,
            issueId: $commit->issueId,
            repoId: $commit->repoId,
            commitHash: $commit->commitHash,
            note: $commit->note,
            addedByAiu: $addedBy->aiu,
            createdAtUtc: $this->now,
        );
        return $id;
    }

    #[\Override]
    public function deleteCommit(int $commitId): void
    {
        unset($this->commits[$commitId]);
    }

    #[\Override]
    public function repoInAccount(int $repoId, int $userId): bool
    {
        return ($this->repos[$repoId] ?? null) === $userId;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function matchesQuery(IssueRow $issue, ListIssues $query): bool
    {
        if ($issue->projectId !== $query->projectId) {
            return false;
        }
        $status = $this->statuses[$issue->statusId] ?? throw new \LogicException('unknown status');
        if ($query->statusSlug !== null) {
            return $status->slug === $query->statusSlug && $this->matchesActors($issue, $query);
        }
        if (!$query->includeTerminal && $status->isTerminal) {
            return false;
        }
        return $this->matchesActors($issue, $query);
    }

    private function matchesActors(IssueRow $issue, ListIssues $query): bool
    {
        if (!$query->assigneeAiu instanceof Absent && $issue->assigneeAiu !== $query->assigneeAiu) {
            return false;
        }
        return $query->parentIssueId instanceof Absent || $issue->parentIssueId === $query->parentIssueId;
    }

    /**
     * @param list<IssueTimerRow> $timers
     * @return list<TimerView>
     */
    private function timerViews(array $timers): array
    {
        return array_map(
            fn(IssueTimerRow $timer): TimerView
                => TimerView::of($timer, $this->actorName($timer->workerAiu), $this->now),
            $timers,
        );
    }

    private function actorName(int $aiu): string
    {
        return $this->actorNames[$aiu] ?? "actor {$aiu}";
    }
}
