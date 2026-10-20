<?php

declare(strict_types=1);

namespace Issues\Command;

use Input\Input;
use Input\Length;

/**
 * A new body for an existing comment. Only the comment's author, or a human on
 * the account, may send one; IssueService decides that.
 */
final readonly class EditComment
{
    /**
     * @param positive-int $issueCommentId
     * @param non-empty-string $body
     */
    public function __construct(
        public int $issueCommentId,
        public string $body,
    ) {
    }

    /**
     * @param array<mixed> $in
     */
    public static function fromInput(array $in): self
    {
        return new self(
            issueCommentId: Input::positiveId($in, 'issue_comment_id'),
            body: Input::encodedText($in, 'body', 'encoding', 65_535, Length::Bytes),
        );
    }
}
