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
     * @return array Array of activities with activity_id and activity_name
     */
    public function getActivitiesForUser(int $user_id, bool $is_admin): array {
        if ($is_admin) {
            // Admin users see all active activities
            $stmt = $this->pdo->prepare("
                SELECT activity_id, activity_name
                FROM activities
                WHERE is_active = 1
                ORDER BY activity_id
            ");
            $stmt->execute();
        } else {
            // Regular (free) users only see free activities (is_pro=0)
            $stmt = $this->pdo->prepare("
                SELECT activity_id, activity_name
                FROM activities
                WHERE is_active = 1
                AND is_pro = 0
                ORDER BY activity_id
            ");
            $stmt->execute();
        }

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
