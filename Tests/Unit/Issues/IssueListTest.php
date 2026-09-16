<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Issues\AccessException;
use Issues\Command\ListIssues;
use Issues\Command\Paging;
use Issues\IssueListItem;
use Issues\NotFoundException;

/**
 * The list read the API, the MCP issue_list tool and the project page share:
 * which issues a caller may see, and which of them a filter asks for.
 */
final class IssueListTest extends IssueCase
{
    /**
     * @param array<string, mixed> $filters
     * @return list<int>
     */
    private function listedIds(array $filters = []): array
    {
        $page = $this->service->listIssues($this->writer, ListIssues::fromInput(['project_id' => 7, ...$filters]));

        return array_map(static fn(IssueListItem $item): int => $item->issue->issueId, $page->items);
    }

    public function testAStrangerCannotTellTheProjectFromOneThatIsNotThere(): void
    {
        $this->create();

        $this->assertThrows(
            NotFoundException::class,
            fn() => $this->service->listIssues($this->stranger, ListIssues::fromInput(['project_id' => 7])),
        );
        $this->assertThrows(
            NotFoundException::class,
            fn() => $this->service->listIssues($this->writer, ListIssues::fromInput(['project_id' => 9999])),
        );
    }

    public function testAMemberWhoseReadAccessWasTakenAwayIsRefused(): void
    {
        $this->create();

        $this->assertThrows(
            AccessException::class,
            fn() => $this->service->listIssues($this->blocked, ListIssues::fromInput(['project_id' => 7])),
        );
    }

    public function testAReaderSeesTheProjectsIssues(): void
    {
        $issue = $this->create();

        $page = $this->service->listIssues($this->reader, ListIssues::fromInput(['project_id' => 7]));

        $this->assertCount(1, $page->items);
        $this->assertSame($issue->issueId, $page->items[0]->issue->issueId);
        $this->assertSame('open', $page->items[0]->status->slug);
    }

    public function testDoneIssuesStayOutUnlessTheyAreAskedFor(): void
    {
        $open = $this->create();
        $done = $this->create(['status_id' => 4]);

        $this->assertSame([$open->issueId], $this->listedIds());
        $this->assertSame([$open->issueId, $done->issueId], $this->listedIds(['include_terminal' => 1]));
    }

    public function testAStatusSlugShowsThatStatusEvenWhenItIsTerminal(): void
    {
        $this->create();
        $done = $this->create(['status_id' => 4]);

        $this->assertSame([$done->issueId], $this->listedIds(['status' => 'done']));
        $this->assertSame([], $this->listedIds(['status' => 'in_progress']));
    }

    public function testAnAssigneeFilterOfNoneFindsTheUnassigned(): void
    {
        $unassigned = $this->create();
        $assigned = $this->create(['assignee_aiu' => 36]);

        $this->assertSame([$unassigned->issueId], $this->listedIds(['assignee_aiu' => 0]));
        $this->assertSame([$assigned->issueId], $this->listedIds(['assignee_aiu' => 36]));
        $this->assertSame([$unassigned->issueId, $assigned->issueId], $this->listedIds());
    }

    public function testAParentFilterSeparatesChildrenFromTopLevelIssues(): void
    {
        $parent = $this->create();
        $child = $this->create(['parent_issue_id' => $parent->issueId]);

        $this->assertSame([$child->issueId], $this->listedIds(['parent_issue_id' => $parent->issueId]));
        $this->assertSame([$parent->issueId], $this->listedIds(['parent_issue_id' => 'null']));
    }

    public function testHighPriorityComesFirst(): void
    {
        $normal = $this->create();
        $low = $this->create(['priority' => 'low']);
        $high = $this->create(['priority' => 'high']);

        $this->assertSame([$high->issueId, $normal->issueId, $low->issueId], $this->listedIds());
    }

    public function testAPageCountsEveryMatchNotJustItsOwnRows(): void
    {
        $this->create();
        $this->create();
        $this->create();

        $page = $this->service->listIssues($this->writer, new ListIssues(
            projectId: 7,
            paging: new Paging(limit: 2),
        ));

        $this->assertCount(2, $page->items);
        $this->assertSame(3, $page->total);
        $this->assertSame(2, $page->limit);
        $this->assertSame(0, $page->offset);
    }

    public function testTheListRowsCarryTheStatusButNotTheDescription(): void
    {
        $this->create(['description' => 'long body']);

        $json = json_encode($this->listedItem(), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"status_slug":"open"', $json);
        $this->assertStringNotContainsString('description', $json);
    }

    private function listedItem(): IssueListItem
    {
        $page = $this->service->listIssues($this->writer, ListIssues::fromInput(['project_id' => 7]));

        return $page->items[0];
    }
}
