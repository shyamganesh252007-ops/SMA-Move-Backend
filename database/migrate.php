<?php
declare(strict_types=1);

/**
 * SMA-Move — Versioned Database Migration Engine
 *
 * Provides safe, reviewable, non-destructive schema migrations for MariaDB.
 *
 * Usage from command line:
 *   php database/migrate.php status             # View applied and pending migrations
 *   php database/migrate.php inspect <filename> # View the exact SQL of a migration file
 *   php database/migrate.php run                # Execute all pending migrations
 */

namespace SmaMove\Database;

require_once dirname(__DIR__) . '/config/environment.php';
require_once dirname(__DIR__) . '/config/database.php';

use SmaMove\Config\Database;
use PDO;
use Exception;

class Migrator
{
    private PDO $pdo;
    private string $migrationsDir;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
        $this->migrationsDir = __DIR__ . DIRECTORY_SEPARATOR . 'migrations';
        $this->ensureMigrationTableExists();
    }

    /**
     * Create the migration tracking table if it doesn't already exist.
     */
    private function ensureMigrationTableExists(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `migration` VARCHAR(255) NOT NULL UNIQUE,
            `batch` INT UNSIGNED NOT NULL,
            `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $this->pdo->exec($sql);
    }

    /**
     * Get list of already applied migrations from database.
     *
     * @return array<string, array> Keyed by migration name
     */
    public function getAppliedMigrations(): array
    {
        $stmt = $this->pdo->query("SELECT migration, batch, executed_at FROM schema_migrations ORDER BY id ASC");
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[$row['migration']] = $row;
        }
        return $results;
    }

    /**
     * Get list of all migration files available in the migrations directory.
     *
     * @return array<string>
     */
    public function getAllMigrationFiles(): array
    {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }

        $files = scandir($this->migrationsDir);
        if ($files === false) {
            return [];
        }

        $migrationFiles = [];
        foreach ($files as $file) {
            if (str_ends_with($file, '.sql')) {
                $migrationFiles[] = $file;
            }
        }

        sort($migrationFiles, SORT_NATURAL);
        return $migrationFiles;
    }

    /**
     * Print status table of all migrations.
     */
    public function showStatus(): void
    {
        $applied = $this->getAppliedMigrations();
        $files = $this->getAllMigrationFiles();

        echo "\n====================================================================\n";
        echo " SMA-MOVE — DATABASE MIGRATION STATUS\n";
        echo "====================================================================\n";
        printf("%-45s | %-10s | %-20s\n", "Migration File", "Status", "Executed At");
        echo str_repeat("-", 82) . "\n";

        if (empty($files)) {
            echo " No migration files found in database/migrations/\n";
            echo str_repeat("-", 82) . "\n";
            return;
        }

        foreach ($files as $file) {
            if (isset($applied[$file])) {
                $status = "APPLIED";
                $time = $applied[$file]['executed_at'];
            } else {
                $status = "PENDING";
                $time = "—";
            }
            printf("%-45s | %-10s | %-20s\n", $file, $status, $time);
        }

        echo str_repeat("-", 82) . "\n\n";
    }

    /**
     * Inspect the exact SQL contents of a migration file before executing.
     */
    public function inspect(string $fileName): void
    {
        $fullPath = $this->migrationsDir . DIRECTORY_SEPARATOR . $fileName;
        if (!file_exists($fullPath)) {
            // Also try with .sql extension appended
            $fullPath = $this->migrationsDir . DIRECTORY_SEPARATOR . $fileName . '.sql';
            if (!file_exists($fullPath)) {
                echo "\n[ERROR] Migration file '{$fileName}' does not exist in {$this->migrationsDir}\n\n";
                return;
            }
        }

        echo "\n====================================================================\n";
        echo " INSPECTING MIGRATION: " . basename($fullPath) . "\n";
        echo "====================================================================\n";
        echo file_get_contents($fullPath);
        echo "\n====================================================================\n\n";
    }

    /**
     * Execute all pending migrations sequentially.
     */
    public function runPending(): bool
    {
        $applied = $this->getAppliedMigrations();
        $files = $this->getAllMigrationFiles();

        $pending = [];
        foreach ($files as $file) {
            if (!isset($applied[$file])) {
                $pending[] = $file;
            }
        }

        if (empty($pending)) {
            echo "\n✓ Database schema is already up to date. No pending migrations.\n\n";
            return true;
        }

        // Determine next batch number
        $stmt = $this->pdo->query("SELECT MAX(batch) AS max_batch FROM schema_migrations");
        $nextBatch = ((int)$stmt->fetchColumn()) + 1;

        echo "\n====================================================================\n";
        echo " RUNNING " . count($pending) . " PENDING MIGRATION(S) (Batch #{$nextBatch})\n";
        echo "====================================================================\n";

        foreach ($pending as $migrationFile) {
            $filePath = $this->migrationsDir . DIRECTORY_SEPARATOR . $migrationFile;
            $sql = file_get_contents($filePath);

            if ($sql === false || trim($sql) === '') {
                echo "[SKIP] Empty migration file: {$migrationFile}\n";
                continue;
            }

            echo "Applying: {$migrationFile} ... ";

            try {
                // Strip multi-line and single-line SQL comments before splitting queries
                $cleanedSql = preg_replace('!/\*.*?\*/!s', '', $sql);
                $cleanedSql = preg_replace('/^[\t ]*--.*$/m', '', (string)$cleanedSql);

                // Split queries by semicolon
                $rawQueries = explode(';', (string)$cleanedSql);

                foreach ($rawQueries as $query) {
                    $executable = trim($query);
                    if ($executable !== '') {
                        $this->pdo->exec($executable);
                    }
                }

                // Record migration in schema_migrations table
                $insertStmt = $this->pdo->prepare("INSERT INTO schema_migrations (migration, batch) VALUES (:migration, :batch)");
                $insertStmt->execute([
                    ':migration' => $migrationFile,
                    ':batch' => $nextBatch
                ]);

                echo "SUCCESS\n";
            } catch (Exception $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                echo "FAILED!\n";
                echo "\n[ERROR] Migration '{$migrationFile}' failed: " . $e->getMessage() . "\n";
                echo "Halting further migrations.\n\n";
                return false;
            }
        }

        echo "\n✓ All pending migrations successfully applied.\n\n";
        return true;
    }
}

// CLI Dispatcher
if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $action = $argv[1] ?? 'status';
    $migrator = new Migrator();

    switch ($action) {
        case 'inspect':
            $target = $argv[2] ?? '';
            if ($target === '') {
                echo "Usage: php database/migrate.php inspect <migration_name>\n";
            } else {
                $migrator->inspect($target);
            }
            break;

        case 'run':
        case 'migrate':
            $migrator->runPending();
            break;

        case 'status':
        default:
            $migrator->showStatus();
            break;
    }
}
