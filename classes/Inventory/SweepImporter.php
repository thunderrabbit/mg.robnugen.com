<?php

declare(strict_types=1);

namespace Inventory;

use Db\RowValue;
use Db\Rows;

/**
 * Writes one checked sweep (mg #775): finds or creates each thing's identity
 * row, then versions what changed about it with SpanPlan. All of it in one
 * transaction, so a failure leaves nothing behind.
 *
 * Sweeps go in date order per account; an older one, or one already in, is
 * refused. Backfilling means emptying the layer-1 state tables and replaying
 * every sweep in date order (SCHEMA.md); nothing outside layer 1 refers to a
 * sweep id, so a replay loses nothing.
 */
final class SweepImporter
{
    private const string USERS_IN_ACCOUNT =
        's.dh_user_id IN (SELECT dh_user_id FROM dh_users WHERE dh_account_id = ?)';
    private const string DOMAINS_IN_ACCOUNT =
        's.dh_domain_id IN (SELECT dh_domain_id FROM dh_domains WHERE dh_account_id = ?)';
    private const string SERVERS_IN_ACCOUNT =
        's.dh_mysql_server_id IN (SELECT dh_mysql_server_id FROM dh_mysql_servers WHERE dh_account_id = ?)';
    private const string DATABASES_IN_ACCOUNT =
        's.dh_database_id IN (SELECT d.dh_database_id FROM dh_databases d'
        . ' JOIN dh_mysql_servers m ON m.dh_mysql_server_id = d.dh_mysql_server_id WHERE m.dh_account_id = ?)';
    private const string OBJECTS_USERS_IN_ACCOUNT =
        's.dh_objects_user_id IN (SELECT dh_objects_user_id FROM dh_objects_users WHERE dh_account_id = ?)';
    private const string BUCKETS_IN_ACCOUNT =
        's.dh_bucket_id IN (SELECT b.dh_bucket_id FROM dh_buckets b'
        . ' JOIN dh_objects_users o ON o.dh_objects_user_id = b.dh_objects_user_id WHERE o.dh_account_id = ?)';
    private const string CRONS_IN_ACCOUNT =
        's.dh_cron_id IN (SELECT c.dh_cron_id FROM dh_crons c'
        . ' JOIN dh_users u ON u.dh_user_id = c.dh_user_id WHERE u.dh_account_id = ?)';

    /** @var array<string, array{new: int, opened: int, closed: int, unchanged: int}> */
    private array $stats = [];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return array<string, array{new: int, opened: int, closed: int, unchanged: int}> per family
     *     what was created, opened, closed and left alone
     * @throws SweepRefusedException
     */
    public function import(SweepPayload $sweep, bool $dryRun = false): array
    {
        $this->stats = [];
        $this->pdo->beginTransaction();
        try {
            $this->importAll($sweep);
            if ($dryRun) {
                $this->pdo->rollBack();
            } else {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->stats;
    }

    private function importAll(SweepPayload $sweep): void
    {
        $accountId = $this->ensureAccount($sweep);
        $sweepId = $this->openSweep($sweep, $accountId);

        $userIds = $this->importUsers($sweep, $accountId, $sweepId);
        $this->importDomains($sweep, $accountId, $sweepId, $userIds);
        $this->importMysql($sweep, $accountId, $sweepId);
        $this->importCrons($sweep, $sweepId, $accountId, $userIds);
        $this->importObjects($sweep, $accountId, $sweepId);
    }

    private function ensureAccount(SweepPayload $sweep): int
    {
        $found = Rows::one($this->run(
            'SELECT dh_account_id FROM dh_accounts WHERE panel_account_id = ?',
            [$sweep->panelAccountId],
        )->fetch(\PDO::FETCH_ASSOC));
        if ($found !== null) {
            $id = RowValue::int($found, 'dh_account_id');
            $this->run('UPDATE dh_accounts SET account_name = ? WHERE dh_account_id = ?', [$sweep->accountName, $id]);
            return $id;
        }
        $this->run(
            'INSERT INTO dh_accounts (panel_account_id, account_name) VALUES (?, ?)',
            [$sweep->panelAccountId, $sweep->accountName],
        );
        return $this->lastId();
    }

    private function openSweep(SweepPayload $sweep, int $accountId): int
    {
        $newest = Rows::one($this->run(
            'SELECT MAX(swept_on) AS newest FROM dh_sweeps WHERE dh_account_id = ?',
            [$accountId],
        )->fetch(\PDO::FETCH_ASSOC));
        $newestOn = $newest === null ? null : RowValue::stringOrNull($newest, 'newest');
        if ($newestOn !== null && $newestOn >= $sweep->sweptOn) {
            throw new SweepRefusedException(
                "sweep {$sweep->sweptOn} is not newer than {$newestOn}, which is already imported;"
                . ' sweeps go in date order (backfill = rebuild, see SCHEMA.md)'
            );
        }
        $this->run(
            'INSERT INTO dh_sweeps (dh_account_id, swept_on, provenance, swept_at_utc, source_dir)'
            . " VALUES (?, ?, 'swept', ?, ?)",
            [$accountId, $sweep->sweptOn, $sweep->sweptAtUtc, $sweep->sourceDir],
        );
        return $this->lastId();
    }

    /**
     * @return array<int|string, int> dh_user_id by username
     */
    private function importUsers(SweepPayload $sweep, int $accountId, int $sweepId): array
    {
        $rows = [];
        foreach ($sweep->users as $user) {
            $rows[] = ['ref' => $user['username'], 'key' => ['dh_account_id' => $accountId, 'username' => $user['username']], 'extra' => []];
        }
        $ids = $this->ensureIdentities('users', 'dh_users', 'dh_user_id', $rows);

        $states = [];
        foreach ($sweep->users as $user) {
            $states[] = new SweptSpan(['dh_user_id' => $ids[$user['username']]], ['access' => $user['access']]);
        }
        $this->syncSpans(
            'user states',
            new SpanTable('dh_user_states', 'dh_user_state_id', ['dh_user_id'], ['access'], self::USERS_IN_ACCOUNT),
            $states,
            $accountId,
            $sweepId,
        );

        foreach ($sweep->users as $user) {
            $this->run(
                'INSERT INTO dh_user_usage (dh_user_id, dh_sweep_id, disk_bytes) VALUES (?, ?, ?)',
                [$ids[$user['username']], $sweepId, $user['disk_bytes']],
            );
        }
        return $ids;
    }

    /**
     * @param array<int|string, int> $userIds
     */
    private function importDomains(SweepPayload $sweep, int $accountId, int $sweepId, array $userIds): void
    {
        $createdOn = [];
        foreach ($sweep->registrations as $registration) {
            $createdOn[$registration['domain']] = $registration['created'];
        }
        $names = array_map(static fn (array $row): string => $row['domain'], $sweep->domains);
        $names = array_values(array_unique([...$names, ...array_keys($createdOn)]));

        $rows = [];
        foreach ($names as $name) {
            $name = (string) $name;
            $rows[] = [
                'ref' => $name,
                'key' => ['dh_account_id' => $accountId, 'domain' => $name],
                'extra' => ['base_domain' => SweepPayload::baseDomain($name), 'registered_on' => $createdOn[$name] ?? null],
            ];
        }
        $ids = $this->ensureIdentities('domains', 'dh_domains', 'dh_domain_id', $rows);

        $sites = [];
        $certs = [];
        foreach ($sweep->domains as $row) {
            $key = ['dh_domain_id' => $ids[$row['domain']]];
            $owner = $row['username'] === null ? null : $userIds[$row['username']];
            $sites[] = new SweptSpan($key, [
                'type' => $row['type'],
                'dh_user_id' => $owner,
                'server' => $row['server'],
                'php_version' => $row['php_version'],
                'web_directory' => $row['web_directory'],
            ]);
            $certs[] = new SweptSpan($key, ['https' => $row['https'], 'cert_expires' => $row['cert_expires']]);
        }
        $registered = [];
        foreach ($sweep->registrations as $row) {
            $registered[] = new SweptSpan(['dh_domain_id' => $ids[$row['domain']]], [
                'registry' => $row['registry'],
                'expires' => $row['expires'],
                'auto_renew' => $row['auto_renew'],
                'auto_renew_on' => $row['auto_renew_on'],
                'transfer_lock' => $row['transfer_lock'],
                'privacy' => $row['privacy'],
                'status' => $row['status'],
                'hidden' => $row['hidden'],
            ]);
        }

        $this->syncSpans('website states', new SpanTable(
            'dh_domain_states',
            'dh_domain_state_id',
            ['dh_domain_id'],
            ['type', 'dh_user_id', 'server', 'php_version', 'web_directory'],
            self::DOMAINS_IN_ACCOUNT,
        ), $sites, $accountId, $sweepId);
        $this->syncSpans('certificates', new SpanTable(
            'dh_domain_certs',
            'dh_domain_cert_id',
            ['dh_domain_id'],
            ['https', 'cert_expires'],
            self::DOMAINS_IN_ACCOUNT,
        ), $certs, $accountId, $sweepId);
        $this->syncSpans('registrations', new SpanTable(
            'dh_registration_states',
            'dh_registration_state_id',
            ['dh_domain_id'],
            ['registry', 'expires', 'auto_renew', 'auto_renew_on', 'transfer_lock', 'privacy', 'status', 'hidden'],
            self::DOMAINS_IN_ACCOUNT,
        ), $registered, $accountId, $sweepId);
    }

    private function importMysql(SweepPayload $sweep, int $accountId, int $sweepId): void
    {
        $serverKeys = [];
        foreach ($sweep->servers as $server) {
            $serverKeys[] = ['ref' => $server['name'], 'key' => ['dh_account_id' => $accountId, 'name' => $server['name']]];
        }
        $serverIds = $this->syncLifetime(
            'mysql servers',
            'dh_mysql_servers',
            'dh_mysql_server_id',
            $serverKeys,
            's.dh_account_id = ?',
            $accountId,
            $sweepId,
        );

        $hosts = [];
        foreach ($sweep->servers as $server) {
            foreach ($server['hostnames'] as $hostname) {
                $hosts[] = new SweptSpan(['hostname' => $hostname], ['dh_mysql_server_id' => $serverIds[$server['name']]]);
            }
        }
        $this->syncSpans('phpMyAdmin hostnames', new SpanTable(
            'dh_mysql_hosts',
            'dh_mysql_host_id',
            ['hostname'],
            ['dh_mysql_server_id'],
            self::SERVERS_IN_ACCOUNT,
        ), $hosts, $accountId, $sweepId);

        $databaseRows = [];
        $dbUserKeys = [];
        foreach ($sweep->databases as $database) {
            $serverId = $serverIds[$database['server']];
            $databaseRows[] = [
                'ref' => $database['server'] . "\t" . $database['name'],
                'key' => ['dh_mysql_server_id' => $serverId, 'name' => $database['name']],
                'extra' => [],
            ];
            foreach ($database['db_users'] as $dbUser) {
                $dbUserKeys[$database['server'] . "\t" . $dbUser] = [
                    'ref' => $database['server'] . "\t" . $dbUser,
                    'key' => ['dh_mysql_server_id' => $serverId, 'username' => $dbUser],
                ];
            }
        }
        $databaseIds = $this->ensureIdentities('databases', 'dh_databases', 'dh_database_id', $databaseRows);
        $dbUserIds = $this->syncLifetime(
            'mysql users',
            'dh_db_users',
            'dh_db_user_id',
            array_values($dbUserKeys),
            's.dh_mysql_server_id IN (SELECT dh_mysql_server_id FROM dh_mysql_servers WHERE dh_account_id = ?)',
            $accountId,
            $sweepId,
        );

        $states = [];
        $grants = [];
        foreach ($sweep->databases as $database) {
            $databaseId = $databaseIds[$database['server'] . "\t" . $database['name']];
            $states[] = new SweptSpan(['dh_database_id' => $databaseId], ['description' => $database['description']]);
            $this->run(
                'INSERT INTO dh_database_usage (dh_database_id, dh_sweep_id, size_bytes) VALUES (?, ?, ?)',
                [$databaseId, $sweepId, $database['size_bytes']],
            );
            foreach ($database['db_users'] as $dbUser) {
                $grants[] = new SweptSpan(
                    ['dh_database_id' => $databaseId, 'dh_db_user_id' => $dbUserIds[$database['server'] . "\t" . $dbUser]],
                    [],
                );
            }
        }
        $this->syncSpans('database states', new SpanTable(
            'dh_database_states',
            'dh_database_state_id',
            ['dh_database_id'],
            ['description'],
            self::DATABASES_IN_ACCOUNT,
        ), $states, $accountId, $sweepId);
        $this->syncSpans('database grants', new SpanTable(
            'dh_db_grants',
            'dh_db_grant_id',
            ['dh_database_id', 'dh_db_user_id'],
            [],
            self::DATABASES_IN_ACCOUNT,
        ), $grants, $accountId, $sweepId);
    }

    /**
     * @param array<int|string, int> $userIds
     */
    private function importCrons(SweepPayload $sweep, int $sweepId, int $accountId, array $userIds): void
    {
        $rows = [];
        foreach ($sweep->crons as $cron) {
            $rows[] = [
                'ref' => $cron['username'] . "\t" . $cron['title'],
                'key' => ['dh_user_id' => $userIds[$cron['username']], 'title' => $cron['title']],
                'extra' => [],
            ];
        }
        $ids = $this->ensureIdentities('crons', 'dh_crons', 'dh_cron_id', $rows);

        $states = [];
        foreach ($sweep->crons as $cron) {
            $states[] = new SweptSpan(
                ['dh_cron_id' => $ids[$cron['username'] . "\t" . $cron['title']]],
                ['active' => $cron['active'], 'output_emailed_to' => $cron['output_emailed_to']],
            );
        }
        $this->syncSpans('cron states', new SpanTable(
            'dh_cron_states',
            'dh_cron_state_id',
            ['dh_cron_id'],
            ['active', 'output_emailed_to'],
            self::CRONS_IN_ACCOUNT,
        ), $states, $accountId, $sweepId);
    }

    private function importObjects(SweepPayload $sweep, int $accountId, int $sweepId): void
    {
        $rows = [];
        foreach ($sweep->objectsUsers as $user) {
            $rows[] = [
                'ref' => $user['username'],
                'key' => ['dh_account_id' => $accountId, 'username' => $user['username']],
                'extra' => ['endpoint' => $user['endpoint']],
            ];
        }
        $userIds = $this->ensureIdentities('DreamObjects users', 'dh_objects_users', 'dh_objects_user_id', $rows);

        $bucketRows = [];
        $userStates = [];
        $bucketStates = [];
        foreach ($sweep->objectsUsers as $user) {
            $userStates[] = new SweptSpan(['dh_objects_user_id' => $userIds[$user['username']]], ['key_count' => $user['key_count']]);
            foreach ($user['buckets'] as $bucket) {
                $bucketRows[] = [
                    'ref' => $user['username'] . "\t" . $bucket,
                    'key' => ['dh_objects_user_id' => $userIds[$user['username']], 'name' => $bucket],
                    'extra' => [],
                ];
            }
        }
        $bucketIds = $this->ensureIdentities('buckets', 'dh_buckets', 'dh_bucket_id', $bucketRows);
        foreach ($sweep->objectsUsers as $user) {
            foreach ($user['buckets'] as $bucket) {
                // permissions and size are not swept yet; the row still dates the bucket.
                $bucketStates[] = new SweptSpan(
                    ['dh_bucket_id' => $bucketIds[$user['username'] . "\t" . $bucket]],
                    ['permissions' => null, 'size_bytes' => null],
                );
            }
        }

        $this->syncSpans('DreamObjects user states', new SpanTable(
            'dh_objects_user_states',
            'dh_objects_user_state_id',
            ['dh_objects_user_id'],
            ['key_count'],
            self::OBJECTS_USERS_IN_ACCOUNT,
        ), $userStates, $accountId, $sweepId);
        $this->syncSpans('bucket states', new SpanTable(
            'dh_bucket_states',
            'dh_bucket_state_id',
            ['dh_bucket_id'],
            ['permissions', 'size_bytes'],
            self::BUCKETS_IN_ACCOUNT,
        ), $bucketStates, $accountId, $sweepId);
    }

    /**
     * Finds or creates one identity row per thing. Attributes in `extra` are
     * fixed for life: they are written at creation, filled in later if they
     * were NULL, and a sweep that contradicts a stored one is refused.
     *
     * @param list<array{ref: string, key: array<string, int|string>, extra: array<string, string|null>}> $rows
     * @return array<int|string, int> id by ref
     */
    private function ensureIdentities(string $family, string $table, string $idColumn, array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $keyColumns = array_keys($row['key']);
            $extraColumns = array_keys($row['extra']);
            $selected = array_map(self::quote(...), [$idColumn, ...$extraColumns]);
            $found = Rows::one($this->run(
                'SELECT ' . implode(', ', $selected) . ' FROM ' . self::quote($table)
                . ' WHERE ' . self::equalities($keyColumns),
                array_values($row['key']),
            )->fetch(\PDO::FETCH_ASSOC));

            if ($found === null) {
                $columns = [...$keyColumns, ...$extraColumns];
                $this->run(
                    'INSERT INTO ' . self::quote($table) . ' (' . implode(', ', array_map(self::quote(...), $columns)) . ')'
                    . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
                    [...array_values($row['key']), ...array_values($row['extra'])],
                );
                $ids[$row['ref']] = $this->lastId();
                $this->count($family, 'new');
                continue;
            }

            $id = RowValue::int($found, $idColumn);
            foreach ($row['extra'] as $column => $wanted) {
                $stored = RowValue::stringOrNull($found, $column);
                if ($wanted === null || $stored === $wanted) {
                    continue;
                }
                if ($stored !== null) {
                    throw new SweepFormatException(
                        "{$family}: '{$row['ref']}' has {$column} '{$stored}' stored but the sweep says '{$wanted}'"
                    );
                }
                $this->run(
                    'UPDATE ' . self::quote($table) . ' SET ' . self::quote($column) . ' = ? WHERE ' . self::quote($idColumn) . ' = ?',
                    [$wanted, $id],
                );
            }
            $ids[$row['ref']] = $id;
        }
        return $ids;
    }

    /**
     * Identities with no attributes of their own: first_seen_sweep_id on
     * creation, gone_in_sweep_id when they leave a sweep, cleared if they return.
     *
     * @param list<array{ref: string, key: array<string, int|string>}> $rows
     * @return array<int|string, int> id by ref
     */
    private function syncLifetime(
        string $family,
        string $table,
        string $idColumn,
        array $rows,
        string $scopeSql,
        int $accountId,
        int $sweepId,
    ): array {
        $ids = [];
        foreach ($rows as $row) {
            $found = Rows::one($this->run(
                'SELECT ' . self::quote($idColumn) . ', gone_in_sweep_id FROM ' . self::quote($table)
                . ' WHERE ' . self::equalities(array_keys($row['key'])),
                array_values($row['key']),
            )->fetch(\PDO::FETCH_ASSOC));

            if ($found === null) {
                $columns = [...array_keys($row['key']), 'first_seen_sweep_id'];
                $this->run(
                    'INSERT INTO ' . self::quote($table) . ' (' . implode(', ', array_map(self::quote(...), $columns)) . ')'
                    . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
                    [...array_values($row['key']), $sweepId],
                );
                $ids[$row['ref']] = $this->lastId();
                $this->count($family, 'new');
                continue;
            }

            $id = RowValue::int($found, $idColumn);
            $ids[$row['ref']] = $id;
            if (RowValue::intOrNull($found, 'gone_in_sweep_id') !== null) {
                $this->run('UPDATE ' . self::quote($table) . ' SET gone_in_sweep_id = NULL WHERE ' . self::quote($idColumn) . ' = ?', [$id]);
                $this->count($family, 'opened');
            } else {
                $this->count($family, 'unchanged');
            }
        }

        $present = array_flip($ids);
        $stillThere = $this->rows(
            'SELECT s.' . self::quote($idColumn) . ' FROM ' . self::quote($table) . ' s'
            . ' WHERE s.gone_in_sweep_id IS NULL AND ' . $scopeSql,
            [$accountId],
        );
        foreach ($stillThere as $row) {
            $id = RowValue::int($row, $idColumn);
            if (!isset($present[$id])) {
                $this->run('UPDATE ' . self::quote($table) . ' SET gone_in_sweep_id = ? WHERE ' . self::quote($idColumn) . ' = ?', [$sweepId, $id]);
                $this->count($family, 'closed');
            }
        }
        return $ids;
    }

    /**
     * @param list<SweptSpan> $swept
     */
    private function syncSpans(string $family, SpanTable $table, array $swept, int $accountId, int $sweepId): void
    {
        $sweptByKey = [];
        foreach ($swept as $span) {
            $sweptByKey[$span->keyString()] = $span;
        }

        $columns = [...$table->keyColumns, ...$table->attrColumns];
        $open = [];
        $loaded = $this->rows(
            'SELECT s.' . self::quote($table->idColumn) . ', ' . implode(', ', array_map(static fn (string $c): string => 's.' . self::quote($c), $columns))
            . ' FROM ' . self::quote($table->table) . ' s WHERE s.until_sweep_id IS NULL AND ' . $table->scopeSql,
            [$accountId],
        );
        foreach ($loaded as $row) {
            $key = [];
            foreach ($table->keyColumns as $column) {
                $key[] = SpanPlan::normalise($row[$column] ?? null);
            }
            $attrs = [];
            foreach ($table->attrColumns as $column) {
                $attrs[$column] = SpanPlan::normalise($row[$column] ?? null);
            }
            $open[implode('|', $key)] = new OpenSpan(RowValue::int($row, $table->idColumn), $attrs);
        }

        $changes = SpanPlan::diff($open, $sweptByKey);

        foreach ($changes->closeSpanIds as $spanId) {
            $this->run(
                'UPDATE ' . self::quote($table->table) . ' SET until_sweep_id = ? WHERE ' . self::quote($table->idColumn) . ' = ?',
                [$sweepId, $spanId],
            );
        }
        foreach ($changes->opens as $span) {
            $insert = [...$table->keyColumns, ...$table->attrColumns, 'from_sweep_id'];
            $this->run(
                'INSERT INTO ' . self::quote($table->table) . ' (' . implode(', ', array_map(self::quote(...), $insert)) . ')'
                . ' VALUES (' . implode(', ', array_fill(0, count($insert), '?')) . ')',
                [...array_values($span->key), ...array_values($span->attrs), $sweepId],
            );
        }

        $this->stats[$family] = [
            'new' => $this->stats[$family]['new'] ?? 0,
            'opened' => count($changes->opens),
            'closed' => count($changes->closeSpanIds),
            'unchanged' => $changes->unchanged,
        ];
    }

    private function count(string $family, string $what): void
    {
        $stats = $this->stats[$family] ?? ['new' => 0, 'opened' => 0, 'closed' => 0, 'unchanged' => 0];
        $this->stats[$family] = match ($what) {
            'new' => [...$stats, 'new' => $stats['new'] + 1],
            'opened' => [...$stats, 'opened' => $stats['opened'] + 1],
            'closed' => [...$stats, 'closed' => $stats['closed'] + 1],
            default => [...$stats, 'unchanged' => $stats['unchanged'] + 1],
        };
    }

    /**
     * @param list<string> $columns
     */
    private static function equalities(array $columns): string
    {
        return implode(' AND ', array_map(static fn (string $c): string => self::quote($c) . ' = ?', $columns));
    }

    /**
     * Table and column names come from this class, never from a sweep; the
     * backticks are for the reserved words among them (`type`, `domain`).
     */
    private static function quote(string $name): string
    {
        if (preg_match('/^[a-z_]+$/', $name) !== 1) {
            throw new \LogicException("Not an identifier: {$name}");
        }
        return "`{$name}`";
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

    private function lastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }
}
