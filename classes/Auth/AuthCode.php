<?php

/**
 * The short-lived OAuth authorization code that /authorize hands a client,
 * which the client then exchanges at /mcp/token.php.
 *
 * @see wwwroot/authorize/index.php
 */

declare(strict_types=1);

namespace Auth;

class AuthCode
{
    /**
     * Stores a new code for this agent and sends the browser back to the
     * client with it. Only the code's hash is stored. Ends the request.
     *
     * The caller has already checked the redirect URI against
     * OAuthClients::redirectAllowed and that the agent is the user's own.
     */
    public static function issueAndRedirect(\PDO $pdo, int $aiu_id, int $user_id, string $code_challenge, string $redirect_uri, string $state): void
    {
        $raw_code  = bin2hex(random_bytes(32));
        $code_hash = hash('sha256', $raw_code);

        $pdo->prepare(
            "INSERT INTO oauth_auth_codes
                 (aiu_id, user_id, code_hash, code_challenge, redirect_uri, state, expires_at_utc)
             VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
        )->execute([$aiu_id, $user_id, $code_hash, $code_challenge, $redirect_uri, $state]);

        header('Location: ' . self::redirectUrl($redirect_uri, $raw_code, $state));
        exit;
    }

    /**
     * Where the browser goes with its new code: the client's redirect URI
     * with `code` and `state` added.
     */
    public static function redirectUrl(string $redirect_uri, string $raw_code, string $state): string
    {
        $sep = str_contains($redirect_uri, '?') ? '&' : '?';

        return "{$redirect_uri}{$sep}code={$raw_code}&state=" . urlencode($state);
    }
}
