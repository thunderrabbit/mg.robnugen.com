<?php

declare(strict_types=1);

namespace Projects;

use Db\Row\ProjectRow;
use Db\RowValue;
use Issues\Membership;

/**
 * A project with the caller's own rights on it, as /projects/list and
 * /projects/get answer. The owning user_id stays out of the JSON: it is always
 * the caller's account.
 */
final readonly class ProjectView implements \JsonSerializable
{
    public function __construct(
        public ProjectRow $project,
        public Membership $membership,
    ) {
    }

    /**
     * @return array{project_id: int, name: string, description: string|null, is_archived: 0|1, created_at_utc: string, updated_at_utc: string, my_can_read: 0|1, my_can_write: 0|1}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'project_id'     => $this->project->projectId,
            'name'           => $this->project->name,
            'description'    => $this->project->description,
            'is_archived'    => (int) $this->project->isArchived,
            'created_at_utc' => $this->project->createdAtUtc->format(RowValue::DATETIME_FORMAT),
            'updated_at_utc' => $this->project->updatedAtUtc->format(RowValue::DATETIME_FORMAT),
            'my_can_read'    => (int) $this->membership->canRead,
            'my_can_write'   => (int) $this->membership->canWrite,
        ];
    }
}
