<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

class ActivityKaiActivityIdOfTest extends Unit
{
    private \ActivityTracking\ActivityKai $kai;

    protected function _before(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE activity_kai (ak_id INTEGER PRIMARY KEY, user_id INTEGER, activity_id INTEGER)");
        $pdo->exec("INSERT INTO activity_kai VALUES (10, 1, 7)");
        $this->kai = new \ActivityTracking\ActivityKai($pdo);
    }

    public function testReturnsTheActivityIdForTheOwner(): void
    {
        $this->assertSame(7, $this->kai->activityIdOf(10, 1));
    }

    public function testReturnsNullForAnotherUser(): void
    {
        $this->assertNull($this->kai->activityIdOf(10, 2));
    }

    public function testReturnsNullForAMissingSession(): void
    {
        $this->assertNull($this->kai->activityIdOf(99, 1));
    }
}
