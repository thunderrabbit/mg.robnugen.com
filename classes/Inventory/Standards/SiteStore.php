<?php

declare(strict_types=1);

namespace Inventory\Standards;

use Db\Rows;
use Db\RowValue;

/**
 * The facts about a site that are kept by hand: its shortcode and standard,
 * the mg project holding its notes, where its code is, and which DreamHost
 * domains serve it. Everything about steps comes from the step files instead.
 *
 * Every refusal is an InvalidArgumentException whose message is a sentence
 * for the person at the command line.
 */
final class SiteStore
{
    public function __construct(private readonly \PDO $pdo)
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
     * @param array{project_id?: ?int, checkout_path?: ?string, trunk_branch?: string, github_repo?: ?string, autonomy?: string} $changes
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
        if (array_key_exists('trunk_branch', $changes)) {
            if (preg_match('#\A[A-Za-z0-9._/-]{1,100}\z#', $changes['trunk_branch']) !== 1) {
                throw new \InvalidArgumentException("\"{$changes['trunk_branch']}\" is not a branch name.");
            }
            $sets[] = 'trunk_branch = ?';
            $params[] = $changes['trunk_branch'];
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
        $removed = $this->run(
            'DELETE FROM dh_site_domains WHERE std_site_id = ? AND dh_domain_id = ? AND path_prefix = ?',
            [$this->siteId($shortcode), $this->domainId($domain), $pathPrefix],
        )->rowCount();
        if ($removed === 0) {
            throw new \InvalidArgumentException("{$domain}{$pathPrefix} is not linked to site {$shortcode}.");
        }
    }

    /**
     * What the scanner on Lemur 13 needs to know about each site.
     *
     * @return list<array{shortcode: string, standard: string, checkout_path: ?string, trunk_branch: string}>
     */
    public function forScanner(): array
    {
        $sites = [];
        $rows = $this->rows(
            'SELECT s.shortcode, t.slug AS standard, s.checkout_path, s.trunk_branch
               FROM std_sites s JOIN std_standards t ON t.std_standard_id = s.std_standard_id
              ORDER BY s.shortcode'
        );
        foreach ($rows as $row) {
            $sites[] = [
                'shortcode' => RowValue::string($row, 'shortcode'),
                'standard' => RowValue::string($row, 'standard'),
                'checkout_path' => RowValue::stringOrNull($row, 'checkout_path'),
                'trunk_branch' => RowValue::string($row, 'trunk_branch'),
            ];
        }
        return $sites;
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
