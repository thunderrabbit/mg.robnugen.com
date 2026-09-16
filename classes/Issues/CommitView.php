<?php

declare(strict_types=1);

namespace Issues;

use Db\Row\IssueCommitRow;

/**
 * A commit link as a reader sees it: the row plus the repository it points at
 * and the name of whoever linked it.
 */
final readonly class CommitView implements \JsonSerializable
{
    public function __construct(
        public IssueCommitRow $commit,
        public string $repoName,
        public ?string $repoUrl,
        public string $addedByName,
    ) {
    }

    /**
     * @return array{issue_commit_id: int, issue_id: int, repo_id: int, commit_hash: string, note: string|null, added_by_aiu: int, created_at_utc: string, repo_name: string, repo_url: string|null, added_by_name: string}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->commit->jsonSerialize() + [
            'repo_name' => $this->repoName,
            'repo_url' => $this->repoUrl,
            'added_by_name' => $this->addedByName,
        ];
    }
}
