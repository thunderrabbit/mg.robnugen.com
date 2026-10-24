<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\SweepFormatException;
use Inventory\SweepPayload;

/**
 * Hermetic: what DreamHost inventory (mg #775) accepts from a sweep and what it
 * refuses. The fixture is a tiny made-up account in the shape sweep.py writes.
 */
final class SweepPayloadTest extends Unit
{
    /**
     * @return array<string, mixed>
     */
    private function file(string $key, mixed $records, string $extra = 'count', int $count = 0): array
    {
        return [
            'source' => 'test',
            'account' => ['account_id' => '1', 'name' => 'Test Account'],
            'swept_at' => '2026-10-24T20:55+10:30',
            'swept_by' => 'sweep.py',
            $extra => $count,
            $key => $records,
        ];
    }

    /**
     * @param array<string, mixed> $overrides merged over the fixture, recursively
     * @return array<mixed>
     */
    private function sweep(array $overrides = []): array
    {
        $base = [
            'swept_on' => '2026-10-24',
            'source_dir' => 'sweeps/2026-10-24',
            'files' => [
                'users' => $this->file('users', [
                    ['user' => 'alice', 'panel_order_newest_first' => 1, 'domain_count' => 1,
                        'domains' => ['Alice.Example.com'], 'access' => 'ssh', 'shell' => 'bash',
                        'home' => '/home/alice', 'disk_bytes' => 100],
                    ['user' => 'bob', 'panel_order_newest_first' => 2, 'domain_count' => 0,
                        'domains' => [], 'access' => 'sftp', 'shell' => '/usr/lib/sftp-server',
                        'home' => '/home/bob', 'disk_bytes' => null],
                ], 'count', 2),
                'domains' => $this->file('domains', [
                    ['domain' => 'alice.example.com', 'type' => 'hosted', 'server' => 's1', 'php' => '8.5',
                        'registered_here_expires' => null, 'user' => 'alice',
                        'web_directory' => 'alice.example.com/wwwroot', 'https' => 'lets_encrypt',
                        'cert_expires' => '2026-12-01'],
                    ['domain' => 'example.org', 'type' => 'dns_only', 'server' => null, 'php' => null,
                        'registered_here_expires' => '2026-12-03', 'user' => null,
                        'web_directory' => null, 'https' => 'none', 'cert_expires' => null],
                ], 'count', 2),
                'registrations' => $this->file('registrations', [
                    ['domain' => 'example.org', 'registry' => 'enom', 'created' => '2001-12-03',
                        'expires' => '2026-12-03', 'auto_renew' => true, 'auto_renew_on' => '2026-11-02',
                        'transfer_lock' => true, 'privacy' => true, 'status' => 'active', 'hidden' => false],
                ], 'count', 1),
                'databases' => $this->file('databases', [
                    ['database' => 'blog', 'server' => 'srv:one', 'size' => '1 MB', 'size_bytes' => 1048576,
                        'description' => 'MySQL Database', 'db_users' => ['alice']],
                ], 'count', 1) + ['servers' => ['srv:one' => ['hostnames' => ['db.example.com']]]],
                'crons' => $this->file('crons', [
                    ['user' => 'alice', 'server' => 's1', 'title' => 'build', 'active' => true,
                        'output_emailed_to' => 'build@example.com'],
                ], 'count', 1),
                'buckets' => $this->file('objects_users', [
                    ['user' => 'alice', 'endpoint' => 's3.example', 'keys' => 1, 'buckets' => 2,
                        'bucket_names' => ['a', 'B']],
                ], 'bucket_total', 2),
            ],
        ];
        return array_replace_recursive($base, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function refuses(string $message, array $overrides): void
    {
        try {
            SweepPayload::fromArray($this->sweep($overrides));
        } catch (SweepFormatException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            return;
        }
        $this->fail("Expected the sweep to be refused with: {$message}");
    }

    public function testAValidSweepIsReducedToWhatMgStores(): void
    {
        $sweep = SweepPayload::fromArray($this->sweep());

        $this->assertSame('1', $sweep->panelAccountId);
        $this->assertSame('Test Account', $sweep->accountName);
        $this->assertSame('2026-10-24 10:25:00', $sweep->sweptAtUtc);
        $this->assertSame('alice', $sweep->users[0]['username']);
        $this->assertNull($sweep->users[1]['disk_bytes']);
        $this->assertSame('alice.example.com', $sweep->domains[0]['domain']);
        $this->assertSame('8.5', $sweep->domains[0]['php_version']);
        $this->assertSame(['db.example.com'], $sweep->servers[0]['hostnames']);
        $this->assertSame(['a', 'B'], $sweep->objectsUsers[0]['buckets']);
        $this->assertSame(1, $sweep->objectsUsers[0]['key_count']);
        $this->assertSame('build@example.com', $sweep->crons[0]['output_emailed_to']);
    }

    public function testRefusesAFieldNamedLikeASecret(): void
    {
        $this->refuses("field 'db_password' is named like a secret", [
            'files' => ['databases' => ['databases' => [0 => ['db_password' => 'x']]]],
        ]);
    }

    public function testServerNamesAreDataNotFieldNames(): void
    {
        $sweep = SweepPayload::fromArray($this->sweep([
            'files' => ['databases' => ['servers' => ['srv:one' => ['hostnames' => ['db.example.com']]]]],
        ]));
        $this->assertSame('srv:one', $sweep->servers[0]['name']);
    }

    public function testRefusesAKeyCarriedInAValue(): void
    {
        $this->refuses('carries a key, token or password', [
            'files' => ['databases' => ['databases' => [0 => ['description' => 'https://x.example/login?key=abc']]]],
        ]);
    }

    public function testRefusesFilesFromDifferentAccounts(): void
    {
        $this->refuses('different accounts', [
            'files' => ['crons' => ['account' => ['account_id' => '2']]],
        ]);
    }

    public function testRefusesFilesFromDifferentSweeps(): void
    {
        $this->refuses('different sweeps', [
            'files' => ['crons' => ['swept_at' => '2026-10-25T09:00+10:30']],
        ]);
    }

    public function testRefusesAHostedSiteWithNoOwner(): void
    {
        $this->refuses("hosted domain 'alice.example.com' has no owner", [
            'files' => ['domains' => ['domains' => [0 => ['user' => null]]]],
        ]);
    }

    public function testRefusesAnOwnerWhoDoesNotListTheirSite(): void
    {
        $this->refuses('does not list it', [
            'files' => ['users' => ['users' => [0 => ['domains' => ['other.example.com']]]]],
        ]);
    }

    public function testRefusesAUserListingASiteThatIsNotHosted(): void
    {
        $this->refuses("user 'bob' lists 'example.org'", [
            'files' => ['users' => ['users' => [1 => ['domains' => ['example.org'], 'domain_count' => 1]]]],
        ]);
    }

    public function testRefusesAShellThatAccessDoesNotImply(): void
    {
        $this->refuses("ssh users have shell 'bash'", [
            'files' => ['users' => ['users' => [0 => ['shell' => 'zsh']]]],
        ]);
    }

    public function testRefusesACountThatDoesNotAddUp(): void
    {
        $this->refuses('says 3 users, the file holds 2', [
            'files' => ['users' => ['count' => 3]],
        ]);
    }

    public function testRefusesACronForAnUnknownUser(): void
    {
        $this->refuses("user 'zed' is not in users", [
            'files' => ['crons' => ['crons' => [0 => ['user' => 'zed']]]],
        ]);
    }

    public function testRefusesADatabaseOnAnUnknownServer(): void
    {
        $this->refuses("unknown server 'nope'", [
            'files' => ['databases' => ['databases' => [0 => ['server' => 'nope']]]],
        ]);
    }

    public function testRefusesAMissingField(): void
    {
        $this->expectException(SweepFormatException::class);
        $data = $this->sweep();
        unset($data['swept_on']);
        SweepPayload::fromArray($data);
    }

    public function testBaseDomainIsTheRegistrablePart(): void
    {
        $this->assertSame('chatforest.com', SweepPayload::baseDomain('games.chatforest.com'));
        $this->assertSame('chatforest.com', SweepPayload::baseDomain('chatforest.com'));
        $this->assertSame('example.co.jp', SweepPayload::baseDomain('www.example.co.jp'));
        $this->assertSame('localhost', SweepPayload::baseDomain('localhost'));
    }
}
