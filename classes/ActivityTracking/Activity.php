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
        if ($is_admin) {
            // Admin users see ALL activities (system + all users' custom)
            $stmt = $this->pdo->prepare("
                SELECT activity_id, activity_name, description, user_id
                FROM activities
                WHERE is_active = 1
                ORDER BY user_id IS NULL DESC, user_id, activity_name
            ");
            $stmt->execute();
        } elseif ($is_pro) {
            // Pro users see all system activities + their own custom activities
            $stmt = $this->pdo->prepare("
                SELECT activity_id, activity_name, description, user_id
                FROM activities
                WHERE is_active = 1
                  AND (user_id IS NULL OR user_id = ?)
                ORDER BY user_id IS NULL DESC, activity_name
            ");
            $stmt->execute([$user_id]);
        } else {
            // Free users see only free system activities + their own custom activities
            $stmt = $this->pdo->prepare("
                SELECT activity_id, activity_name, description, user_id
                FROM activities
                WHERE is_active = 1
                  AND ((user_id IS NULL AND is_pro = 0) OR user_id = ?)
                ORDER BY user_id IS NULL DESC, activity_name
            ");
            $stmt->execute([$user_id]);
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

        // Insert new activity
        $stmt = $this->pdo->prepare("
            INSERT INTO activities (user_id, activity_name, description, is_pro, is_active)
            VALUES (?, ?, ?, 1, 1)
        ");

        if ($stmt->execute([$user_id, $activity_name, $description])) {
            return (int) $this->pdo->lastInsertId();
        }

        return false;
    }
}
