<?php

/**
 * Stripe webhook receiver.
 *
 * Called by Stripe's servers — do NOT include prepend.php (no session,
 * no cookie auth, no browser redirect logic).
 *
 * Register this URL in the Stripe dashboard:
 *   https://mg.robnugen.com/billing/webhook.php
 *
 * Events to enable:
 *   checkout.session.completed
 *   invoice.payment_succeeded
 */

// Bootstrap without prepend.php (same pattern as login/register.php)
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
$project_root = $matches[1];

include_once $project_root . '/classes/Mlaphp/Autoloader.php';
$autoloader = new \Mlaphp\Autoloader();
spl_autoload_register([$autoloader, 'load']);

$config = new \Config();
$pdo    = \Database\Base::getPDO($config);

// ── Verify signature before reading payload ───────────────────────────────────

$payload    = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!\Billing\StripeWebhook::verifySignature($payload, $sig_header, $config->stripe_webhook_secret)) {
    http_response_code(400);
    exit;
}

// ── Parse and dispatch ────────────────────────────────────────────────────────

$event = json_decode($payload, true);

if (!$event || !isset($event['type'])) {
    http_response_code(400);
    exit;
}

try {
    $handler = new \Billing\StripeWebhook($pdo);
    $handler->handle($event);
    http_response_code(200);
    echo json_encode(['received' => true]);
} catch (\Exception $e) {
    // Return 500 so Stripe retries the event
    http_response_code(500);
    exit;
}
