<?php

/**
 * The two gates of the REST API that end a request themselves: the credit a
 * write costs, and the coarse permission for the project subsystem.
 *
 * @see wwwroot/api/v1/index.php
 * @see \Auth\Guards for the gates that return a verdict instead
 */

declare(strict_types=1);

namespace Auth;

class ApiGate
{
    /**
     * Call at the top of any write endpoint before processing. Atomically
     * deducts 1 credit, then logs the call to api_usage. With no credit left
     * it answers 402 and ends the request.
     */
    public static function requireCredit(\PDO $pdo, int $user_id, ?int $key_id, string $endpoint, ?int $token_id = null): void
    {
        if (!self::takeCredit($pdo, $user_id)) {
            http_response_code(402);
            echo json_encode([
                'error'       => 'No credits remaining',
                'upgrade_url' => 'https://mg.robnugen.com/billing',
            ]);
            exit;
        }

        // Log to api_usage after successful deduction
        try {
            $log = $pdo->prepare(
                "INSERT INTO api_usage (user_id, key_id, token_id, endpoint) VALUES (?, ?, ?, ?)"
            );
            $log->execute([$user_id, $key_id, $token_id, $endpoint]);
        } catch (\Exception) {
            // Never fail a request over logging
        }
    }

    /**
     * Takes one credit from the user in a single statement, so two requests
     * at once cannot both spend the last one. False when there was none.
     */
    public static function takeCredit(\PDO $pdo, int $user_id): bool
    {
        $stmt = $pdo->prepare(
            "UPDATE api_credits
             SET credits_remaining = credits_remaining - 1
             WHERE user_id = ? AND credits_remaining > 0"
        );
        $stmt->execute([$user_id]);

        return $stmt->rowCount() !== 0;
    }

    /**
     * Coarse subsystem gate for /projects, /repositories, /issues, /exterm
     * and /standards. Without the flag it answers 403 and ends the request.
     * Fine-grained access within the subsystem continues to flow through
     * project_members.
     *
     * @param array<mixed> $auth_actor
     */
    public static function requireProjectPerm(array $auth_actor, string $method): void
    {
        if (!self::hasProjectPerm($auth_actor, $method)) {
            $needs = self::projectPermNeeded($method);
            http_response_code(403);
            echo json_encode(['error' => "This API key does not have permission ({$needs}) for the project subsystem"]);
            exit;
        }
    }

    /**
     * GET → can_read_project; everything else (POST/PATCH/DELETE) → can_write_project.
     */
    public static function projectPermNeeded(string $method): string
    {
        return ($method === 'GET') ? 'can_read_project' : 'can_write_project';
    }

    /**
     * Missing/0/''/false/null all read as "not granted" (empty() semantics).
     *
     * @param array<mixed> $auth_actor
     */
    public static function hasProjectPerm(array $auth_actor, string $method): bool
    {
        return !empty($auth_actor[self::projectPermNeeded($method)]);
    }
}
