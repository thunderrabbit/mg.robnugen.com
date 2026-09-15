<?php

namespace Tests\Unit;

use Codeception\Test\Unit;

/**
 * Hermetic unit tests for the \Field helper, which reads one scalar field
 * from an untyped source (decoded JSON, a PDO row, a superglobal) the way
 * the casts it replaces did: a scalar is cast, anything missing or odd
 * becomes the default.
 */
class FieldTest extends Unit
{
    // === int() ===

    public function testIntCastsScalarsLikeAnIntCast(): void
    {
        $this->assertSame(42, \Field::int(['k' => '42'], 'k'));
        $this->assertSame(7, \Field::int(['k' => 7], 'k'));
        $this->assertSame(3, \Field::int(['k' => 3.9], 'k'));
        $this->assertSame(1, \Field::int(['k' => true], 'k'));
        $this->assertSame(0, \Field::int(['k' => false], 'k'));
        $this->assertSame(0, \Field::int(['k' => 'abc'], 'k'));
        $this->assertSame(12, \Field::int(['k' => '12abc'], 'k'));
    }

    public function testIntUsesDefaultForMissingOrNull(): void
    {
        $this->assertSame(0, \Field::int([], 'k'));
        $this->assertSame(0, \Field::int(['k' => null], 'k'));
        $this->assertSame(50, \Field::int([], 'k', 50));
        $this->assertSame(50, \Field::int(['k' => null], 'k', 50));
    }

    public function testIntUsesDefaultForNonScalarValues(): void
    {
        $this->assertSame(9, \Field::int(['k' => ['1']], 'k', 9));
        $this->assertSame(9, \Field::int(['k' => new \stdClass()], 'k', 9));
    }

    public function testIntUsesDefaultWhenSourceIsNotAnArray(): void
    {
        $this->assertSame(9, \Field::int(null, 'k', 9));
        $this->assertSame(9, \Field::int('k=5', 'k', 9));
        $this->assertSame(9, \Field::int(false, 'k', 9));
    }

    // === intOrNull() ===

    public function testIntOrNullCastsPresentScalars(): void
    {
        $this->assertSame(5, \Field::intOrNull(['k' => '5'], 'k'));
        $this->assertSame(0, \Field::intOrNull(['k' => 0], 'k'));
    }

    public function testIntOrNullIsNullForMissingNullOrOdd(): void
    {
        $this->assertNull(\Field::intOrNull([], 'k'));
        $this->assertNull(\Field::intOrNull(['k' => null], 'k'));
        $this->assertNull(\Field::intOrNull(['k' => [5]], 'k'));
        $this->assertNull(\Field::intOrNull(null, 'k'));
    }

    // === string() ===

    public function testStringCastsScalarsWithoutTrimming(): void
    {
        $this->assertSame('  hi ', \Field::string(['k' => '  hi '], 'k'));
        $this->assertSame('5', \Field::string(['k' => 5], 'k'));
        $this->assertSame('1.5', \Field::string(['k' => 1.5], 'k'));
        $this->assertSame('1', \Field::string(['k' => true], 'k'));
        $this->assertSame('', \Field::string(['k' => false], 'k'));
    }

    public function testStringUsesDefaultForMissingNullOrOdd(): void
    {
        $this->assertSame('', \Field::string([], 'k'));
        $this->assertSame('normal', \Field::string(['k' => null], 'k', 'normal'));
        $this->assertSame('normal', \Field::string(['k' => ['x']], 'k', 'normal'));
        $this->assertSame('normal', \Field::string(null, 'k', 'normal'));
    }

    // === toInt() / toString() ===

    public function testToIntCastsScalarsAndDefaultsTheRest(): void
    {
        $this->assertSame(42, \Field::toInt('42'));
        $this->assertSame(3, \Field::toInt(3.9));
        $this->assertSame(1, \Field::toInt(true));
        $this->assertSame(0, \Field::toInt('abc'));
        $this->assertSame(0, \Field::toInt(null));
        $this->assertSame(7, \Field::toInt(null, 7));
        $this->assertSame(7, \Field::toInt(['1'], 7));
    }

    public function testToStringCastsScalarsAndDefaultsTheRest(): void
    {
        $this->assertSame(' a ', \Field::toString(' a '));
        $this->assertSame('5', \Field::toString(5));
        $this->assertSame('1', \Field::toString(true));
        $this->assertSame('', \Field::toString(null));
        $this->assertSame('UTC', \Field::toString(null, 'UTC'));
        $this->assertSame('UTC', \Field::toString(['x'], 'UTC'));
    }

    public function testToIntWorksAsAnArrayMapCallback(): void
    {
        $this->assertSame([1, 2, 0], array_map(\Field::toInt(...), ['1', 2, 'x']));
    }

    // === jsonArray() ===

    public function testJsonArrayDecodesObjectsAndLists(): void
    {
        $this->assertSame(['title' => 'x', 'n' => 2], \Field::jsonArray('{"title":"x","n":2}'));
        $this->assertSame([1, 2], \Field::jsonArray('[1,2]'));
    }

    public function testJsonArrayIsEmptyForBadEmptyOrScalarJson(): void
    {
        $this->assertSame([], \Field::jsonArray(''));
        $this->assertSame([], \Field::jsonArray('{not json'));
        $this->assertSame([], \Field::jsonArray('null'));
        $this->assertSame([], \Field::jsonArray('5'));
        $this->assertSame([], \Field::jsonArray('"text"'));
    }
}
