<?php

class Config {

    public $domain_name = '';  // used for cookies
    public $cookie_name = '';  // used for cookies
    public $cookie_lifetime = 60 * 60 * 24 * 30; // 30 days
    public $app_path = '/home/user/sub.domain.com';

    public $dbHost = "eich";
    public $dbUser = "";
    public $dbPass = "";
    public $dbName = "";

    // Stripe — copy these into Config.php and fill in real values
    // Get keys from https://dashboard.stripe.com/apikeys
    // Get webhook secret from https://dashboard.stripe.com/webhooks
    public $stripe_publishable_key = '';  // pk_test_
    public $stripe_secret_key      = '';  // sk_test_...
    public $stripe_webhook_secret  = '';  // whsec_... from Stripe Webhooks
    public $stripe_webhook_endpoint = '/webhooks/stripe';
    public $stripe_price_developer = '';   // price_... for $5/mo Developer plan
    public $stripe_price_growth    = '';   // price_... for $15/mo Growth plan
}
