<?php

declare(strict_types=1);

namespace Issues\Images;

/**
 * What a command-line run has to say: its exit status and the lines for
 * stdout and stderr.
 */
final readonly class CommandResult
{
    /**
     * @param list<string> $out
     * @param list<string> $err
     */
    public function __construct(
        public int $exit,
        public array $out = [],
        public array $err = [],
    ) {
    }
}
