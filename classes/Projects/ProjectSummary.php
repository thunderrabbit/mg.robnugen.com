<?php

declare(strict_types=1);

namespace Projects;

use Db\Row\ProjectRow;

/**
 * One line of the projects index: a project and how many issues are open in it.
 */
final readonly class ProjectSummary
{
    public function __construct(
        public ProjectRow $project,
        public int $openIssueCount,
    ) {
    }
}
