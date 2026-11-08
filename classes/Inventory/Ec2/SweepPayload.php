<?php

declare(strict_types=1);

namespace Inventory\Ec2;

use Inventory\Json;
use Inventory\Standards\Names;
use Inventory\SweepFormatException;

/**
 * One EC2 sweep as tools/ec2-sweep/deets.sh prints it: one server, the
 * hostnames mg asked about, and what the server said about each. Reading it
 * is the only place the document's shape is checked; the importer trusts a
 * payload that got this far.
 *
 * A php_version is the web PHP read from the server's Apache module package,
 * or null when that could not be read; probe_problem then says what came back instead.
 *
 * @phpstan-type DomainRecord array{domain: string, directory: string, php_version: ?string, probe_route: ?string, probe_problem: string, directory_exists: bool, is_git_checkout: bool, head_commit: ?string, head_branch: ?string, deployed_at_utc: ?string}
 */
final readonly class SweepPayload
{
    public const array ROUTES = ['https', 'http_forwarded'];

    private const string VERSION = '/\A\d+\.\d+(?:\.\d+)?\z/';
    private const string SHA = '/\A[0-9a-f]{40}\z/';
    private const string DATETIME = '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/';
    /** Field names that look like secrets; a sweep carries none. */
    private const string SECRET_NAME = '/pass|secret|key|token|hash|salt|cookie|session|email|phone/i';

    /**
     * @param list<DomainRecord> $domains
     */
    public function __construct(
        public string $sshAlias,
        public string $sweptAtUtc,
        public ?string $webServer,
        public ?string $cliPhp,
        public array $domains,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        self::refuseSecrets($data, '');

        $sweptAt = Json::str($data, 'swept_at_utc');
        if (preg_match(self::DATETIME, $sweptAt) !== 1) {
            throw new SweepFormatException("swept_at_utc: \"{$sweptAt}\" is not a YYYY-MM-DD HH:MM:SS time");
        }
        $domains = [];
        $seen = [];
        foreach (Json::records($data, 'domains') as $i => $record) {
            $domain = self::domain($record, "domains[{$i}]");
            if (isset($seen[$domain['domain']])) {
                throw new SweepFormatException("domains: {$domain['domain']} is listed twice");
            }
            $seen[$domain['domain']] = true;
            $domains[] = $domain;
        }

        return new self(
            Json::str($data, 'ssh_alias'),
            $sweptAt,
            Json::strOrNull($data, 'web_server'),
            self::versionOrNull($data, 'cli_php', 'cli_php'),
            $domains,
        );
    }

    /**
     * @param array<mixed> $record
     * @return DomainRecord
     */
    private static function domain(array $record, string $at): array
    {
        $directory = Json::str($record, 'directory');
        if (!Names::isServerDirectory($directory)) {
            throw new SweepFormatException("{$at}.directory: \"{$directory}\" is not an absolute directory");
        }
        $route = Json::strOrNull($record, 'probe_route');
        if ($route !== null && !in_array($route, self::ROUTES, true)) {
            throw new SweepFormatException("{$at}.probe_route: \"{$route}\" is not one of " . implode(', ', self::ROUTES));
        }
        $commit = Json::strOrNull($record, 'head_commit');
        if ($commit !== null && preg_match(self::SHA, $commit) !== 1) {
            throw new SweepFormatException("{$at}.head_commit: \"{$commit}\" is not a 40-character commit id");
        }
        $branch = Json::strOrNull($record, 'head_branch');
        if ($branch !== null && !Names::isBranch($branch)) {
            throw new SweepFormatException("{$at}.head_branch: \"{$branch}\" is not a branch name");
        }
        $deployed = Json::strOrNull($record, 'deployed_at_utc');
        if ($deployed !== null && preg_match(self::DATETIME, $deployed) !== 1) {
            throw new SweepFormatException("{$at}.deployed_at_utc: \"{$deployed}\" is not a YYYY-MM-DD HH:MM:SS time");
        }
        $domain = Json::str($record, 'domain');
        if (!Names::isHostname($domain)) {
            throw new SweepFormatException("{$at}.domain: \"{$domain}\" is not a hostname");
        }

        return [
            'domain' => $domain,
            'directory' => $directory,
            'php_version' => self::versionOrNull($record, 'php_version', "{$at}.php_version"),
            'probe_route' => $route,
            'probe_problem' => Json::str($record, 'probe_problem'),
            'directory_exists' => Json::bool($record, 'directory_exists'),
            'is_git_checkout' => Json::bool($record, 'is_git_checkout'),
            'head_commit' => $commit,
            'head_branch' => $branch,
            'deployed_at_utc' => $deployed,
        ];
    }

    /**
     * @param array<mixed> $record
     */
    private static function versionOrNull(array $record, string $field, string $at): ?string
    {
        $value = Json::strOrNull($record, $field);
        if ($value !== null && preg_match(self::VERSION, $value) !== 1) {
            $shown = strlen($value) > 40 ? substr($value, 0, 40) . '…' : $value;
            throw new SweepFormatException("{$at}: \"{$shown}\" is not a PHP version");
        }
        return $value;
    }

    /**
     * No field anywhere in the document may be named like a secret. The
     * sweep script never reads one, so a document with such a field did not
     * come from it.
     *
     * @param array<mixed> $data
     */
    private static function refuseSecrets(array $data, string $path): void
    {
        foreach ($data as $name => $value) {
            $here = $path === '' ? (string) $name : "{$path}.{$name}";
            if (is_string($name) && preg_match(self::SECRET_NAME, $name) === 1) {
                throw new SweepFormatException("{$here}: a sweep never carries a field by that name");
            }
            if (is_array($value)) {
                self::refuseSecrets($value, $here);
            }
        }
    }
}
