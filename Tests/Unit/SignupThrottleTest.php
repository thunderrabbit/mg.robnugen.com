<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

class SignupThrottleTest extends Unit
{
    private \PDO $pdo;
    private \DateTimeImmutable $t0;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE login_attempts (
                login_attempt_id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                ip_address BLOB,
                attempted_at TEXT NOT NULL
            )"
        );
        $this->t0 = new \DateTimeImmutable('2026-10-21 12:00:00');
    }

    private function at(int $seconds): \Auth\SignupThrottle
    {
        return new \Auth\SignupThrottle($this->pdo, $this->t0->modify("+{$seconds} seconds"));
    }

    public function testThreeSignupsFromOneIpThenRefused(): void
    {
        for ($i = 0; $i < \Auth\SignupThrottle::MAX_PER_IP; $i++) {
            $this->assertFalse($this->at($i)->isThrottled('203.0.113.7'));
            $this->at($i)->recordSignup('203.0.113.7');
        }
        $this->assertTrue($this->at(10)->isThrottled('203.0.113.7'));
        $this->assertFalse($this->at(10)->isThrottled('198.51.100.1'), 'another IP is unaffected');
    }

    public function testManyIpsHitTheOverallCap(): void
    {
        for ($i = 0; $i < \Auth\SignupThrottle::MAX_TOTAL; $i++) {
            $this->at($i)->recordSignup("198.51.100.{$i}");
        }
        $this->assertTrue($this->at(20)->isThrottled('203.0.113.99'));
    }

    public function testTheWindowPasses(): void
    {
        for ($i = 0; $i < \Auth\SignupThrottle::MAX_PER_IP; $i++) {
            $this->at(0)->recordSignup('203.0.113.7');
        }
        $this->assertFalse($this->at(\Auth\SignupThrottle::WINDOW_SECONDS + 1)->isThrottled('203.0.113.7'));
    }

    public function testSignupsDoNotTouchALoginBucket(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->at(0)->recordSignup('203.0.113.7');
        }
        $login = new \Auth\LoginThrottle($this->pdo, $this->t0);
        $this->assertFalse($login->isThrottled('signup', '198.51.100.1'));
    }
}
