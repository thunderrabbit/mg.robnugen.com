<?php

declare(strict_types=1);

namespace Inventory\Standards;

use Database\Rows;
use Database\RowValue;

/**
 * The facts about a site that are kept by hand: its shortcode and standard,
 * the mg project holding its notes, where its code is, which domains or
 * hostnames serve it, and which of those is the server the scanner asks what
 * is deployed. Everything about steps comes from the step files instead.
 *
 * A site's links are DreamHost domains (dh_site_domains) or EC2 hostnames
 * (ec2_site_domains), never both (LinkKindRule). EC2 servers and their
 * hostnames are also kept here, by hand; the EC2 sweep fills in the facts.
 *
 * Every refusal is an InvalidArgumentException whose message is a sentence
 * for the person at the command line.
 */
final readonly class SiteStore
{
    private const string SSH_ALIAS = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/';

    /**
     * The link table and std_sites columns of each kind. Names come from
     * here and never from input, so they may be spliced into SQL.
     *
     * @var array<string, array{link_table: string, link_id: string, domain_id: string, server_col: string, test_col: string}>
     */
    private const array KINDS = [
        'dreamhost' => [
            'link_table' => 'dh_site_domains',
            'link_id' => 'dh_site_domain_id',
            'domain_id' => 'dh_domain_id',
            'server_col' => 'server_site_domain_id',
            'test_col' => 'test_site_domain_id',
        ],
        'ec2' => [
            'link_table' => 'ec2_site_domains',
            'link_id' => 'ec2_site_domain_id',
            'domain_id' => 'ec2_domain_id',
            'server_col' => 'server_ec2_site_domain_id',
            'test_col' => 'test_ec2_site_domain_id',
        ],
    ];

    public function __construct(private \PDO $pdo)
    {
    }

    public function addServer(string $sshAlias, string $label): void
    {
        if (preg_match(self::SSH_ALIAS, $sshAlias) !== 1) {
            throw new \InvalidArgumentException(
                "\"{$sshAlias}\" is not an ssh alias: letters, digits, dots, dashes and underscores, 64 at most."
            );
        }
        if ($this->rows('SELECT ec2_server_id FROM ec2_servers WHERE ssh_alias = ?', [$sshAlias]) !== []) {
            throw new \InvalidArgumentException("An EC2 server with alias {$sshAlias} is already entered.");
        }
        $this->run('INSERT INTO ec2_servers (ssh_alias, label) VALUES (?, ?)', [$sshAlias, $label]);
    }

    public function addDomain(string $sshAlias, string $domain, string $directory): void
    {
        $serverId = $this->serverId($sshAlias);
        if (!Names::isHostname($domain)) {
            throw new \InvalidArgumentException(
                "\"{$domain}\" is not a hostname: labels of letters, digits and dashes, joined by dots."
            );
        }
        if ($this->rows('SELECT dh_domain_id FROM dh_domains WHERE domain = ?', [$domain]) !== []) {
            throw new \InvalidArgumentException("{$domain} is a DreamHost domain; it cannot also be an EC2 hostname.");
        }
        if ($this->rows('SELECT ec2_domain_id FROM ec2_domains WHERE domain = ?', [$domain]) !== []) {
            throw new \InvalidArgumentException("The hostname {$domain} is already entered.");
        }
        if (!Names::isServerDirectory($directory)) {
            throw new \InvalidArgumentException(
                "\"{$directory}\" is not an absolute directory on the server. Write it like /var/www/example.test/current."
            );
        }
        $this->run(
            'INSERT INTO ec2_domains (ec2_server_id, domain, directory) VALUES (?, ?, ?)',
            [$serverId, $domain, $directory],
        );
    }

    /**
     * What the EC2 sweep on Lemur 13 visits: every server with the hostnames
     * it serves and the directory behind each.
     *
     * @return list<array{ssh_alias: string, domains: list<array{domain: string, directory: string}>}>
     */
    public function serversForSweep(): array
    {
        $servers = [];
        $rows = $this->rows(
            'SELECT v.ssh_alias, d.domain, d.directory
               FROM ec2_servers v LEFT JOIN ec2_domains d ON d.ec2_server_id = v.ec2_server_id
              ORDER BY v.ssh_alias, d.domain'
        );
        foreach ($rows as $row) {
            $alias = RowValue::string($row, 'ssh_alias');
            $servers[$alias] ??= ['ssh_alias' => $alias, 'domains' => []];
            $domain = RowValue::stringOrNull($row, 'domain');
            if ($domain !== null) {
                $servers[$alias]['domains'][] = ['domain' => $domain, 'directory' => RowValue::string($row, 'directory')];
            }
        }
        return array_values($servers);
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
     * @param array{project_id?: ?int, checkout_path?: ?string, server?: string, test?: string, github_repo?: ?string, autonomy?: string, upgrade_branch?: ?string} $changes
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
            $kind = $this->hostOf($siteId);
            if ($kind === HostKind::None && (($changes['server'] ?? '') !== '' || ($changes['test'] ?? '') !== '')) {
                throw new \InvalidArgumentException("Nothing is linked to site {$shortcode} yet. Link it with site-link first.");
            }
            $cols = self::cols($kind === HostKind::None ? HostKind::DreamHost : $kind);
            $serverId = array_key_exists('server', $changes)
                ? ($changes['server'] === '' ? null : $this->linkId($siteId, $shortcode, $changes['server'], $kind))
                : $this->namedLink($siteId, $cols['server_col']);
            $testId = array_key_exists('test', $changes)
                ? ($changes['test'] === '' ? null : $this->linkId($siteId, $shortcode, $changes['test'], $kind))
                : $this->namedLink($siteId, $cols['test_col']);
            $problem = TestDomainRule::problem($serverId, $testId);
            if ($problem !== null) {
                throw new \InvalidArgumentException($problem);
            }
            if (array_key_exists('server', $changes)) {
                $sets[] = $cols['server_col'] . ' = ?';
                $params[] = $serverId;
            }
            if (array_key_exists('test', $changes)) {
                $sets[] = $cols['test_col'] . ' = ?';
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
        if (array_key_exists('upgrade_branch', $changes)) {
            $branch = $changes['upgrade_branch'];
            if ($branch !== null && !Names::isBranch($branch)) {
                throw new \InvalidArgumentException("\"{$branch}\" is not a branch name.");
            }
            $sets[] = 'upgrade_branch = ?';
            $params[] = $branch;
        }
        if ($sets === []) {
            return;
        }
        $this->run('UPDATE std_sites SET ' . implode(', ', $sets) . ' WHERE std_site_id = ?', [...$params, $siteId]);
    }

    /**
     * Links a DreamHost domain or an EC2 hostname to a site, whichever the
     * name is, so long as the site's other links are of the same kind.
     */
    public function link(string $shortcode, string $domain, string $pathPrefix = ''): void
    {
        $siteId = $this->siteId($shortcode);
        ['kind' => $kind, 'id' => $domainId] = $this->domainRef($domain);
        $this->checkPath($pathPrefix);
        $problem = LinkKindRule::problem($this->hostOf($siteId), $kind);
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }
        $cols = self::cols($kind);
        $taken = $this->rows(
            "SELECT s.shortcode FROM {$cols['link_table']} d JOIN std_sites s ON s.std_site_id = d.std_site_id
              WHERE d.{$cols['domain_id']} = ? AND d.path_prefix = ?",
            [$domainId, $pathPrefix],
        );
        if ($taken !== []) {
            throw new \InvalidArgumentException(
                "{$domain}{$pathPrefix} is already linked to site " . RowValue::string($taken[0], 'shortcode') . '.'
            );
        }
        $this->run(
            "INSERT INTO {$cols['link_table']} (std_site_id, {$cols['domain_id']}, path_prefix) VALUES (?, ?, ?)",
            [$siteId, $domainId, $pathPrefix],
        );
    }

    public function unlink(string $shortcode, string $domain, string $pathPrefix = ''): void
    {
        $siteId = $this->siteId($shortcode);
        ['kind' => $kind, 'id' => $domainId] = $this->domainRef($domain);
        $cols = self::cols($kind);
        $named = $this->rows(
            "SELECT s.std_site_id FROM std_sites s
               JOIN {$cols['link_table']} l ON l.{$cols['link_id']} = s.{$cols['server_col']}
              WHERE s.std_site_id = ? AND l.{$cols['domain_id']} = ? AND l.path_prefix = ?",
            [$siteId, $domainId, $pathPrefix],
        );
        if ($named !== []) {
            throw new \InvalidArgumentException(
                "{$domain}{$pathPrefix} is the server of site {$shortcode}. "
                . "Name another with site-set --server=, or clear it with --server=, first."
            );
        }
        $isTest = $this->rows(
            "SELECT s.std_site_id FROM std_sites s
               JOIN {$cols['link_table']} l ON l.{$cols['link_id']} = s.{$cols['test_col']}
              WHERE s.std_site_id = ? AND l.{$cols['domain_id']} = ? AND l.path_prefix = ?",
            [$siteId, $domainId, $pathPrefix],
        );
        if ($isTest !== []) {
            throw new \InvalidArgumentException(
                "{$domain}{$pathPrefix} is the test domain of site {$shortcode}. Clear it with site-set --test= first."
            );
        }
        $removed = $this->run(
            "DELETE FROM {$cols['link_table']} WHERE std_site_id = ? AND {$cols['domain_id']} = ? AND path_prefix = ?",
            [$siteId, $domainId, $pathPrefix],
        )->rowCount();
        if ($removed === 0) {
            throw new \InvalidArgumentException("{$domain}{$pathPrefix} is not linked to site {$shortcode}.");
        }
    }

    /**
     * What the scanner on Lemur 13 needs to know about each site: where its
     * checkout is, which server to ask what is deployed (or why there is
     * none to ask), and the branch it is preparing, if any.
     *
     * @return list<array{shortcode: string, standard: string, checkout_path: ?string, server: ?array{ssh_alias: string, directory: string}, server_problem: ?string, php_current: ?string, test: ?array{domain: string, php_version: ?string, ssh_alias: ?string, directory: ?string}, upgrade_branch: ?string}>
     */
    public function forScanner(): array
    {
        $links = [];
        $ec2Rows = $this->rows(
            'SELECT l.std_site_id, l.ec2_site_domain_id, d.domain, l.path_prefix, d.directory, v.ssh_alias, st.php_version
               FROM ec2_site_domains l
               JOIN ec2_domains d ON d.ec2_domain_id = l.ec2_domain_id
               JOIN ec2_servers v ON v.ec2_server_id = d.ec2_server_id
               LEFT JOIN ec2_domain_states st ON st.ec2_domain_id = d.ec2_domain_id AND st.until_sweep_id IS NULL
              ORDER BY d.domain, l.path_prefix'
        );
        foreach ($ec2Rows as $row) {
            $links[RowValue::int($row, 'std_site_id')][] = [
                'link_id' => RowValue::int($row, 'ec2_site_domain_id'),
                'where' => RowValue::string($row, 'domain'),
                'path_prefix' => RowValue::string($row, 'path_prefix'),
                'directory' => RowValue::string($row, 'directory'),
                'ssh_alias' => RowValue::string($row, 'ssh_alias'),
                'php_version' => RowValue::stringOrNull($row, 'php_version'),
            ];
        }
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
                'link_id' => RowValue::int($row, 'dh_site_domain_id'),
                'where' => RowValue::string($row, 'domain'),
                'path_prefix' => RowValue::string($row, 'path_prefix'),
                'directory' => RowValue::stringOrNull($row, 'web_directory'),
                'ssh_alias' => RowValue::stringOrNull($row, 'ssh_alias'),
                'php_version' => RowValue::stringOrNull($row, 'php_version'),
            ];
        }

        $sites = [];
        $rows = $this->rows(
            'SELECT s.std_site_id, s.shortcode, t.slug AS standard, s.checkout_path, s.upgrade_branch,
                    COALESCE(s.server_site_domain_id, s.server_ec2_site_domain_id) AS named_server,
                    COALESCE(s.test_site_domain_id, s.test_ec2_site_domain_id) AS named_test
               FROM std_sites s JOIN std_standards t ON t.std_standard_id = s.std_standard_id
              ORDER BY s.shortcode'
        );
        foreach ($rows as $row) {
            // A site's links are all of one kind, so the one pair of columns that
            // is set names links in the one list the site has.
            $server = ServerChoice::pick(
                $links[RowValue::int($row, 'std_site_id')] ?? [],
                RowValue::intOrNull($row, 'named_server'),
            );
            $testId = RowValue::intOrNull($row, 'named_test');
            $test = null;
            $mine = $links[RowValue::int($row, 'std_site_id')] ?? [];
            // The PHP the site is served on now: its named server's, else the first
            // domain's (ServerChoice has already refused sites whose domains differ).
            $phpCurrent = null;
            $namedServer = RowValue::intOrNull($row, 'named_server');
            foreach ($mine as $link) {
                if ($namedServer === null ? $link === $mine[0] : $link['link_id'] === $namedServer) {
                    $phpCurrent = $link['php_version'];
                }
            }
            foreach ($mine as $link) {
                if ($link['link_id'] === $testId) {
                    $where = ServerChoice::pick([$link], null);
                    $test = [
                        'domain' => $link['where'] . $link['path_prefix'],
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
                'upgrade_branch' => RowValue::stringOrNull($row, 'upgrade_branch'),
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
     * The link of this site that --server= or --test= names: a domain or
     * hostname of the site's kind, with the path inside it for a site that is
     * only part of one (example.test/blog).
     */
    private function linkId(int $siteId, string $shortcode, string $server, HostKind $kind): int
    {
        $slash = strpos($server, '/');
        $domain = $slash === false ? $server : substr($server, 0, $slash);
        $path = $slash === false ? '' : substr($server, $slash);
        $ref = $this->domainRef($domain);
        $cols = self::cols($kind);
        $found = $ref['kind'] !== $kind ? [] : $this->rows(
            "SELECT {$cols['link_id']} FROM {$cols['link_table']}
              WHERE std_site_id = ? AND {$cols['domain_id']} = ? AND path_prefix = ?",
            [$siteId, $ref['id'], $path],
        );
        if ($found === []) {
            throw new \InvalidArgumentException(
                "{$server} is not linked to site {$shortcode}. Link it with site-link first."
            );
        }
        return RowValue::int($found[0], $cols['link_id']);
    }

    /**
     * The link a site's server or test column names right now, or null.
     *
     * @param string $column one of the server_col / test_col names in KINDS
     */
    private function namedLink(int $siteId, string $column): ?int
    {
        $found = $this->rows("SELECT {$column} AS named FROM std_sites WHERE std_site_id = ?", [$siteId]);
        return $found === [] ? null : RowValue::intOrNull($found[0], 'named');
    }

    /**
     * Which kind of links a site has: EC2 if any, else DreamHost if any, else
     * none. A site never has both (LinkKindRule).
     */
    private function hostOf(int $siteId): HostKind
    {
        if ($this->rows('SELECT ec2_site_domain_id FROM ec2_site_domains WHERE std_site_id = ? LIMIT 1', [$siteId]) !== []) {
            return HostKind::Ec2;
        }
        if ($this->rows('SELECT dh_site_domain_id FROM dh_site_domains WHERE std_site_id = ? LIMIT 1', [$siteId]) !== []) {
            return HostKind::DreamHost;
        }
        return HostKind::None;
    }

    /**
     * A DreamHost domain or an EC2 hostname by name, whichever it is.
     *
     * @return array{kind: HostKind, id: int}
     */
    private function domainRef(string $domain): array
    {
        $dh = $this->rows('SELECT dh_domain_id FROM dh_domains WHERE domain = ?', [$domain]);
        $ec2 = $this->rows('SELECT ec2_domain_id FROM ec2_domains WHERE domain = ?', [$domain]);
        return match (true) {
            count($dh) > 1 => throw new \InvalidArgumentException("More than one DreamHost account has {$domain}."),
            $dh !== [] && $ec2 !== [] => throw new \InvalidArgumentException(
                "{$domain} is both a DreamHost domain and an EC2 hostname; remove one before linking it."
            ),
            $dh !== [] => ['kind' => HostKind::DreamHost, 'id' => RowValue::int($dh[0], 'dh_domain_id')],
            $ec2 !== [] => ['kind' => HostKind::Ec2, 'id' => RowValue::int($ec2[0], 'ec2_domain_id')],
            default => throw new \InvalidArgumentException("No DreamHost domain or EC2 hostname is called {$domain}."),
        };
    }

    /**
     * The link table and columns of a kind that has them.
     *
     * @return array{link_table: string, link_id: string, domain_id: string, server_col: string, test_col: string}
     */
    private static function cols(HostKind $kind): array
    {
        return match ($kind) {
            HostKind::DreamHost => self::KINDS['dreamhost'],
            HostKind::Ec2 => self::KINDS['ec2'],
            HostKind::None => throw new \LogicException('A site with no links has no link columns.'),
        };
    }

    private function serverId(string $sshAlias): int
    {
        $found = $this->rows('SELECT ec2_server_id FROM ec2_servers WHERE ssh_alias = ?', [$sshAlias]);
        if ($found === []) {
            throw new \InvalidArgumentException(
                "No EC2 server has the alias {$sshAlias}. Enter it with ec2-server-add first."
            );
        }
        return RowValue::int($found[0], 'ec2_server_id');
    }

    private function siteId(string $shortcode): int
    {
        $found = $this->rows('SELECT std_site_id FROM std_sites WHERE shortcode = ?', [$shortcode]);
        if ($found === []) {
            throw new \InvalidArgumentException("No site has the shortcode {$shortcode}.");
        }
        return RowValue::int($found[0], 'std_site_id');
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
