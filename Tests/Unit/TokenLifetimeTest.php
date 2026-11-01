<?php

declare(strict_types=1);

namespace Tests\Unit;

use Auth\TokenLifetime;
use Codeception\Test\Unit;

class TokenLifetimeTest extends Unit
{
    public function testRefreshDays(): void
    {
        $this->assertSame(31, TokenLifetime::refreshDays(['todos_limited' => 1]));
        $this->assertSame(400, TokenLifetime::refreshDays(['todos_limited' => 0]));
        $this->assertSame(400, TokenLifetime::refreshDays([]));
    }
}
