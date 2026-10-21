<?php

declare(strict_types=1);

namespace Projects;

use Db\Row\IssueRow;
use Db\Row\IssueStatusRow;
use Db\RowValue;

/**
 * One open issue on the /projects/dashboard: the caller's own work, or the
 * unassigned queue, across every project they can read.
 */
final readonly class DashboardIssue implements \JsonSerializable
{
    public function __construct(
        public IssueRow $issue,
        public IssueStatusRow $status,
        public string $projectName,
    ) {
    }

    /**
     * @return array{issue_id: int, priority: string, project_id: int, project_name: string, title: string, status_id: int, status_slug: string, status_label: string, assignee_aiu: int|null, author_aiu: int, parent_issue_id: int|null, created_at_utc: string, updated_at_utc: string}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'issue_id'        => $this->issue->issueId,
            'priority'        => $this->issue->priority->value,
            'project_id'      => $this->issue->projectId,
            'project_name'    => $this->projectName,
            'title'           => $this->issue->title,
            'status_id'       => $this->status->statusId,
            'status_slug'     => $this->status->slug,
            'status_label'    => $this->status->label,
            'assignee_aiu'    => $this->issue->assigneeAiu,
            'author_aiu'      => $this->issue->authorAiu,
            'parent_issue_id' => $this->issue->parentIssueId,
            'created_at_utc'  => $this->issue->createdAtUtc->format(RowValue::DATETIME_FORMAT),
            'updated_at_utc'  => $this->issue->updatedAtUtc->format(RowValue::DATETIME_FORMAT),
        ];
    }
}
