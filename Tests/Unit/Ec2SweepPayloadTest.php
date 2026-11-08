<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Ec2\SweepPayload;
use Inventory\SweepFormatException;

/**
 * Hermetic: what mg accepts from tools/ec2-sweep/deets.sh and what it
 * refuses. The fixture is one made-up server with one hostname.
 */
final class Ec2SweepPayloadTest extends Unit
{
    /**
     * One hostname's record as deets.sh prints it, with fields replaced.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function domainRecord(array $overrides = []): array
    {
        return array_replace([
            'domain' => 'example.test',
            'directory' => '/var/www/example.test/current',
            'php_version' => '8.1.2',
            'probe_route' => 'http_forwarded',
            'probe_problem' => '',
            'directory_exists' => true,
            'is_git_checkout' => true,
            'head_commit' => str_repeat('a', 40),
            'head_branch' => 'master',
            'deployed_at_utc' => '2026-11-01 10:00:00',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides merged over the fixture
     * @param array<string, mixed> $domainOverrides replaces fields of the one hostname
     * @return array<string, mixed>
     */
    private function sweep(array $overrides = [], array $domainOverrides = []): array
    {
        return array_replace([
            'ssh_alias' => 'example-ec2',
            'swept_at_utc' => '2026-11-08 01:02:03',
            'web_server' => 'Apache/2.4.52 (Ubuntu)',
            'cli_php' => '8.1.2',
            'domains' => [$this->domainRecord($domainOverrides)],
        ], $overrides);
    }

    public function testAcceptsAWellFormedDocument(): void
    {
        $payload = SweepPayload::fromArray($this->sweep());

        $this->assertSame('example-ec2', $payload->sshAlias);
        $this->assertSame('2026-11-08 01:02:03', $payload->sweptAtUtc);
        $this->assertCount(1, $payload->domains);
        $this->assertSame('8.1.2', $payload->domains[0]['php_version']);
        $this->assertSame('http_forwarded', $payload->domains[0]['probe_route']);
        $this->assertTrue($payload->domains[0]['is_git_checkout']);
    }

    public function testRefusesABodyThatIsNotAVersion(): void
    {
        $this->expectException(SweepFormatException::class);
        $this->expectExceptionMessageMatches('/is not a PHP version/');
        SweepPayload::fromArray($this->sweep([], ['php_version' => '<!DOCTYPE html>']));
    }

    public function testANullVersionIsAllowedWithAProblem(): void
    {
        $payload = SweepPayload::fromArray($this->sweep([], [
            'php_version' => null,
            'probe_route' => null,
            'probe_problem' => 'redirected to /system/',
        ]));

        $this->assertNull($payload->domains[0]['php_version']);
        $this->assertSame('redirected to /system/', $payload->domains[0]['probe_problem']);
    }

    public function testRefusesARelativeDirectory(): void
    {
        $this->expectException(SweepFormatException::class);
        $this->expectExceptionMessageMatches('/directory/');
        SweepPayload::fromArray($this->sweep([], ['directory' => 'var/www/example.test']));
    }

    public function testRefusesAnUnknownProbeRoute(): void
    {
        $this->expectException(SweepFormatException::class);
        SweepPayload::fromArray($this->sweep([], ['probe_route' => 'ftp']));
    }

    public function testRefusesAShortCommit(): void
    {
        $this->expectException(SweepFormatException::class);
        SweepPayload::fromArray($this->sweep([], ['head_commit' => 'abc1234']));
    }

    public function testRefusesTheSameHostnameTwice(): void
    {
        $sweep = $this->sweep(['domains' => [$this->domainRecord(), $this->domainRecord()]]);

        $this->expectException(SweepFormatException::class);
        $this->expectExceptionMessageMatches('/twice/');
        SweepPayload::fromArray($sweep);
    }

    public function testRefusesAFieldThatLooksLikeASecret(): void
    {
        $this->expectException(SweepFormatException::class);
        $this->expectExceptionMessageMatches('/db_password/');
        SweepPayload::fromArray($this->sweep([], ['db_password' => 'hunter2']));
    }

    public function testRefusesAMissingField(): void
    {
        $domain = $this->domainRecord();
        unset($domain['directory_exists']);
        $sweep = $this->sweep(['domains' => [$domain]]);

        $this->expectException(SweepFormatException::class);
        SweepPayload::fromArray($sweep);
    }
}
