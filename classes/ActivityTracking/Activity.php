<?php

declare(strict_types=1);

namespace ActivityTracking;

class Activity
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * True when the user may start or link this activity: a shared one
     * (no owner) or one of their own. Someone else's PRIVATE activity is not
     * theirs to use, and using it would reveal its name back to them.
     */
    public function userMayUse(int $activity_id, int $user_id): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM activities WHERE activity_id = ? AND (user_id IS NULL OR user_id = ?) LIMIT 1"
        );
        $stmt->execute([$activity_id, $user_id]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Get activities available to a user based on their role
     *
     * @param int $user_id User ID
     * @param bool $is_admin Whether the user is an admin
     * @param bool $is_pro Whether the user is a Pro subscriber
     * @return list<array<mixed>> Array of activities with activity_id, activity_name, and user_id
     */
    public function getActivitiesForUser(int $user_id, bool $is_admin, bool $is_pro = false): array
    {
        if ($is_admin || $is_pro) {
            // Admin and Pro users see FREE + PUBLIC activities + their own PRIVATE activities
            $stmt = $this->pdo->prepare("
                SELECT activity_id, activity_name, description, user_id
                FROM activities
                WHERE is_active = 1
                  AND (type IN ('FREE', 'PUBLIC') OR (type = 'PRIVATE' AND user_id = ?))
                ORDER BY activity_name
            ");
            $stmt->execute([$user_id]);
        } else {
            // Free users see only FREE activities
            $stmt = $this->pdo->prepare("
                SELECT activity_id, activity_name, description, user_id
                FROM activities
                WHERE is_active = 1
                  AND (type = 'FREE')
                ORDER BY activity_name
            ");
            $stmt->execute();
        }

        return \Db\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Create a custom activity for a user
     *
     * @param int $user_id User ID
     * @param string $activity_name Activity name
     * @param string|null $description Optional description
     * @return int|false The new activity_id or false on failure
     */
    public function createUserActivity(int $user_id, string $activity_name, ?string $description = null)
    {
        $activity_name = trim($activity_name);
        if (empty($activity_name) || strlen($activity_name) > 64) {
            return false;
        }

        // Check for duplicate name for this user
        $stmt = $this->pdo->prepare("
            SELECT activity_id
            FROM activities
            WHERE user_id = ? AND activity_name = ?
        ");
        $stmt->execute([$user_id, $activity_name]);
        if ($stmt->fetch()) {
            return false;
        }

        // Insert new activity (user-created activities are PRIVATE by default)
        $stmt = $this->pdo->prepare("
            INSERT INTO activities (user_id, activity_name, description, type, is_active)
            VALUES (?, ?, ?, 'PRIVATE', 1)
        ");

        if ($stmt->execute([$user_id, $activity_name, $description])) {
            return (int) $this->pdo->lastInsertId();
        }

        return false;
    }
}
