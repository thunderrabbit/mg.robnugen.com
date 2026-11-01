<?php

declare(strict_types=1);

namespace Tests\Unit;

use ActivityTracking\ActivityKai;
use ActivityTracking\SessionKey;
use Codeception\Test\Unit;

class BackfillDuplicateTest extends Unit
{
    private \PDO $pdo;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE activity_kai (
                ak_id INTEGER PRIMARY KEY, user_id INTEGER, activity_id INTEGER,
                start_local_dt TEXT, actual_sec INTEGER
            )"
        );
        $this->pdo->exec("CREATE TABLE activity_session_keys (session_key TEXT PRIMARY KEY, ak_id INTEGER)");
        $this->pdo->exec("INSERT INTO activity_kai VALUES (5, 1, 1, '2026-11-01 06:00:00', 1200)");
        $this->pdo->exec("INSERT INTO activity_session_keys VALUES ('abcdefghijk', 5)");
    }

    public function testFindsExactMatch(): void
    {
        $this->assertSame(5, (new ActivityKai($this->pdo))->findBackfill(1, 1, '2026-11-01 06:00:00', 1200));
    }

    public function testDifferentDurationIsNotADuplicate(): void
    {
        $this->assertNull((new ActivityKai($this->pdo))->findBackfill(1, 1, '2026-11-01 06:00:00', 1199));
    }

    public function testOtherUserIsNotADuplicate(): void
    {
        $this->assertNull((new ActivityKai($this->pdo))->findBackfill(2, 1, '2026-11-01 06:00:00', 1200));
    }

    public function testKeyForReturnsTheKeyOrNull(): void
    {
        $keys = new SessionKey($this->pdo);
        $this->assertSame('abcdefghijk', $keys->keyFor(5));
        $this->assertNull($keys->keyFor(6));
    }
}
