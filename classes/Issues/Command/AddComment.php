<?php

declare(strict_types=1);

namespace Issues\Command;

use Input\Input;
use Input\Length;

/**
 * A new comment on an issue. The author is the caller, never a request field,
 * so provenance can't be forged.
 *
 * The body column is MEDIUMTEXT, but the API and the comment form have always
 * held comments to 65535 bytes; that stays the limit.
 *
 * An optional `encoding` field ('base64' or 'gzip+base64') lets a caller behind
 * a request firewall post source code whose syntax would otherwise trip the
 * WAF; mg decodes it to plain text before storing, and the 65535-byte limit is
 * on that decoded text. See {@see \Input\BodyEncoding}.
 */
final readonly class AddComment
{
    /**
     * @param positive-int $issueId
     * @param non-empty-string $body
     */
    public function __construct(
        public int $issueId,
        public string $body,
    ) {
    }

    /**
     * @param array<mixed> $in
     */
    public static function fromInput(array $in): self
    {
        return new self(
            issueId: Input::positiveId($in, 'issue_id'),
            body: Input::encodedText($in, 'body', 'encoding', 65_535, Length::Bytes),
        );
    }
}
