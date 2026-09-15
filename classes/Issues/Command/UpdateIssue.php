<?php

declare(strict_types=1);

namespace Issues\Command;

use Db\Enum\IssuePriority;
use Input\Absent;
use Input\Input;
use Input\InvalidInput;
use Input\Length;

/**
 * A PATCH to an issue. A field left Absent is not changed; a nullable field
 * sent as null (or 0 for an id, '' for text) is cleared.
 */
final readonly class UpdateIssue
{
    /**
     * @param positive-int $issueId
     * @param non-empty-string|Absent $title
     * @param non-empty-string|null|Absent $description
     * @param positive-int|null|Absent $assigneeAiu
     * @param positive-int|null|Absent $parentIssueId
     * @param positive-int|Absent $statusId
     */
    public function __construct(
        public int $issueId,
        public string|Absent $title = Absent::Field,
        public string|null|Absent $description = Absent::Field,
        public IssuePriority|Absent $priority = Absent::Field,
        public int|null|Absent $assigneeAiu = Absent::Field,
        public int|null|Absent $parentIssueId = Absent::Field,
        public int|Absent $statusId = Absent::Field,
    ) {
    }

    /**
     * @param array<mixed> $in
     */
    public static function fromInput(array $in): self
    {
        $update = new self(
            issueId: Input::positiveId($in, 'issue_id'),
            title: array_key_exists('title', $in) ? Input::text($in, 'title', 255) : Absent::Field,
            description: Input::optionalText($in, 'description', 16_777_215, Length::Bytes),
            priority: Input::enum($in, 'priority', IssuePriority::class),
            assigneeAiu: Input::nullableId($in, 'assignee_aiu'),
            parentIssueId: Input::nullableId($in, 'parent_issue_id'),
            statusId: array_key_exists('status_id', $in) ? Input::positiveId($in, 'status_id') : Absent::Field,
        );
        if ($update->changesNothing()) {
            throw new InvalidInput('provide at least one field to update');
        }
        return $update;
    }

    public function changesNothing(): bool
    {
        return $this->title === Absent::Field
            && $this->description === Absent::Field
            && $this->priority === Absent::Field
            && $this->assigneeAiu === Absent::Field
            && $this->parentIssueId === Absent::Field
            && $this->statusId === Absent::Field;
    }
}
