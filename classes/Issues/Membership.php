<?php

declare(strict_types=1);

namespace Issues;

use Db\Row\ProjectMemberRow;

/**
 * A caller's rights on one project, from their project_members row.
 */
final readonly class Membership
{
    public function __construct(
        public bool $canRead,
        public bool $canWrite,
    ) {
    }

    public static function fromRow(ProjectMemberRow $row): self
    {
        return new self(canRead: $row->canRead, canWrite: $row->canWrite);
    }
}
