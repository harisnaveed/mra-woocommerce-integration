<?php
/**
 * MRA -> WooCommerce Bridge Configuration
 *
 * Central configuration file that parses environment variables (.env)
 * and provides structured, sanitized configuration settings.
 *
 * Core PHP — no external dependencies.
 */

declare(strict_types=1);

// Prevent direct web access to sensitive config if served directly via web server
if (php_sapi_name() !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Lightweight .env parser for Core PHP.
 * Reads KEY=VALUE pairs from a file and registers them into $_ENV and getenv().
 */
function bridge_load_env(string $filePath): void
{
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and empty lines
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip surrounding quotes if present
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            // Only populate if not already set in server environment
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

// Load .env file from project root if it exists
bridge_load_env(__DIR__ . '/.env');

/**
 * Retrieve an environment variable or fallback value.
 */
function bridge_env(string $key, mixed $default = null): mixed
{
    $val = getenv($key);
    if ($val === false) {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }

    if (is_string($val)) {
        $lower = strtolower($val);
        if ($lower === 'true') return true;
        if ($lower === 'false') return false;
        if ($lower === 'null') return null;
    }

    return $val;
}

/**
 * Return global configuration array.
 */
function bridge_config(?string $key = null, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $config = [
            'app' => [
                'env'        => bridge_env('APP_ENV', 'production'),
                'root_path'  => __DIR__,
                'log_path'   => __DIR__ . '/' . ltrim((string) bridge_env('LOG_FILE', 'logs/sync.log'), '/'),
                'log_level'  => strtoupper((string) bridge_env('LOG_LEVEL', 'INFO')),
            ],
            'database' => [
                'host'       => (string) bridge_env('DB_HOST', 'localhost'),
                'port'       => (int) bridge_env('DB_PORT', 3306),
                'dbname'     => (string) bridge_env('DB_NAME', 'techrestore_ecommerce'),
                'username'   => (string) bridge_env('DB_USER', 'root'),
                'password'   => (string) bridge_env('DB_PASS', ''),
                'charset'    => (string) bridge_env('DB_CHARSET', 'utf8mb4'),
            ],
            'mra' => [
                'base_url'   => rtrim((string) bridge_env('MRA_API_BASE_URL', 'https://www.myrepairapp.com/api'), '/'),
                'api_key'    => (string) bridge_env('MRA_API_KEY', ''),
                'store_id'   => (string) bridge_env('MRA_STORE_ID', ''),
                'rate_limit' => 60, // MRA documented requests per minute
            ],
            'woocommerce' => [
                'base_url'        => rtrim((string) bridge_env('WC_API_BASE_URL', 'http://localhost/wp-json/wc/v3'), '/'),
                'consumer_key'    => (string) bridge_env('WC_CONSUMER_KEY', ''),
                'consumer_secret' => (string) bridge_env('WC_CONSUMER_SECRET', ''),
                'verify_ssl'      => (bool) bridge_env('WC_VERIFY_SSL', false),
            ],
            'sync' => [
                'page_size'   => (int) bridge_env('SYNC_PAGE_SIZE', 50),
                'timeout'     => (int) bridge_env('SYNC_TIMEOUT', 30),
                'max_retries' => (int) bridge_env('SYNC_MAX_RETRIES', 3),
            ],
            'webhook' => [
                'secret'      => (string) bridge_env('WEBHOOK_SECRET', ''),
            ],
            'cron' => [
                'secret'      => (string) bridge_env('CRON_SECRET', bridge_env('WEBHOOK_SECRET', '')),
            ],
        ];
    }

    if ($key === null) {
        return $config;
    }

    // Support dot-notation: e.g. bridge_config('database.host')
    $segments = explode('.', $key);
    $current = $config;

    foreach ($segments as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return $default;
        }
        $current = $current[$segment];
    }

    return $current;
}

// Return the full configuration array when directly required
return bridge_config();
