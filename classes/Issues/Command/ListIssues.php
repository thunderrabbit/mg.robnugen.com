<?php

declare(strict_types=1);

namespace Issues\Command;

use Input\Absent;
use Input\Input;
use Input\Length;

/**
 * A request for one project's issues. An assignee or parent left Absent is not
 * filtered on at all; sent as null (or 0) it filters for issues with none.
 *
 * A status slug filters on that status and shows terminal issues with it;
 * without one, terminal issues are left out unless include_terminal is set.
 */
final readonly class ListIssues
{
    /**
     * @param positive-int $projectId
     * @param non-empty-string|null $statusSlug
     * @param positive-int|null|Absent $assigneeAiu
     * @param positive-int|null|Absent $parentIssueId
     */
    public function __construct(
        public int $projectId,
        public ?string $statusSlug = null,
        public int|null|Absent $assigneeAiu = Absent::Field,
        public int|null|Absent $parentIssueId = Absent::Field,
        public bool $includeTerminal = false,
        public Paging $paging = new Paging(100),
    ) {
    }

    /**
     * From a query string or MCP arguments.
     *
     * @param array<mixed> $in
     */
    public static function fromInput(array $in): self
    {
        $status = Input::optionalText($in, 'status', 50, Length::Characters, trim: true);

        return new self(
            projectId: Input::positiveId($in, 'project_id'),
            statusSlug: $status instanceof Absent ? null : $status,
            assigneeAiu: Input::nullableId($in, 'assignee_aiu'),
            parentIssueId: Input::nullableId($in, 'parent_issue_id'),
            includeTerminal: Input::flag($in, 'include_terminal'),
            paging: Paging::fromInput($in, 100, 200),
        );
    }
}
