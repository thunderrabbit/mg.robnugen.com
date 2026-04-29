<?php

namespace Tests\Unit;

use Codeception\Test\Unit;

/**
 * Unit tests for the agent web-login password contract (issue #122).
 *
 * Doesn't instantiate Auth\IsLoggedIn (which needs PDO+Config) — instead
 * pins down the constants and Argon2id round-trip behavior we rely on.
 */
class AgentPasswordHashTest extends Unit
{
    public function testMinPasswordLengthIs100()
    {
        $this->assertSame(100, \Auth\IsLoggedIn::AIU_MIN_PASSWORD_LENGTH);
    }

    public function testArgon2idRoundTripFor100CharPassword()
    {
        $pw = str_repeat('x', 100);
        $hash = password_hash($pw, PASSWORD_ARGON2ID);
        $this->assertNotFalse($hash);
        $this->assertTrue(password_verify($pw, $hash));
        $this->assertFalse(password_verify($pw . 'tampered', $hash));
    }

    public function testArgon2idHashFitsInVarchar255()
    {
        // Exercise the longest realistic path: a 200-char password and bumped params.
        $pw = str_repeat('Z', 200);
        $hash = password_hash($pw, PASSWORD_ARGON2ID, [
            'memory_cost' => 131072,
            'time_cost'   => 4,
            'threads'     => 2,
        ]);
        $this->assertLessThanOrEqual(255, strlen($hash),
            'Argon2id hash must fit in VARCHAR(255) — got ' . strlen($hash) . ' chars');
    }

    public function testArgon2idAlgoTagIsRecorded()
    {
        // Sanity: password_get_info exposes the algorithm so future migrations
        // (e.g. Argon2id v2) can detect old hashes that need rehashing.
        $hash = password_hash('a-100-char-pw' . str_repeat('y', 87), PASSWORD_ARGON2ID);
        $info = password_get_info($hash);
        $this->assertSame('argon2id', $info['algoName']);
    }
}
