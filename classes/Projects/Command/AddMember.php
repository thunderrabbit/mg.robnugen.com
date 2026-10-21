<?php

declare(strict_types=1);

namespace Projects\Command;

use Input\Input;

/**
 * An actor joining a project. Unsent rights default to read but not write.
 */
final readonly class AddMember
{
    /**
     * @param positive-int $projectId
     * @param positive-int $memberAiu
     */
    public function __construct(
        public int $projectId,
        public int $memberAiu,
        public bool $canRead = true,
        public bool $canWrite = false,
    ) {
    }

    /**
     * @param array<mixed> $in
     */
    public static function fromInput(array $in): self
    {
        return new self(
            projectId: Input::positiveId($in, 'project_id'),
            memberAiu: Input::positiveId($in, 'member_aiu'),
            canRead: isset($in['can_read']) ? Input::flag($in, 'can_read') : true,
            canWrite: isset($in['can_write']) ? Input::flag($in, 'can_write') : false,
        );
    }
}
