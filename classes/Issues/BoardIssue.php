<?php

declare(strict_types=1);

namespace Issues;

use Db\Row\IssueRow;
use Db\Row\IssueStatusRow;

/**
 * One row of a project page's issue table: the issue, its status, who it is
 * assigned to, and how many of its sub-issues are still open.
 */
final readonly class BoardIssue
{
    public function __construct(
        public IssueRow $issue,
        public IssueStatusRow $status,
        public ?string $assigneeName,
        public int $openChildren,
    ) {
    }
}
