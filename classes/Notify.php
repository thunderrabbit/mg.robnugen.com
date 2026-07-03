<?php

/**
 * Notify — brokered outbound notifications to Rob's phone.
 *
 * Currently backs one transport: Telegram (bot "mg Agent Messenger"). The bot
 * token and chat_id live server-side in Config, so agents never hold the secret;
 * they reach this helper through the gated /notify/send API endpoint.
 *
 * A ping is stamped with the sending agent's name and a type emoji so Rob can
 * eyeball who it's from and how urgent it is at a glance.
 */
class Notify
{
    public function __construct(
        private \Config $di_config,
    ) {}

    /**
     * Send a phone ping.
     *
     * @param string $from_name Sending agent's display name (agent_inbox_user.name)
     * @param string $text      The message body
     * @param string $type      'done' | 'blocked' | 'alert'
     * @return array Telegram's decoded JSON response, or ['ok'=>false,'error'=>...]
     */
    public function pingPhone(string $from_name, string $text, string $type = 'done'): array
    {
        $token = $this->di_config->telegram_bot_token;
        $chat  = $this->di_config->telegram_chat_id;
        if (!$token || !$chat) {
            return ['ok' => false, 'error' => 'telegram not configured'];
        }

        $emoji = ['done' => '✅', 'blocked' => '⛔', 'alert' => '🔴'][$type] ?? 'ℹ️';
        $payload = http_build_query([
            'chat_id' => $chat,
            'text'    => "{$emoji} [{$from_name}] {$text}",
        ]);

        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 8,
        ]]);

        $resp = @file_get_contents(
            "https://api.telegram.org/bot{$token}/sendMessage",
            false,
            $ctx
        );
        if ($resp === false) {
            return ['ok' => false, 'error' => 'telegram unreachable'];
        }

        return json_decode($resp, true) ?: ['ok' => false, 'error' => 'bad response'];
    }
}
