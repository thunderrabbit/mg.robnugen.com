<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Codeception\Test\Unit;
use Db\Row\IssueCommentRow;
use Db\Row\IssueCommitRow;
use Db\Row\IssueRow;
use Db\Row\IssueStatusRow;
use Db\Row\IssueTimerRow;
use Issues\CommentView;
use Issues\CommitView;
use Issues\IssueListItem;
use Issues\TimerView;

/**
 * The typed views the list reads answer with. Their JSON is the shape the API
 * and the MCP tools have always returned, so these tests pin the keys rather
 * than just the values.
 */
final class IssueViewsTest extends Unit
{
    private function utc(string $at): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
    }

    public function testIssueListItemJsonCarriesTheStatusAndNoDescription(): void
    {
        $item = new IssueListItem(
            IssueRow::fromRow([
                'issue_id'        => 512,
                'priority'        => 'high',
                'project_id'      => 1,
                'parent_issue_id' => 505,
                'author_aiu'      => 35,
                'assignee_aiu'    => null,
                'status_id'       => 1,
                'title'           => 'Type-safe Issues area, part 2',
                'description'     => 'long body the list never sends',
                'created_at_utc'  => '2026-09-15 22:08:17.063857',
                'updated_at_utc'  => '2026-09-15 22:08:17.063857',
                'done_at_utc'     => null,
            ]),
            IssueStatusRow::fromRow([
                'status_id'      => 1,
                'slug'           => 'open',
                'label'          => 'Open',
                'sort_order'     => 10,
                'is_terminal'    => 0,
                'is_default'     => 1,
                'created_at_utc' => '2026-04-20 04:42:40.557849',
            ]),
        );

        $this->assertSame(
            '{"issue_id":512,"priority":"high","project_id":1,"parent_issue_id":505,"author_aiu":35,'
            . '"assignee_aiu":null,"status_id":1,"status_slug":"open","status_label":"Open","is_terminal":0,'
            . '"title":"Type-safe Issues area, part 2","created_at_utc":"2026-09-15 22:08:17.063857",'
            . '"updated_at_utc":"2026-09-15 22:08:17.063857","done_at_utc":null}',
            json_encode($item, JSON_THROW_ON_ERROR),
        );
    }

    public function testCommentViewJsonAddsTheAuthorName(): void
    {
        $view = new CommentView(
            IssueCommentRow::fromRow([
                'issue_comment_id' => 9,
                'issue_id'         => 512,
                'author_aiu'       => 35,
                'body'             => 'Progress note',
                'created_at_utc'   => '2026-09-16 09:00:00.000000',
                'updated_at_utc'   => '2026-09-16 09:00:00.000000',
            ]),
            'mgClaude',
        );

        $this->assertSame(
            '{"issue_comment_id":9,"issue_id":512,"author_aiu":35,"body":"Progress note",'
            . '"created_at_utc":"2026-09-16 09:00:00.000000","updated_at_utc":"2026-09-16 09:00:00.000000",'
            . '"author_name":"mgClaude"}',
            json_encode($view, JSON_THROW_ON_ERROR),
        );
    }

    public function testTimerViewMeasuresARunningTimerAgainstTheGivenClock(): void
    {
        $view = TimerView::of($this->timerRow(null), 'mgClaude', $this->utc('2026-09-16 09:00:02.500000'));

        $this->assertTrue($view->isRunning());
        $this->assertSame(2500.0, $view->elapsedMs);
    }

    public function testTimerViewMeasuresAStoppedTimerToTheMicrosecond(): void
    {
        $view = TimerView::of(
            $this->timerRow('2026-09-16 09:00:00.000230'),
            'mgClaude',
            $this->utc('2026-09-16 10:00:00.000000'),
        );

        $this->assertFalse($view->isRunning());
        $this->assertSame(0.23, $view->elapsedMs);
        $this->assertSame(
            '{"issue_timer_id":3,"issue_id":512,"worker_aiu":35,'
            . '"started_at_utc":"2026-09-16 09:00:00.000000","stopped_at_utc":"2026-09-16 09:00:00.000230",'
            . '"note":"Batch run","worker_name":"mgClaude","elapsed_ms":0.23}',
            json_encode($view, JSON_THROW_ON_ERROR),
        );
    }

    public function testCommitViewJsonAddsTheRepositoryAndWhoLinkedIt(): void
    {
        $view = new CommitView(
            IssueCommitRow::fromRow([
                'issue_commit_id' => 2,
                'issue_id'        => 512,
                'repo_id'         => 4,
                'commit_hash'     => 'f8cbf42',
                'note'            => null,
                'added_by_aiu'    => 35,
                'created_at_utc'  => '2026-09-16 09:00:00.000000',
            ]),
            'mg.robnugen.com',
            'https://github.com/thunderrabbit/mg.robnugen.com',
            'mgClaude',
        );

        $this->assertSame(
            '{"issue_commit_id":2,"issue_id":512,"repo_id":4,"commit_hash":"f8cbf42","note":null,'
            . '"added_by_aiu":35,"created_at_utc":"2026-09-16 09:00:00.000000",'
            . '"repo_name":"mg.robnugen.com",'
            . '"repo_url":"https:\/\/github.com\/thunderrabbit\/mg.robnugen.com","added_by_name":"mgClaude"}',
            json_encode($view, JSON_THROW_ON_ERROR),
        );
    }

    private function timerRow(?string $stoppedAt): IssueTimerRow
    {
        return IssueTimerRow::fromRow([
            'issue_timer_id' => 3,
            'issue_id'       => 512,
            'worker_aiu'     => 35,
            'started_at_utc' => '2026-09-16 09:00:00.000000',
            'stopped_at_utc' => $stoppedAt,
            'note'           => 'Batch run',
        ]);
    }
}
