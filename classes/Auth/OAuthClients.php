<?php

/**
 * The OAuth clients /authorize knows: which redirect_uri each may use, and
 * whether a client is pinned to one actor and skips remembered consent.
 */

declare(strict_types=1);

namespace Auth;

class OAuthClients
{
    private const string BGBELLS_CLIENT_ID    = 'bgbells';
    private const string BGBELLS_REDIRECT_URI = 'bgbells://oauth/callback';

    // mg #801: BG Bells is pinned to ONE hand-made actor named 'BG Bells' per
    // account. #801 replaces this with per-user actors.
    private const string BGBELLS_ACTOR_NAME = 'BG Bells';

    // Without this list any site could send Rob a link whose redirect_uri is
    // its own, and one click on Approve would hand it a code it can redeem
    // with its own PKCE verifier.
    private const array CLAUDE_REDIRECT_URIS = [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
    ];

    public static function redirectAllowed(string $client_id, string $redirect_uri): bool
    {
        if ($client_id === self::BGBELLS_CLIENT_ID) {
            return $redirect_uri === self::BGBELLS_REDIRECT_URI;
        }
        return in_array($redirect_uri, self::CLAUDE_REDIRECT_URIS, true);
    }

    public static function pinnedActorName(string $client_id): ?string
    {
        return $client_id === self::BGBELLS_CLIENT_ID ? self::BGBELLS_ACTOR_NAME : null;
    }

    public static function remembersConsent(string $client_id): bool
    {
        return $client_id !== self::BGBELLS_CLIENT_ID;
    }

    public static function displayName(string $client_id): ?string
    {
        return $client_id === self::BGBELLS_CLIENT_ID ? 'BG Bells' : null;
    }
}
