<?php

declare(strict_types=1);

namespace Tests\Unit;

use Auth\PasswordHash;
use Codeception\Test\Unit;

/**
 * The gate image's PHP may or may not have argon2id, so the tests that make
 * a real hash check against PasswordHash::algorithm(), and the fallback is
 * reached by naming the algorithms a lesser PHP would list.
 */
class PasswordHashTest extends Unit
{
    /**
     * @return non-empty-string
     */
    private static function prefix(): string
    {
        return PasswordHash::algorithm() === PasswordHash::ARGON2ID ? '$argon2id$' : '$2y$';
    }

    public function testArgon2idIsUsedWhereThePhpHasIt(): void
    {
        $this->assertSame('argon2id', PasswordHash::algorithm(['2y', 'argon2i', 'argon2id']));
    }

    public function testBcryptIsTheFallbackWhereItDoesNot(): void
    {
        $this->assertSame('2y', PasswordHash::algorithm(['2y']));
        $this->assertSame('2y', PasswordHash::algorithm(['2y', 'argon2i']));
    }

    public function testANewHashIsOfTheAlgorithmInUseAndVerifies(): void
    {
        $hash = PasswordHash::make('correct horse battery staple');

        $this->assertStringStartsWith(self::prefix(), $hash);
        $this->assertTrue(password_verify('correct horse battery staple', $hash));
        $this->assertFalse(password_verify('correct horse battery stapl', $hash));
    }

    public function testUnderArgon2idEveryByteOfALongPasswordCounts(): void
    {
        // These two differ only past byte 72, where bcrypt stops reading.
        $long = str_repeat('a', 100);
        $hash = PasswordHash::make($long . 'x');

        $this->assertTrue(password_verify($long . 'x', $hash));
        $this->assertSame(
            PasswordHash::algorithm() !== PasswordHash::ARGON2ID,
            password_verify($long . 'y', $hash),
            'argon2id tells them apart; bcrypt cannot, which is why PasswordPolicy caps it',
        );
    }

    public function testABcryptHashAtAnOldCostNeedsRehashing(): void
    {
        $old = password_hash('correct horse battery staple', PASSWORD_BCRYPT, ['cost' => 4]);

        $this->assertTrue(PasswordHash::needsRehash($old));
    }

    public function testABcryptHashAtTodaysCostNeedsRehashingOnlyUnderArgon2id(): void
    {
        $bcrypt = password_hash('correct horse battery staple', PASSWORD_BCRYPT);

        $this->assertSame(PasswordHash::algorithm() === PasswordHash::ARGON2ID, PasswordHash::needsRehash($bcrypt));
    }

    public function testAFreshHashDoesNot(): void
    {
        $this->assertFalse(PasswordHash::needsRehash(PasswordHash::make('correct horse battery staple')));
    }

    public function testTheAbsentUserHashMatchesTheAlgorithmInUse(): void
    {
        $this->assertStringStartsWith(self::prefix(), PasswordHash::absentUserHash());
        $this->assertStringStartsWith('$argon2id$', PasswordHash::absentUserHash('argon2id'));
        $this->assertStringStartsWith('$2y$', PasswordHash::absentUserHash('2y'));
    }

    public function testTheAbsentUserHashInUseIsARealHashAtTodaysCost(): void
    {
        // A malformed one would make password_verify() return at once, and
        // an outdated cost would make a missing user answer faster or slower.
        $this->assertFalse(PasswordHash::needsRehash(PasswordHash::absentUserHash()));
        $this->assertTrue(password_verify('never a real password', PasswordHash::absentUserHash()));
    }

    public function testTheBcryptAbsentUserHashIsARealHashAtTodaysCost(): void
    {
        // Every PHP has bcrypt, so the fallback's hash is checked everywhere.
        $this->assertFalse(password_needs_rehash(PasswordHash::absentUserHash('2y'), '2y'));
        $this->assertTrue(password_verify('never a real password', PasswordHash::absentUserHash('2y')));
    }
}
