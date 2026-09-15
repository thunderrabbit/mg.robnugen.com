<?php

declare(strict_types=1);

namespace Issues;

use Db\Row\IssueRow;
use Db\Row\IssueStatusRow;

/**
 * An issue as a reader sees it: the row, its status, and the caller's rights on
 * its project. Surfaces shape their responses from this.
 */
final readonly class IssueView
{
    public function __construct(
        public IssueRow $issue,
        public IssueStatusRow $status,
        public Membership $membership,
    ) {
    }
}
