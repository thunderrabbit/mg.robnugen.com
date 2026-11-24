<?php

declare(strict_types=1);

namespace Tests\Unit;

use ActivityTracking\Todo;
use Auth\Actors;
use Auth\ApiKey;
use Auth\AuthCodes;
use Auth\ClientConsents;
use Auth\McpTokens;
use Codeception\Test\Unit;

/**
 * The queries the token endpoint (wwwroot/mcp/token.php), the MCP entry
 * (wwwroot/mcp/index.php) and the authorize page (wwwroot/authorize/index.php)
 * run through Auth\McpTokens, Auth\AuthCodes, Auth\ClientConsents and three
 * lookups on existing gateways.
 *
 * Most run on an in-memory SQLite given a NOW() fixed at 2026-06-15
 * 12:00:00. Four cannot run there (DATE_ADD and DATE_SUB with INTERVAL, and
 * ON DUPLICATE KEY UPDATE), so they are pinned: each of those tests compares
 * the SQL text, whitespace collapsed on both sides, and the parameters, and
 * says so in its name.
 */
class OAuthTokenQueriesTest extends Unit
{
    private const string NOW = '2026-06-15 12:00:00';

    private \Pdo\Sqlite $pdo;

    protected function _before(): void
    {
        $this->pdo = new \Pdo\Sqlite('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->createFunction('NOW', static fn (): string => self::NOW, 0);
        $this->pdo->exec(
            "CREATE TABLE mcp_tokens (
                token_id INTEGER PRIMARY KEY,
                aiu_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL,
                refresh_token_hash TEXT NULL,
                expires_at_utc TEXT NOT NULL,
                refresh_expires_at_utc TEXT NULL
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE oauth_auth_codes (
                code_id INTEGER PRIMARY KEY,
                aiu_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                code_hash TEXT NOT NULL,
                code_challenge TEXT NOT NULL,
                expires_at_utc TEXT NOT NULL,
                used_at_utc TEXT NULL
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE oauth_client_consents (
                consent_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                aiu_id INTEGER NOT NULL,
                client_id TEXT NOT NULL,
                redirect_uri TEXT NOT NULL
            )"
        );
        $this->pdo->exec("CREATE TABLE api_keys (key_id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, aiu_id INTEGER NULL)");
        $this->pdo->exec(
            "CREATE TABLE agent_inbox_user (
                aiu_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                actor_type TEXT NOT NULL,
                description TEXT NULL,
                can_read_project INTEGER NOT NULL DEFAULT 0,
                can_write_project INTEGER NOT NULL DEFAULT 0
            )"
        );
        $this->pdo->exec("CREATE TABLE todos (todo_id INTEGER PRIMARY KEY, title TEXT NOT NULL)");

        // Token 1 is live with a live refresh; 2 has expired but can still refresh;
        // 3 is expired with an expired refresh; 4 is expired and never had one; 5 is live with none.
        $this->pdo->exec(
            "INSERT INTO mcp_tokens
                (token_id, aiu_id, user_id, token_hash, refresh_token_hash, expires_at_utc, refresh_expires_at_utc) VALUES
                (1, 11, 1, 'hash-live', 'refresh-live', '2026-06-16 00:00:00', '2026-07-01 00:00:00'),
                (2, 12, 1, 'hash-expired', 'refresh-still-good', '2026-06-14 00:00:00', '2026-07-01 00:00:00'),
                (3, 13, 2, 'hash-dead', 'refresh-dead', '2026-06-01 00:00:00', '2026-06-10 00:00:00'),
                (4, 14, 2, 'hash-dead-no-refresh', NULL, '2026-06-01 00:00:00', NULL),
                (5, 15, 2, 'hash-live-no-refresh', NULL, '2026-06-16 00:00:00', NULL)"
        );
        // Code 1 is usable, 2 has expired, 3 was used.
        $this->pdo->exec(
            "INSERT INTO oauth_auth_codes
                (code_id, aiu_id, user_id, code_hash, code_challenge, expires_at_utc, used_at_utc) VALUES
                (1, 11, 1, 'code-good', 'challenge-1', '2026-06-15 12:05:00', NULL),
                (2, 11, 1, 'code-expired', 'challenge-2', '2026-06-15 11:55:00', NULL),
                (3, 11, 1, 'code-used', 'challenge-3', '2026-06-15 12:05:00', '2026-06-15 11:59:00')"
        );
        $this->pdo->exec(
            "INSERT INTO oauth_client_consents (user_id, aiu_id, client_id, redirect_uri) VALUES
                (1, 11, 'client-a', 'https://a.example/cb'),
                (2, 21, 'client-a', 'https://a.example/cb')"
        );
        $this->pdo->exec("INSERT INTO api_keys (key_id, user_id, aiu_id) VALUES (100, 1, 11), (101, 1, NULL)");
        $this->pdo->exec(
            "INSERT INTO agent_inbox_user
                (aiu_id, user_id, name, actor_type, description, can_read_project, can_write_project) VALUES
                (10, 1, 'Rob', 'human', NULL, 1, 1),
                (11, 1, 'Zed', 'agent', 'reads', 1, 0),
                (12, 1, 'Amy', 'agent', 'writes', 0, 1),
                (13, 1, 'Nope', 'agent', NULL, 0, 0),
                (20, 2, 'Other', 'agent', NULL, 1, 1)"
        );
        $this->pdo->exec("INSERT INTO todos (todo_id, title) VALUES (3, 'third'), (1, 'first'), (2, 'second')");
    }

    /**
     * A PDO that accepts one prepare() of exactly this SQL and one execute()
     * of exactly these parameters, compared by type as well as value so a 0
     * cannot pass for a null (null: executed with none given), and reports
     * this many rows changed.
     *
     * @param list<mixed>|null $params
     */
    private function pdoExpecting(string $sql, ?array $params, int $row_count = 1): \PDO
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with($this->identicalTo($params))->willReturn(true);
        $stmt->method('rowCount')->willReturn($row_count);

        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->once())->method('prepare')->with($this->callback(
            static fn (string $given): bool => trim((string) preg_replace('/\s+/', ' ', $given)) === $sql
        ))->willReturn($stmt);
        return $pdo;
    }

    /**
     * @return list<mixed>
     */
    private function column(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        $this->assertNotFalse($stmt);
        return array_values($stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testUserAndActorOfIsOnlyForALiveAccessToken(): void
    {
        $tokens = new McpTokens($this->pdo);

        $this->assertSame(['user_id' => 1, 'aiu_id' => 11], $tokens->userAndActorOf('hash-live'));
        $this->assertNull($tokens->userAndActorOf('hash-expired'));
        $this->assertNull($tokens->userAndActorOf('refresh-live'));
        $this->assertNull($tokens->userAndActorOf('no-such-hash'));
    }

    public function testGetByRefreshHashIsTheWholeRowWhileTheRefreshIsLive(): void
    {
        $tokens = new McpTokens($this->pdo);

        $row = $tokens->getByRefreshHash('refresh-still-good');
        $this->assertNotNull($row);
        $this->assertSame([2, 12, 1], [$row['token_id'], $row['aiu_id'], $row['user_id']]);
        $this->assertArrayHasKey('token_hash', $row);

        $this->assertNull($tokens->getByRefreshHash('refresh-dead'));
        $this->assertNull($tokens->getByRefreshHash('hash-live'));
    }

    public function testRotateIsPinnedAndTrueOnlyWhenOneRowChanged(): void
    {
        $sql = 'UPDATE mcp_tokens'
            . ' SET token_hash = ?, expires_at_utc = DATE_ADD(NOW(), INTERVAL ? SECOND),'
            . ' refresh_token_hash = ?, refresh_expires_at_utc = DATE_ADD(NOW(), INTERVAL ? DAY)'
            . ' WHERE token_id = ? AND refresh_token_hash = ?';
        $params = ['new-hash', 86400, 'new-refresh', 31, 7, 'old-refresh'];

        $this->assertTrue(
            new McpTokens($this->pdoExpecting($sql, $params, 1))->rotate(7, 'old-refresh', 'new-hash', 86400, 'new-refresh', 31)
        );
        $this->assertFalse(
            new McpTokens($this->pdoExpecting($sql, $params, 0))->rotate(7, 'old-refresh', 'new-hash', 86400, 'new-refresh', 31)
        );
    }

    public function testAddTokenIsPinnedWithAndWithoutARefreshToken(): void
    {
        $sql = 'INSERT INTO mcp_tokens (aiu_id, user_id, token_hash, expires_at_utc, refresh_token_hash, refresh_expires_at_utc)'
            . ' VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, DATE_ADD(NOW(), INTERVAL ? DAY))';

        new McpTokens($this->pdoExpecting($sql, [11, 1, 'new-hash', 86400, 'new-refresh', 400]))
            ->addToken(11, 1, 'new-hash', 86400, 'new-refresh', 400);
        new McpTokens($this->pdoExpecting($sql, [11, 1, 'new-hash', 86400, null, null]))
            ->addToken(11, 1, 'new-hash', 86400, null, null);
    }

    public function testDeleteExpiredKeepsATokenThatCanStillRefresh(): void
    {
        new McpTokens($this->pdo)->deleteExpired();

        $this->assertSame([1, 2, 5], $this->column('SELECT token_id FROM mcp_tokens ORDER BY token_id'));
    }

    public function testGetUsableIsAnUnusedCodeThatHasNotExpired(): void
    {
        $codes = new AuthCodes($this->pdo);

        $row = $codes->getUsable('code-good');
        $this->assertNotNull($row);
        $this->assertSame([1, 11, 1, 'challenge-1'], [$row['code_id'], $row['aiu_id'], $row['user_id'], $row['code_challenge']]);

        $this->assertNull($codes->getUsable('code-expired'));
        $this->assertNull($codes->getUsable('code-used'));
        $this->assertNull($codes->getUsable('no-such-code'));
    }

    public function testMarkUsedClaimsACodeOnce(): void
    {
        $codes = new AuthCodes($this->pdo);

        $this->assertTrue($codes->markUsed(1));
        $this->assertSame([self::NOW], $this->column('SELECT used_at_utc FROM oauth_auth_codes WHERE code_id = 1'));
        $this->assertFalse($codes->markUsed(1));
        $this->assertFalse($codes->markUsed(3));
        $this->assertSame(['2026-06-15 11:59:00'], $this->column('SELECT used_at_utc FROM oauth_auth_codes WHERE code_id = 3'));
    }

    public function testDeleteOldCodesIsPinned(): void
    {
        new AuthCodes($this->pdoExpecting(
            'DELETE FROM oauth_auth_codes WHERE expires_at_utc < DATE_SUB(NOW(), INTERVAL 1 DAY)',
            null
        ))->deleteOld();
    }

    public function testRememberedAiuOfIsForThatUserClientAndRedirect(): void
    {
        $consents = new ClientConsents($this->pdo);

        $this->assertSame(11, $consents->rememberedAiuOf(1, 'client-a', 'https://a.example/cb'));
        $this->assertSame(21, $consents->rememberedAiuOf(2, 'client-a', 'https://a.example/cb'));
        $this->assertNull($consents->rememberedAiuOf(1, 'client-b', 'https://a.example/cb'));
        $this->assertNull($consents->rememberedAiuOf(1, 'client-a', 'https://a.example/other'));
    }

    public function testRememberIsPinned(): void
    {
        new ClientConsents($this->pdoExpecting(
            'INSERT INTO oauth_client_consents (user_id, aiu_id, client_id, redirect_uri)'
            . ' VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE aiu_id = VALUES(aiu_id), created_at_utc = NOW()',
            [1, 12, 'client-a', 'https://a.example/cb']
        ))->remember(1, 12, 'client-a', 'https://a.example/cb');
    }

    public function testAiuIdOfKeyIsZeroForAKeyWithNoActorAndForNoKey(): void
    {
        $keys = new ApiKey($this->pdo);

        $this->assertSame(11, $keys->aiuIdOfKey(100));
        $this->assertSame(0, $keys->aiuIdOfKey(101));
        $this->assertSame(0, $keys->aiuIdOfKey(999));
        $this->assertSame(0, $keys->aiuIdOfKey(null));
    }

    public function testGetGrantableAreTheAccountsProjectActorsHumanFirstThenByName(): void
    {
        $choices = new Actors($this->pdo)->getGrantable(1);

        $this->assertSame([10, 12, 11], array_column($choices, 'aiu_id'));
        $this->assertSame(['aiu_id', 'name', 'actor_type', 'description'], array_keys($choices[0]));
    }

    public function testTitlesOfAreInTodoIdOrderAndEmptyForNoIds(): void
    {
        $todos = new Todo($this->pdo);

        $this->assertSame(['first', 'third'], $todos->titlesOf([3, 1]));
        $this->assertSame([], $todos->titlesOf([]));
    }
}
