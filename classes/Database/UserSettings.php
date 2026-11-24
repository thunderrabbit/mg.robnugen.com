<?php

declare(strict_types=1);

namespace Database;

/**
 * The user_settings table: the site title and subtitle a user chose on the
 * profile page. A user has no row until they first save them.
 * Auth\IsLoggedIn reads them when a user logs in.
 */
class UserSettings
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * Whether the user has saved site settings before.
     */
    public function hasSettings(int $user_id): bool
    {
        $stmt = $this->pdo->prepare("SELECT setting_id FROM user_settings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetch() !== false;
    }

    /**
     * Changes the site title and subtitle of a user who has a row.
     */
    public function updateSite(int $user_id, string $site_title, string $site_subtitle): void
    {
        $stmt = $this->pdo->prepare("UPDATE user_settings SET site_title = ?, site_subtitle = ? WHERE user_id = ?");
        $stmt->execute([$site_title, $site_subtitle, $user_id]);
    }

    /**
     * Stores the first site title and subtitle of a user who has no row.
     */
    public function addSite(int $user_id, string $site_title, string $site_subtitle): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO user_settings (user_id, site_title, site_subtitle) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $site_title, $site_subtitle]);
    }
}
