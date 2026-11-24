<?php

declare(strict_types=1);

class Config
{
    public string $domain_name = '';  // must equal the browser's HTTP_HOST
    public string $cookie_name = '';  // the login cookie; the browser sees it as __Host-<this>
    public int $cookie_lifetime = 60 * 60 * 24 * 30; // 30 days
    public string $app_path = '/home/user/sub.domain.com';

    public string $dbHost = "eich";
    public string $dbUser = "";
    public string $dbPass = "";
    public string $dbName = "";

    // Stripe — copy these into Config.php and fill in real values
    // Get keys from https://dashboard.stripe.com/apikeys
    // Get webhook secret from https://dashboard.stripe.com/webhooks
    public string $stripe_publishable_key = '';  // pk_test_
    public string $stripe_secret_key      = '';  // sk_test_...
    public string $stripe_webhook_secret  = '';  // whsec_... from Stripe Webhooks
    public string $stripe_webhook_endpoint = '/webhooks/stripe';
    public string $stripe_price_developer = '';   // price_... for $5/mo Developer plan
    public string $stripe_price_growth    = '';   // price_... for $15/mo Growth plan

    // Telegram bot notifications — copy these into Config.php and fill in real values
    // Create a bot via @BotFather in Telegram (/newbot), then get your chat_id by
    // messaging the bot and visiting https://api.telegram.org/bot<TOKEN>/getUpdates
    public string $telegram_bot_token = '';  // 123456789:ABC-DEF...
    public string $telegram_chat_id   = '';  // numeric chat.id from getUpdates response

    // Pictures uploaded to issues. An absolute directory OUTSIDE the checkout
    // and the web root, e.g. /home/user/mg_files/images, readable only by this
    // user. Empty turns issue images off: no strip, no upload, every image URL 404s.
    public string $image_store_path = '';
}
