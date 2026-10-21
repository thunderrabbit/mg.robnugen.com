<?php

declare(strict_types=1);

namespace Projects\Command;

use Input\Absent;
use Input\Input;

/**
 * A new project. Whether the caller may create it, and whether the name is
 * free in the account, is ProjectService's job.
 */
final readonly class CreateProject
{
    /**
     * @param non-empty-string $name
     * @param non-empty-string|null $description
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {
    }

    /**
     * From a JSON body or form post. An empty description means none.
     *
     * @param array<mixed> $in
     */
    public static function fromInput(array $in): self
    {
        $description = Input::optionalText($in, 'description', 65_535, trim: true);
        return new self(
            name: Input::text($in, 'name', 100),
            description: $description instanceof Absent ? null : $description,
        );
    }
}
