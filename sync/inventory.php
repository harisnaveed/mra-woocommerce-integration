<?php
/**
 * Inventory Synchronization Coordinator
 *
 * Coordinates full or batched synchronization between MyRepairApp
 * and WooCommerce. Integrates category mapping, product diffing,
 * pagination, and error isolation.
 *
 * Core PHP — no external dependencies.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../api/MRAClient.php';
require_once __DIR__ . '/../helpers/logger.php';
require_once __DIR__ . '/categories.php';
require_once __DIR__ . '/products.php';

class InventorySyncEngine
{
    private MRAClient $mraClient;
    private ProductSynchronizer $synchronizer;
    private CategorySync $categorySync;

    public function __construct(
        ?MRAClient $mraClient = null,
        ?ProductSynchronizer $synchronizer = null,
        ?CategorySync $categorySync = null
    ) {
        $this->mraClient    = $mraClient ?? new MRAClient();
        $this->synchronizer = $synchronizer ?? new ProductSynchronizer();
        $this->categorySync = $categorySync ?? new CategorySync();
    }

    /**
     * Run synchronization on a specific batch of MRA items.
     *
     * @param array $items Array of raw MRA items
     * @param string|null $syncId Audit session identifier
     * @param callable|null $onProgress Optional callback: fn(int $index, int $total, array $item, array $result)
     * @return array Sync statistics
     */
    public function syncItems(array $items, ?string $syncId = null, ?callable $onProgress = null): array
    {
        $syncId = $syncId ?? 'sync-' . date('Ymd-His') . '-' . substr(md5((string)microtime()), 0, 6);
        $startTime = microtime(true);

        $stats = [
            'sync_id' => $syncId,
            'total'   => count($items),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed'  => 0,
            'errors'  => [],
        ];

        BridgeLogger::info("Starting batch inventory sync ({$stats['total']} items) [Session: {$syncId}]");

        foreach ($items as $index => $item) {
            $itemId = $item['id'] ?? ('unknown-' . $index);
            $result = [];
            try {
                $result = $this->synchronizer->syncItem($item, $syncId);
                $action = $result['status'] ?? 'unknown';

                if ($action === 'created') {
                    $stats['created']++;
                } elseif ($action === 'updated') {
                    $stats['updated']++;
                } elseif ($action === 'skipped') {
                    $stats['skipped']++;
                } else {
                    $stats['failed']++;
                    if (!empty($result['error'])) {
                        $stats['errors'][] = "Item {$itemId}: " . $result['error'];
                    }
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = "Item {$itemId}: " . $e->getMessage();
                $result = ['status' => 'failed', 'error' => $e->getMessage()];
                BridgeLogger::error("Unhandled error syncing item {$itemId}: " . $e->getMessage());
            }

            if ($onProgress !== null) {
                try {
                    $onProgress($index, $stats['total'], $item, $result);
                } catch (Throwable) {
                    // Suppress callback exceptions to ensure sync continues
                }
            }
        }

        $duration = round(microtime(true) - $startTime, 2);
        $stats['duration_seconds'] = $duration;

        BridgeLogger::info("Completed batch inventory sync in {$duration}s", [
            'total'   => $stats['total'],
            'created' => $stats['created'],
            'updated' => $stats['updated'],
            'skipped' => $stats['skipped'],
            'failed'  => $stats['failed'],
        ]);

        return $stats;
    }

    /**
     * Fetch from MRA and synchronize.
     *
     * @param array $searchFilters Optional query filters for MRA
     * @param int $pageSize Page size for MRA query
     * @param int $maxPages Maximum pages to process (0 = all)
     * @param callable|null $onProgress Optional callback for real-time item progress
     * @return array
     */
    public function run(array $searchFilters = [], int $pageSize = 50, int $maxPages = 0, ?callable $onProgress = null): array
    {
        $syncId = 'full-' . date('Ymd-His') . '-' . substr(md5((string)microtime()), 0, 6);
        BridgeLogger::info("Initiating MRA inventory sync [Session: {$syncId}]");

        // Fetch items from MRA (Strict Read-Only)
        $items = $this->mraClient->fetchAllInventory($searchFilters, $pageSize, $maxPages);

        return $this->syncItems($items, $syncId, $onProgress);
    }
}
