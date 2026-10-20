<?php

namespace Tests\Unit;

use Auth\Guards;
use Codeception\Test\Unit;

/**
 * Hermetic unit tests for the pure Auth\Guards helper.
 *
 * No DB, no network, no credentials — runs in the keyless, networkless commit
 * tier. Covers the same permission and byte-limit behavior the (now relocated)
 * live-API integration tests exercised, but in-process against Guards directly.
 */
class GuardsTest extends Unit
{
    private Guards $guards;

    protected function _before(): void
    {
        $this->guards = new Guards();
    }

    // === permission() ===

    public function testPermissionGrantedReturnsNull(): void
    {
        // DB delivers flags as 1 / '1'; both mean granted.
        $this->assertNull($this->guards->permission(['can_read_inbox' => 1], 'can_read_inbox', 'read inbox'));
        $this->assertNull($this->guards->permission(['can_read_inbox' => '1'], 'can_read_inbox', 'read inbox'));
        $this->assertNull($this->guards->permission(['can_read_inbox' => true], 'can_read_inbox', 'read inbox'));
    }

    public function testPermissionDeniedForEveryFalsyOrMissingValue(): void
    {
        foreach ([0, '0', '', false, null] as $denied) {
            $verdict = $this->guards->permission(['can_write_inbox' => $denied], 'can_write_inbox', 'write inbox');
            $this->assertSame(403, $verdict['code'] ?? null);
            $this->assertSame('This API key does not have permission to write inbox', $verdict['error']);
        }

        // Missing key entirely (empty() semantics) is also denied, no warning.
        $verdict = $this->guards->permission([], 'can_write_inbox', 'write inbox');
        $this->assertSame(403, $verdict['code'] ?? null);
        $this->assertSame('This API key does not have permission to write inbox', $verdict['error']);
    }

    public function testPermissionLabelsMatchEndpointStrings(): void
    {
        $cases = [
            ['can_read_inbox',      'read inbox'],
            ['can_write_inbox',     'write inbox'],
            ['can_broadcast_inbox', 'broadcast'],
            ['can_list_actors',     'list actors'],
        ];
        foreach ($cases as [$flag, $label]) {
            $verdict = $this->guards->permission([], $flag, $label);
            $this->assertSame(
                "This API key does not have permission to $label",
                $verdict['error'] ?? null
            );
        }
    }

    // === byteLimit() ===

    public function testByteLimitAllowsUnderAndExactlyAtCap(): void
    {
        $this->assertNull($this->guards->byteLimit('message', str_repeat('a', 10239)));
        // Exactly 10240 bytes is allowed (the check is strictly greater-than).
        $this->assertNull($this->guards->byteLimit('message', str_repeat('a', 10240)));
    }

    public function testByteLimitRejectsOverCapWithExactString(): void
    {
        $verdict = $this->guards->byteLimit('message', str_repeat('a', 10241));
        $this->assertSame(400, $verdict['code'] ?? null);
        $this->assertSame('message exceeds 10240 byte limit (10241 bytes)', $verdict['error']);
    }

    public function testByteLimitFieldNameIsReflected(): void
    {
        $verdict = $this->guards->byteLimit('response', str_repeat('a', 10241));
        $this->assertSame('response exceeds 10240 byte limit (10241 bytes)', $verdict['error'] ?? null);
    }

    public function testByteLimitCountsBytesNotCharacters(): void
    {
        // 3414 × "あ" (3 UTF-8 bytes) = 3414 chars but 10242 bytes → over cap.
        $multibyte = str_repeat("\u{3042}", 3414);
        $this->assertSame(3414, mb_strlen($multibyte), 'guard: char count under cap');
        $this->assertSame(10242, strlen($multibyte), 'guard: byte count over cap');

        $verdict = $this->guards->byteLimit('message', $multibyte);
        $this->assertSame(400, $verdict['code'] ?? null);
        $this->assertSame('message exceeds 10240 byte limit (10242 bytes)', $verdict['error']);

        // One fewer multibyte char (10239 bytes) is under the cap.
        $this->assertNull($this->guards->byteLimit('message', str_repeat("\u{3042}", 3413)));
    }

    // === decodedText() ===

    public function testDecodedTextReturnsPlainTextExactlyAsSent(): void
    {
        $this->assertSame("  as typed\n", $this->guards->decodedText(['message' => "  as typed\n"], 'message'));
        $this->assertSame('', $this->guards->decodedText([], 'message'));
        $this->assertSame('plain, spelled out', $this->guards->decodedText(
            ['message' => 'plain, spelled out', 'encoding' => 'plain'],
            'message',
        ));
    }

    public function testDecodedTextDecodesBase64AndGzipBase64(): void
    {
        $source = "if (n < 0) { drop(it); } -- a message about code";
        $this->assertSame($source, $this->guards->decodedText(
            ['message' => base64_encode($source), 'encoding' => 'base64'],
            'message',
        ));
        $this->assertSame($source, $this->guards->decodedText(
            ['response' => base64_encode((string) gzencode($source)), 'encoding' => 'gzip+base64'],
            'response',
        ));
    }

    public function testDecodedTextRejectsBadInputWithAFourHundredVerdictNamingTheField(): void
    {
        $this->assertSame(
            ['code' => 400, 'error' => 'message is not valid base64'],
            $this->guards->decodedText(['message' => '*** nope ***', 'encoding' => 'base64'], 'message'),
        );
        $this->assertSame(
            ['code' => 400, 'error' => 'response is not valid gzip data'],
            $this->guards->decodedText(
                ['response' => base64_encode('not gzip'), 'encoding' => 'gzip+base64'],
                'response',
            ),
        );
        $this->assertSame(
            ['code' => 400, 'error' => "encoding must be 'plain', 'base64', or 'gzip+base64'"],
            $this->guards->decodedText(['message' => 'x', 'encoding' => 'rot13'], 'message'),
        );
    }

    public function testDecodedTextRefusesAGzipBombAtTheMessageLimit(): void
    {
        $bomb = base64_encode((string) gzencode(str_repeat('A', 5_000_000), 9));
        $this->assertSame(
            ['code' => 400, 'error' => 'message is larger than 10240 bytes once decompressed'],
            $this->guards->decodedText(['message' => $bomb, 'encoding' => 'gzip+base64'], 'message'),
        );
    }
}
