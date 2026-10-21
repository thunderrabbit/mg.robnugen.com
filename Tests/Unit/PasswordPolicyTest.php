<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

class PasswordPolicyTest extends Unit
{
    public function testTwelveCharactersOfAnythingIsEnough(): void
    {
        foreach (['correct horse', 'aaaaaaaaaaaa', 'ロブロブロブロブロブロブ', '123456789012'] as $password) {
            $this->assertSame('', \Auth\PasswordPolicy::problem($password), $password);
        }
    }

    public function testShorterPasswordsAreRefused(): void
    {
        foreach (['', 'short', 'P@ssw0rd!', '12345678901'] as $password) {
            $this->assertStringContainsString('12', \Auth\PasswordPolicy::problem($password), $password);
        }
    }
}
