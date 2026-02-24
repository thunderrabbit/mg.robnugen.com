<?php
/**
 * Handles incoming Stripe webhook events.
 *
 * Handles two event types:
 *   checkout.session.completed  — first payment; stores customer ID, sets role, adds credits
 *   invoice.payment_succeeded   — monthly renewal; looks up user by customer ID, adds credits
 *
 * Credit amounts by plan:
 *   developer  $5/mo  → 5,000 credits
 *   growth     $15/mo → 25,000 credits
 */
namespace Billing;

class StripeWebhook
{
    private const PLAN_CREDITS = [
        'developer' => 5000,
        'growth'    => 25000,
    ];

    public function __construct(private \PDO $pdo) {}

    // ── Signature verification ────────────────────────────────────────────────
    // Must be called before parsing the payload.
    // Returns false if the signature is missing, malformed, or doesn't match.

    public static function verifySignature(
        string $payload,
        string $sig_header,
        string $secret
    ): bool {
        if (!preg_match('/t=(\d+)/', $sig_header, $t_match)) {
            return false;
        }
        if (!preg_match('/v1=([a-f0-9]+)/', $sig_header, $v1_match)) {
            return false;
        }

        $signed_payload = $t_match[1] . '.' . $payload;
        $expected       = hash_hmac('sha256', $signed_payload, $secret);

        return hash_equals($expected, $v1_match[1]);
    }

    // ── Event dispatch ────────────────────────────────────────────────────────

    public function handle(array $event): void
    {
        $object = $event['data']['object'] ?? [];

        match ($event['type'] ?? '') {
            'checkout.session.completed' => $this->handleCheckoutComplete($object),
            'invoice.payment_succeeded'  => $this->handleInvoicePayment($object),
            default                      => null,
        };
    }

    // ── checkout.session.completed ────────────────────────────────────────────
    // Fired once when a user completes the Stripe Checkout flow.
    // client_reference_id = user_id (set during checkout session creation)
    // metadata[plan]       = 'developer' or 'growth' (set during checkout session creation)

    private function handleCheckoutComplete(array $session): void
    {
        $user_id     = (int)($session['client_reference_id'] ?? 0);
        $customer_id = $session['customer'] ?? '';
        $plan        = $session['metadata']['plan'] ?? 'developer';

        if (!$user_id || !$customer_id) {
            return;
        }

        // Store Stripe customer ID for renewal lookups
        $stmt = $this->pdo->prepare(
            "UPDATE users SET stripe_customer_id = ? WHERE user_id = ?"
        );
        $stmt->execute([$customer_id, $user_id]);

        // Upgrade role to paid
        $stmt = $this->pdo->prepare(
            "UPDATE users SET role = 'paid' WHERE user_id = ?"
        );
        $stmt->execute([$user_id]);

        // Add credits
        $this->addCredits($user_id, self::PLAN_CREDITS[$plan] ?? self::PLAN_CREDITS['developer']);
    }

    // ── invoice.payment_succeeded ─────────────────────────────────────────────
    // Fired every billing cycle (monthly renewal).
    // Only handle billing_reason = 'subscription_cycle' to avoid double-crediting
    // the first payment (which is already handled by checkout.session.completed).
    // subscription_details.metadata[plan] carries the plan name set at checkout.

    private function handleInvoicePayment(array $invoice): void
    {
        if (($invoice['billing_reason'] ?? '') !== 'subscription_cycle') {
            return;
        }

        $customer_id = $invoice['customer'] ?? '';
        if (!$customer_id) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "SELECT user_id FROM users WHERE stripe_customer_id = ? LIMIT 1"
        );
        $stmt->execute([$customer_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return;
        }

        $user_id = (int)$row['user_id'];
        $plan    = $invoice['subscription_details']['metadata']['plan'] ?? 'developer';

        $this->addCredits($user_id, self::PLAN_CREDITS[$plan] ?? self::PLAN_CREDITS['developer']);
    }

    // ── Credit top-up ─────────────────────────────────────────────────────────
    // INSERT ... ON DUPLICATE KEY ensures we never lose credits already in balance.

    private function addCredits(int $user_id, int $amount): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO api_credits (user_id, credits_remaining)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE credits_remaining = credits_remaining + ?"
        );
        $stmt->execute([$user_id, $amount, $amount]);
    }
}
