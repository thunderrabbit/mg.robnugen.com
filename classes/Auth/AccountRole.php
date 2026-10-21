<?php

declare(strict_types=1);

namespace Auth;

/**
 * The role of an account, for API callers that have a user_id from their key
 * but no IsLoggedIn.
 */
class AccountRole
{
    public static function isAdmin(\PDO $pdo, int $user_id): bool
    {
        $stmt = $pdo->prepare("SELECT `role` FROM `users` WHERE `user_id` = ? LIMIT 1");
        $stmt->execute([$user_id]);
        return $stmt->fetchColumn() === 'admin';
    }
}
