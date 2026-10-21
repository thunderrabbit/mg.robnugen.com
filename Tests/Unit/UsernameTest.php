<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

class UsernameTest extends Unit
{
    public function testPlainAsciiNamesAreValid(): void
    {
        foreach (['gambare', 'Dr_Hilbert_Space_mgTester', 'rob@example.com', 'a.b-c', 'abc'] as $name) {
            $this->assertTrue(\Auth\Username::isValid($name), $name);
            $this->assertSame('', \Auth\Username::problem($name));
        }
    }

    public function testAccentedAndOtherNonAsciiNamesAreRefused(): void
    {
        foreach (['ádmin', 'àdmin', 'aḋmin', 'admin‮', 'ロブ', "admin\u{00A0}"] as $name) {
            $this->assertFalse(\Auth\Username::isValid($name), $name);
        }
    }

    public function testSpacesControlCharactersAndNewlinesAreRefused(): void
    {
        foreach (['ad min', "admin\n", "admin\tx", "ad\x00min"] as $name) {
            $this->assertFalse(\Auth\Username::isValid($name), json_encode($name) ?: '');
        }
    }

    public function testLengthLimits(): void
    {
        $this->assertFalse(\Auth\Username::isValid('ab'));
        $this->assertTrue(\Auth\Username::isValid(str_repeat('a', 64)));
        $this->assertFalse(\Auth\Username::isValid(str_repeat('a', 65)));
        $this->assertNotSame('', \Auth\Username::problem('ab'));
    }
}
