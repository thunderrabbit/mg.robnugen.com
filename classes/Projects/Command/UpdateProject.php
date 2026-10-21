<?php

declare(strict_types=1);

namespace Projects\Command;

use Input\Absent;
use Input\Input;
use Input\InvalidInput;

/**
 * A PATCH to a project. A field left Absent is not changed; a description sent
 * empty or null is cleared.
 */
final readonly class UpdateProject
{
    /**
     * @param positive-int $projectId
     * @param non-empty-string|Absent $name
     * @param non-empty-string|null|Absent $description
     */
    public function __construct(
        public int $projectId,
        public string|Absent $name = Absent::Field,
        public string|null|Absent $description = Absent::Field,
        public bool|Absent $isArchived = Absent::Field,
    ) {
    }

    /**
     * @param array<mixed> $in
     */
    public static function fromInput(array $in): self
    {
        $update = new self(
            projectId: Input::positiveId($in, 'project_id'),
            name: array_key_exists('name', $in) ? Input::text($in, 'name', 100) : Absent::Field,
            description: Input::optionalText($in, 'description', 65_535, trim: true),
            isArchived: array_key_exists('is_archived', $in) ? Input::flag($in, 'is_archived') : Absent::Field,
        );
        if ($update->changesNothing()) {
            throw new InvalidInput('provide name, description, and/or is_archived to update');
        }
        return $update;
    }

    public function changesNothing(): bool
    {
        return $this->name === Absent::Field
            && $this->description === Absent::Field
            && $this->isArchived === Absent::Field;
    }
}
