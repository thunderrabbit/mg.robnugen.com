<?php

declare(strict_types=1);

namespace Issues;

/**
 * One issue as the detail page shows it: the fields the template prints, already
 * flattened, so the template reaches for $issue->statusLabel rather than digging
 * through a row, a status and a membership.
 */
final readonly class IssueDetail
{
    public function __construct(
        public int $issueId,
        public int $projectId,
        public string $projectName,
        public string $title,
        public \Db\Enum\IssuePriority $priority,
        public ?string $description,
        public int $statusId,
        public string $statusLabel,
        public string $authorName,
        public ?int $assigneeAiu,
        public ?string $assigneeName,
        public bool $canWrite,
    ) {
    }

    public static function of(IssueView $view, IssueNames $names): self
    {
        return new self(
            issueId: $view->issue->issueId,
            projectId: $view->issue->projectId,
            projectName: $names->projectName,
            title: $view->issue->title,
            priority: $view->issue->priority,
            description: $view->issue->description,
            statusId: $view->issue->statusId,
            statusLabel: $view->status->label,
            authorName: $names->authorName,
            assigneeAiu: $view->issue->assigneeAiu,
            assigneeName: $names->assigneeName,
            canWrite: $view->membership->canWrite,
        );
    }
}
