<?php

declare(strict_types=1);

namespace Billing;

/**
 * The api_credits table as the pages read it: how many API credits a user
 * has left, and the trial a new user starts with. Auth\ApiGate spends them
 * and StripeWebhook adds the ones a user buys.
 */
class Credits
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * How many credits a user has left; 0 for a user with no credit row.
     */
    public function getRemaining(int $user_id): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT credits_remaining FROM api_credits WHERE user_id = ? LIMIT 1"
        );
        $stmt->execute([$user_id]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Gives a new user their 100 trial credits. A user who already has a
     * credit row throws the PDOException of the primary key.
     */
    public function addTrial(int $user_id): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO api_credits (user_id, credits_remaining) VALUES (?, 100)"
        );
        $stmt->execute([$user_id]);
    }
}
