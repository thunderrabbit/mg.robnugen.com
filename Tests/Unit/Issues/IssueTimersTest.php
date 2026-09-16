<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Issues\AccessException;
use Issues\Command\EditTimerNote;
use Issues\Command\ListTimers;
use Issues\Command\StartTimer;
use Issues\ConflictException;
use Issues\IssueService;
use Issues\NotFoundException;

/**
 * Count-up timers on an issue: who may start one, who may stop or retitle it,
 * and how long it says it has run.
 *
 * The commands are built through fromInput() here, as every surface builds them,
 * which is also what proves an id off a row passes their checks.
 */
final class IssueTimersTest extends IssueCase
{
    public function testATimerIsStartedForTheCallerAndRunsUntilStopped(): void
    {
        $issue = $this->create();

        $timer = $this->service->startTimer(
            $this->writer,
            StartTimer::fromInput(['issue_id' => $issue->issueId, 'note' => ' Batch run ']),
        );

        $this->assertSame($issue->issueId, $timer->issueId);
        $this->assertSame(35, $timer->workerAiu);
        $this->assertSame('Batch run', $timer->note);
        $this->assertNull($timer->stoppedAtUtc);
    }

    public function testStartingATimerNeedsWriteAccess(): void
    {
        $issue = $this->create();

        $this->assertThrows(
            AccessException::class,
            fn() => $this->service->startTimer(
                $this->reader,
                StartTimer::fromInput(['issue_id' => $issue->issueId]),
            ),
        );
        $this->assertThrows(
            NotFoundException::class,
            fn() => $this->service->startTimer(
                $this->stranger,
                StartTimer::fromInput(['issue_id' => $issue->issueId]),
            ),
        );
    }

    public function testStoppingATimerWritesTheTimeOnlyOnce(): void
    {
        $issue = $this->create();
        $id = $this->store->startTimer($issue->issueId);
        $this->store->advance('PT30S');

        $stopped = $this->service->stopTimer($this->writer, $id);
        $this->assertNotNull($stopped->stoppedAtUtc);

        $this->assertThrows(ConflictException::class, fn() => $this->service->stopTimer($this->writer, $id));
    }

    public function testAHumanMayStopAnAgentsTimerButAnotherAgentMayNot(): void
    {
        $issue = $this->create();
        $mine = $this->store->startTimer($issue->issueId, workerAiu: 35);
        $theirs = $this->store->startTimer($issue->issueId, workerAiu: 36);

        $this->assertNotNull($this->service->stopTimer($this->human, $mine)->stoppedAtUtc);
        $this->assertThrows(AccessException::class, fn() => $this->service->stopTimer($this->writer, $theirs));
    }

    public function testStoppingAnUnknownTimerIsNotFound(): void
    {
        $this->assertThrows(NotFoundException::class, fn() => $this->service->stopTimer($this->writer, 4242));
    }

    public function testANoteCanBeRewrittenOrCleared(): void
    {
        $issue = $this->create();
        $id = $this->store->startTimer($issue->issueId, note: 'First note');

        $renamed = $this->service->editTimerNote(
            $this->writer,
            EditTimerNote::fromInput(['issue_timer_id' => $id, 'note' => 'Second note']),
        );
        $this->assertSame('Second note', $renamed->note);

        $cleared = $this->service->editTimerNote(
            $this->writer,
            EditTimerNote::fromInput(['issue_timer_id' => $id]),
        );
        $this->assertNull($cleared->note);
    }

    public function testATimerIsDeletedByItsWorkerOrAHuman(): void
    {
        $issue = $this->create();
        $mine = $this->store->startTimer($issue->issueId, workerAiu: 35);
        $theirs = $this->store->startTimer($issue->issueId, workerAiu: 36);

        $this->service->deleteTimer($this->writer, $mine);
        $this->service->deleteTimer($this->human, $theirs);

        $this->assertSame(0, $this->timers($issue->issueId)->total);
        $this->assertThrows(NotFoundException::class, fn() => $this->service->deleteTimer($this->writer, $mine));
    }

    public function testTimersComeBackNewestFirstWithTheirElapsedTime(): void
    {
        $issue = $this->create();
        $first = $this->store->startTimer($issue->issueId);
        $this->store->advance('PT1M');
        $this->store->startTimer($issue->issueId);
        $this->service->stopTimer($this->writer, $first);

        $page = $this->timers($issue->issueId);

        $this->assertSame(2, $page->total);
        // The newest timer started a minute after the first one, which ran until
        // it was stopped just now.
        $this->assertSame(0.0, $page->items[0]->elapsedMs);
        $this->assertSame(60_000.0, $page->items[1]->elapsedMs);
        $this->assertSame('mgClaude', $page->items[1]->workerName);
    }

    public function testRunningOnlyAndWorkerFiltersNarrowTheList(): void
    {
        $issue = $this->create();
        $stopped = $this->store->startTimer($issue->issueId, workerAiu: 35);
        $this->store->startTimer($issue->issueId, workerAiu: 36);
        $this->service->stopTimer($this->human, $stopped);

        $running = $this->timers($issue->issueId, ['running_only' => 1]);
        $this->assertSame(1, $running->total);
        $this->assertSame(36, $running->items[0]->timer->workerAiu);

        $mine = $this->timers($issue->issueId, ['worker_aiu' => 35]);
        $this->assertSame(1, $mine->total);
        $this->assertSame(35, $mine->items[0]->timer->workerAiu);
    }

    public function testRunningTimersAreWhatABlockedStatusChangeCanReport(): void
    {
        $issue = $this->create();
        $this->store->startTimer($issue->issueId);

        $running = $this->service->runningTimers($this->writer, $issue->issueId);

        $this->assertCount(1, $running);
        $this->assertTrue($running[0]->isRunning());
        $this->assertThrows(
            NotFoundException::class,
            fn() => $this->service->runningTimers($this->stranger, $issue->issueId),
        );
    }

    public function testTimersAreHiddenWithTheIssue(): void
    {
        $issue = $this->create();

        $this->assertThrows(
            NotFoundException::class,
            fn() => $this->service->timers($this->stranger, ListTimers::fromInput(['issue_id' => $issue->issueId])),
        );
    }

    public function testOnlyASuccessfulTimerWriteIsBilled(): void
    {
        $charged = [];
        $service = new IssueService($this->store, beforeWrite: static function (string $action) use (&$charged): void {
            $charged[] = $action;
        });
        $issue = $this->create();

        try {
            $service->startTimer($this->reader, StartTimer::fromInput(['issue_id' => $issue->issueId]));
        } catch (AccessException) {
        }
        $this->assertSame([], $charged);

        $timer = $service->startTimer($this->writer, StartTimer::fromInput(['issue_id' => $issue->issueId]));
        $service->editTimerNote($this->writer, EditTimerNote::fromInput([
            'issue_timer_id' => $timer->issueTimerId,
            'note'           => 'noted',
        ]));
        $service->stopTimer($this->writer, $timer->issueTimerId);
        $service->deleteTimer($this->writer, $timer->issueTimerId);

        $this->assertSame(
            ['issues/timers/start', 'issues/timers/edit-note', 'issues/timers/stop', 'issues/timers/delete'],
            $charged,
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return \Issues\Page<\Issues\TimerView>
     */
    private function timers(int $issueId, array $filters = []): \Issues\Page
    {
        return $this->service->timers(
            $this->writer,
            ListTimers::fromInput(['issue_id' => $issueId, ...$filters]),
        );
    }
}
