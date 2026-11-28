<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

class SensitiveParametersTest extends Unit
{
    /**
     * @return array<string, array{class-string, string, string}>
     */
    public static function secretParameters(): array
    {
        return [
            'login password' => [\Auth\IsLoggedIn::class, 'attemptPasswordLogin', 'password'],
            'hash check password' => [\Auth\IsLoggedIn::class, 'checkPHPHashedPassword', 'password'],
            'raw login cookie' => [\Auth\IsLoggedIn::class, 'getUserIdForCookieInDatabase', 'cookie'],
            'hashed password' => [\Auth\PasswordHash::class, 'make', 'password'],
            'policy password' => [\Auth\PasswordPolicy::class, 'problems', 'password'],
            'verified password' => [\Database\PasswordRepository::class, 'verifyPassword', 'password'],
            'current password' => [\Database\PasswordRepository::class, 'changePassword', 'current_password'],
            'new password' => [\Database\PasswordRepository::class, 'changePassword', 'new_password'],
            'database password' => [\Config\Settings::class, '__construct', 'dbPass'],
            'Stripe secret key' => [\Config\Settings::class, '__construct', 'stripe_secret_key'],
            'Stripe webhook secret' => [\Config\Settings::class, '__construct', 'stripe_webhook_secret'],
            'Telegram bot token' => [\Config\Settings::class, '__construct', 'telegram_bot_token'],
        ];
    }

    /**
     * @dataProvider secretParameters
     * @param class-string $class
     */
    public function testParameterIsSensitive(string $class, string $method, string $param): void
    {
        $found = false;
        foreach (new \ReflectionMethod($class, $method)->getParameters() as $p) {
            if ($p->getName() === $param) {
                $found = true;
                $this->assertCount(1, $p->getAttributes(\SensitiveParameter::class));
            }
        }
        $this->assertTrue($found, "$class::$method has no \$$param");
    }
}
