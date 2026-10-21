<?php

declare(strict_types=1);

namespace Database;

const CONFIG_DATABASE_OUTPUT_ENCODING = "utf8mb4";

class Base
{
    private static ?\PDO $pdo = null;

    // Modern database access using native PDO interface
    public static function getPDO(\Config $config): \PDO
    {
        return self::$pdo ??= self::connect($config);
    }

    /**
     * Connects, trying once more after a second's sleep.
     */
    private static function connect(\Config $config): \PDO
    {
        $dsn = "mysql:host={$config->dbHost}";
        if (!empty($config->dbName)) {
            $dsn .= ";dbname={$config->dbName}";
        }
        $dsn .= ";charset=" . CONFIG_DATABASE_OUTPUT_ENCODING;

        try {
            return self::open($dsn, $config);
        } catch (\PDOException) {
            sleep(1);
            try {
                return self::open($dsn, $config);
            } catch (\PDOException $e) {
                throw new \Database\EDatabaseException(
                    "Could not connect to server after trying with 1s sleep: " . $e->getMessage()
                );
            }
        }
    }

    /**
     * Opens a connection whose session time zone is always UTC, whatever
     * PHP's time zone is: mg's *_utc columns rely on it.
     */
    private static function open(string $dsn, \Config $config): \PDO
    {
        $pdo = new \PDO($dsn, $config->dbUser, $config->dbPass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }

    /**
     * Check if database exists using native PDO
     */
    public static function databaseExists(\Config $config): bool
    {
        try {
            // Connect without database name to check if server is reachable
            $dsn = "mysql:host={$config->dbHost};charset=" . CONFIG_DATABASE_OUTPUT_ENCODING;
            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ];

            $conn = new \PDO($dsn, $config->dbUser, $config->dbPass, $options);
        } catch (\PDOException $e) {
            throw new \Database\ECouldNotConnectToServer("Check Config because " . $e->getMessage());
        }

        try {
            $stmt = $conn->prepare("SHOW DATABASES LIKE ?");
            $stmt->execute([$config->dbName]);
            $result = $stmt->fetchAll();

            if (count($result) === 0) {
                throw new \Database\EDatabaseMissing("Database '{$config->dbName}' not found.");
            }

            return true;
        } catch (\PDOException $e) {
            throw new \Database\EDatabaseException("Failed to query for DB existence: " . $e->getMessage());
        }
    }

    /**
     * Execute multiple SQL statements from a string (for schema migrations)
     * Splits on semicolons and executes each statement separately
     */
    public static function executeMultipleSQL(\PDO $pdo, string $sql): void
    {
        // Strip single-line SQL comments before splitting on semicolons.
        // A '--' comment containing a ';' would cause a false split.
        $sql = preg_replace('/--[^\n]*\n/', "\n", $sql) ?? $sql;

        // Split SQL into individual statements
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            function ($stmt) {
                return !empty($stmt);
            }
        );

        foreach ($statements as $statement) {
            try {
                $pdo->exec($statement);
            } catch (\PDOException $e) {
                throw new \Database\EDatabaseException("Error executing statement: $statement. Error: " . $e->getMessage());
            }
        }
    }
}
