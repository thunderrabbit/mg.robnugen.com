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

    private const INFLATE_STEP_BYTES = 1024;

    /**
     * The plain text the wire string stands for under this encoding.
     *
     * `$maxBytes` caps what a gzip body may inflate to. A few hundred bytes of
     * gzip can expand to gigabytes, so the decoder stops at the cap instead of
     * trusting the compressed size. Base64 never expands (the decoded text is
     * three quarters of the wire), and the request size already bounds the
     * wire, so only gzip needs the cap. `$field` names the request field in
     * error messages, so a caller sees "message is not valid base64" rather
     * than a name it did not send.
     *
     * @param int<0, max> $maxBytes
     * @throws InvalidInput when the wire string is not valid for this encoding,
     *                      or inflates past `$maxBytes`
     */
    public function decode(string $wire, int $maxBytes, string $field = 'body'): string
    {
        return match ($this) {
            self::Plain => $wire,
            self::Base64 => self::fromBase64($wire, $field),
            self::GzipBase64 => self::gunzip(self::fromBase64($wire, $field), $maxBytes, $field),
        };
    }

    private static function fromBase64(string $wire, string $field): string
    {
        // Tolerate the line breaks `base64` and most encoders insert, then
        // decode strictly so stray bytes are an error rather than silence.
        $decoded = base64_decode((string) preg_replace('/\s+/', '', $wire), true);
        if ($decoded === false) {
            throw new InvalidInput("{$field} is not valid base64");
        }
        return $decoded;
    }

    /**
     * Inflates in small steps and stops the moment the output passes the cap.
     * Deflate cannot expand more than about 1000 to 1, so a one kilobyte step
     * adds at most about a megabyte before the next check, however large the
     * whole stream claims to be. Doing it stepwise also lets a corrupt or
     * truncated stream be told apart from one that is merely too big.
     *
     * @param int<0, max> $maxBytes
     */
    private static function gunzip(string $gzip, int $maxBytes, string $field): string
    {
        $context = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            throw new InvalidInput("{$field} is not valid gzip data");
        }

        $steps = str_split($gzip, self::INFLATE_STEP_BYTES);
        $last = array_key_last($steps);
        $decoded = '';
        foreach ($steps as $index => $step) {
            $piece = @inflate_add($context, $step, $index === $last ? ZLIB_FINISH : ZLIB_NO_FLUSH);
            if ($piece === false) {
                throw new InvalidInput("{$field} is not valid gzip data");
            }
            $decoded .= $piece;
            if (strlen($decoded) > $maxBytes) {
                throw new InvalidInput("{$field} is larger than {$maxBytes} bytes once decompressed");
            }
        }
        if (inflate_get_status($context) !== ZLIB_STREAM_END) {
            throw new InvalidInput("{$field} is not valid gzip data");
        }
        return $decoded;
    }
}
