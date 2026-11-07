<?php

declare(strict_types=1);

namespace Auth;

/**
 * How long an MCP/OAuth refresh token lives. Limited actors (apps granted
 * only specific todos, e.g. BG Bells) go stale after a month idle; everyone
 * else keeps the long window. The window slides: each refresh restarts it.
 */
class TokenLifetime
{
    public const LIMITED_REFRESH_DAYS = 31;
    public const REFRESH_DAYS = 400;

    /**
     * @param array<string, mixed> $auth_actor agent_inbox_user row (may be empty).
     */
    public static function refreshDays(array $auth_actor): int
    {
        return new Guards()->isLimited($auth_actor) ? self::LIMITED_REFRESH_DAYS : self::REFRESH_DAYS;
    }
}
