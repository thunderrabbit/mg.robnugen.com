<?php

// Copy to site_config.php in the same directory (the project root, above
// wwwroot/), fill it in, and chmod 600 it. It is gitignored: each server
// has its own. Config\Settings documents every key and checks them all
// when the site starts; a missing, empty or misspelled one answers 503 and
// says which in the error log.
//
// Set only what this server needs. Every key left out takes the default in
// Config\Settings, so a new one reaches this server without editing here.
// app_path is not a key: it is always the directory this file is in.

return [
    // Required.
    'dbHost' => 'mysql.example.com',
    'dbName' => '',
    'dbUser' => '',
    'dbPass' => '',
    'domain_name' => 'example.com',  // must equal the browser's HTTP_HOST
    'cookie_name' => 'example_auth',  // the login cookie; the browser sees __Host-<this>

    // Optional, shown with their defaults.
    // 'display_errors' => false,  // never true on a site strangers can reach
    // 'cookie_lifetime' => 60 * 60 * 24 * 30,  // seconds, 60 up to 400 days

    // Stripe. Billing is off while these are empty.
    // Keys from https://dashboard.stripe.com/apikeys, the webhook secret
    // from https://dashboard.stripe.com/webhooks.
    // 'stripe_publishable_key' => '',  // pk_...
    // 'stripe_secret_key' => '',  // sk_...
    // 'stripe_webhook_secret' => '',  // whsec_...
    // 'stripe_webhook_endpoint' => '/webhooks/stripe',
    // 'stripe_price_developer' => '',  // price_... for the $5/mo Developer plan
    // 'stripe_price_growth' => '',  // price_... for the $15/mo Growth plan

    // Telegram bot notifications. Create a bot with @BotFather (/newbot),
    // message it, and read chat.id at
    // https://api.telegram.org/bot<TOKEN>/getUpdates
    // 'telegram_bot_token' => '',  // 123456789:ABC-DEF...
    // 'telegram_chat_id' => '',

    // Pictures uploaded to issues. An absolute directory OUTSIDE the checkout
    // and the web root, e.g. /home/user/mg_files/images, readable only by
    // this user. Empty turns issue images off.
    // 'image_store_path' => '',
];
