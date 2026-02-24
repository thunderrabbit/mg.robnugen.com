<?php
/**
 * Creates a Stripe Checkout Session and redirects the user to Stripe.
 * POST only — called by the pricing page form.
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if (!$is_logged_in->isLoggedIn()) {
    header('Location: /login/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /billing/');
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    header('Location: /billing/');
    exit;
}

$plan = $_POST['plan'] ?? '';
if (!in_array($plan, ['developer', 'growth'], true)) {
    header('Location: /billing/');
    exit;
}

$user_id  = $is_logged_in->loggedInID();
$price_id = ($plan === 'growth') ? $config->stripe_price_growth : $config->stripe_price_developer;
$base_url = 'https://' . $config->domain_name;

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => $config->stripe_secret_key . ':',
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'mode'                                 => 'subscription',
        'line_items[0][price]'                 => $price_id,
        'line_items[0][quantity]'              => 1,
        'client_reference_id'                  => $user_id,
        // metadata on the session (for checkout.session.completed)
        'metadata[plan]'                       => $plan,
        // metadata on the subscription (for invoice.payment_succeeded renewals)
        'subscription_data[metadata][plan]'    => $plan,
        'subscription_data[metadata][user_id]' => $user_id,
        'success_url'                          => $base_url . '/billing/success.php',
        'cancel_url'                           => $base_url . '/billing/',
    ]),
]);

$result    = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$session = json_decode($result, true);

if ($http_code !== 200 || empty($session['url'])) {
    $stripe_error_msg = $session['error']['message'] ?? 'unknown error';
    error_log("Stripe checkout error HTTP $http_code: $stripe_error_msg | price_id=$price_id | raw=" . $result);
    header('Location: /billing/?stripe_error=1');
    exit;
}

header('Location: ' . $session['url']);
exit;
