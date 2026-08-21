<?php

namespace Admin;

class OmgAlerts
{
    /**
     * Returns all unread alerts, newest first.
     * Returns empty array if the table does not exist yet (migration not yet applied).
     */
    public static function getUnread(\PDO $pdo): array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT omg_id, context, message, created_at
                 FROM omg_rob_this_happened
                 WHERE acknowledged_at IS NULL
                 ORDER BY created_at DESC"
            );
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            // Table doesn't exist yet — migration not applied. Return empty silently.
            return [];
        }
    }

    /**
     * Marks a single alert as acknowledged.
     * No-op if the table does not exist yet.
     */
    public static function dismissOne(\PDO $pdo, int $omg_id): void
    {
        try {
            $stmt = $pdo->prepare("UPDATE omg_rob_this_happened SET acknowledged_at = NOW() WHERE omg_id = ? AND acknowledged_at IS NULL");
            $stmt->execute([$omg_id]);
        } catch (\PDOException $e) {
        }
    }

    /**
     * Marks all unread alerts as acknowledged.
     * No-op if the table does not exist yet.
     */
    public static function dismissAll(\PDO $pdo): void
    {
        try {
            $pdo->exec("UPDATE omg_rob_this_happened SET acknowledged_at = NOW() WHERE acknowledged_at IS NULL");
        } catch (\PDOException $e) {
            // Table doesn't exist yet — nothing to dismiss.
        }
    }
}
