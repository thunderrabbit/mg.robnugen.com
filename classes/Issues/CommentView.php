<?php

declare(strict_types=1);

namespace Issues;

use Db\Row\IssueCommentRow;

/**
 * A comment as a reader sees it: the row plus its author's name, which lives in
 * agent_inbox_user rather than on the comment.
 */
final readonly class CommentView implements \JsonSerializable
{
    public function __construct(
        public IssueCommentRow $comment,
        public string $authorName,
    ) {
    }

    /**
     * @return array{issue_comment_id: int, issue_id: int, author_aiu: int, body: string, created_at_utc: string, updated_at_utc: string, author_name: string}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->comment->jsonSerialize() + ['author_name' => $this->authorName];
    }
}
