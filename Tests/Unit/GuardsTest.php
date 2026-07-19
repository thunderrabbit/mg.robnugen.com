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
    // === permission() ===

    public function testPermissionGrantedReturnsNull()
    {
        // DB delivers flags as 1 / '1'; both mean granted.
        $this->assertNull(Guards::permission(['can_read_inbox' => 1], 'can_read_inbox', 'read inbox'));
        $this->assertNull(Guards::permission(['can_read_inbox' => '1'], 'can_read_inbox', 'read inbox'));
        $this->assertNull(Guards::permission(['can_read_inbox' => true], 'can_read_inbox', 'read inbox'));
    }

    public function testPermissionDeniedForEveryFalsyOrMissingValue()
    {
        foreach ([0, '0', '', false, null] as $denied) {
            $verdict = Guards::permission(['can_write_inbox' => $denied], 'can_write_inbox', 'write inbox');
            $this->assertSame(403, $verdict['code']);
            $this->assertSame('This API key does not have permission to write inbox', $verdict['error']);
        }

        // Missing key entirely (empty() semantics) is also denied, no warning.
        $verdict = Guards::permission([], 'can_write_inbox', 'write inbox');
        $this->assertSame(403, $verdict['code']);
        $this->assertSame('This API key does not have permission to write inbox', $verdict['error']);
    }

    public function testPermissionLabelsMatchEndpointStrings()
    {
        $cases = [
            ['can_read_inbox',      'read inbox'],
            ['can_write_inbox',     'write inbox'],
            ['can_broadcast_inbox', 'broadcast'],
            ['can_list_actors',     'list actors'],
        ];
        foreach ($cases as [$flag, $label]) {
            $verdict = Guards::permission([], $flag, $label);
            $this->assertSame(
                "This API key does not have permission to $label",
                $verdict['error']
            );
        }
    }

    // === byteLimit() ===

    public function testByteLimitAllowsUnderAndExactlyAtCap()
    {
        $this->assertNull(Guards::byteLimit('message', str_repeat('a', 10239)));
        // Exactly 10240 bytes is allowed (the check is strictly greater-than).
        $this->assertNull(Guards::byteLimit('message', str_repeat('a', 10240)));
    }

    public function testByteLimitRejectsOverCapWithExactString()
    {
        $verdict = Guards::byteLimit('message', str_repeat('a', 10241));
        $this->assertSame(400, $verdict['code']);
        $this->assertSame('message exceeds 10240 byte limit (10241 bytes)', $verdict['error']);
    }

    public function testByteLimitFieldNameIsReflected()
    {
        $verdict = Guards::byteLimit('response', str_repeat('a', 10241));
        $this->assertSame('response exceeds 10240 byte limit (10241 bytes)', $verdict['error']);
    }

    public function testByteLimitCountsBytesNotCharacters()
    {
        // 3414 × "あ" (3 UTF-8 bytes) = 3414 chars but 10242 bytes → over cap.
        $multibyte = str_repeat("\u{3042}", 3414);
        $this->assertSame(3414, mb_strlen($multibyte), 'guard: char count under cap');
        $this->assertSame(10242, strlen($multibyte), 'guard: byte count over cap');

        $verdict = Guards::byteLimit('message', $multibyte);
        $this->assertSame(400, $verdict['code']);
        $this->assertSame('message exceeds 10240 byte limit (10242 bytes)', $verdict['error']);

        // One fewer multibyte char (10239 bytes) is under the cap.
        $this->assertNull(Guards::byteLimit('message', str_repeat("\u{3042}", 3413)));
    }
}
