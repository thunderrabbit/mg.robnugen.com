<?php

declare(strict_types=1);

namespace Issues;

/**
 * The names an issue's own row only has ids for: its project, its author, and
 * its assignee when it has one. The web pages show these; the API answers with
 * the ids and lets the client look them up.
 */
final readonly class IssueNames
{
    public function __construct(
        public string $projectName,
        public string $authorName,
        public ?string $assigneeName,
    ) {
    }
}
