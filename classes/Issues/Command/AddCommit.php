<?php

declare(strict_types=1);

namespace Issues\Command;

use Input\Absent;
use Input\Input;

/**
 * A commit linked to an issue. A range is two rows tagged through the note, so
 * there is nothing range-shaped here.
 *
 * That the repository belongs to the issue's account is an account invariant,
 * so IssueService checks it rather than this command.
 */
final readonly class AddCommit
{
    /**
     * @param positive-int $issueId
     * @param positive-int $repoId
     * @param non-empty-string $commitHash
     * @param non-empty-string|null $note
     */
    public function __construct(
        public int $issueId,
        public int $repoId,
        public string $commitHash,
        public ?string $note = null,
    ) {
    }

    /**
     * @param array<mixed> $in
     */
    public static function fromInput(array $in): self
    {
        $note = Input::optionalText($in, 'note', 255, trim: true);

        return new self(
            issueId: Input::positiveId($in, 'issue_id'),
            repoId: Input::positiveId($in, 'repo_id'),
            commitHash: Input::text($in, 'commit_hash', 64),
            note: $note instanceof Absent ? null : $note,
        );
    }
}
