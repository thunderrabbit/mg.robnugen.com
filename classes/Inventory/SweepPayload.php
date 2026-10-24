<?php

declare(strict_types=1);

namespace Inventory;

/**
 * One DreamHost sweep (the six JSON files of sweeps/YYYY-MM-DD/ in the
 * dreamhost-inventory repo), checked and reduced to exactly what mg stores.
 * Built from the decoded JSON; nothing here touches a database.
 *
 * Refuses, by throwing SweepFormatException, anything it cannot vouch for: a
 * field named like a secret, files from different accounts or sweeps, counts
 * that don't add up, a hosted site with no owner or an owner who lists a site
 * that isn't hosted.
 *
 * @phpstan-type UserRow array{username: string, access: string, disk_bytes: int|null}
 * @phpstan-type DomainRow array{domain: string, type: string, username: string|null, server: string|null, php_version: string|null, web_directory: string|null, https: string, cert_expires: string|null}
 * @phpstan-type RegistrationRow array{domain: string, registry: string|null, created: string|null, expires: string, auto_renew: bool, auto_renew_on: string|null, transfer_lock: bool, privacy: bool, status: string, hidden: bool}
 * @phpstan-type ServerRow array{name: string, hostnames: list<string>}
 * @phpstan-type DatabaseRow array{name: string, server: string, size_bytes: int, description: string|null, db_users: list<string>}
 * @phpstan-type CronRow array{username: string, title: string, active: bool, output_emailed_to: string|null}
 * @phpstan-type ObjectsUserRow array{username: string, endpoint: string, key_count: int, buckets: list<string>}
 */
final readonly class SweepPayload
{
    public const array FILES = ['domains', 'users', 'registrations', 'databases', 'crons', 'buckets'];

    private const array ACCESS = ['ssh', 'sftp', 'ftp'];
    private const array SHELL_FOR_ACCESS = ['ssh' => 'bash', 'sftp' => '/usr/lib/sftp-server'];
    private const array DOMAIN_TYPES = ['hosted', 'redirect', 'mirror', 'dns', 'dns_only', 'parked'];
    private const array HTTPS = ['lets_encrypt', 'temporary', 'none'];

    /** Field names that look like secrets, except the two allowed below. */
    private const string SECRET_NAME = '/pass|secret|key|token|hash|salt|cookie|session|email|phone/i';
    /** `keys` is a count (validated as an int); output_emailed_to is one of Rob's own mailboxes. */
    private const array ALLOWED_NAMES = ['keys', 'output_emailed_to'];
    private const string SECRET_IN_VALUE = '/[?&;](key|token|pass(word)?|secret|auth)=/i';

    /** Registrable domains with a two-label public suffix; the account has none yet. */
    private const array TWO_LABEL_SUFFIXES = ['co.uk', 'com.au', 'co.jp', 'or.jp', 'ne.jp', 'ac.jp', 'co.nz', 'com.br'];

    /**
     * @param list<UserRow> $users
     * @param list<DomainRow> $domains
     * @param list<RegistrationRow> $registrations
     * @param list<ServerRow> $servers
     * @param list<DatabaseRow> $databases
     * @param list<CronRow> $crons
     * @param list<ObjectsUserRow> $objectsUsers
     */
    public function __construct(
        public string $panelAccountId,
        public string $accountName,
        public string $sweptOn,
        public string $sweptAtUtc,
        public string $sourceDir,
        public array $users,
        public array $domains,
        public array $registrations,
        public array $servers,
        public array $databases,
        public array $crons,
        public array $objectsUsers,
    ) {
    }

    /**
     * @param array<mixed> $data {swept_on, source_dir, files: {domains: ..., users: ..., ...}}
     */
    public static function fromArray(array $data): self
    {
        $sweptOn = Json::date($data, 'swept_on');
        $sourceDir = Json::str($data, 'source_dir');
        $files = Json::object($data, 'files');
        self::refuseSecrets($files);

        $parsed = [];
        foreach (self::FILES as $name) {
            $parsed[$name] = self::inFile($name, static fn (): array => Json::object($files, $name));
        }

        [$accountId, $accountName] = self::sameAccount($parsed);
        $sweptAtUtc = self::sameSweptAt($parsed);

        $userFile = self::inFile('users', static fn (): array => self::users($parsed['users']));
        $domains = self::inFile('domains', static fn (): array => self::domains($parsed['domains']));
        self::crossCheck($userFile['listed'], $domains);

        $databases = self::inFile('databases', static fn (): array => self::databases($parsed['databases']));
        $crons = self::inFile('crons', static fn (): array => self::crons($parsed['crons']));
        foreach ($crons as $cron) {
            if (!isset($userFile['listed'][$cron['username']])) {
                throw new SweepFormatException("crons: user '{$cron['username']}' is not in users");
            }
        }

        return new self(
            $accountId,
            $accountName,
            $sweptOn,
            $sweptAtUtc,
            $sourceDir,
            $userFile['users'],
            $domains,
            self::inFile('registrations', static fn (): array => self::registrations($parsed['registrations'])),
            $databases['servers'],
            $databases['databases'],
            $crons,
            self::inFile('buckets', static fn (): array => self::objectsUsers($parsed['buckets'])),
        );
    }

    /**
     * The registrable part of a name: games.chatforest.com -> chatforest.com.
     */
    public static function baseDomain(string $domain): string
    {
        $labels = explode('.', $domain);
        if (count($labels) <= 2) {
            return $domain;
        }
        $suffix = implode('.', array_slice($labels, -2));
        $keep = in_array($suffix, self::TWO_LABEL_SUFFIXES, true) ? 3 : 2;
        return implode('.', array_slice($labels, -$keep));
    }

    /**
     * @template T
     * @param \Closure(): T $parse
     * @return T
     */
    private static function inFile(string $name, \Closure $parse): mixed
    {
        try {
            return $parse();
        } catch (SweepFormatException $e) {
            throw new SweepFormatException("{$name}.json: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<mixed> $tree
     */
    private static function refuseSecrets(array $tree, bool $keysAreData = false, string $path = 'files'): void
    {
        foreach ($tree as $key => $value) {
            if (!$keysAreData && is_string($key)) {
                if (preg_match(self::SECRET_NAME, $key) === 1 && !in_array($key, self::ALLOWED_NAMES, true)) {
                    throw new SweepFormatException("{$path}: field '{$key}' is named like a secret; refusing the sweep");
                }
            }
            if (is_string($value) && preg_match(self::SECRET_IN_VALUE, $value) === 1) {
                throw new SweepFormatException("{$path}.{$key}: value carries a key, token or password; refusing the sweep");
            }
            if (is_array($value)) {
                // The keys of `servers` are server names, data, not field names.
                self::refuseSecrets($value, $key === 'servers', "{$path}.{$key}");
            }
        }
    }

    /**
     * @param array<string, array<mixed>> $files
     * @return array{string, string}
     */
    private static function sameAccount(array $files): array
    {
        $seen = [];
        foreach ($files as $name => $file) {
            $account = self::inFile($name, static fn (): array => Json::object($file, 'account'));
            $seen[$name] = self::inFile($name, static fn (): string => Json::str($account, 'account_id'))
                . "\t" . self::inFile($name, static fn (): string => Json::str($account, 'name'));
        }
        if (count(array_unique($seen)) !== 1) {
            throw new SweepFormatException('the files are from different accounts');
        }
        [$id, $name] = explode("\t", (string) reset($seen), 2);
        return [$id, $name];
    }

    /**
     * @param array<string, array<mixed>> $files
     */
    private static function sameSweptAt(array $files): string
    {
        $stamps = [];
        foreach ($files as $name => $file) {
            $stamps[$name] = self::inFile($name, static fn (): string => Json::str($file, 'swept_at'));
        }
        if (count(array_unique($stamps)) !== 1) {
            throw new SweepFormatException('the files are from different sweeps (swept_at differs)');
        }
        try {
            $when = new \DateTimeImmutable((string) reset($stamps));
        } catch (\Exception $e) {
            throw new SweepFormatException('swept_at is not a date: ' . $e->getMessage(), 0, $e);
        }
        return $when->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @param array<mixed> $file
     * @return array{users: list<UserRow>, listed: array<int|string, list<string>>}
     */
    private static function users(array $file): array
    {
        $users = [];
        $listed = [];
        foreach (Json::records($file, 'users') as $record) {
            $name = Json::str($record, 'user');
            if (isset($listed[$name])) {
                throw new SweepFormatException("user '{$name}' appears twice");
            }
            $access = Json::str($record, 'access');
            if (!in_array($access, self::ACCESS, true)) {
                throw new SweepFormatException("user '{$name}': unknown access '{$access}'");
            }
            // Neither is stored: shell follows from access, home from the name.
            $shell = Json::strOrNull($record, 'shell');
            $expected = self::SHELL_FOR_ACCESS[$access] ?? null;
            if ($expected !== null && $shell !== $expected) {
                throw new SweepFormatException("user '{$name}': {$access} users have shell '{$expected}', got '{$shell}'");
            }
            if (Json::str($record, 'home') !== "/home/{$name}") {
                throw new SweepFormatException("user '{$name}': home is not /home/{$name}");
            }
            Json::int($record, 'panel_order_newest_first');
            $domains = array_map(strtolower(...), Json::strList($record, 'domains'));
            if (Json::int($record, 'domain_count') !== count($domains)) {
                throw new SweepFormatException("user '{$name}': domain_count disagrees with the domains listed");
            }
            $listed[$name] = $domains;
            $users[] = ['username' => $name, 'access' => $access, 'disk_bytes' => Json::intOrNull($record, 'disk_bytes')];
        }
        self::countIs($file, 'count', count($users), 'users');
        return ['users' => $users, 'listed' => $listed];
    }

    /**
     * @param array<mixed> $file
     * @return list<DomainRow>
     */
    private static function domains(array $file): array
    {
        $rows = [];
        $seen = [];
        foreach (Json::records($file, 'domains') as $record) {
            $domain = strtolower(Json::str($record, 'domain'));
            if (isset($seen[$domain])) {
                throw new SweepFormatException("domain '{$domain}' appears twice");
            }
            $seen[$domain] = true;
            $type = Json::str($record, 'type');
            $https = Json::str($record, 'https');
            if (!in_array($type, self::DOMAIN_TYPES, true)) {
                throw new SweepFormatException("domain '{$domain}': unknown type '{$type}'");
            }
            if (!in_array($https, self::HTTPS, true)) {
                throw new SweepFormatException("domain '{$domain}': unknown https '{$https}'");
            }
            Json::dateOrNull($record, 'registered_here_expires'); // checked, not stored: the registration has it
            $rows[] = [
                'domain' => $domain,
                'type' => $type,
                'username' => Json::strOrNull($record, 'user'),
                'server' => Json::strOrNull($record, 'server'),
                'php_version' => Json::strOrNull($record, 'php'),
                'web_directory' => Json::strOrNull($record, 'web_directory'),
                'https' => $https,
                'cert_expires' => Json::dateOrNull($record, 'cert_expires'),
            ];
        }
        self::countIs($file, 'count', count($rows), 'domains');
        return $rows;
    }

    /**
     * @param array<int|string, list<string>> $listed domains each user lists, by username
     * @param list<DomainRow> $domains
     */
    private static function crossCheck(array $listed, array $domains): void
    {
        $owned = [];
        foreach ($domains as $row) {
            $owner = $row['username'];
            if ($row['type'] !== 'hosted') {
                if ($owner !== null) {
                    throw new SweepFormatException("domain '{$row['domain']}' is {$row['type']} but has an owner");
                }
                continue;
            }
            if ($owner === null || !isset($listed[$owner])) {
                throw new SweepFormatException("hosted domain '{$row['domain']}' has no owner among the users");
            }
            if (!in_array($row['domain'], $listed[$owner], true)) {
                throw new SweepFormatException("hosted domain '{$row['domain']}': owner '{$owner}' does not list it");
            }
            $owned[$row['domain']] = $owner;
        }
        foreach ($listed as $user => $names) {
            foreach ($names as $name) {
                if (($owned[$name] ?? null) !== (string) $user) {
                    throw new SweepFormatException("user '{$user}' lists '{$name}', which is not a hosted site they own");
                }
            }
        }
    }

    /**
     * @param array<mixed> $file
     * @return list<RegistrationRow>
     */
    private static function registrations(array $file): array
    {
        $rows = [];
        $seen = [];
        foreach (Json::records($file, 'registrations') as $record) {
            $domain = strtolower(Json::str($record, 'domain'));
            if (isset($seen[$domain])) {
                throw new SweepFormatException("registration '{$domain}' appears twice");
            }
            $seen[$domain] = true;
            $rows[] = [
                'domain' => $domain,
                'registry' => Json::strOrNull($record, 'registry'),
                'created' => Json::dateOrNull($record, 'created'),
                'expires' => Json::date($record, 'expires'),
                'auto_renew' => Json::bool($record, 'auto_renew'),
                'auto_renew_on' => Json::dateOrNull($record, 'auto_renew_on'),
                'transfer_lock' => Json::bool($record, 'transfer_lock'),
                'privacy' => Json::bool($record, 'privacy'),
                'status' => Json::str($record, 'status'),
                'hidden' => Json::bool($record, 'hidden'),
            ];
        }
        self::countIs($file, 'count', count($rows), 'registrations');
        return $rows;
    }

    /**
     * @param array<mixed> $file
     * @return array{servers: list<ServerRow>, databases: list<DatabaseRow>}
     */
    private static function databases(array $file): array
    {
        $servers = [];
        $hostnameOwner = [];
        foreach (Json::object($file, 'servers') as $name => $info) {
            $name = (string) $name;
            if (!is_array($info)) {
                throw new SweepFormatException("server '{$name}' should be an object");
            }
            $hostnames = array_map(strtolower(...), Json::strList($info, 'hostnames'));
            foreach ($hostnames as $hostname) {
                if (isset($hostnameOwner[$hostname])) {
                    throw new SweepFormatException("hostname '{$hostname}' is on two servers");
                }
                $hostnameOwner[$hostname] = $name;
            }
            $servers[$name] = ['name' => $name, 'hostnames' => $hostnames];
        }

        $databases = [];
        $seen = [];
        foreach (Json::records($file, 'databases') as $record) {
            $name = Json::str($record, 'database');
            $server = Json::str($record, 'server');
            if (!isset($servers[$server])) {
                throw new SweepFormatException("database '{$name}' is on unknown server '{$server}'");
            }
            if (isset($seen[$server . "\t" . $name])) {
                throw new SweepFormatException("database '{$name}' appears twice on '{$server}'");
            }
            $seen[$server . "\t" . $name] = true;
            $databases[] = [
                'name' => $name,
                'server' => $server,
                'size_bytes' => Json::int($record, 'size_bytes'),
                'description' => Json::strOrNull($record, 'description'),
                'db_users' => Json::strList($record, 'db_users'),
            ];
        }
        self::countIs($file, 'count', count($databases), 'databases');
        return ['servers' => array_values($servers), 'databases' => $databases];
    }

    /**
     * @param array<mixed> $file
     * @return list<CronRow>
     */
    private static function crons(array $file): array
    {
        $rows = [];
        $seen = [];
        foreach (Json::records($file, 'crons') as $record) {
            $user = Json::str($record, 'user');
            $title = Json::str($record, 'title');
            if (isset($seen[$user . "\t" . $title])) {
                throw new SweepFormatException("user '{$user}' has two crons titled '{$title}'");
            }
            $seen[$user . "\t" . $title] = true;
            Json::strOrNull($record, 'server'); // present, not stored (SCHEMA.md open question)
            $rows[] = [
                'username' => $user,
                'title' => $title,
                'active' => Json::bool($record, 'active'),
                'output_emailed_to' => Json::strOrNull($record, 'output_emailed_to'),
            ];
        }
        self::countIs($file, 'count', count($rows), 'crons');
        return $rows;
    }

    /**
     * @param array<mixed> $file
     * @return list<ObjectsUserRow>
     */
    private static function objectsUsers(array $file): array
    {
        $rows = [];
        $seen = [];
        $bucketTotal = 0;
        foreach (Json::records($file, 'objects_users') as $record) {
            $user = Json::str($record, 'user');
            if (isset($seen[$user])) {
                throw new SweepFormatException("DreamObjects user '{$user}' appears twice");
            }
            $seen[$user] = true;
            $buckets = Json::strList($record, 'bucket_names');
            if (Json::int($record, 'buckets') !== count($buckets)) {
                throw new SweepFormatException("DreamObjects user '{$user}': bucket count disagrees with the names listed");
            }
            $bucketTotal += count($buckets);
            $rows[] = [
                'username' => $user,
                'endpoint' => Json::str($record, 'endpoint'),
                'key_count' => Json::int($record, 'keys'),
                'buckets' => $buckets,
            ];
        }
        self::countIs($file, 'bucket_total', $bucketTotal, 'buckets');
        return $rows;
    }

    /**
     * @param array<mixed> $file
     */
    private static function countIs(array $file, string $field, int $actual, string $what): void
    {
        $claimed = Json::int($file, $field);
        if ($claimed !== $actual) {
            throw new SweepFormatException("{$field} says {$claimed} {$what}, the file holds {$actual}");
        }
    }
}
