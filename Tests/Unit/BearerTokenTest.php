<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

class BearerTokenTest extends Unit
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
                token_hash TEXT, expires_at_utc TEXT
            )"
        );
        $this->pdo->exec("INSERT INTO agent_inbox_user VALUES (70, 1, 'BG Bells')");
        $future = gmdate('Y-m-d H:i:s', time() + 3600);
        $past = gmdate('Y-m-d H:i:s', time() - 3600);
        $insert = $this->pdo->prepare(
            "INSERT INTO mcp_tokens (aiu_id, user_id, token_hash, expires_at_utc) VALUES (?, ?, ?, ?)"
        );
        $insert->execute([70, 1, hash('sha256', 'abc'), $future]);
        $insert->execute([70, 1, hash('sha256', 'old'), $past]);
        $insert->execute([70, 2, hash('sha256', 'stray'), $future]);
    }

    public function testFromHeadersReadsBothVariants(): void
    {
        $bearer = new \Auth\BearerToken($this->pdo);
        $this->assertSame('abc', $bearer->fromHeaders(['HTTP_AUTHORIZATION' => 'Bearer abc']));
        $this->assertSame('abc', $bearer->fromHeaders(['REDIRECT_HTTP_AUTHORIZATION' => 'bearer abc']));
        $this->assertNull($bearer->fromHeaders(['HTTP_AUTHORIZATION' => 'Basic x']));
        $this->assertNull($bearer->fromHeaders([]));
    }

    public function testResolvesLiveToken(): void
    {
        $row = (new \Auth\BearerToken($this->pdo))->resolve('abc');
        $this->assertSame(['token_id' => 1, 'user_id' => 1, 'aiu_id' => 70], $row);
    }

    public function testExpiredTokenIsNull(): void
    {
        $this->assertNull((new \Auth\BearerToken($this->pdo))->resolve('old'));
    }

    public function testTokenWhoseActorBelongsToAnotherUserIsNull(): void
    {
        $this->assertNull((new \Auth\BearerToken($this->pdo))->resolve('stray'));
    }

    public function testUnknownTokenIsNull(): void
    {
        $this->assertNull((new \Auth\BearerToken($this->pdo))->resolve('nope'));
    }
}
