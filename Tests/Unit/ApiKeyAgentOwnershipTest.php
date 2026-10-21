<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

/**
 * An API key carries its agent's identity and permission bits, so it may only
 * be bound to an agent from the key owner's own account. In-memory SQLite.
 */
class ApiKeyAgentOwnershipTest extends Unit
{
    private \PDO $pdo;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE agent_inbox_user (aiu_id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, actor_type TEXT)"
        );
        $this->pdo->exec(
            "CREATE TABLE api_keys (
                key_id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER, aiu_id INTEGER, api_key_hash TEXT, label TEXT, is_active INTEGER DEFAULT 1
            )"
        );
        $this->pdo->exec("INSERT INTO agent_inbox_user VALUES (8, 1, 'Boss Claude', 'agent'), (40, 2, 'Mine', 'agent')");
    }

    private function keyCount(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM api_keys");
        $count = $stmt === false ? false : $stmt->fetchColumn();
        return is_int($count) ? $count : -1;
    }

    public function testAgentBelongsOnlyToItsOwnAccount(): void
    {
        $keys = new \Auth\ApiKey($this->pdo);
        $this->assertTrue($keys->aiuBelongsToUser(40, 2));
        $this->assertFalse($keys->aiuBelongsToUser(8, 2));
        $this->assertFalse($keys->aiuBelongsToUser(999, 2));
    }

    public function testKeyForOwnAgentIsIssued(): void
    {
        $raw = (new \Auth\ApiKey($this->pdo))->generateKey(2, 'mine', 40);
        $this->assertStringStartsWith('sk_', $raw);
        $this->assertSame(1, $this->keyCount());
    }

    public function testKeyForAnotherAccountsAgentIsRefused(): void
    {
        try {
            (new \Auth\ApiKey($this->pdo))->generateKey(2, 'stolen', 8);
            $this->fail('a key for user 1\'s agent was issued to user 2');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not one of your agents', $e->getMessage());
        }
        $this->assertSame(0, $this->keyCount());
    }
}
