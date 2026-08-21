<?php

namespace ActivityTracking;

class Timezone
{
    private $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get timezone_id for a given IANA timezone name
     * If timezone doesn't exist, auto-insert it
     *
     * @param string $iana_name IANA timezone name (e.g., "Asia/Tokyo")
     * @return int timezone_id
     */
    public function getTimezoneId(string $iana_name): int
    {
        // Try to find existing timezone
        $stmt = $this->pdo->prepare("
            SELECT timezone_id
            FROM timezones
            WHERE iana_name = ? AND is_active = 1
        ");
        $stmt->execute([$iana_name]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($result) {
            return (int)$result['timezone_id'];
        }

        // Timezone doesn't exist, insert it
        $stmt = $this->pdo->prepare("
            INSERT INTO timezones (iana_name, display_name, is_active)
            VALUES (?, ?, 1)
        ");
        $stmt->execute([$iana_name, $iana_name]); // Use IANA name as display name

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Get all active timezones (for dropdowns)
     *
     * @return array Array of timezones with id, iana_name, display_name
     */
    public function getAllTimezones(): array
    {
        $stmt = $this->pdo->query("
            SELECT timezone_id, iana_name, display_name
            FROM timezones
            WHERE is_active = 1
            ORDER BY display_name
        ");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Validate IANA timezone name format
     *
     * @param string $iana_name
     * @return bool
     */
    public function isValidIanaName(string $iana_name): bool
    {
        // Basic validation: should match pattern like "Continent/City"
        return preg_match('/^[A-Z][a-z]+\/[A-Z][a-z_]+$/', $iana_name) === 1;
    }
}
