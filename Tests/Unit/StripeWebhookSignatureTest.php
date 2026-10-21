<?php

declare(strict_types=1);

namespace Tests\Unit;

use Billing\StripeWebhook;
use Codeception\Test\Unit;

class StripeWebhookSignatureTest extends Unit
{
    private const SECRET = 'whsec_test_secret';
    private const PAYLOAD = '{"id":"evt_1","type":"checkout.session.completed"}';
    private const T = 1_800_000_000;

    private function header(int $t, string $secret = self::SECRET, string $extra = ''): string
    {
        return "t={$t},v1=" . hash_hmac('sha256', $t . '.' . self::PAYLOAD, $secret) . $extra;
    }

    public function testFreshCorrectSignatureIsAccepted(): void
    {
        $this->assertTrue(StripeWebhook::verifySignature(self::PAYLOAD, $this->header(self::T), self::SECRET, self::T + 10));
    }

    public function testWrongSecretOrTamperedPayloadIsRefused(): void
    {
        $this->assertFalse(StripeWebhook::verifySignature(self::PAYLOAD, $this->header(self::T, 'other'), self::SECRET, self::T));
        $this->assertFalse(StripeWebhook::verifySignature(self::PAYLOAD . ' ', $this->header(self::T), self::SECRET, self::T));
    }

    public function testOldOrFutureEventsAreRefused(): void
    {
        $header = $this->header(self::T);
        $this->assertTrue(StripeWebhook::verifySignature(self::PAYLOAD, $header, self::SECRET, self::T + 300));
        $this->assertFalse(StripeWebhook::verifySignature(self::PAYLOAD, $header, self::SECRET, self::T + 301), 'replayed later');
        $this->assertFalse(StripeWebhook::verifySignature(self::PAYLOAD, $header, self::SECRET, self::T - 301), 'from the future');
    }

    public function testEmptySecretIsAlwaysRefused(): void
    {
        $this->assertFalse(StripeWebhook::verifySignature(self::PAYLOAD, $this->header(self::T, ''), '', self::T));
    }

    public function testAnyOfSeveralSignaturesMayMatch(): void
    {
        $t = self::T;
        $header = "t={$t},v1=" . str_repeat('0', 64) . ',v1=' . hash_hmac('sha256', $t . '.' . self::PAYLOAD, self::SECRET);
        $this->assertTrue(StripeWebhook::verifySignature(self::PAYLOAD, $header, self::SECRET, $t));
    }

    public function testMissingPartsAreRefused(): void
    {
        $this->assertFalse(StripeWebhook::verifySignature(self::PAYLOAD, '', self::SECRET, self::T));
        $this->assertFalse(StripeWebhook::verifySignature(self::PAYLOAD, 't=' . self::T, self::SECRET, self::T));
    }
}
