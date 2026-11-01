<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

class DeviceNameTest extends Unit
{
    public function testAnOrdinaryNameIsKeptAsTyped(): void
    {
        $this->assertSame("Ma's Laptop in Riverdell", \Auth\DeviceName::clean("Ma's Laptop in Riverdell"));
        $this->assertSame('Firefox on Lemur 13', \Auth\DeviceName::clean('Firefox on Lemur 13'));
    }

    public function testBlankMeansNoName(): void
    {
        $this->assertNull(\Auth\DeviceName::clean(''));
        $this->assertNull(\Auth\DeviceName::clean("  \t\n "));
    }

    public function testWhitespaceIsTrimmedAndCollapsed(): void
    {
        $this->assertSame('Work phone', \Auth\DeviceName::clean("  Work \n\n phone\t"));
    }

    public function testControlCharactersAreDropped(): void
    {
        $this->assertSame('Phone', \Auth\DeviceName::clean("Ph\x00o\x1Bne"));
    }

    public function testEmojiSurvive(): void
    {
        $this->assertSame('📱 Phone 👨‍👩‍👧', \Auth\DeviceName::clean('📱 Phone 👨‍👩‍👧'));
    }

    public function testALongNameIsCutToTheColumnWidthInCharactersNotBytes(): void
    {
        $name = \Auth\DeviceName::clean(str_repeat('é', 100));
        $this->assertSame(\Auth\DeviceName::MAX_LENGTH, mb_strlen($name ?? ''));
        $this->assertSame(str_repeat('é', \Auth\DeviceName::MAX_LENGTH), $name);
    }

    public function testInvalidUtf8IsNoName(): void
    {
        $this->assertNull(\Auth\DeviceName::clean("Lap\xFFtop"));
    }
}
