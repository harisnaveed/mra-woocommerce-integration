<?php
/**
 * MRA -> WooCommerce Scheduled Synchronization Runner
 *
 * Designed to execute seamlessly via both:
 * 1. Command Line Interface (CLI): php cron.php [--page-size=50] [--max-pages=2]
 * 2. Web Browser: http://localhost:8000/mra-woocommerce/cron.php?page_size=50&max_pages=2
 *
 * Implements non-blocking process locking, CLI subprocess delegator for browser runs
 * (preventing single-threaded web server deadlocks), and live streaming UI.
 *
 * Core PHP — no external dependencies.
 */

declare(strict_types=1);

@set_time_limit(0);
@ignore_user_abort(true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers/logger.php';
require_once __DIR__ . '/sync/inventory.php';

$isCli = (php_sapi_name() === 'cli');

// =============================================================================
// BROWSER MODE: Delegate to CLI Subprocess to stream output & prevent deadlocks
// =============================================================================
if (!$isCli && !isset($_GET['internal_exec'])) {
    $env = bridge_config('app.env', 'development');
    $expectedSecret = (string)bridge_config('cron.secret', bridge_config('webhook.secret'));
    $providedSecret = (string)($_GET['secret'] ?? $_GET['token'] ?? '');

    // In production, enforce secret if configured
    if ($env === 'production' && !empty($expectedSecret)) {
        if (!hash_equals($expectedSecret, $providedSecret)) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            echo "<h2>403 Forbidden</h2><p>Access denied. Provide valid secret token via <code>?secret=YOUR_TOKEN</code>.</p>";
            exit;
        }
    }

    // Disable output buffering for live browser streaming
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>MRA -> WooCommerce Sync Runner</title>';
    echo '<style>
        body { background: #0f172a; color: #f8fafc; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; padding: 24px; margin: 0; line-height: 1.6; }
        .container { max-width: 960px; margin: 0 auto; background: #1e293b; border-radius: 12px; padding: 24px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); border: 1px solid #334155; }
        h1 { color: #38bdf8; font-size: 20px; margin-top: 0; border-bottom: 1px solid #334155; padding-bottom: 12px; }
        pre { background: #0f172a; color: #4ade80; padding: 18px; border-radius: 8px; overflow-x: auto; font-size: 14px; border: 1px solid #334155; white-space: pre-wrap; word-break: break-all; min-height: 200px; }
        .meta { color: #94a3b8; font-size: 13px; margin-bottom: 16px; }
        .badge { background: #0284c7; color: white; padding: 3px 8px; border-radius: 4px; font-weight: bold; }
        .actions { margin-top: 16px; font-size: 13px; color: #94a3b8; }
        a { color: #38bdf8; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style></head><body><div class="container">';
    echo '<h1>MRA &rarr; WooCommerce Synchronization Engine</h1>';
    echo '<div class="meta">Mode: <span class="badge">Web Browser UI</span> | Environment: <strong>' . htmlspecialchars((string)bridge_config('app.env')) . '</strong></div>';
    echo '<pre>';

    // Build CLI invocation
    $phpBin = 'php';
    if (defined('PHP_BINARY') && PHP_BINARY && file_exists(PHP_BINARY)) {
        $phpBin = escapeshellarg(PHP_BINARY);
    }

    $scriptPath = escapeshellarg(__DIR__ . '/cron.php');
    $cmd = "{$phpBin} {$scriptPath} --cli-runner";

    $pageSize = isset($_GET['page_size']) ? (int)$_GET['page_size'] : (isset($_GET['pageSize']) ? (int)$_GET['pageSize'] : 0);
    $maxPages = isset($_GET['max_pages']) ? (int)$_GET['max_pages'] : (isset($_GET['maxPages']) ? (int)$_GET['maxPages'] : 0);
    $storeId  = isset($_GET['store_id']) ? (string)$_GET['store_id'] : (isset($_GET['storeId']) ? (string)$_GET['storeId'] : '');

    if ($pageSize > 0) $cmd .= " --page-size={$pageSize}";
    if ($maxPages > 0) $cmd .= " --max-pages={$maxPages}";
    if ($storeId !== '') $cmd .= " --store-id=" . escapeshellarg($storeId);

    // Stream CLI output directly to browser
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptor, $pipes);

    if (is_resource($process)) {
        fclose($pipes[0]);
        while (!feof($pipes[1])) {
            $line = fgets($pipes[1]);
            if ($line !== false) {
                echo htmlspecialchars($line);
                flush();
            }
        }
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        if (!empty($stderr)) {
            echo htmlspecialchars($stderr);
        }
        fclose($pipes[2]);

        proc_close($process);
    } else {
        echo "[ERROR] Failed to spawn background CLI sync process.\n";
    }

    echo '</pre>';
    echo '<div class="actions">Quick Run Links: ';
    echo '<a href="?page_size=5&max_pages=1">Sync 5 Items</a> &bull; ';
    echo '<a href="?page_size=20&max_pages=1">Sync 20 Items</a> &bull; ';
    echo '<a href="?page_size=50&max_pages=2">Sync 100 Items</a> &bull; ';
    echo '<a href="?">Sync Full Catalog</a>';
    echo '</div></div></body></html>';
    exit(0);
}

// =============================================================================
// CLI SYNCHRONIZATION EXECUTION
// =============================================================================

// Concurrency Lock: Prevent multiple sync processes from overlapping
$lockFile = __DIR__ . '/logs/cron.lock';
$lockHandle = fopen($lockFile, 'c+');

if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $message = "NOTICE: Another synchronization process is already running. Exiting.";
    BridgeLogger::warning($message);
    echo "[!] {$message}\n";
    exit(0);
}

// Register automatic lock cleanup on exit
register_shutdown_function(function () use (&$lockHandle, $lockFile) {
    if (is_resource($lockHandle)) {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
});

// Parse Options
$pageSize = (int)bridge_config('sync.page_size', 50);
$maxPages = 0; // 0 = unlimited / all
$storeId  = (string)bridge_config('mra.store_id', '');

$cliOpts = getopt('', ['page-size::', 'max-pages::', 'store-id::', 'cli-runner']);
if (isset($cliOpts['page-size'])) $pageSize = (int)$cliOpts['page-size'];
if (isset($cliOpts['max-pages'])) $maxPages = (int)$cliOpts['max-pages'];
if (isset($cliOpts['store-id']))  $storeId  = (string)$cliOpts['store-id'];

$filters = [];
if (!empty($storeId)) {
    $filters['storeIds'] = $storeId;
}

$syncId = 'cron-' . date('Ymd-His') . '-' . substr(md5((string)microtime()), 0, 6);
$startTime = microtime(true);

echo "========================================================\n";
echo "   MRA -> WooCommerce Scheduled Sync [{$syncId}]        \n";
echo "========================================================\n";
echo "Started at:   " . date('Y-m-d H:i:s') . "\n";
echo "Page Size:    {$pageSize}\n";
echo "Max Pages:    " . ($maxPages > 0 ? (string)$maxPages : 'Unlimited (All)') . "\n";
echo "Store Filter: " . ($storeId !== '' ? $storeId : 'All Stores') . "\n\n";
try {
    $engine = new InventorySyncEngine();

    $onProgress = function (int $index, int $total, array $item, array $result) {
        $num = $index + 1;
        $name = mb_strimwidth($item['name'] ?? 'Untitled Item', 0, 42, '...');
        $status = strtoupper($result['status'] ?? 'UNKNOWN');
        $sku = $item['sku'] ?? 'N/A';
        $statusDisplay = match($status) {
            'CREATED' => "\033[32mCREATED\033[0m",
            'UPDATED' => "\033[36mUPDATED\033[0m",
            'SKIPPED' => "\033[33mSKIPPED\033[0m",
            default   => "\033[31mFAILED \033[0m",
        };
        // Print plain status if not interactive ANSI terminal
        if (DIRECTORY_SEPARATOR === '\\' && !getenv('ANSICON') && !getenv('WT_SESSION')) {
            $statusDisplay = sprintf("%-7s", $status);
        }
        echo sprintf("  [%d/%d] [%s] %-45s (SKU: %s)\n", $num, $total, $statusDisplay, $name, $sku);
        @flush();
    };

    $stats = $engine->run($filters, $pageSize, $maxPages, $onProgress);

    $duration = round(microtime(true) - $startTime, 2);

    echo "\n--------------------------------------------------------\n";
    echo "                   Execution Summary                    \n";
    echo "--------------------------------------------------------\n";
    echo "Duration:        {$duration} seconds\n";
    echo "Total Items:     {$stats['total']}\n";
    echo "Created:         {$stats['created']}\n";
    echo "Updated:         {$stats['updated']}\n";
    echo "Skipped:         {$stats['skipped']}\n";
    echo "Failed:          {$stats['failed']}\n";

    if (!empty($stats['errors'])) {
        echo "\nErrors Logged (" . count($stats['errors']) . "):\n";
        foreach (array_slice($stats['errors'], 0, 5) as $err) {
            echo "  * {$err}\n";
        }
        if (count($stats['errors']) > 5) {
            echo "  * ...and " . (count($stats['errors']) - 5) . " more errors (see logs/sync.log)\n";
        }
    }
    echo "--------------------------------------------------------\n";

    exit(0);

} catch (Throwable $e) {
    BridgeLogger::error("Fatal cron synchronization failure: " . $e->getMessage(), [
        'sync_id' => $syncId,
    ]);

    echo "\n[FATAL ERROR] Synchronization aborted: " . $e->getMessage() . "\n";
    exit(1);
}
