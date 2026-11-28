<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Config\EConfigInvalid;
use Config\Loader;

class LoaderTest extends Unit
{
    private string $root;

    protected function _before(): void
    {
        $this->root = sys_get_temp_dir() . '/loader-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0700);
    }

    protected function _after(): void
    {
        array_map(unlink(...), glob($this->root . '/*') ?: []);
        rmdir($this->root);
    }

    private function siteConfig(string $php): void
    {
        file_put_contents($this->root . '/' . Loader::FILE, $php);
    }

    public function testReadsSiteConfigAndDerivesAppPath(): void
    {
        $this->siteConfig("<?php return [
            'dbHost' => 'h', 'dbName' => 'n', 'dbUser' => 'u', 'dbPass' => 'p',
            'domain_name' => 'example.com', 'cookie_name' => 'auth',
            'image_store_path' => '/home/u/files',
        ];");

        $settings = Loader::load($this->root);

        $this->assertSame($this->root, $settings->app_path);
        $this->assertSame('example.com', $settings->domain_name);
        $this->assertSame('/home/u/files', $settings->image_store_path);
    }

    public function testRefusesASiteConfigThatIsNotAnArray(): void
    {
        $this->siteConfig('<?php $dbPass = "p";');

        $this->expectException(EConfigInvalid::class);
        $this->expectExceptionMessage('site_config.php must return an array');
        Loader::load($this->root);
    }

    public function testATypoNamesTheLineButNotTheCode(): void
    {
        $this->siteConfig("<?php return [\n'dbPass' => 'hunter2' 'dbUser' => 'u',\n];");

        try {
            Loader::load($this->root);
            $this->fail('A file that does not parse was accepted');
        } catch (EConfigInvalid $e) {
            $this->assertStringContainsString('does not parse, line 2', $e->getMessage());
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }

    public function testSaysWhatIsMissingWhenThereIsNoFile(): void
    {
        $this->expectException(EConfigInvalid::class);
        $this->expectExceptionMessage('No site_config.php in ' . $this->root);
        Loader::load($this->root);
    }

    public function testFallsBackToTheOldConfigFile(): void
    {
        // Declares \Config\Settings, so it can run once per process: this is that once.
        mkdir($this->root . '/classes', 0700, true);
        file_put_contents($this->root . '/' . Loader::LEGACY_FILE, '<?php
            class Config {
                public string $domain_name = "old.example.com";
                public string $cookie_name = "old_auth";
                public string $app_path = "/home/user/sub.domain.com";
                public string $dbHost = "h";
                public string $dbName = "n";
                public string $dbUser = "u";
                public string $dbPass = "p";
            }');

        try {
            $settings = Loader::load($this->root);
        } finally {
            unlink($this->root . '/' . Loader::LEGACY_FILE);
            rmdir($this->root . '/classes');
        }

        $this->assertSame('old.example.com', $settings->domain_name);
        $this->assertSame($this->root, $settings->app_path);
    }

    public function testLegacyConfigKeepsItsValuesAndLosesItsAppPath(): void
    {
        $legacy = new class {
            public string $stripe_price_growth = 'price_old';
            public bool $display_errors = true;
            public string $domain_name = 'example.com';
            public string $cookie_name = 'auth';
            public int $cookie_lifetime = 3600;
            public string $app_path = '/home/user/sibling.com';
            public string $dbHost = 'h';
            public string $dbName = 'n';
            public string $dbUser = 'u';
            public string $dbPass = 'p';
        };

        $settings = Loader::fromLegacy($legacy, '/home/user/example.com');

        $this->assertSame('/home/user/example.com', $settings->app_path);
        $this->assertSame('price_old', $settings->stripe_price_growth);
        $this->assertTrue($settings->display_errors);
        $this->assertSame(3600, $settings->cookie_lifetime);
    }

    public function testLegacyConfigWithoutNewerPropertiesGetsTheirDefaults(): void
    {
        // Older than display_errors and image_store_path: errors stay hidden
        // and issue images stay off.
        $legacy = new class {
            public string $domain_name = 'example.com';
            public string $cookie_name = 'auth';
            public string $dbHost = 'h';
            public string $dbName = 'n';
            public string $dbUser = 'u';
            public string $dbPass = 'p';
        };

        $settings = Loader::fromLegacy($legacy, '/r');

        $this->assertFalse($settings->display_errors);
        $this->assertSame('', $settings->image_store_path);
    }

    public function testLegacyConfigWithAKeySettingsDoesNotKnowStillLoads(): void
    {
        // The old leniency, kept until the server converts: the new file
        // would be refused for the same key.
        $legacy = new class {
            public string $domain_name = 'example.com';
            public string $cookie_name = 'auth';
            public string $dbHost = 'h';
            public string $dbName = 'n';
            public string $dbUser = 'u';
            public string $dbPass = 'p';
            public string $left_over = 'x';
        };

        $this->assertSame('auth', Loader::fromLegacy($legacy, '/r')->cookie_name);
    }

    public function testLegacyConfigWithAnEmptyCookieNameStopsTheSite(): void
    {
        // ConfigSample shipped cookie_name = ''; a site that never set it fails closed.
        $legacy = new class {
            public string $domain_name = 'example.com';
            public string $cookie_name = '';
            public string $dbHost = 'h';
            public string $dbName = 'n';
            public string $dbUser = 'u';
            public string $dbPass = 'p';
        };

        $this->expectException(EConfigInvalid::class);
        $this->expectExceptionMessage("'cookie_name' must be letters");
        Loader::fromLegacy($legacy, '/r');
    }
}
