<?php

declare(strict_types=1);

namespace Projects\Command;

use Input\Absent;
use Input\Input;
use Input\InvalidInput;

/**
 * A change to a member's rights. A right left Absent is not changed.
 */
final readonly class UpdateMember
{
    /**
     * @param positive-int $projectId
     * @param positive-int $memberAiu
     */
    public function __construct(
        public int $projectId,
        public int $memberAiu,
        public bool|Absent $canRead = Absent::Field,
        public bool|Absent $canWrite = Absent::Field,
    ) {
    }

    /**
     * @param array<mixed> $in
     */
    public static function fromInput(array $in): self
    {
        $update = new self(
            projectId: Input::positiveId($in, 'project_id'),
            memberAiu: Input::positiveId($in, 'member_aiu'),
            canRead: array_key_exists('can_read', $in) ? Input::flag($in, 'can_read') : Absent::Field,
            canWrite: array_key_exists('can_write', $in) ? Input::flag($in, 'can_write') : Absent::Field,
        );
        if ($update->canRead === Absent::Field && $update->canWrite === Absent::Field) {
            throw new InvalidInput('provide can_read and/or can_write to update');
        }
        return $update;
    }
}
