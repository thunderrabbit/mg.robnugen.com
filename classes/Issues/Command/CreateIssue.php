<?php

declare(strict_types=1);

namespace Issues\Command;

use Db\Enum\IssuePriority;
use Input\Absent;
use Input\Input;
use Input\Length;

/**
 * A request to create an issue, typed and validated for shape. Whether the
 * caller may create it, and whether the assignee, parent and status fit the
 * project, is IssueService's job.
 */
final readonly class CreateIssue
{
    /**
     * @param positive-int $projectId
     * @param non-empty-string $title
     * @param non-empty-string|null $description
     * @param positive-int|null $assigneeAiu
     * @param positive-int|null $parentIssueId
     * @param positive-int|null $statusId null means the default status
     */
    public function __construct(
        public int $projectId,
        public string $title,
        public ?string $description = null,
        public IssuePriority $priority = IssuePriority::Normal,
        public ?int $assigneeAiu = null,
        public ?int $parentIssueId = null,
        public ?int $statusId = null,
    ) {
    }

    /**
     * From a JSON body, form post or MCP arguments. An assignee, parent or
     * status of 0 or null means none.
     *
     * @param array<mixed> $in
     */
    public static function fromInput(array $in): self
    {
        $projectId = Input::positiveId($in, 'project_id');
        $title = Input::text($in, 'title', 255);
        $description = Input::optionalText($in, 'description', 16_777_215, Length::Bytes);
        $priority = Input::enum($in, 'priority', IssuePriority::class);
        $assigneeAiu = Input::nullableId($in, 'assignee_aiu');
        $parentIssueId = Input::nullableId($in, 'parent_issue_id');
        $statusId = Input::nullableId($in, 'status_id');

        return new self(
            projectId: $projectId,
            title: $title,
            description: $description instanceof Absent ? null : $description,
            priority: $priority instanceof Absent ? IssuePriority::Normal : $priority,
            assigneeAiu: $assigneeAiu instanceof Absent ? null : $assigneeAiu,
            parentIssueId: $parentIssueId instanceof Absent ? null : $parentIssueId,
            statusId: $statusId instanceof Absent ? null : $statusId,
        );
    }
}
