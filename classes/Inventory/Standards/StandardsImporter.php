<?php

declare(strict_types=1);

namespace Inventory\Standards;

use Db\Rows;
use Db\RowValue;
use Inventory\OpenSpan;
use Inventory\SpanPlan;
use Inventory\SweptSpan;

/**
 * Writes one scan into the std_* tables, in one transaction.
 *
 * The step files are the truth, so the standards, tracks and steps are
 * brought into line with the document: rows are matched by slug and updated
 * in place (ids stay stable), and a step the files no longer have is retired,
 * never deleted, so its history stays.
 *
 * Check outcomes are versioned like the sweep tables: a std_step_states row
 * holds from from_scan_id until until_scan_id, and NULL means it still holds.
 * A site the scanner skipped keeps every result it had.
 */
final class StandardsImporter
{
    /** @var array<string, int> */
    private array $stats = [];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return array<string, int> what the import did, by name
     * @throws ScanRefusedException
     */
    public function import(ScanPayload $payload, bool $dryRun = false): array
    {
        $this->stats = [
            'steps_new' => 0,
            'steps_updated' => 0,
            'steps_retired' => 0,
            'sites_scanned' => 0,
            'sites_skipped' => 0,
            'states_opened' => 0,
            'states_closed' => 0,
            'states_unchanged' => 0,
        ];
        $this->pdo->beginTransaction();
        try {
            $scanId = $this->openScan($payload);
            $this->syncSteps($payload, $scanId);
            foreach ($payload->sites as $site) {
                $this->importSite($site, $scanId);
            }
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

    private function openScan(ScanPayload $payload): int
    {
        $notOlder = $this->rows(
            'SELECT scanned_at_utc FROM std_scans WHERE scanned_at_utc >= ? ORDER BY scanned_at_utc DESC LIMIT 1',
            [$payload->scannedAtUtc],
        );
        if ($notOlder !== []) {
            throw new ScanRefusedException(
                "a scan from {$payload->scannedAtUtc} UTC is not newer than the latest one stored ("
                . RowValue::string($notOlder[0], 'scanned_at_utc') . ')'
            );
        }
        $this->run(
            'INSERT INTO std_scans (scanned_at_utc, steps_commit) VALUES (?, ?)',
            [$payload->scannedAtUtc, $payload->stepsCommit],
        );
        return $this->lastId();
    }

    private function syncSteps(ScanPayload $payload, int $scanId): void
    {
        $retiredOn = substr($payload->scannedAtUtc, 0, 10);
        $inFiles = [];
        foreach ($payload->tree->standards as $standard) {
            $inFiles[$standard->slug] = true;
            $standardId = $this->upsert(
                'std_standards',
                'std_standard_id',
                ['slug' => $standard->slug],
                ['name' => $standard->name],
            );

            $existing = [];
            foreach ($this->stepsOf($standardId) as $row) {
                $existing[RowValue::string($row, 'slug')] = $row;
            }
            foreach ($standard->tracks as $track) {
                $trackId = $this->upsert(
                    'std_tracks',
                    'std_track_id',
                    ['std_standard_id' => $standardId, 'slug' => $track->slug],
                    ['name' => $track->name, 'position' => $track->position],
                );
                foreach ($track->steps as $step) {
                    $this->writeStep($existing[$step->slug] ?? null, $trackId, $step);
                    unset($existing[$step->slug]);
                }
            }
            foreach ($existing as $row) {
                $this->retire($row, $retiredOn, $scanId);
            }
        }

        // A standard whose directory is gone has no step files left at all.
        foreach ($this->rows('SELECT std_standard_id, slug FROM std_standards') as $standard) {
            if (isset($inFiles[RowValue::string($standard, 'slug')])) {
                continue;
            }
            foreach ($this->stepsOf(RowValue::int($standard, 'std_standard_id')) as $row) {
                $this->retire($row, $retiredOn, $scanId);
            }
        }
    }

    /**
     * Every step of a standard, retired or not, whichever track it is in.
     *
     * @return list<array<mixed>>
     */
    private function stepsOf(int $standardId): array
    {
        return $this->rows(
            'SELECT s.std_step_id, s.slug, s.retired_on
               FROM std_steps s
               JOIN std_tracks t ON t.std_track_id = s.std_track_id
              WHERE t.std_standard_id = ?',
            [$standardId],
        );
    }

    /**
     * @param array<mixed>|null $existing the step's row when the standard already has one by this name
     */
    private function writeStep(?array $existing, int $trackId, StepDef $step): void
    {
        $values = [
            $trackId,
            $step->position,
            $step->title,
            $step->explanation,
            $step->howTo,
            $step->status->value,
            $step->whoseMove->value,
            $step->checkName,
            $step->checkParam,
        ];
        if ($existing === null) {
            $this->run(
                'INSERT INTO std_steps (std_track_id, position, title, explanation, how_to, status, whose_move,
                                        check_name, check_param, slug)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [...$values, $step->slug],
            );
            $this->stats['steps_new']++;
            return;
        }
        $changed = $this->run(
            'UPDATE std_steps
                SET std_track_id = ?, position = ?, title = ?, explanation = ?, how_to = ?, status = ?,
                    whose_move = ?, check_name = ?, check_param = ?, retired_on = NULL
              WHERE std_step_id = ?',
            [...$values, RowValue::int($existing, 'std_step_id')],
        )->rowCount();
        $this->stats['steps_updated'] += $changed;
    }

    /**
     * @param array<mixed> $row a step the files no longer have
     */
    private function retire(array $row, string $retiredOn, int $scanId): void
    {
        if (RowValue::stringOrNull($row, 'retired_on') !== null) {
            return;
        }
        $stepId = RowValue::int($row, 'std_step_id');
        $this->run('UPDATE std_steps SET retired_on = ? WHERE std_step_id = ?', [$retiredOn, $stepId]);
        $this->stats['steps_retired']++;
        $this->stats['states_closed'] += $this->run(
            'UPDATE std_step_states SET until_scan_id = ? WHERE std_step_id = ? AND until_scan_id IS NULL',
            [$scanId, $stepId],
        )->rowCount();
    }

    /**
     * @param array{shortcode: string, branch: ?string, commit: ?string, skippedReason: ?string, results: list<array{step: string, outcome: Outcome, detail: string}>} $site
     */
    private function importSite(array $site, int $scanId): void
    {
        $found = $this->rows(
            'SELECT std_site_id, std_standard_id FROM std_sites WHERE shortcode = ?',
            [$site['shortcode']],
        );
        if ($found === []) {
            throw new ScanRefusedException("no site has the shortcode {$site['shortcode']}");
        }
        $siteId = RowValue::int($found[0], 'std_site_id');

        $this->run(
            'INSERT INTO std_scan_sites (std_scan_id, std_site_id, branch, commit_sha, skipped_reason)
             VALUES (?, ?, ?, ?, ?)',
            [$scanId, $siteId, $site['branch'], $site['commit'], $site['skippedReason']],
        );
        if ($site['skippedReason'] !== null) {
            // Nothing was looked at, so nothing it said before is touched.
            $this->stats['sites_skipped']++;
            return;
        }
        $this->stats['sites_scanned']++;

        $stepIds = [];
        foreach ($this->stepsOf(RowValue::int($found[0], 'std_standard_id')) as $row) {
            if (RowValue::stringOrNull($row, 'retired_on') === null) {
                $stepIds[RowValue::string($row, 'slug')] = RowValue::int($row, 'std_step_id');
            }
        }
        $swept = [];
        foreach ($site['results'] as $result) {
            $stepId = $stepIds[$result['step']]
                ?? throw new ScanRefusedException(
                    "site {$site['shortcode']} reports the step {$result['step']}, which its standard does not have"
                );
            $span = new SweptSpan(
                ['std_step_id' => $stepId],
                ['outcome' => $result['outcome']->value, 'detail' => $result['detail']],
            );
            $swept[$span->keyString()] = $span;
        }

        $open = [];
        $states = $this->rows(
            'SELECT std_step_state_id, std_step_id, outcome, detail
               FROM std_step_states
              WHERE std_site_id = ? AND until_scan_id IS NULL',
            [$siteId],
        );
        foreach ($states as $row) {
            $open[(string) RowValue::int($row, 'std_step_id')] = new OpenSpan(
                RowValue::int($row, 'std_step_state_id'),
                ['outcome' => RowValue::string($row, 'outcome'), 'detail' => RowValue::string($row, 'detail')],
            );
        }

        $changes = SpanPlan::diff($open, $swept);
        foreach ($changes->closeSpanIds as $stateId) {
            $this->run('UPDATE std_step_states SET until_scan_id = ? WHERE std_step_state_id = ?', [$scanId, $stateId]);
        }
        foreach ($changes->opens as $span) {
            $this->run(
                'INSERT INTO std_step_states (std_site_id, std_step_id, from_scan_id, outcome, detail)
                 VALUES (?, ?, ?, ?, ?)',
                [$siteId, $span->key['std_step_id'], $scanId, $span->attrs['outcome'], $span->attrs['detail']],
            );
        }
        $this->stats['states_closed'] += count($changes->closeSpanIds);
        $this->stats['states_opened'] += count($changes->opens);
        $this->stats['states_unchanged'] += $changes->unchanged;
    }

    /**
     * Finds the row with these key columns, or makes it, and sets the rest.
     * Table and column names are written in this class, never taken from a scan.
     *
     * @param array<string, int|string> $key
     * @param array<string, int|string> $values
     */
    private function upsert(string $table, string $idColumn, array $key, array $values): int
    {
        $where = implode(' AND ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($key)));
        $found = $this->rows("SELECT {$idColumn} FROM {$table} WHERE {$where}", array_values($key));
        if ($found === []) {
            $columns = [...array_keys($key), ...array_keys($values)];
            $this->run(
                "INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES ('
                . implode(', ', array_fill(0, count($columns), '?')) . ')',
                [...array_values($key), ...array_values($values)],
            );
            return $this->lastId();
        }
        $id = RowValue::int($found[0], $idColumn);
        $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($values)));
        $this->run("UPDATE {$table} SET {$sets} WHERE {$idColumn} = ?", [...array_values($values), $id]);
        return $id;
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
