<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\ServerChoice;

/**
 * Hermetic: which server the scanner asks, with made-up domains.
 */
final class StandardsServerChoiceTest extends Unit
{
    /**
     * @return array{dh_site_domain_id: int, domain: string, path_prefix: string, web_directory: ?string, ssh_alias: ?string}
     */
    private function link(int $id, string $domain, ?string $directory, ?string $alias = 'example', string $path = ''): array
    {
        return [
            'dh_site_domain_id' => $id,
            'domain' => $domain,
            'path_prefix' => $path,
            'web_directory' => $directory,
            'ssh_alias' => $alias,
        ];
    }

    public function testOneDomainIsTheServer(): void
    {
        $choice = ServerChoice::pick([$this->link(1, 'example.test', 'example.test/wwwroot')], null);

        $this->assertSame('example', $choice->sshAlias);
        $this->assertSame('example.test/wwwroot', $choice->directory);
        $this->assertNull($choice->problem);
    }

    public function testDomainsSharingOneDirectoryNeedNoName(): void
    {
        $choice = ServerChoice::pick([
            $this->link(1, 'example.test', 'example.test/wwwroot'),
            $this->link(2, 'www.example.test', 'example.test/wwwroot'),
        ], null);

        $this->assertSame('example.test/wwwroot', $choice->directory);
    }

    public function testDomainsWithDifferentCheckoutsMustNameTheServer(): void
    {
        $links = [
            $this->link(1, 'example.test', 'example.test/wwwroot'),
            $this->link(2, 'staging.example.test', 'staging.example.test/wwwroot'),
        ];

        $unnamed = ServerChoice::pick($links, null);
        $this->assertNull($unnamed->sshAlias);
        $this->assertStringContainsString('--server=', (string) $unnamed->problem);

        $named = ServerChoice::pick($links, 1);
        $this->assertSame('example.test/wwwroot', $named->directory);
        $this->assertNull($named->problem);
    }

    public function testAPathInsideADomainIsPartOfTheDirectory(): void
    {
        $choice = ServerChoice::pick([$this->link(3, 'example.test', 'example.test/wwwroot', 'example', '/blog')], null);

        $this->assertSame('example.test/wwwroot/blog', $choice->directory);
    }

    public function testNoDomainMeansNoServer(): void
    {
        $this->assertSame('no domain is linked to it', ServerChoice::pick([], null)->problem);
    }

    public function testANamedServerThatIsNotLinkedIsAProblem(): void
    {
        $choice = ServerChoice::pick([$this->link(1, 'example.test', 'example.test/wwwroot')], 9);

        $this->assertNull($choice->directory);
        $this->assertNotNull($choice->problem);
    }

    public function testMissingAliasOrDirectorySaysWhich(): void
    {
        $noAlias = ServerChoice::pick([$this->link(1, 'example.test', 'example.test/wwwroot', null)], null);
        $this->assertStringContainsString('ssh alias', (string) $noAlias->problem);

        $noDirectory = ServerChoice::pick([$this->link(1, 'example.test', null)], null);
        $this->assertStringContainsString('directory', (string) $noDirectory->problem);
    }
}
