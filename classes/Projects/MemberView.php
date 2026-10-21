<?php

declare(strict_types=1);

namespace Projects;

use Db\Row\ProjectMemberRow;
use Db\RowValue;

/**
 * One member of a project with the actor behind it, as /projects/members
 * answers and the project page lists.
 */
final readonly class MemberView implements \JsonSerializable
{
    public function __construct(
        public ProjectMemberRow $member,
        public string $name,
        public ?string $description,
        public string $actorType,
    ) {
    }

    /**
     * @return array{member_aiu: int, can_read: 0|1, can_write: 0|1, granted_at_utc: string, name: string, description: string|null, actor_type: string}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'member_aiu'     => $this->member->memberAiu,
            'can_read'       => (int) $this->member->canRead,
            'can_write'      => (int) $this->member->canWrite,
            'granted_at_utc' => $this->member->grantedAtUtc->format(RowValue::DATETIME_FORMAT),
            'name'           => $this->name,
            'description'    => $this->description,
            'actor_type'     => $this->actorType,
        ];
    }
}
