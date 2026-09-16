<?php

declare(strict_types=1);

namespace Issues;

use Db\Row\IssueRow;
use Db\Row\IssueStatusRow;
use Db\RowValue;

/**
 * One row of an issue list: the issue and the status it points at.
 *
 * The list reads have never carried descriptions — they would dwarf the rest of
 * the page, and the phone reads them one issue at a time — so this serializes
 * the issue's other columns plus the status, exactly as before.
 */
final readonly class IssueListItem implements \JsonSerializable
{
    public function __construct(
        public IssueRow $issue,
        public IssueStatusRow $status,
    ) {
    }

    /**
     * @return array{issue_id: int, priority: string, project_id: int, parent_issue_id: int|null, author_aiu: int, assignee_aiu: int|null, status_id: int, status_slug: string, status_label: string, is_terminal: 0|1, title: string, created_at_utc: string, updated_at_utc: string, done_at_utc: string|null}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'issue_id' => $this->issue->issueId,
            'priority' => $this->issue->priority->value,
            'project_id' => $this->issue->projectId,
            'parent_issue_id' => $this->issue->parentIssueId,
            'author_aiu' => $this->issue->authorAiu,
            'assignee_aiu' => $this->issue->assigneeAiu,
            'status_id' => $this->issue->statusId,
            'status_slug' => $this->status->slug,
            'status_label' => $this->status->label,
            'is_terminal' => (int) $this->status->isTerminal,
            'title' => $this->issue->title,
            'created_at_utc' => $this->issue->createdAtUtc->format(RowValue::DATETIME_FORMAT),
            'updated_at_utc' => $this->issue->updatedAtUtc->format(RowValue::DATETIME_FORMAT),
            'done_at_utc' => $this->issue->doneAtUtc?->format(RowValue::DATETIME_FORMAT),
        ];
    }
}
