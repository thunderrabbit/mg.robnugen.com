<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

class ConnectedAppsTest extends Unit
{
    private \PDO $pdo;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE agent_inbox_user (aiu_id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT)");
        $this->pdo->exec(
            "CREATE TABLE mcp_tokens (
                token_id INTEGER PRIMARY KEY AUTOINCREMENT, aiu_id INTEGER, user_id INTEGER,
                created_at_utc TEXT, expires_at_utc TEXT, refresh_expires_at_utc TEXT
            )"
        );
        $this->pdo->exec("CREATE TABLE oauth_client_consents (user_id INTEGER, aiu_id INTEGER, client_id TEXT)");
        $this->pdo->exec("INSERT INTO agent_inbox_user VALUES (63, 1, 'Claude Phone'), (90, 2, 'Other')");
        $this->pdo->exec(
            "INSERT INTO mcp_tokens (aiu_id, user_id, created_at_utc, expires_at_utc, refresh_expires_at_utc) VALUES
             (63, 1, '2026-10-01 00:00:00', '2026-10-02 00:00:00', '2027-11-05 00:00:00'),
             (63, 1, '2026-10-10 00:00:00', '2026-10-11 00:00:00', '2027-11-14 00:00:00'),
             (90, 2, '2026-10-12 00:00:00', '2026-10-13 00:00:00', '2027-11-16 00:00:00')"
        );
        $this->pdo->exec("INSERT INTO oauth_client_consents VALUES (1, 63, 'phone'), (2, 90, 'x')");
    }

    private function rows(string $table): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM {$table}");
        $n = $stmt === false ? false : $stmt->fetchColumn();
        return is_int($n) ? $n : -1;
    }

    public function testListsOnlyTheUsersConnectionsNewestFirst(): void
    {
        $apps = (new \Auth\ConnectedApps($this->pdo))->listForUser(1);
        $this->assertCount(2, $apps);
        $this->assertSame('Claude Phone', $apps[0]['agent_name']);
        $this->assertSame('2026-10-10 00:00:00', $apps[0]['created_at_utc']);
    }

    public function testRevokeIsScopedToTheUser(): void
    {
        $apps = new \Auth\ConnectedApps($this->pdo);
        $this->assertFalse($apps->revoke(1, 3), 'token 3 belongs to user 2');
        $this->assertTrue($apps->revoke(1, 1));
        $this->assertSame(2, $this->rows('mcp_tokens'));
    }

    public function testRevokeAllClearsTokensAndConsentsForThatUserOnly(): void
    {
        $this->assertSame(2, (new \Auth\ConnectedApps($this->pdo))->revokeAll(1));
        $this->assertSame(1, $this->rows('mcp_tokens'));
        $this->assertSame(1, $this->rows('oauth_client_consents'));
    }
}
