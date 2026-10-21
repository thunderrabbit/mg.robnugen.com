<?php

declare(strict_types=1);

namespace Startup;

/**
 * Starts the PHP session with hardened cookie settings. prepend.php and
 * login/register.php (which cannot load prepend) both start sessions here,
 * so the two can never drift apart. Loaded before the autoloader exists.
 */
class Session
{
    public static function start(): void
    {
        // PHPSESSID holds the CSRF token. Host-only, HTTPS-only, out of reach
        // of JavaScript, withheld from cross-site subrequests, and never
        // adopted from an id the server did not issue (strict mode blocks
        // planting a known id).
        ini_set('session.use_strict_mode', '1');
        session_set_cookie_params([
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}
