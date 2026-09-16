<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Db\Rows;

/**
 * Hermetic unit tests for Db\Rows, which narrows what PDO hands back into a
 * shape the analyser can follow. PDO::fetch() answers false when there is no
 * row, and both fetch() and fetchAll() are typed as mixed, so without this
 * every caller repeats the same is_array() dance before reading a column with
 * RowValue. The shapes match PdoIssueStore's private one()/all(): rows stay
 * array<mixed> and are read column by column, never trusted as array<string>.
 */
final class RowsTest extends Unit
{
    // === one() ===

    public function testOneReturnsTheRow(): void
    {
        $row = ['todo_id' => 7, 'title' => 'Write the test first'];
        $this->assertSame($row, Rows::one($row));
    }

    public function testOneReturnsNullWhenThereIsNoRow(): void
    {
        // false is what PDO::fetch() answers on an empty result set.
        $this->assertNull(Rows::one(false));
        $this->assertNull(Rows::one(null));
    }

    public function testOneReturnsNullForAScalarResult(): void
    {
        $this->assertNull(Rows::one(7));
        $this->assertNull(Rows::one('7'));
    }

    public function testOneKeepsARowWithNoColumns(): void
    {
        $this->assertSame([], Rows::one([]));
    }

    // === all() ===

    public function testAllReturnsEveryRow(): void
    {
        $rows = [['n' => 1], ['n' => 2]];
        $this->assertSame($rows, Rows::all($rows));
    }

    public function testAllReindexesToAList(): void
    {
        $this->assertSame(
            [['n' => 1], ['n' => 2]],
            Rows::all([3 => ['n' => 1], 9 => ['n' => 2]]),
        );
    }

    public function testAllDropsEntriesThatAreNotRows(): void
    {
        $this->assertSame([['n' => 1]], Rows::all([['n' => 1], 'not a row', null]));
    }

    public function testAllReturnsEmptyForANonArrayResult(): void
    {
        $this->assertSame([], Rows::all(false));
        $this->assertSame([], Rows::all(null));
    }
}
