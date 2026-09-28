<?php
/**
 * MRA -> WooCommerce Bridge Database Helper
 *
 * Manages PDO connection lifecycle and provides prepared-statement query utilities.
 * Configured with strict error reporting and UTF-8 charset.
 *
 * Core PHP — no external dependencies.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/logger.php';

class BridgeDatabase
{
    private static ?PDO $instance = null;

    /**
     * Get or initialize the singleton PDO connection.
     *
     * @throws PDOException
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dbConfig = bridge_config('database');

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['dbname'],
                $dbConfig['charset']
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ];

            try {
                self::$instance = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $options);
            } catch (PDOException $e) {
                BridgeLogger::error('Database connection failed: ' . $e->getMessage());
                throw $e;
            }
        }

        return self::$instance;
    }

    /**
     * Helper to execute a prepared SELECT and fetch all matching records.
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Helper to execute a prepared SELECT and fetch a single record.
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result === false ? null : $result;
    }

    /**
     * Helper to execute an INSERT, UPDATE, or DELETE with prepared parameters.
     * Returns the affected rows count.
     */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Get the last inserted row ID.
     */
    public static function lastInsertId(): string
    {
        return self::getConnection()->lastInsertId();
    }

    /**
     * Execute a callable inside a database transaction.
     *
     * @throws Throwable
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::getConnection();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Execute the migration SQL file to ensure required tables exist.
     */
    public static function runMigrations(?string $filePath = null): bool
    {
        $filePath = $filePath ?? __DIR__ . '/database/migrations.sql';

        if (!file_exists($filePath)) {
            BridgeLogger::error("Migration file not found at: {$filePath}");
            return false;
        }

        $sql = file_get_contents($filePath);
        if ($sql === false || trim($sql) === '') {
            BridgeLogger::error("Migration file is empty: {$filePath}");
            return false;
        }

        try {
            $pdo = self::getConnection();
            $pdo->exec($sql);
            BridgeLogger::info("Database migrations applied successfully from {$filePath}.");
            return true;
        } catch (PDOException $e) {
            BridgeLogger::error("Failed to run migrations: " . $e->getMessage());
            throw $e;
        }
    }
}
