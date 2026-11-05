<?php
declare(strict_types=1);

/**
 * SMA-Move — Secure MariaDB PDO Database Connection Factory
 *
 * Implements a thread-safe singleton connection provider connecting to
 * the existing `sma_move` database with native prepared statements and UTF-8mb4.
 */

namespace SmaMove\Config;

require_once __DIR__ . '/environment.php';

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Get or create the singleton PDO connection instance.
     *
     * @return PDO Active PDO connection
     * @throws RuntimeException If connection fails
     */
    public static function getConnection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // Load environment variables if not loaded
        Environment::load();

        $host = (string)env('DB_HOST', '127.0.0.1');
        $port = (int)env('DB_PORT', 3306);
        $dbname = (string)env('DB_NAME', 'sma_move');
        $user = (string)env('DB_USER', 'sma_app_user');
        $pass = (string)env('DB_PASS', 'SmaMoveLocal#2026!AppSecure');
        $charset = (string)env('DB_CHARSET', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, // Native MariaDB prepared statements
            PDO::ATTR_PERSISTENT => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci",
            PDO::ATTR_TIMEOUT => 5,
        ];

        try {
            self::$instance = new PDO($dsn, $user, $pass, $options);
            return self::$instance;
        } catch (PDOException $e) {
            // Log real internal error without revealing credentials to caller
            error_log(sprintf(
                "[Database Connection Error] Host: %s, DB: %s, User: %s, Message: %s",
                $host,
                $dbname,
                $user,
                $e->getMessage()
            ));

            throw new RuntimeException("Database connection failure. Please verify service availability.");
        }
    }

    /**
     * Check if database connection is alive.
     *
     * @return bool
     */
    public static function testConnection(): bool
    {
        try {
            $pdo = self::getConnection();
            $stmt = $pdo->query('SELECT 1');
            return $stmt !== false && $stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Reset the active connection (useful in long-running CLI tasks or tests).
     */
    public static function resetConnection(): void
    {
        self::$instance = null;
    }
}
