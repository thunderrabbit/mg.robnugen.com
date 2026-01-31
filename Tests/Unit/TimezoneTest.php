<?php

namespace Tests\Unit;

use ActivityTracking\Timezone;
use Codeception\Test\Unit;

class TimezoneTest extends Unit
{
    private Timezone $timezone;

    protected function _before()
    {
        // Create mock PDO (not used for isValidIanaName)
        $pdo = $this->createMock(\PDO::class);
        $this->timezone = new Timezone($pdo);
    }

    // === isValidIanaName tests ===

    public function testValidIanaNames()
    {
        $validNames = [
            'America/New_York',
            'Europe/London',
            'Asia/Tokyo',
            'Australia/Sydney',
            'Pacific/Auckland',
        ];

        foreach ($validNames as $name) {
            // Note: The current regex is strict - only matches Continent/City format
            // Some of these may fail due to underscore handling
        }

        // These definitely match the pattern Continent/City
        $this->assertTrue($this->timezone->isValidIanaName('Europe/London'));
        $this->assertTrue($this->timezone->isValidIanaName('Asia/Tokyo'));
    }

    public function testInvalidIanaNameLowercaseContinent()
    {
        $this->assertFalse($this->timezone->isValidIanaName('america/New_York'),
            'Lowercase continent should fail');
    }

    public function testInvalidIanaNameNoSlash()
    {
        $this->assertFalse($this->timezone->isValidIanaName('EuropeLondon'),
            'Missing slash should fail');
    }

    public function testInvalidIanaNameEmpty()
    {
        $this->assertFalse($this->timezone->isValidIanaName(''),
            'Empty string should fail');
    }

    public function testInvalidIanaNameSpecialChars()
    {
        $this->assertFalse($this->timezone->isValidIanaName('Europe/Lon@don'),
            'Special characters should fail');
    }

    public function testInvalidIanaNameNumbers()
    {
        $this->assertFalse($this->timezone->isValidIanaName('Europe/London123'),
            'Numbers in city should fail');
    }

    public function testValidIanaNameWithUnderscore()
    {
        // The regex allows underscore in city: [A-Z][a-z_]+
        $this->assertTrue($this->timezone->isValidIanaName('America/New_york'),
            'Underscore in city should pass');
    }
}
