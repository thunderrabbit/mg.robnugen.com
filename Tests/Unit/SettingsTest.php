<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Config\EConfigInvalid;
use Config\Settings;

class SettingsTest extends Unit
{
    /** @return array<string, mixed> */
    private function minimal(): array
    {
        return [
            'dbHost' => 'mysql.example.com',
            'dbName' => 'site_db',
            'dbUser' => 'site_user',
            'dbPass' => 'correct horse battery staple',
            'domain_name' => 'example.com',
            'cookie_name' => 'example_auth',
        ];
    }

    public function testMinimalFileTakesTheShippedDefaults(): void
    {
        $settings = Settings::fromArray($this->minimal(), '/home/u/example.com');

        $this->assertSame('/home/u/example.com', $settings->app_path);
        $this->assertFalse($settings->display_errors);
        $this->assertSame(60 * 60 * 24 * 30, $settings->cookie_lifetime);
        $this->assertSame('/webhooks/stripe', $settings->stripe_webhook_endpoint);
        foreach (
            [
                'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret', 'stripe_price_developer',
                'stripe_price_growth', 'telegram_bot_token', 'telegram_chat_id', 'image_store_path',
            ] as $off_when_empty
        ) {
            $this->assertSame('', $settings->$off_when_empty, $off_when_empty);
        }
    }

    public function testEveryKeyIsAPropertyAndAppPathIsNotAKey(): void
    {
        $properties = array_keys(get_object_vars(Settings::fromArray($this->minimal(), '/r')));

        $this->assertSame($properties, ['app_path', ...Settings::KEYS]);
    }

    public function testGivenValuesReplaceTheDefaults(): void
    {
        $settings = Settings::fromArray(
            [
                'display_errors' => true,
                'cookie_lifetime' => 3600,
                'stripe_publishable_key' => 'pk_x',
                'stripe_secret_key' => 'sk_x',
                'stripe_webhook_secret' => 'whsec_x',
                'stripe_webhook_endpoint' => '/hooks/stripe',
                'stripe_price_developer' => 'price_dev',
                'stripe_price_growth' => 'price_growth',
                'telegram_bot_token' => '123:abc',
                'telegram_chat_id' => '42',
                'image_store_path' => '/home/u/files/images',
            ] + $this->minimal(),
            '/home/u/example.com',
        );

        $this->assertTrue($settings->display_errors);
        $this->assertSame(3600, $settings->cookie_lifetime);
        $this->assertSame('pk_x', $settings->stripe_publishable_key);
        $this->assertSame('sk_x', $settings->stripe_secret_key);
        $this->assertSame('whsec_x', $settings->stripe_webhook_secret);
        $this->assertSame('/hooks/stripe', $settings->stripe_webhook_endpoint);
        $this->assertSame('price_dev', $settings->stripe_price_developer);
        $this->assertSame('price_growth', $settings->stripe_price_growth);
        $this->assertSame('123:abc', $settings->telegram_bot_token);
        $this->assertSame('42', $settings->telegram_chat_id);
        $this->assertSame('/home/u/files/images', $settings->image_store_path);
    }

    public function testRefusesAMisspelledKey(): void
    {
        $this->expectException(EConfigInvalid::class);
        $this->expectExceptionMessage("unknown key: 'display_erors'");
        Settings::fromArray(['display_erors' => false] + $this->minimal(), '/r');
    }

    public function testRefusesAnAppPathFromTheFile(): void
    {
        $this->expectException(EConfigInvalid::class);
        $this->expectExceptionMessage("unknown key: 'app_path'");
        Settings::fromArray(['app_path' => '/home/u/sibling.com'] + $this->minimal(), '/r');
    }

    public function testRefusesAMissingRequiredKey(): void
    {
        $values = $this->minimal();
        unset($values['cookie_name']);

        $this->expectException(EConfigInvalid::class);
        $this->expectExceptionMessage("'cookie_name' is missing");
        Settings::fromArray($values, '/r');
    }

    public function testRefusesAnEmptyPassword(): void
    {
        $this->expectException(EConfigInvalid::class);
        $this->expectExceptionMessage("'dbPass' must not be empty");
        Settings::fromArray(['dbPass' => '  '] + $this->minimal(), '/r');
    }

    public function testRefusesAnEmptyAppPath(): void
    {
        $this->expectException(EConfigInvalid::class);
        $this->expectExceptionMessage("'app_path' must not be empty");
        Settings::fromArray($this->minimal(), '');
    }

    public function testRefusesAStringWhereABoolBelongs(): void
    {
        // 'false' is a non-empty string, so a loose check would read it as true.
        $this->expectException(EConfigInvalid::class);
        $this->expectExceptionMessage("'display_errors' must be true or false, not string");
        Settings::fromArray(['display_errors' => 'false'] + $this->minimal(), '/r');
    }

    public function testRefusesAnExplicitNullInsteadOfDefaultingIt(): void
    {
        // `??` reads a present null as absent, which handed back the default.
        $this->expectException(EConfigInvalid::class);
        $this->expectExceptionMessage("'display_errors' must be true or false, not null");
        Settings::fromArray(['display_errors' => null] + $this->minimal(), '/r');
    }

    public function testRefusesAnExplicitNullForAnOptionalString(): void
    {
        $this->expectException(EConfigInvalid::class);
        $this->expectExceptionMessage("'image_store_path' must be a string, not null");
        Settings::fromArray(['image_store_path' => null] + $this->minimal(), '/r');
    }

    public function testWrongTypeMessageLeavesTheValueOut(): void
    {
        try {
            Settings::fromArray(['dbPass' => 12345678] + $this->minimal(), '/r');
            $this->fail('An int password was accepted');
        } catch (EConfigInvalid $e) {
            $this->assertSame("Config 'dbPass' must be a string, not int", $e->getMessage());
        }
    }

    public function testRefusesACookieNameThatIsNotAToken(): void
    {
        $this->expectException(EConfigInvalid::class);
        $this->expectExceptionMessage("'cookie_name' must be letters");
        Settings::fromArray(['cookie_name' => 'a;b'] + $this->minimal(), '/r');
    }

    public function testAcceptsACookieLifetimeAtEachEnd(): void
    {
        $short = Settings::fromArray(['cookie_lifetime' => 60] + $this->minimal(), '/r');
        $long = Settings::fromArray(
            ['cookie_lifetime' => Settings::MAX_COOKIE_LIFETIME] + $this->minimal(),
            '/r',
        );

        $this->assertSame(60, $short->cookie_lifetime);
        $this->assertSame(Settings::MAX_COOKIE_LIFETIME, $long->cookie_lifetime);
    }

    public function testRefusesALifetimeWrittenAsAString(): void
    {
        $this->expectException(EConfigInvalid::class);
        $this->expectExceptionMessage("'cookie_lifetime' must be a whole number, not string");
        Settings::fromArray(['cookie_lifetime' => '3600'] + $this->minimal(), '/r');
    }

    public function testRefusesACookieLifetimeUnderAMinute(): void
    {
        $this->expectException(EConfigInvalid::class);
        $this->expectExceptionMessage("'cookie_lifetime' must be between");
        Settings::fromArray(['cookie_lifetime' => 59] + $this->minimal(), '/r');
    }

    public function testRefusesACookieLifetimeOver400Days(): void
    {
        $this->expectException(EConfigInvalid::class);
        $this->expectExceptionMessage("'cookie_lifetime' must be between");
        Settings::fromArray(
            ['cookie_lifetime' => Settings::MAX_COOKIE_LIFETIME + 1] + $this->minimal(),
            '/r',
        );
    }

    public function testCannotBeChangedOnceBuilt(): void
    {
        $class = new \ReflectionClass(Settings::class);

        // A readonly class: PHP refuses any write to any property after the
        // constructor. Final, so no subclass can add one that is writable.
        $this->assertTrue($class->isReadOnly());
        $this->assertTrue($class->isFinal());
        foreach ($class->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), $property->getName());
        }
    }

    public function testDumpHidesThePassword(): void
    {
        $dump = print_r(Settings::fromArray([
            'stripe_secret_key' => 'sk_live_hush',
            'stripe_webhook_secret' => 'whsec_hush',
            'telegram_bot_token' => '123:token_hush',
        ] + $this->minimal(), '/r'), true);

        $this->assertStringNotContainsString('correct horse', $dump);
        $this->assertStringNotContainsString('hush', $dump);
        $this->assertStringContainsString('(hidden)', $dump);
        $this->assertStringContainsString('site_user', $dump);
    }
}
