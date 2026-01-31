<?php

namespace Tests\Unit;

use ActivityTracking\SessionKey;
use Codeception\Test\Unit;

class SessionKeyTest extends Unit
{
    private SessionKey $sessionKey;

    protected function _before()
    {
        // Create mock PDO (not used for generateSessionKey)
        $pdo = $this->createMock(\PDO::class);
        $this->sessionKey = new SessionKey($pdo);
    }

    // === generateSessionKey tests ===

    public function testGenerateSessionKeyLength()
    {
        $key = $this->sessionKey->generateSessionKey();
        $this->assertEquals(11, strlen($key), 'Session key should be 11 characters');
    }

    public function testGenerateSessionKeyValidChars()
    {
        // Base64url chars: a-z, A-Z, 0-9, _, -
        $validChars = '_-abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        for ($i = 0; $i < 10; $i++) {
            $key = $this->sessionKey->generateSessionKey();
            for ($j = 0; $j < strlen($key); $j++) {
                $char = $key[$j];
                $this->assertStringContainsString($char, $validChars,
                    "Character '$char' should be valid base64url");
            }
        }
    }

    public function testGenerateSessionKeyUnique()
    {
        $keys = [];
        for ($i = 0; $i < 100; $i++) {
            $keys[] = $this->sessionKey->generateSessionKey();
        }
        $unique = array_unique($keys);
        $this->assertCount(100, $unique, 'All 100 keys should be unique');
    }

    public function testGenerateSessionKeyNoInvalidChars()
    {
        // Should not contain +, /, = (standard base64 chars not in base64url)
        for ($i = 0; $i < 50; $i++) {
            $key = $this->sessionKey->generateSessionKey();
            $this->assertStringNotContainsString('+', $key);
            $this->assertStringNotContainsString('/', $key);
            $this->assertStringNotContainsString('=', $key);
        }
    }
}
