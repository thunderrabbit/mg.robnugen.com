<?php

namespace ActivityTracking;

class Activity {
    private $pdo;

    public function __construct(\PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Get activities available to a user based on their role
     *
     * @param int $user_id User ID
     * @param bool $is_admin Whether the user is an admin
     * @param bool $is_pro Whether the user is a Pro subscriber
     * @return array Array of activities with activity_id, activity_name, and user_id
     */
    public function getActivitiesForUser(int $user_id, bool $is_admin, bool $is_pro = false): array {
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

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Create a custom activity for a user
     *
     * @param int $user_id User ID
     * @param string $activity_name Activity name
     * @param string|null $description Optional description
     * @return int|false The new activity_id or false on failure
     */
    public function createUserActivity(int $user_id, string $activity_name, ?string $description = null) {
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
