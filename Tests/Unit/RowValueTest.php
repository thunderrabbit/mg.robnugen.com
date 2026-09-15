<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Db\RowShapeException;
use Db\RowValue;

/**
 * Hermetic unit tests for Db\RowValue, the strict reader behind the generated
 * row classes. Unlike the lenient \Field, a database row whose column is
 * missing or has the wrong type means the schema and the code disagree, so it
 * throws instead of defaulting.
 */
final class RowValueTest extends Unit
{
    // === int() / intOrNull() ===

    public function testIntAcceptsIntsAndDigitStrings(): void
    {
        $this->assertSame(5, RowValue::int(['n' => 5], 'n'));
        $this->assertSame(42, RowValue::int(['n' => '42'], 'n'));
        $this->assertSame(-3, RowValue::int(['n' => '-3'], 'n'));
    }

    public function testIntRejectsMissingNullAndNonIntegers(): void
    {
        $this->assertAllThrow(
            static fn(array $row): int => RowValue::int($row, 'n'),
            [[], ['n' => null], ['n' => '4.2'], ['n' => 'x'], ['n' => 4.0], ['n' => [1]]],
        );
    }

    public function testIntOrNullAllowsNullButNotAMissingColumn(): void
    {
        $this->assertNull(RowValue::intOrNull(['n' => null], 'n'));
        $this->assertSame(3, RowValue::intOrNull(['n' => '3'], 'n'));
        $this->assertAllThrow(
            static fn(array $row): ?int => RowValue::intOrNull($row, 'n'),
            [[], ['n' => 'x']],
        );
    }

    // === string() / stringOrNull() ===

    public function testStringRequiresAString(): void
    {
        $this->assertSame('', RowValue::string(['s' => ''], 's'));
        $this->assertSame('hi', RowValue::string(['s' => 'hi'], 's'));
        $this->assertAllThrow(
            static fn(array $row): string => RowValue::string($row, 's'),
            [[], ['s' => null], ['s' => 5]],
        );
    }

    public function testStringOrNullAllowsNull(): void
    {
        $this->assertNull(RowValue::stringOrNull(['s' => null], 's'));
        $this->assertSame('x', RowValue::stringOrNull(['s' => 'x'], 's'));
        $this->assertAllThrow(
            static fn(array $row): ?string => RowValue::stringOrNull($row, 's'),
            [[], ['s' => 5]],
        );
    }

    // === bool() ===

    public function testBoolReadsTinyintFlags(): void
    {
        $this->assertTrue(RowValue::bool(['f' => 1], 'f'));
        $this->assertFalse(RowValue::bool(['f' => 0], 'f'));
        $this->assertTrue(RowValue::bool(['f' => '1'], 'f'));
        $this->assertFalse(RowValue::bool(['f' => '0'], 'f'));
        $this->assertAllThrow(
            static fn(array $row): bool => RowValue::bool($row, 'f'),
            [[], ['f' => null], ['f' => 2], ['f' => 'yes']],
        );
    }

    // === dateTime() / dateTimeOrNull() ===

    public function testDateTimeParsesUtcWithAndWithoutMicroseconds(): void
    {
        $at = RowValue::dateTime(['t' => '2026-04-20 04:42:40.557849'], 't');
        $this->assertSame('2026-04-20 04:42:40.557849', $at->format(RowValue::DATETIME_FORMAT));
        $this->assertSame('UTC', $at->getTimezone()->getName());

        $plain = RowValue::dateTime(['t' => '2026-04-20 04:42:40'], 't');
        $this->assertSame('2026-04-20 04:42:40.000000', $plain->format(RowValue::DATETIME_FORMAT));
    }

    public function testDateTimeRejectsMissingNullAndGarbage(): void
    {
        $this->assertAllThrow(
            static fn(array $row): \DateTimeImmutable => RowValue::dateTime($row, 't'),
            [[], ['t' => null], ['t' => 'yesterday'], ['t' => '2026-13-45 99:00:00']],
        );
    }

    public function testDateTimeOrNullAllowsNull(): void
    {
        $this->assertNull(RowValue::dateTimeOrNull(['t' => null], 't'));
        $this->assertSame(
            '2026-04-20 04:42:40.000000',
            RowValue::dateTimeOrNull(['t' => '2026-04-20 04:42:40'], 't')?->format(RowValue::DATETIME_FORMAT),
        );
    }

    // === enum() ===

    public function testEnumMapsAColumnToItsBackedCase(): void
    {
        $this->assertSame(RowValueTestColor::Blue, RowValue::enum(['c' => 'blue'], 'c', RowValueTestColor::class));
        $this->assertAllThrow(
            static fn(array $row): RowValueTestColor => RowValue::enum($row, 'c', RowValueTestColor::class),
            [[], ['c' => null], ['c' => 'purple'], ['c' => 3]],
        );
    }

    /**
     * @param \Closure(array<mixed>): mixed $read
     * @param list<array<mixed>> $rows
     */
    private function assertAllThrow(\Closure $read, array $rows): void
    {
        foreach ($rows as $row) {
            try {
                $read($row);
                $this->fail('Expected RowShapeException for ' . json_encode($row));
            } catch (RowShapeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}

enum RowValueTestColor: string
{
    case Red = 'red';
    case Blue = 'blue';
}
