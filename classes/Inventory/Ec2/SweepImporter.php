<?php

declare(strict_types=1);

namespace Inventory\Ec2;

use Database\Rows;
use Database\RowValue;
use Inventory\OpenSpan;
use Inventory\SpanPlan;
use Inventory\SweepRefusedException;
use Inventory\SweptSpan;

/**
 * Writes one EC2 sweep (Ec2\SweepPayload) into ec2_sweeps and
 * ec2_domain_states, the way Inventory\SweepImporter writes a DreamHost
 * sweep: a hostname's facts stay open until a sweep sees different ones.
 * The first sweep of a server also proves its ssh login, once.
 *
 * mg is the authority on servers and hostnames: a document about a server
 * or hostname mg has not been told of, or one that names a different
 * directory, is refused whole and nothing is written.
 */
final readonly class SweepImporter
{
    private const array ATTRS = [
        'php_version',
        'probe_route',
        'directory_exists',
        'is_git_checkout',
        'head_commit',
        'head_branch',
        'deployed_at_utc',
    ];

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<string, array{new: int, opened: int, closed: int, unchanged: int}> by family
     */
    public function import(SweepPayload $sweep, bool $dryRun = false): array
    {
        $this->pdo->beginTransaction();
        try {
            $stats = $this->importAll($sweep);
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
        return $stats;
    }

    /**
     * @return array<string, array{new: int, opened: int, closed: int, unchanged: int}>
     */
    private function importAll(SweepPayload $sweep): array
    {
        $serverId = $this->serverId($sweep->sshAlias);
        $domainIds = $this->domainIds($serverId, $sweep);

        $this->run(
            'INSERT INTO ec2_sweeps (ec2_server_id, swept_at_utc, web_server, cli_php) VALUES (?, ?, ?, ?)',
            [$serverId, $sweep->sweptAtUtc, $sweep->webServer, $sweep->cliPhp],
        );
        $sweepId = (int) $this->pdo->lastInsertId();

        $swept = [];
        foreach ($sweep->domains as $domain) {
            $attrs = [];
            foreach (self::ATTRS as $column) {
                $attrs[$column] = $domain[$column];
            }
            $span = new SweptSpan(['ec2_domain_id' => $domainIds[$domain['domain']]], $attrs);
            $swept[$span->keyString()] = $span;
        }
        $changes = $this->syncStates($serverId, $sweepId, $swept);

        $this->run(
            'UPDATE ec2_servers SET login_confirmed_on = CURDATE() WHERE ec2_server_id = ? AND login_confirmed_on IS NULL',
            [$serverId],
        );

        return ['hostname states' => $changes];
    }

    private function serverId(string $sshAlias): int
    {
        $found = $this->rows('SELECT ec2_server_id FROM ec2_servers WHERE ssh_alias = ?', [$sshAlias]);
        if ($found === []) {
            throw new SweepRefusedException(
                "no EC2 server has the alias {$sshAlias}; enter it with site.sh ec2-server-add"
            );
        }
        return RowValue::int($found[0], 'ec2_server_id');
    }

    /**
     * @return array<string, int> ec2_domain_id by hostname
     */
    private function domainIds(int $serverId, SweepPayload $sweep): array
    {
        $known = [];
        foreach ($this->rows('SELECT ec2_domain_id, domain, directory FROM ec2_domains WHERE ec2_server_id = ?', [$serverId]) as $row) {
            $known[RowValue::string($row, 'domain')] = $row;
        }
        $ids = [];
        foreach ($sweep->domains as $domain) {
            $row = $known[$domain['domain']] ?? null;
            if ($row === null) {
                throw new SweepRefusedException(
                    "{$domain['domain']} is not a hostname of server {$sweep->sshAlias}; enter it with site.sh ec2-domain-add"
                );
            }
            $directory = RowValue::string($row, 'directory');
            if ($directory !== $domain['directory']) {
                throw new SweepRefusedException(
                    "mg has {$domain['domain']} served from {$directory}, the sweep says {$domain['directory']}"
                );
            }
            $ids[$domain['domain']] = RowValue::int($row, 'ec2_domain_id');
        }
        return $ids;
    }

    /**
     * @param array<int|string, SweptSpan> $swept by key string
     * @return array{new: int, opened: int, closed: int, unchanged: int}
     */
    private function syncStates(int $serverId, int $sweepId, array $swept): array
    {
        $open = [];
        $columns = implode(', ', array_map(static fn (string $c): string => 's.' . $c, self::ATTRS));
        $loaded = $this->rows(
            "SELECT s.ec2_domain_state_id, s.ec2_domain_id, {$columns}
               FROM ec2_domain_states s
               JOIN ec2_domains d ON d.ec2_domain_id = s.ec2_domain_id
              WHERE s.until_sweep_id IS NULL AND d.ec2_server_id = ?",
            [$serverId],
        );
        foreach ($loaded as $row) {
            $attrs = [];
            foreach (self::ATTRS as $column) {
                $attrs[$column] = SpanPlan::normalise($row[$column] ?? null);
            }
            $key = SpanPlan::keyString(['ec2_domain_id' => RowValue::int($row, 'ec2_domain_id')]);
            $open[$key] = new OpenSpan(RowValue::int($row, 'ec2_domain_state_id'), $attrs);
        }

        $changes = SpanPlan::diff($open, $swept);

        foreach ($changes->closeSpanIds as $spanId) {
            $this->run(
                'UPDATE ec2_domain_states SET until_sweep_id = ? WHERE ec2_domain_state_id = ?',
                [$sweepId, $spanId],
            );
        }
        $insert = ['ec2_domain_id', ...self::ATTRS, 'from_sweep_id'];
        foreach ($changes->opens as $span) {
            $this->run(
                'INSERT INTO ec2_domain_states (' . implode(', ', $insert) . ')'
                . ' VALUES (' . implode(', ', array_fill(0, count($insert), '?')) . ')',
                [...array_values($span->key), ...array_values($span->attrs), $sweepId],
            );
        }

        return [
            'new' => 0,
            'opened' => count($changes->opens),
            'closed' => count($changes->closeSpanIds),
            'unchanged' => $changes->unchanged,
        ];
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
