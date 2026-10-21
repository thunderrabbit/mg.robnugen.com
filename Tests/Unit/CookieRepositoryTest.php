<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

/**
 * In-memory SQLite: hermetic, no database server.
 */
class CookieRepositoryTest extends Unit
{
    private const DAY = 86400;
    private const UA = 'd41d8cd98f00b204e9800998ecf8427e';

    private \PDO $pdo;
    private \DateTimeImmutable $t0;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE cookies (
                cookie_id INTEGER PRIMARY KEY AUTOINCREMENT,
                cookie TEXT NOT NULL UNIQUE,
                user_id INTEGER NOT NULL,
                created_at TEXT,
                last_access TEXT,
                expires_at TEXT NOT NULL,
                user_agent_md5 TEXT
            )"
        );
        $this->t0 = new \DateTimeImmutable('2026-10-21 12:00:00');
    }

    private function at(int $seconds_after_t0): \Database\CookieRepository
    {
        return new \Database\CookieRepository($this->pdo, $this->t0->modify("+{$seconds_after_t0} seconds"));
    }

    private function rowCount(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM cookies");
        $count = $stmt === false ? false : $stmt->fetchColumn();
        if (!is_int($count)) {
            $this->fail("COUNT query failed");
        }
        return $count;
    }

    public function testIssuedCookieIsFoundForTheSameBrowser(): void
    {
        $repo = $this->at(0);
        $repo->issue(7, 'hash-a', self::UA, 30 * self::DAY);
        $this->assertSame(7, $repo->findUserId('hash-a', self::UA));
    }

    public function testDifferentBrowserOrUnknownHashIsNobody(): void
    {
        $repo = $this->at(0);
        $repo->issue(7, 'hash-a', self::UA, 30 * self::DAY);
        $this->assertSame(0, $repo->findUserId('hash-a', str_repeat('0', 32)), 'other browser');
        $this->assertSame(0, $repo->findUserId('hash-zzz', self::UA), 'unknown');
    }

    public function testCookieStopsWorkingWhenItExpires(): void
    {
        $this->at(0)->issue(7, 'hash-a', self::UA, 30 * self::DAY);
        $this->assertSame(7, $this->at(30 * self::DAY - 1)->findUserId('hash-a', self::UA));
        $this->assertSame(0, $this->at(30 * self::DAY)->findUserId('hash-a', self::UA), 'at expiry');
        $this->assertSame(0, $this->at(31 * self::DAY)->findUserId('hash-a', self::UA), 'after');
    }

    public function testWhyRefusedNamesTheReason(): void
    {
        $this->at(0)->issue(7, 'hash-a', self::UA, self::DAY);
        $this->assertSame(
            ['reason' => 'user agent mismatch', 'user_id' => 7],
            $this->at(0)->whyRefused('hash-a', str_repeat('0', 32))
        );
        $this->assertSame(
            ['reason' => 'expired', 'user_id' => 7],
            $this->at(2 * self::DAY)->whyRefused('hash-a', self::UA)
        );
        $this->assertSame(
            ['reason' => 'not found', 'user_id' => 0],
            $this->at(0)->whyRefused('hash-zzz', self::UA)
        );
    }

    public function testIssuingPurgesExpiredRows(): void
    {
        $this->at(0)->issue(7, 'old', self::UA, self::DAY);
        $this->at(0)->issue(7, 'fresh', self::UA, 30 * self::DAY);
        $this->at(2 * self::DAY)->issue(8, 'newest', self::UA, 30 * self::DAY);
        $this->assertSame(2, $this->rowCount(), 'old is gone, fresh and newest remain');
    }

    public function testRevokeRemovesOneCookie(): void
    {
        $repo = $this->at(0);
        $repo->issue(7, 'hash-a', self::UA, self::DAY);
        $repo->issue(7, 'hash-b', self::UA, self::DAY);
        $repo->revoke('hash-a');
        $this->assertSame(0, $repo->findUserId('hash-a', self::UA));
        $this->assertSame(7, $repo->findUserId('hash-b', self::UA));
    }

    public function testRevokeAllKeepsTheCookieInHand(): void
    {
        $repo = $this->at(0);
        $repo->issue(7, 'phone', self::UA, self::DAY);
        $repo->issue(7, 'laptop', self::UA, self::DAY);
        $repo->issue(7, 'tablet', self::UA, self::DAY);
        $repo->issue(9, 'someone-else', self::UA, self::DAY);

        $this->assertSame(2, $repo->revokeAllForUser(7, 'laptop'));
        $this->assertSame(7, $repo->findUserId('laptop', self::UA), 'current browser stays in');
        $this->assertSame(0, $repo->findUserId('phone', self::UA));
        $this->assertSame(9, $repo->findUserId('someone-else', self::UA), 'other users untouched');
    }

    public function testRevokeAllWithoutAKeepSignsOutEverywhere(): void
    {
        $repo = $this->at(0);
        $repo->issue(7, 'phone', self::UA, self::DAY);
        $repo->issue(7, 'laptop', self::UA, self::DAY);
        $this->assertSame(2, $repo->revokeAllForUser(7));
        $this->assertSame(0, $this->rowCount());
    }
}
