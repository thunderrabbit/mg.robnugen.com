<?php

declare(strict_types=1);

namespace Issues\Command;

use Input\Absent;
use Input\Input;

/**
 * A request for the commits linked to one issue, oldest first. A repository or
 * hash left out doesn't filter.
 */
final readonly class ListCommits
{
    /**
     * @param positive-int $issueId
     * @param positive-int|null $repoId
     * @param non-empty-string|null $commitHash
     */
    public function __construct(
        public int $issueId,
        public ?int $repoId = null,
        public ?string $commitHash = null,
        public Paging $paging = new Paging(200),
    ) {
    }

    /**
     * @param array<mixed> $in
     */
    public static function fromInput(array $in): self
    {
        $repoId = Input::nullableId($in, 'repo_id');
        $hash = Input::optionalText($in, 'commit_hash', 64, trim: true);

        return new self(
            issueId: Input::positiveId($in, 'issue_id'),
            repoId: $repoId instanceof Absent ? null : $repoId,
            commitHash: $hash instanceof Absent ? null : $hash,
            paging: Paging::fromInput($in, 200, 500),
        );
    }
}
