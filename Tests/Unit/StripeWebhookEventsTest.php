<?php

declare(strict_types=1);

namespace Tests\Unit;

use Billing\StripeWebhook;
use Codeception\Test\Unit;

/**
 * Event handling on in-memory SQLite. Paths that add credits use MySQL's
 * ON DUPLICATE KEY, so these tests stick to events that do not reach it.
 */
class StripeWebhookEventsTest extends Unit
{
    private \PDO $pdo;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE users (user_id INTEGER PRIMARY KEY, role TEXT, stripe_customer_id TEXT)");
        $this->pdo->exec(
            "CREATE TABLE stripe_events (
                stripe_event_id TEXT PRIMARY KEY, event_type TEXT NOT NULL, received_at TEXT DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $this->pdo->exec("INSERT INTO users VALUES (7, 'user', NULL), (8, 'paid', 'cus_paid'), (9, 'admin', 'cus_admin')");
    }

    /**
     * @param array<mixed> $object
     * @return array<mixed>
     */
    private function event(string $id, string $type, array $object): array
    {
        return ['id' => $id, 'type' => $type, 'data' => ['object' => $object]];
    }

    private function role(): string
    {
        $stmt = $this->pdo->query("SELECT role FROM users WHERE user_id = 7");
        $role = $stmt === false ? false : $stmt->fetchColumn();
        return is_string($role) ? $role : '';
    }

    public function testAnEventIdIsAppliedOnlyOnce(): void
    {
        $handler = new StripeWebhook($this->pdo);
        $event = $this->event('evt_1', 'invoice.payment_succeeded', ['billing_reason' => 'manual']);
        $this->assertTrue($handler->handle($event));
        $this->assertFalse($handler->handle($event), 'redelivery is skipped');
    }

    public function testAnEventWithoutAnIdIsIgnored(): void
    {
        $this->assertFalse((new StripeWebhook($this->pdo))->handle(['type' => 'checkout.session.completed']));
    }

    public function testAnUnpaidCheckoutGrantsNothing(): void
    {
        $handler = new StripeWebhook($this->pdo);
        $handler->handle($this->event('evt_2', 'checkout.session.completed', [
            'client_reference_id' => 7,
            'customer' => 'cus_123',
            'payment_status' => 'unpaid',
            'metadata' => ['plan' => 'growth'],
        ]));
        $this->assertSame('user', $this->role());
    }

    private function roleOf(int $user_id): string
    {
        $stmt = $this->pdo->prepare("SELECT role FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $role = $stmt->fetchColumn();
        return is_string($role) ? $role : '';
    }

    public function testCancellationDemotesPaidToUser(): void
    {
        (new StripeWebhook($this->pdo))->handle(
            $this->event('evt_10', 'customer.subscription.deleted', ['customer' => 'cus_paid'])
        );
        $this->assertSame('user', $this->roleOf(8));
    }

    public function testFullRefundDemotesButPartialDoesNot(): void
    {
        $handler = new StripeWebhook($this->pdo);
        $handler->handle($this->event('evt_11', 'charge.refunded', ['customer' => 'cus_paid', 'refunded' => false]));
        $this->assertSame('paid', $this->roleOf(8), 'partial refund');
        $handler->handle($this->event('evt_12', 'charge.refunded', ['customer' => 'cus_paid', 'refunded' => true]));
        $this->assertSame('user', $this->roleOf(8), 'full refund');
    }

    public function testAnAdminIsNeverDemoted(): void
    {
        (new StripeWebhook($this->pdo))->handle(
            $this->event('evt_13', 'customer.subscription.deleted', ['customer' => 'cus_admin'])
        );
        $this->assertSame('admin', $this->roleOf(9));
    }

    public function testAFailureRollsBackTheClaimSoTheRetryRuns(): void
    {
        $this->pdo->exec("DROP TABLE users");
        $handler = new StripeWebhook($this->pdo);
        $event = $this->event('evt_3', 'checkout.session.completed', [
            'client_reference_id' => 7,
            'customer' => 'cus_123',
            'payment_status' => 'paid',
        ]);
        try {
            $handler->handle($event);
            $this->fail('expected the missing users table to throw');
        } catch (\PDOException $e) {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM stripe_events");
            $this->assertSame(0, $stmt === false ? -1 : $stmt->fetchColumn());
        }
    }
}
