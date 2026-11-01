<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

class DeviceTest extends Unit
{
    private const FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:147.0) Gecko/20100101 Firefox/147.0';

    private function device(string $last_access, string $user_agent = '', ?string $device_name = null): \Auth\Device
    {
        return new \Auth\Device(
            cookie_id: 1,
            user_agent: $user_agent,
            created_at: '2026-10-22 09:16:41',
            last_access: $last_access,
            expires_at: '2026-11-21 09:16:40',
            is_current: false,
            device_name: $device_name,
        );
    }

    public function testAnUnnamedDeviceIsLabelledFromItsUserAgent(): void
    {
        $device = $this->device('', self::FIREFOX);
        $this->assertFalse($device->isNamed());
        $this->assertSame('Linux · Firefox 147', $device->label());
        $this->assertSame('Linux · Firefox 147', $device->autoLabel());
    }

    public function testANamedDeviceIsLabelledByItsNameAndStillKnowsItsBrowser(): void
    {
        $device = $this->device('', self::FIREFOX, 'Firefox on Lemur 13');
        $this->assertTrue($device->isNamed());
        $this->assertSame('Firefox on Lemur 13', $device->label());
        $this->assertSame('Linux · Firefox 147', $device->autoLabel());
    }

    public function testTimestampsAreReadAsUtcInstants(): void
    {
        $device = $this->device('2026-11-01 09:26:21');
        $this->assertSame('2026-11-01T09:26:21+00:00', $device->lastUsed()?->format(DATE_ATOM));
        $this->assertSame('2026-10-22T09:16:41+00:00', $device->signedIn()?->format(DATE_ATOM));
        $this->assertSame('2026-11-21T09:16:40+00:00', $device->expires()?->format(DATE_ATOM));
    }

    public function testANeverRecordedLastUseIsNull(): void
    {
        $this->assertNull($this->device('')->lastUsed());
    }
}
