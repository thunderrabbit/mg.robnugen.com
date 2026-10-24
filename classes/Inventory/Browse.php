<?php

declare(strict_types=1);

namespace Inventory;

use Db\RowValue;
use Db\Rows;

/**
 * Read-only queries behind the inventory pages. "Current" means a state whose
 * until_sweep_id is NULL, i.e. still true in the latest imported sweep.
 * Rows come back as the database gives them; the templates escape and show.
 */
final class Browse
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * The newest sweep, and the one before it if there is one.
     *
     * @return array{id: int, swept_on: string, previous_id: int|null, previous_on: string|null}|null
     */
    public function latestSweep(): ?array
    {
        $rows = $this->rows('SELECT dh_sweep_id, swept_on FROM dh_sweeps ORDER BY swept_on DESC, dh_sweep_id DESC LIMIT 2');
        if ($rows === []) {
            return null;
        }
        $previous = $rows[1] ?? null;
        return [
            'id' => RowValue::int($rows[0], 'dh_sweep_id'),
            'swept_on' => RowValue::string($rows[0], 'swept_on'),
            'previous_id' => $previous === null ? null : RowValue::int($previous, 'dh_sweep_id'),
            'previous_on' => $previous === null ? null : RowValue::string($previous, 'swept_on'),
        ];
    }

    /**
     * @return list<array<mixed>>
     */
    public function sweeps(): array
    {
        return $this->rows('SELECT dh_sweep_id, swept_on, provenance, swept_at_utc FROM dh_sweeps ORDER BY swept_on DESC');
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'hosted sites' => $this->count("SELECT COUNT(*) AS n FROM dh_domain_states WHERE until_sweep_id IS NULL AND type = 'hosted'"),
            'other website rows' => $this->count("SELECT COUNT(*) AS n FROM dh_domain_states WHERE until_sweep_id IS NULL AND type <> 'hosted'"),
            'users' => $this->count('SELECT COUNT(*) AS n FROM dh_user_states WHERE until_sweep_id IS NULL'),
            'users with no domain' => $this->count($this->noDomainUsersSql('COUNT(*) AS n')),
            'registrations' => $this->count('SELECT COUNT(*) AS n FROM dh_registration_states WHERE until_sweep_id IS NULL'),
            'databases' => $this->count('SELECT COUNT(*) AS n FROM dh_database_states WHERE until_sweep_id IS NULL'),
            'buckets' => $this->count('SELECT COUNT(*) AS n FROM dh_bucket_states WHERE until_sweep_id IS NULL'),
        ];
    }

    /**
     * Hosted sites per PHP version, oldest version first.
     *
     * @return list<array<mixed>>
     */
    public function phpSummary(): array
    {
        return $this->rows(
            "SELECT COALESCE(s.php_version, '(none)') AS php_version, COUNT(*) AS sites,"
            . " GROUP_CONCAT(d.domain ORDER BY d.domain SEPARATOR ', ') AS domains"
            . ' FROM dh_domain_states s JOIN dh_domains d ON d.dh_domain_id = s.dh_domain_id'
            . " WHERE s.until_sweep_id IS NULL AND s.type = 'hosted'"
            . ' GROUP BY s.php_version ORDER BY s.php_version'
        );
    }

    /**
     * Hosted sites without a real certificate.
     *
     * @return list<array<mixed>>
     */
    public function certProblems(): array
    {
        return $this->rows(
            'SELECT d.dh_domain_id, d.domain, c.https FROM dh_domain_certs c'
            . ' JOIN dh_domains d ON d.dh_domain_id = c.dh_domain_id'
            . ' JOIN dh_domain_states s ON s.dh_domain_id = d.dh_domain_id AND s.until_sweep_id IS NULL'
            . " WHERE c.until_sweep_id IS NULL AND s.type = 'hosted' AND c.https <> 'lets_encrypt' ORDER BY d.domain"
        );
    }

    /**
     * Certificates that expire soonest, counted from the day of the sweep.
     *
     * @return list<array<mixed>>
     */
    public function certExpiries(string $sweptOn, int $limit): array
    {
        return $this->rows(
            'SELECT d.dh_domain_id, d.domain, c.cert_expires, DATEDIFF(c.cert_expires, ?) AS days_left'
            . ' FROM dh_domain_certs c JOIN dh_domains d ON d.dh_domain_id = c.dh_domain_id'
            . " WHERE c.until_sweep_id IS NULL AND c.https = 'lets_encrypt' AND c.cert_expires IS NOT NULL"
            . ' ORDER BY c.cert_expires, d.domain LIMIT ' . $limit,
            [$sweptOn],
        );
    }

    /**
     * @return list<array<mixed>>
     */
    public function registrations(string $sweptOn): array
    {
        return $this->rows(
            'SELECT d.dh_domain_id, d.domain, r.registry, r.expires, DATEDIFF(r.expires, ?) AS days_left,'
            . ' r.auto_renew, r.auto_renew_on, r.transfer_lock, r.privacy, r.status'
            . ' FROM dh_registration_states r JOIN dh_domains d ON d.dh_domain_id = r.dh_domain_id'
            . ' WHERE r.until_sweep_id IS NULL ORDER BY r.expires, d.domain',
            [$sweptOn],
        );
    }

    /**
     * Users who own more than one hosted site: they share one home directory.
     *
     * @return list<array<mixed>>
     */
    public function sharedUsers(): array
    {
        return $this->rows(
            "SELECT u.dh_user_id, u.username, COUNT(*) AS sites, GROUP_CONCAT(d.domain ORDER BY d.domain SEPARATOR ', ') AS domains,"
            . " GROUP_CONCAT(DISTINCT s.php_version ORDER BY s.php_version SEPARATOR ', ') AS php_versions"
            . ' FROM dh_domain_states s JOIN dh_users u ON u.dh_user_id = s.dh_user_id'
            . ' JOIN dh_domains d ON d.dh_domain_id = s.dh_domain_id'
            . " WHERE s.until_sweep_id IS NULL AND s.type = 'hosted'"
            . ' GROUP BY u.dh_user_id, u.username HAVING COUNT(*) > 1 ORDER BY sites DESC, u.username'
        );
    }

    /**
     * Current website rows, optionally narrowed.
     *
     * @return list<array<mixed>>
     */
    public function domains(?string $type, ?string $php, bool $certProblem): array
    {
        $where = ['(s.dh_domain_state_id IS NOT NULL OR r.dh_registration_state_id IS NOT NULL)'];
        $params = [];
        if ($type !== null) {
            $where[] = 's.type = ?';
            $params[] = $type;
        }
        if ($php !== null) {
            $where[] = 's.php_version = ?';
            $params[] = $php;
        }
        if ($certProblem) {
            $where[] = "s.type = 'hosted' AND c.https <> 'lets_encrypt'";
        }
        return $this->rows(
            'SELECT d.dh_domain_id, d.domain, s.type, u.dh_user_id, u.username, s.php_version, c.https, c.cert_expires,'
            . ' r.expires AS reg_expires, r.auto_renew'
            . ' FROM dh_domains d'
            . ' LEFT JOIN dh_domain_states s ON s.dh_domain_id = d.dh_domain_id AND s.until_sweep_id IS NULL'
            . ' LEFT JOIN dh_users u ON u.dh_user_id = s.dh_user_id'
            . ' LEFT JOIN dh_domain_certs c ON c.dh_domain_id = d.dh_domain_id AND c.until_sweep_id IS NULL'
            . ' LEFT JOIN dh_registration_states r ON r.dh_domain_id = d.dh_domain_id AND r.until_sweep_id IS NULL'
            . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY d.base_domain, d.domain',
            $params,
        );
    }

    /**
     * @return array<mixed>|null
     */
    public function domain(int $domainId): ?array
    {
        return $this->one('SELECT dh_domain_id, domain, base_domain, registered_on FROM dh_domains WHERE dh_domain_id = ?', [$domainId]);
    }

    /**
     * Every website state of one domain, oldest first, with the sweep dates it spans.
     *
     * @return list<array<mixed>>
     */
    public function domainSites(int $domainId): array
    {
        return $this->rows(
            'SELECT s.type, u.dh_user_id, u.username, s.server, s.php_version, s.web_directory,'
            . ' f.swept_on AS from_on, e.swept_on AS until_on'
            . ' FROM dh_domain_states s JOIN dh_sweeps f ON f.dh_sweep_id = s.from_sweep_id'
            . ' LEFT JOIN dh_sweeps e ON e.dh_sweep_id = s.until_sweep_id LEFT JOIN dh_users u ON u.dh_user_id = s.dh_user_id'
            . ' WHERE s.dh_domain_id = ? ORDER BY s.from_sweep_id',
            [$domainId],
        );
    }

    /**
     * @return list<array<mixed>>
     */
    public function domainCerts(int $domainId): array
    {
        return $this->rows(
            'SELECT c.https, c.cert_expires, f.swept_on AS from_on, e.swept_on AS until_on'
            . ' FROM dh_domain_certs c JOIN dh_sweeps f ON f.dh_sweep_id = c.from_sweep_id'
            . ' LEFT JOIN dh_sweeps e ON e.dh_sweep_id = c.until_sweep_id WHERE c.dh_domain_id = ? ORDER BY c.from_sweep_id',
            [$domainId],
        );
    }

    /**
     * @return list<array<mixed>>
     */
    public function domainRegistrations(int $domainId): array
    {
        return $this->rows(
            'SELECT r.registry, r.expires, r.auto_renew, r.auto_renew_on, r.transfer_lock, r.privacy, r.status,'
            . ' f.swept_on AS from_on, e.swept_on AS until_on'
            . ' FROM dh_registration_states r JOIN dh_sweeps f ON f.dh_sweep_id = r.from_sweep_id'
            . ' LEFT JOIN dh_sweeps e ON e.dh_sweep_id = r.until_sweep_id WHERE r.dh_domain_id = ? ORDER BY r.from_sweep_id',
            [$domainId],
        );
    }

    /**
     * Current users with what they own, newest-sweep disk use, and whether
     * they have a domain at all.
     *
     * @return list<array<mixed>>
     */
    public function users(?string $filter): array
    {
        $having = match ($filter) {
            'nodomain' => ' HAVING COUNT(ds.dh_domain_state_id) = 0',
            'shared' => ' HAVING COUNT(ds.dh_domain_state_id) > 1',
            default => '',
        };
        return $this->rows(
            'SELECT u.dh_user_id, u.username, st.access, us.disk_bytes, COUNT(ds.dh_domain_state_id) AS sites,'
            . " GROUP_CONCAT(d.domain ORDER BY d.domain SEPARATOR ', ') AS domains"
            . ' FROM dh_users u JOIN dh_user_states st ON st.dh_user_id = u.dh_user_id AND st.until_sweep_id IS NULL'
            . ' LEFT JOIN dh_domain_states ds ON ds.dh_user_id = u.dh_user_id AND ds.until_sweep_id IS NULL'
            . ' LEFT JOIN dh_domains d ON d.dh_domain_id = ds.dh_domain_id'
            . ' LEFT JOIN dh_user_usage us ON us.dh_user_id = u.dh_user_id AND us.dh_sweep_id ='
            . ' (SELECT MAX(dh_sweep_id) FROM dh_sweeps)'
            . ' GROUP BY u.dh_user_id, u.username, st.dh_user_state_id, us.dh_user_usage_id' . $having
            . ' ORDER BY u.username'
        );
    }

    /**
     * @return array<mixed>|null
     */
    public function user(int $userId): ?array
    {
        return $this->one('SELECT dh_user_id, username FROM dh_users WHERE dh_user_id = ?', [$userId]);
    }

    /**
     * @return list<array<mixed>>
     */
    public function userStates(int $userId): array
    {
        return $this->rows(
            'SELECT st.access, f.swept_on AS from_on, e.swept_on AS until_on FROM dh_user_states st'
            . ' JOIN dh_sweeps f ON f.dh_sweep_id = st.from_sweep_id LEFT JOIN dh_sweeps e ON e.dh_sweep_id = st.until_sweep_id'
            . ' WHERE st.dh_user_id = ? ORDER BY st.from_sweep_id',
            [$userId],
        );
    }

    /**
     * @return list<array<mixed>>
     */
    public function userUsage(int $userId): array
    {
        return $this->rows(
            'SELECT w.swept_on, g.disk_bytes FROM dh_user_usage g JOIN dh_sweeps w ON w.dh_sweep_id = g.dh_sweep_id'
            . ' WHERE g.dh_user_id = ? ORDER BY w.swept_on',
            [$userId],
        );
    }

    /**
     * Sites this user owns now.
     *
     * @return list<array<mixed>>
     */
    public function userSites(int $userId): array
    {
        return $this->rows(
            'SELECT d.dh_domain_id, d.domain, s.php_version, s.web_directory FROM dh_domain_states s'
            . ' JOIN dh_domains d ON d.dh_domain_id = s.dh_domain_id'
            . ' WHERE s.dh_user_id = ? AND s.until_sweep_id IS NULL ORDER BY d.domain',
            [$userId],
        );
    }

    /**
     * @return list<array<mixed>>
     */
    public function userCrons(int $userId): array
    {
        return $this->rows(
            'SELECT c.title, cs.active, cs.output_emailed_to FROM dh_crons c'
            . ' JOIN dh_cron_states cs ON cs.dh_cron_id = c.dh_cron_id AND cs.until_sweep_id IS NULL'
            . ' WHERE c.dh_user_id = ? ORDER BY c.title',
            [$userId],
        );
    }

    /**
     * @return list<array<mixed>>
     */
    public function userAgeClues(int $userId): array
    {
        return $this->rows(
            'SELECT clue_kind, existed_by, collected_at_utc, note FROM dh_user_age_clues WHERE dh_user_id = ? ORDER BY existed_by',
            [$userId],
        );
    }

    /**
     * Current databases by size, with growth since the previous sweep and who can reach them.
     *
     * @return list<array<mixed>>
     */
    public function databases(?int $latestId, ?int $previousId): array
    {
        return $this->rows(
            'SELECT db.dh_database_id, db.name, m.name AS server, st.description, cur.size_bytes,'
            . ' cur.size_bytes - prev.size_bytes AS growth,'
            . " (SELECT GROUP_CONCAT(u.username ORDER BY u.username SEPARATOR ', ') FROM dh_db_grants g"
            . ' JOIN dh_db_users u ON u.dh_db_user_id = g.dh_db_user_id'
            . ' WHERE g.dh_database_id = db.dh_database_id AND g.until_sweep_id IS NULL) AS db_users'
            . ' FROM dh_databases db JOIN dh_mysql_servers m ON m.dh_mysql_server_id = db.dh_mysql_server_id'
            . ' JOIN dh_database_states st ON st.dh_database_id = db.dh_database_id AND st.until_sweep_id IS NULL'
            . ' LEFT JOIN dh_database_usage cur ON cur.dh_database_id = db.dh_database_id AND cur.dh_sweep_id <=> ?'
            . ' LEFT JOIN dh_database_usage prev ON prev.dh_database_id = db.dh_database_id AND prev.dh_sweep_id <=> ?'
            . ' ORDER BY cur.size_bytes DESC, db.name',
            [$latestId, $previousId],
        );
    }

    /**
     * Everything that opened or closed in one sweep, across the state tables.
     *
     * @return list<array<mixed>>
     */
    public function changes(int $sweepId): array
    {
        $families = [
            ['users', 'dh_user_states', 'JOIN dh_users x ON x.dh_user_id = s.dh_user_id', 'x.username', "CONCAT('access ', s.access)"],
            ['websites', 'dh_domain_states', 'JOIN dh_domains x ON x.dh_domain_id = s.dh_domain_id', 'x.domain',
                "CONCAT_WS(', ', s.type, CONCAT('php ', s.php_version), CONCAT('owner #', s.dh_user_id))"],
            ['certificates', 'dh_domain_certs', 'JOIN dh_domains x ON x.dh_domain_id = s.dh_domain_id', 'x.domain',
                "CONCAT_WS(', ', s.https, CONCAT('expires ', s.cert_expires))"],
            ['registrations', 'dh_registration_states', 'JOIN dh_domains x ON x.dh_domain_id = s.dh_domain_id', 'x.domain',
                "CONCAT_WS(', ', CONCAT('expires ', s.expires), IF(s.auto_renew, 'auto-renew', 'NO auto-renew'))"],
            ['databases', 'dh_database_states', 'JOIN dh_databases x ON x.dh_database_id = s.dh_database_id', 'x.name', 's.description'],
            ['db grants', 'dh_db_grants', 'JOIN dh_databases x ON x.dh_database_id = s.dh_database_id'
                . ' JOIN dh_db_users y ON y.dh_db_user_id = s.dh_db_user_id', "CONCAT(x.name, ' <- ', y.username)", "''"],
            ['hostnames', 'dh_mysql_hosts', '', 's.hostname', "CONCAT('server #', s.dh_mysql_server_id)"],
            ['crons', 'dh_cron_states', 'JOIN dh_crons x ON x.dh_cron_id = s.dh_cron_id', 'x.title',
                "CONCAT_WS(', ', IF(s.active, 'active', 'inactive'), s.output_emailed_to)"],
            ['buckets', 'dh_bucket_states', 'JOIN dh_buckets x ON x.dh_bucket_id = s.dh_bucket_id', 'x.name', "''"],
        ];
        $selects = [];
        $params = [];
        foreach ($families as [$family, $table, $join, $label, $detail]) {
            foreach (['opened' => 'from_sweep_id', 'closed' => 'until_sweep_id'] as $what => $column) {
                $selects[] = "SELECT '{$family}' AS family, {$label} AS label, '{$what}' AS what, {$detail} AS detail"
                    . " FROM {$table} s {$join} WHERE s.{$column} = ?";
                $params[] = $sweepId;
            }
        }
        return $this->rows(implode(' UNION ALL ', $selects) . ' ORDER BY family, label, what DESC', $params);
    }

    private function noDomainUsersSql(string $select): string
    {
        return "SELECT {$select} FROM dh_user_states st"
            . ' WHERE st.until_sweep_id IS NULL AND NOT EXISTS (SELECT 1 FROM dh_domain_states ds'
            . ' WHERE ds.dh_user_id = st.dh_user_id AND ds.until_sweep_id IS NULL)';
    }

    /**
     * @param list<int|string|null> $params
     * @return list<array<mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return Rows::all($statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param list<int|string|null> $params
     * @return array<mixed>|null
     */
    private function one(string $sql, array $params = []): ?array
    {
        return $this->rows($sql, $params)[0] ?? null;
    }

    /**
     * @param list<int|string|null> $params
     */
    private function count(string $sql, array $params = []): int
    {
        $row = $this->one($sql, $params);
        return $row === null ? 0 : RowValue::int($row, 'n');
    }
}
