<?php

declare(strict_types=1);

namespace Tests\Unit;

use Auth\Argon2id;
use Codeception\Test\Unit;

class Argon2idTest extends Unit
{
    public function testItIsListedWhenPhpNamesIt(): void
    {
        $this->assertTrue(Argon2id::isListed(['2y', 'argon2i', 'argon2id']));
    }

    public function testArgon2iAloneIsNotArgon2id(): void
    {
        $this->assertFalse(Argon2id::isListed(['2y', 'argon2i']));
    }

    public function testAPhpWithOnlyBcryptSaysSo(): void
    {
        $this->assertSame(
            'bcrypt only; this PHP has no argon2id; bcrypt at cost 12 took 250 ms',
            Argon2id::summary(false, null, 250, 12),
        );
    }

    public function testATrialIsIgnoredWhenTheAlgorithmIsNotListed(): void
    {
        $this->assertSame(
            'bcrypt only; this PHP has no argon2id; bcrypt at cost 12 took 250 ms',
            Argon2id::summary(false, 80, 250, 12),
        );
    }

    public function testListedButFailingIsItsOwnAnswer(): void
    {
        $this->assertSame(
            'argon2id is listed, but a test hash failed; bcrypt at cost 12 took 250 ms',
            Argon2id::summary(true, null, 250, 12),
        );
    }

    public function testAWorkingHashReportsHowLongItTook(): void
    {
        $this->assertSame(
            'argon2id available (a test hash took 80 ms); bcrypt at cost 12 took 250 ms',
            Argon2id::summary(true, 80, 250, 12),
        );
    }

    public function testAFailedBcryptHashSaysSo(): void
    {
        $this->assertSame(
            'argon2id available (a test hash took 80 ms); a bcrypt test hash at cost 10 failed',
            Argon2id::summary(true, 80, null, 10),
        );
    }

    public function testBcryptIsTimedOnAnyPhp(): void
    {
        $this->assertIsInt(Argon2id::bcryptMilliseconds());
    }

    public function testTheReportMatchesWhatThisPhpHas(): void
    {
        // The gate image may or may not have argon2id; either answer is right.
        $report = Argon2id::report();
        if (Argon2id::isListed()) {
            $this->assertIsInt(Argon2id::trialMilliseconds());
            $this->assertStringStartsWith('argon2id available (a test hash took ', $report);
        } else {
            $this->assertStringStartsWith('bcrypt only; this PHP has no argon2id; ', $report);
        }
        $this->assertStringEndsWith(' ms', $report);
        $this->assertStringContainsString('; bcrypt at cost ' . PASSWORD_BCRYPT_DEFAULT_COST . ' took ', $report);
    }
}
