<?php
/**
 * MRA -> WooCommerce Bridge Logger Helper
 *
 * Provides safe logging to local files and the database audit table.
 * Automatically scrubs sensitive credentials (API keys, secrets, tokens).
 *
 * Core PHP — no external dependencies.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

class BridgeLogger
{
    public const DEBUG   = 100;
    public const INFO    = 200;
    public const WARNING = 300;
    public const ERROR   = 400;

    protected static array $levels = [
        'DEBUG'   => self::DEBUG,
        'INFO'    => self::INFO,
        'WARNING' => self::WARNING,
        'ERROR'   => self::ERROR,
    ];

    /**
     * Patterns/keys that must never be recorded in plain text.
     */
    protected static array $sensitiveKeys = [
        'api_key',
        'apikey',
        'key',
        'secret',
        'consumer_secret',
        'password',
        'pass',
        'token',
        'authorization',
        'auth',
        'x-api-key',
    ];

    /**
     * Write an INFO level message.
     */
    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    /**
     * Write a WARNING level message.
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    /**
     * Write an ERROR level message.
     */
    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    /**
     * Write a DEBUG level message.
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log('DEBUG', $message, $context);
    }

    /**
     * Core logging dispatcher.
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        $configuredLevelName = bridge_config('app.log_level', 'INFO');
        $configuredLevel = self::$levels[$configuredLevelName] ?? self::INFO;
        $currentLevel = self::$levels[strtoupper($level)] ?? self::INFO;

        // Skip if message level is lower than configured threshold
        if ($currentLevel < $configuredLevel) {
            return;
        }

        $scrubbedContext = self::scrubSensitiveData($context);
        $scrubbedMessage = self::scrubString($message);

        $timestamp = date('Y-m-d H:i:s');
        $contextString = !empty($scrubbedContext) ? ' ' . json_encode($scrubbedContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $logLine = sprintf("[%s] [%s] %s%s%s", $timestamp, strtoupper($level), $scrubbedMessage, $contextString, PHP_EOL);

        // Ensure target directory exists
        $filePath = bridge_config('app.log_path');
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($filePath, $logLine, FILE_APPEND | LOCK_EX);
    }

    /**
     * Record an entry directly into the `mra_woocommerce_sync_logs` database table.
     */
    public static function audit(
        PDO $pdo,
        string $action,
        string $status,
        ?string $message = null,
        mixed $error = null,
        ?string $syncId = null,
        ?string $mraInventoryId = null,
        ?int $wooProductId = null
    ): void {
        try {
            $sql = "INSERT INTO mra_woocommerce_sync_logs 
                    (sync_id, mra_inventory_id, woo_product_id, action, status, message, error, created_at)
                    VALUES 
                    (:sync_id, :mra_inventory_id, :woo_product_id, :action, :status, :message, :error, NOW())";

            $stmt = $pdo->prepare($sql);

            $errorStr = null;
            if ($error !== null) {
                if (is_array($error) || is_object($error)) {
                    $errorStr = json_encode(self::scrubSensitiveData((array) $error), JSON_UNESCAPED_SLASHES);
                } else {
                    $errorStr = self::scrubString((string) $error);
                }
            }

            $stmt->execute([
                ':sync_id'          => $syncId,
                ':mra_inventory_id' => $mraInventoryId,
                ':woo_product_id'   => $wooProductId,
                ':action'           => strtoupper($action),
                ':status'           => strtolower($status),
                ':message'          => $message !== null ? self::scrubString($message) : null,
                ':error'            => $errorStr,
            ]);
        } catch (Throwable $e) {
            // Never crash the application if audit logging to DB fails; fallback to file
            self::error('Failed to write audit log to database: ' . $e->getMessage());
        }
    }

    /**
     * Recursively scrubs sensitive values from arrays.
     */
    public static function scrubSensitiveData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $isSensitiveKey = false;
            $normalizedKey = strtolower((string) $key);

            foreach (self::$sensitiveKeys as $sensitive) {
                if (strpos($normalizedKey, $sensitive) !== false) {
                    $isSensitiveKey = true;
                    break;
                }
            }

            if ($isSensitiveKey) {
                $sanitized[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::scrubSensitiveData($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = self::scrubString($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Mask potential secrets embedded inside string messages.
     */
    public static function scrubString(string $input): string
    {
        // Redact consumer_secret or api_key query parameters if embedded in URLs
        $pattern = '/([?&](?:key|secret|consumer_secret|api_key|token)=)[^&]+/i';
        $output = preg_replace($pattern, '$1***REDACTED***', $input);

        // Redact Authorization headers if logged
        $authPattern = '/(Authorization:\s*(?:Basic|Bearer)\s+)[^\r\n]+/i';
        $output = preg_replace($authPattern, '$1***REDACTED***', $output ?? $input);

        // Explicitly scrub configured MRA API key and WooCommerce secrets if present
        $mraKey = (string) bridge_config('mra.api_key', '');
        if (!empty($mraKey) && strlen($mraKey) > 6) {
            $output = str_replace($mraKey, '***REDACTED***', $output ?? $input);
        }

        $wcSecret = (string) bridge_config('woocommerce.consumer_secret', '');
        if (!empty($wcSecret) && strlen($wcSecret) > 6) {
            $output = str_replace($wcSecret, '***REDACTED***', $output ?? $input);
        }

        $webhookSecret = (string) bridge_config('webhook.secret', '');
        if (!empty($webhookSecret) && strlen($webhookSecret) > 6) {
            $output = str_replace($webhookSecret, '***REDACTED***', $output ?? $input);
        }

        return $output ?? $input;
    }
}
