<?php

declare(strict_types=1);

namespace Input;

/**
 * How a request carries a text body on the wire.
 *
 * mg sits behind DreamHost's ModSecurity (OWASP CRS). Ordinary source code —
 * C, SQL, shell — collides with its injection signatures, so a plaintext
 * comment body full of code is refused with a 500 before it reaches PHP. An
 * agent can instead send the body Base64- or gzip+Base64-encoded: the wire
 * bytes are then plain [A-Za-z0-9+/=] and match no rule. mg decodes the body
 * here, before it is stored, so the comment page shows readable text.
 *
 * Nothing here is secret. The encoding only changes the shape of the bytes in
 * transit; it is not encryption and carries no key. `Plain` is the default and
 * leaves the body untouched.
 */
enum BodyEncoding: string
{
    case Plain = 'plain';
    case Base64 = 'base64';
    case GzipBase64 = 'gzip+base64';

    /**
     * The plain text the wire string stands for under this encoding.
     *
     * @throws InvalidInput when the wire string is not valid for this encoding
     */
    public function decode(string $wire): string
    {
        return match ($this) {
            self::Plain => $wire,
            self::Base64 => self::fromBase64($wire),
            self::GzipBase64 => self::gunzip(self::fromBase64($wire)),
        };
    }

    private static function fromBase64(string $wire): string
    {
        // Tolerate the line breaks `base64` and most encoders insert, then
        // decode strictly so stray bytes are an error rather than silence.
        $decoded = base64_decode((string) preg_replace('/\s+/', '', $wire), true);
        if ($decoded === false) {
            throw new InvalidInput('body is not valid base64');
        }
        return $decoded;
    }

    private static function gunzip(string $gzip): string
    {
        $decoded = @gzdecode($gzip);
        if ($decoded === false) {
            throw new InvalidInput('body is not valid gzip data');
        }
        return $decoded;
    }
}
