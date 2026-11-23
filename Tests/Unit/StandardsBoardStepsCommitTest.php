<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\Board;

/**
 * Hermetic: which commit's step files mg last imported, from std_scans in SQLite.
 */
final class StandardsBoardStepsCommitTest extends Unit
{
    private const string OLDER = '1111111111111111111111111111111111111111';
    private const string NEWER = '2222222222222222222222222222222222222222';

    private \PDO $pdo;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE std_scans (
                std_scan_id INTEGER PRIMARY KEY,
                scanned_at_utc TEXT NOT NULL,
                steps_commit TEXT NOT NULL
            )'
        );
    }

    public function testNoScanYetIsNull(): void
    {
        $this->assertNull(new Board($this->pdo)->latestStepsCommit());
    }

    public function testItIsTheNewestScansWhateverOrderTheyWereImportedIn(): void
    {
        $this->pdo->exec(
            "INSERT INTO std_scans (std_scan_id, scanned_at_utc, steps_commit) VALUES
                (1, '2026-11-08 01:20:00.000000', '" . self::NEWER . "'),
                (2, '2026-11-07 01:20:00.000000', '" . self::OLDER . "')"
        );

        $this->assertSame(self::NEWER, new Board($this->pdo)->latestStepsCommit());
    }
}
