<?php

declare(strict_types=1);

namespace Inventory\Standards;

use Db\Rows;
use Db\RowValue;

/**
 * The facts about a site that are kept by hand: its shortcode and standard,
 * the mg project holding its notes, where its code is, which DreamHost
 * domains serve it, and which of those is the server the scanner asks what is
 * deployed. Everything about steps comes from the step files instead.
 *
 * Every refusal is an InvalidArgumentException whose message is a sentence
 * for the person at the command line.
 */
final readonly class SiteStore
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function add(string $shortcode, string $standardSlug, ?int $projectId): int
    {
        if (!Names::isShortcode($shortcode)) {
            throw new \InvalidArgumentException(
                "\"{$shortcode}\" is not a valid shortcode: lowercase letters and digits, "
                . 'groups joined by single hyphens, ' . Names::SHORTCODE_MAX . ' characters at most.'
            );
        }
        if ($this->rows('SELECT std_site_id FROM std_sites WHERE shortcode = ?', [$shortcode]) !== []) {
            throw new \InvalidArgumentException("The shortcode {$shortcode} is already taken.");
        }
        $standard = $this->rows('SELECT std_standard_id FROM std_standards WHERE slug = ?', [$standardSlug]);
        if ($standard === []) {
            throw new \InvalidArgumentException("No such standard: {$standardSlug}. Import the step files first.");
        }
        $this->checkProject($projectId);

        $this->run(
            'INSERT INTO std_sites (std_standard_id, shortcode, project_id) VALUES (?, ?, ?)',
            [RowValue::int($standard[0], 'std_standard_id'), $shortcode, $projectId],
        );
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{project_id?: ?int, checkout_path?: ?string, server?: string, test?: string, github_repo?: ?string, autonomy?: string} $changes
     */
    public function set(string $shortcode, array $changes): void
    {
        $siteId = $this->siteId($shortcode);
        $sets = [];
        $params = [];
        if (array_key_exists('project_id', $changes)) {
            $this->checkProject($changes['project_id']);
            $sets[] = 'project_id = ?';
            $params[] = $changes['project_id'];
        }
        if (array_key_exists('checkout_path', $changes)) {
            $path = $changes['checkout_path'];
            if ($path !== null && !Names::isCheckoutPath($path)) {
                throw new \InvalidArgumentException(
                    "\"{$path}\" is not a checkout path. Give it relative to ~/work, like rob/example.test."
                );
            }
            $sets[] = 'checkout_path = ?';
            $params[] = $path;
        }
        if (array_key_exists('server', $changes) || array_key_exists('test', $changes)) {
            $serverId = array_key_exists('server', $changes)
                ? ($changes['server'] === '' ? null : $this->linkId($siteId, $shortcode, $changes['server']))
                : $this->namedLink($siteId, 'server_site_domain_id');
            $testId = array_key_exists('test', $changes)
                ? ($changes['test'] === '' ? null : $this->linkId($siteId, $shortcode, $changes['test']))
                : $this->namedLink($siteId, 'test_site_domain_id');
            $problem = TestDomainRule::problem($serverId, $testId);
            if ($problem !== null) {
                throw new \InvalidArgumentException($problem);
            }
            if (array_key_exists('server', $changes)) {
                $sets[] = 'server_site_domain_id = ?';
                $params[] = $serverId;
            }
            if (array_key_exists('test', $changes)) {
                $sets[] = 'test_site_domain_id = ?';
                $params[] = $testId;
            }
        }
        if (array_key_exists('github_repo', $changes)) {
            $repo = $changes['github_repo'];
            if ($repo !== null && preg_match('#\A[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\z#', $repo) !== 1) {
                throw new \InvalidArgumentException("\"{$repo}\" is not a GitHub repo. Write it as owner/name.");
            }
            $sets[] = 'github_repo = ?';
            $params[] = $repo;
        }
        if (array_key_exists('autonomy', $changes)) {
            $autonomy = Autonomy::tryFrom($changes['autonomy'])
                ?? throw new \InvalidArgumentException('Autonomy is one of: paired, reviewed, solo.');
            $sets[] = 'autonomy = ?';
            $params[] = $autonomy->value;
        }
        if ($sets === []) {
            return;
        }
        $this->run('UPDATE std_sites SET ' . implode(', ', $sets) . ' WHERE std_site_id = ?', [...$params, $siteId]);
    }

    public function link(string $shortcode, string $domain, string $pathPrefix = ''): void
    {
        $siteId = $this->siteId($shortcode);
        $domainId = $this->domainId($domain);
        $this->checkPath($pathPrefix);
        $taken = $this->rows(
            'SELECT s.shortcode FROM dh_site_domains d JOIN std_sites s ON s.std_site_id = d.std_site_id
              WHERE d.dh_domain_id = ? AND d.path_prefix = ?',
            [$domainId, $pathPrefix],
        );
        if ($taken !== []) {
            throw new \InvalidArgumentException(
                "{$domain}{$pathPrefix} is already linked to site " . RowValue::string($taken[0], 'shortcode') . '.'
            );
        }
        $this->run(
            'INSERT INTO dh_site_domains (std_site_id, dh_domain_id, path_prefix) VALUES (?, ?, ?)',
            [$siteId, $domainId, $pathPrefix],
        );
    }

    public function unlink(string $shortcode, string $domain, string $pathPrefix = ''): void
    {
        $siteId = $this->siteId($shortcode);
        $domainId = $this->domainId($domain);
        $named = $this->rows(
            'SELECT s.std_site_id FROM std_sites s
               JOIN dh_site_domains l ON l.dh_site_domain_id = s.server_site_domain_id
              WHERE s.std_site_id = ? AND l.dh_domain_id = ? AND l.path_prefix = ?',
            [$siteId, $domainId, $pathPrefix],
        );
        if ($named !== []) {
            throw new \InvalidArgumentException(
                "{$domain}{$pathPrefix} is the server of site {$shortcode}. "
                . "Name another with site-set --server=, or clear it with --server=, first."
            );
        }
        $isTest = $this->rows(
            'SELECT s.std_site_id FROM std_sites s
               JOIN dh_site_domains l ON l.dh_site_domain_id = s.test_site_domain_id
              WHERE s.std_site_id = ? AND l.dh_domain_id = ? AND l.path_prefix = ?',
            [$siteId, $domainId, $pathPrefix],
        );
        if ($isTest !== []) {
            throw new \InvalidArgumentException(
                "{$domain}{$pathPrefix} is the test domain of site {$shortcode}. Clear it with site-set --test= first."
            );
        }
        $removed = $this->run(
            'DELETE FROM dh_site_domains WHERE std_site_id = ? AND dh_domain_id = ? AND path_prefix = ?',
            [$siteId, $domainId, $pathPrefix],
        )->rowCount();
        if ($removed === 0) {
            throw new \InvalidArgumentException("{$domain}{$pathPrefix} is not linked to site {$shortcode}.");
        }
    }

    /**
     * What the scanner on Lemur 13 needs to know about each site: where its
     * checkout is, and which server to ask what is deployed (or why there is
     * none to ask).
     *
     * @return list<array{shortcode: string, standard: string, checkout_path: ?string, server: ?array{ssh_alias: string, directory: string}, server_problem: ?string, php_current: ?string, test: ?array{domain: string, php_version: ?string, ssh_alias: ?string, directory: ?string}}>
     */
    public function forScanner(): array
    {
        $links = [];
        $linkRows = $this->rows(
            'SELECT l.std_site_id, l.dh_site_domain_id, d.domain, l.path_prefix, st.web_directory, st.php_version, u.ssh_alias
               FROM dh_site_domains l
               JOIN dh_domains d ON d.dh_domain_id = l.dh_domain_id
               LEFT JOIN dh_domain_states st ON st.dh_domain_id = d.dh_domain_id AND st.until_sweep_id IS NULL
               LEFT JOIN dh_users u ON u.dh_user_id = st.dh_user_id
              ORDER BY d.domain, l.path_prefix'
        );
        foreach ($linkRows as $row) {
            $links[RowValue::int($row, 'std_site_id')][] = [
                'dh_site_domain_id' => RowValue::int($row, 'dh_site_domain_id'),
                'domain' => RowValue::string($row, 'domain'),
                'path_prefix' => RowValue::string($row, 'path_prefix'),
                'web_directory' => RowValue::stringOrNull($row, 'web_directory'),
                'ssh_alias' => RowValue::stringOrNull($row, 'ssh_alias'),
                'php_version' => RowValue::stringOrNull($row, 'php_version'),
            ];
        }

        $sites = [];
        $rows = $this->rows(
            'SELECT s.std_site_id, s.shortcode, t.slug AS standard, s.checkout_path, s.server_site_domain_id, s.test_site_domain_id
               FROM std_sites s JOIN std_standards t ON t.std_standard_id = s.std_standard_id
              ORDER BY s.shortcode'
        );
        foreach ($rows as $row) {
            $server = ServerChoice::pick(
                $links[RowValue::int($row, 'std_site_id')] ?? [],
                RowValue::intOrNull($row, 'server_site_domain_id'),
            );
            $testId = RowValue::intOrNull($row, 'test_site_domain_id');
            $test = null;
            $mine = $links[RowValue::int($row, 'std_site_id')] ?? [];
            // The PHP the site is served on now: its named server's, else the first
            // domain's (ServerChoice has already refused sites whose domains differ).
            $phpCurrent = null;
            $namedServer = RowValue::intOrNull($row, 'server_site_domain_id');
            foreach ($mine as $link) {
                if ($namedServer === null ? $link === $mine[0] : $link['dh_site_domain_id'] === $namedServer) {
                    $phpCurrent = $link['php_version'];
                }
            }
            foreach ($mine as $link) {
                if ($link['dh_site_domain_id'] === $testId) {
                    $where = ServerChoice::pick([$link], null);
                    $test = [
                        'domain' => $link['domain'] . $link['path_prefix'],
                        'php_version' => $link['php_version'],
                        'ssh_alias' => $where->sshAlias,
                        'directory' => $where->directory,
                    ];
                }
            }
            $sites[] = [
                'shortcode' => RowValue::string($row, 'shortcode'),
                'standard' => RowValue::string($row, 'standard'),
                'checkout_path' => RowValue::stringOrNull($row, 'checkout_path'),
                'server' => $server->sshAlias === null || $server->directory === null
                    ? null
                    : ['ssh_alias' => $server->sshAlias, 'directory' => $server->directory],
                'server_problem' => $server->problem,
                'php_current' => $phpCurrent,
                'test' => $test,
            ];
        }
        return $sites;
    }

    /**
     * For each site with no test domain yet, the domains that look like its
     * test twin (see TestDomainCandidates). A site's production domain is the
     * one it names as its server, else its only domain; a site with several
     * and none named is left out.
     *
     * @return list<array{shortcode: string, server: string, candidates: list<array{domain: string, reasons: list<string>}>}>
     */
    public function testCandidates(): array
    {
        $domainRows = $this->rows(
            'SELECT d.domain, u.username, st.web_directory, s.shortcode AS linked_to
               FROM dh_domains d
               LEFT JOIN dh_domain_states st ON st.dh_domain_id = d.dh_domain_id AND st.until_sweep_id IS NULL
               LEFT JOIN dh_users u ON u.dh_user_id = st.dh_user_id
               LEFT JOIN dh_site_domains l ON l.dh_domain_id = d.dh_domain_id
               LEFT JOIN std_sites s ON s.std_site_id = l.std_site_id
              ORDER BY d.domain'
        );
        $byDomain = [];
        foreach ($domainRows as $row) {
            $name = RowValue::string($row, 'domain');
            $byDomain[$name] ??= [
                'domain' => $name,
                'dh_user' => RowValue::stringOrNull($row, 'username'),
                'web_directory' => RowValue::stringOrNull($row, 'web_directory'),
                'linked_to' => [],
            ];
            $linked = RowValue::stringOrNull($row, 'linked_to');
            if ($linked !== null && !in_array($linked, $byDomain[$name]['linked_to'], true)) {
                $byDomain[$name]['linked_to'][] = $linked;
            }
        }
        $domains = array_values($byDomain);

        $links = [];
        $linkRows = $this->rows(
            'SELECT l.std_site_id, l.dh_site_domain_id, d.domain, u.username, st.web_directory
               FROM dh_site_domains l
               JOIN dh_domains d ON d.dh_domain_id = l.dh_domain_id
               LEFT JOIN dh_domain_states st ON st.dh_domain_id = d.dh_domain_id AND st.until_sweep_id IS NULL
               LEFT JOIN dh_users u ON u.dh_user_id = st.dh_user_id
              ORDER BY d.domain, l.path_prefix'
        );
        foreach ($linkRows as $row) {
            $links[RowValue::int($row, 'std_site_id')][] = $row;
        }

        $out = [];
        $siteRows = $this->rows(
            'SELECT std_site_id, shortcode, server_site_domain_id FROM std_sites
              WHERE test_site_domain_id IS NULL ORDER BY shortcode'
        );
        foreach ($siteRows as $site) {
            $mine = $links[RowValue::int($site, 'std_site_id')] ?? [];
            $named = RowValue::intOrNull($site, 'server_site_domain_id');
            $production = null;
            foreach ($mine as $link) {
                if ($named !== null ? RowValue::int($link, 'dh_site_domain_id') === $named : count($mine) === 1) {
                    $production = $link;
                }
            }
            if ($production === null) {
                continue;
            }
            $shortcode = RowValue::string($site, 'shortcode');
            $server = RowValue::string($production, 'domain');
            $out[] = [
                'shortcode' => $shortcode,
                'server' => $server,
                'candidates' => TestDomainCandidates::find([
                    'shortcode' => $shortcode,
                    'server_domain' => $server,
                    'dh_user' => RowValue::stringOrNull($production, 'username'),
                    'web_directory' => RowValue::stringOrNull($production, 'web_directory'),
                ], $domains),
            ];
        }
        return $out;
    }

    /**
     * The link of this site that --server= names: a domain, with the path
     * inside it for a site that is only part of one (example.test/blog).
     */
    private function linkId(int $siteId, string $shortcode, string $server): int
    {
        $slash = strpos($server, '/');
        $domain = $slash === false ? $server : substr($server, 0, $slash);
        $path = $slash === false ? '' : substr($server, $slash);
        $found = $this->rows(
            'SELECT dh_site_domain_id FROM dh_site_domains WHERE std_site_id = ? AND dh_domain_id = ? AND path_prefix = ?',
            [$siteId, $this->domainId($domain), $path],
        );
        if ($found === []) {
            throw new \InvalidArgumentException(
                "{$server} is not linked to site {$shortcode}. Link it with site-link first."
            );
        }
        return RowValue::int($found[0], 'dh_site_domain_id');
    }

    /**
     * The link a site's server or test column names right now, or null.
     *
     * @param 'server_site_domain_id'|'test_site_domain_id' $column
     */
    private function namedLink(int $siteId, string $column): ?int
    {
        $found = $this->rows("SELECT {$column} AS named FROM std_sites WHERE std_site_id = ?", [$siteId]);
        return $found === [] ? null : RowValue::intOrNull($found[0], 'named');
    }

    private function siteId(string $shortcode): int
    {
        $found = $this->rows('SELECT std_site_id FROM std_sites WHERE shortcode = ?', [$shortcode]);
        if ($found === []) {
            throw new \InvalidArgumentException("No site has the shortcode {$shortcode}.");
        }
        return RowValue::int($found[0], 'std_site_id');
    }

    private function domainId(string $domain): int
    {
        $found = $this->rows('SELECT dh_domain_id FROM dh_domains WHERE domain = ?', [$domain]);
        return match (count($found)) {
            0 => throw new \InvalidArgumentException("No DreamHost domain is called {$domain}."),
            1 => RowValue::int($found[0], 'dh_domain_id'),
            default => throw new \InvalidArgumentException("More than one DreamHost account has {$domain}."),
        };
    }

    private function checkProject(?int $projectId): void
    {
        if ($projectId === null) {
            return;
        }
        if ($this->rows('SELECT project_id FROM projects WHERE project_id = ?', [$projectId]) === []) {
            throw new \InvalidArgumentException("No such project: {$projectId}.");
        }
    }

    /**
     * A path inside a domain: empty for the whole domain, else /journal style.
     */
    private function checkPath(string $pathPrefix): void
    {
        if ($pathPrefix !== '' && preg_match('#\A(?:/[A-Za-z0-9._-]+)+\z#', $pathPrefix) !== 1) {
            throw new \InvalidArgumentException(
                "\"{$pathPrefix}\" is not a path inside a domain. Write it like /journal, or leave it out."
            );
        }
    }

    /**
     * @param list<int|string|null> $params
     */
    private function run(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    /**
     * @param list<int|string|null> $params
     * @return list<array<mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        return Rows::all($this->run($sql, $params)->fetchAll(\PDO::FETCH_ASSOC));
    }
}
