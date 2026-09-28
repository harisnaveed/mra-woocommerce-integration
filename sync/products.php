<?php
/**
 * Product Synchronizer
 *
 * Implements granular product-level synchronization logic between MRA and WooCommerce.
 * Uses SHA-256 payload hashing to prevent redundant API updates.
 *
 * Core PHP — no external dependencies.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../helpers/logger.php';
require_once __DIR__ . '/../woocommerce/ProductManager.php';
require_once __DIR__ . '/categories.php';

class ProductSynchronizer
{
    private ProductManager $productManager;
    private CategorySync $categorySync;
    private PDO $pdo;

    public function __construct(
        ?ProductManager $productManager = null,
        ?CategorySync $categorySync = null,
        ?PDO $pdo = null
    ) {
        $this->productManager = $productManager ?? new ProductManager();
        $this->categorySync   = $categorySync ?? new CategorySync();
        $this->pdo            = $pdo ?? BridgeDatabase::getConnection();
    }

    /**
     * Synchronize a single normalized MRA inventory item.
     *
     * @param array $item Normalized MRA item array
     * @param string|null $syncId Audit session identifier
     * @return array Result of the sync operation
     */
    public function syncItem(array $item, ?string $syncId = null): array
    {
        $mraInventoryId = (string)($item['id'] ?? '');
        if ($mraInventoryId === '') {
            BridgeLogger::warning("Encountered MRA item missing unique ID. Skipping.");
            return ['status' => 'skipped', 'reason' => 'missing_mra_id'];
        }

        $sku          = trim((string)($item['sku'] ?? ''));
        $name         = trim((string)($item['name'] ?? 'Untitled Item'));
        $price        = (float)($item['price'] ?? 0.0);
        $cost         = (float)($item['cost'] ?? 0.0);
        $stock        = (int)($item['instock'] ?? 0);
        $category     = trim((string)($item['category'] ?? ''));
        $type         = trim((string)($item['type'] ?? ''));
        $storeId      = (string)($item['storeId'] ?? '');
        $mraUpdatedAt = (string)($item['updatedAt'] ?? '');
        $isActive     = (bool)($item['active'] ?? true);

        // Fallback SKU to MRA ID if empty to ensure unique WooCommerce SKU
        $effectiveSku = $sku !== '' ? $sku : 'MRA-' . substr($mraInventoryId, 0, 12);

        // 1. Resolve Category ID via CategorySync
        $wooCategoryId = null;
        if ($category !== '') {
            try {
                $wooCategoryId = $this->categorySync->getOrCreateCategory($category, $type !== '' ? $type : null, $syncId);
            } catch (Throwable $e) {
                BridgeLogger::warning("Could not map category '{$category}' for item '{$name}': " . $e->getMessage());
            }
        }

        // 2. Generate SHA-256 payload hash of essential attributes
        $hashPayload = [
            'sku'             => $effectiveSku,
            'name'            => $name,
            'price'           => number_format($price, 2, '.', ''),
            'stock'           => $stock,
            'woo_category_id' => $wooCategoryId,
            'active'          => $isActive,
        ];
        $currentHash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_SLASHES));

        // 3. Check existing mapping table record
        $existing = BridgeDatabase::fetchOne(
            "SELECT * FROM mra_woocommerce_sync WHERE mra_inventory_id = :id LIMIT 1",
            [':id' => $mraInventoryId]
        );

        // =========================================================================
        // CASE A: EXISTING PRODUCT
        // =========================================================================
        if ($existing) {
            $wooProductId = !empty($existing['woo_product_id']) ? (int)$existing['woo_product_id'] : null;

            // Check if nothing important changed and last sync succeeded
            if (
                $existing['sync_status'] === 'synced' &&
                $existing['payload_hash'] === $currentHash &&
                $wooProductId !== null
            ) {
                // Touch last_sync_at timestamp
                BridgeDatabase::execute(
                    "UPDATE mra_woocommerce_sync SET last_sync_at = NOW() WHERE id = :id",
                    [':id' => $existing['id']]
                );

                BridgeLogger::debug("Item '{$name}' (MRA: {$mraInventoryId}) is unchanged. Skipped WooCommerce update.");
                return [
                    'status'           => 'skipped',
                    'mra_inventory_id' => $mraInventoryId,
                    'woo_product_id'   => $wooProductId,
                    'action'           => 'SKIP',
                ];
            }

            // Attributes changed or previous sync failed -> Update WooCommerce
            try {
                if ($wooProductId === null) {
                    // Try to re-link or re-create
                    $wooProductId = $this->resolveOrCreateWooProduct($effectiveSku, $name, $price, $stock, $wooCategoryId, $isActive);
                } else {
                    $this->productManager->update($wooProductId, [
                        'name'           => $name,
                        'sku'            => $effectiveSku,
                        'regular_price'  => number_format($price, 2, '.', ''),
                        'stock_quantity' => $stock,
                        'categories'     => $wooCategoryId ? [$wooCategoryId] : [],
                        'status'         => $isActive ? 'publish' : 'draft',
                    ]);
                }

                // Update sync table record
                $updateSql = "UPDATE mra_woocommerce_sync SET 
                    mra_store_id    = :store_id,
                    sku             = :sku,
                    name            = :name,
                    category        = :category,
                    type            = :type,
                    mra_price       = :price,
                    mra_cost        = :cost,
                    mra_stock       = :stock,
                    mra_updated_at  = :mra_updated_at,
                    woo_product_id  = :woo_product_id,
                    woo_category_id = :woo_category_id,
                    payload_hash    = :payload_hash,
                    sync_status     = 'synced',
                    last_sync_at    = NOW(),
                    last_success_at = NOW(),
                    last_error      = NULL,
                    retry_count     = 0,
                    updated_at      = NOW()
                    WHERE id = :id";

                BridgeDatabase::execute($updateSql, [
                    ':store_id'        => $storeId,
                    ':sku'             => $effectiveSku,
                    ':name'            => $name,
                    ':category'        => $category,
                    ':type'            => $type,
                    ':price'           => $price,
                    ':cost'            => $cost,
                    ':stock'           => $stock,
                    ':mra_updated_at'  => $mraUpdatedAt,
                    ':woo_product_id'  => $wooProductId,
                    ':woo_category_id' => $wooCategoryId,
                    ':payload_hash'    => $currentHash,
                    ':id'              => $existing['id'],
                ]);

                BridgeLogger::info("Updated product '{$name}' (Woo: {$wooProductId}, Stock: {$stock}, Price: \${$price})");
                BridgeLogger::audit(
                    $this->pdo,
                    'UPDATE_PRODUCT',
                    'success',
                    "Updated product '{$name}'",
                    null,
                    $syncId,
                    $mraInventoryId,
                    $wooProductId
                );

                return [
                    'status'           => 'updated',
                    'mra_inventory_id' => $mraInventoryId,
                    'woo_product_id'   => $wooProductId,
                    'action'           => 'UPDATE_PRODUCT',
                ];
            } catch (Throwable $e) {
                $this->handleFailure($existing['id'], $mraInventoryId, $wooProductId, 'UPDATE_PRODUCT', $e, $syncId);
                return [
                    'status'           => 'failed',
                    'mra_inventory_id' => $mraInventoryId,
                    'woo_product_id'   => $wooProductId,
                    'error'            => $e->getMessage(),
                ];
            }
        }

        // =========================================================================
        // CASE B: NEW PRODUCT
        // =========================================================================
        try {
            $wooProductId = $this->resolveOrCreateWooProduct($effectiveSku, $name, $price, $stock, $wooCategoryId, $isActive);

            // Insert new mapping record
            $insertSql = "INSERT INTO mra_woocommerce_sync 
                (mra_inventory_id, mra_store_id, sku, name, category, type, mra_price, mra_cost, mra_stock, mra_updated_at, woo_product_id, woo_category_id, payload_hash, sync_status, last_sync_at, last_success_at, retry_count, created_at, updated_at)
                VALUES 
                (:mra_inventory_id, :store_id, :sku, :name, :category, :type, :price, :cost, :stock, :mra_updated_at, :woo_product_id, :woo_category_id, :payload_hash, 'synced', NOW(), NOW(), 0, NOW(), NOW())";

            BridgeDatabase::execute($insertSql, [
                ':mra_inventory_id' => $mraInventoryId,
                ':store_id'         => $storeId,
                ':sku'              => $effectiveSku,
                ':name'             => $name,
                ':category'         => $category,
                ':type'             => $type,
                ':price'            => $price,
                ':cost'             => $cost,
                ':stock'            => $stock,
                ':mra_updated_at'   => $mraUpdatedAt,
                ':woo_product_id'   => $wooProductId,
                ':woo_category_id'  => $wooCategoryId,
                ':payload_hash'     => $currentHash,
            ]);

            BridgeLogger::info("Created product '{$name}' (MRA: {$mraInventoryId} -> Woo: {$wooProductId})");
            BridgeLogger::audit(
                $this->pdo,
                'CREATE_PRODUCT',
                'success',
                "Created product '{$name}' (Woo: {$wooProductId})",
                null,
                $syncId,
                $mraInventoryId,
                $wooProductId
            );

            return [
                'status'           => 'created',
                'mra_inventory_id' => $mraInventoryId,
                'woo_product_id'   => $wooProductId,
                'action'           => 'CREATE_PRODUCT',
            ];
        } catch (Throwable $e) {
            BridgeLogger::error("Failed to create product '{$name}' (MRA: {$mraInventoryId}): " . $e->getMessage());

            // Insert placeholder record in failed state for tracking/retries
            BridgeDatabase::execute(
                "INSERT INTO mra_woocommerce_sync 
                (mra_inventory_id, mra_store_id, sku, name, category, type, mra_price, mra_cost, mra_stock, mra_updated_at, sync_status, last_sync_at, last_error, retry_count, created_at, updated_at)
                VALUES 
                (:mra_inventory_id, :store_id, :sku, :name, :category, :type, :price, :cost, :stock, :mra_updated_at, 'failed', NOW(), :error, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE sync_status = 'failed', last_error = :error_dup, retry_count = retry_count + 1, last_sync_at = NOW()",
                [
                    ':mra_inventory_id' => $mraInventoryId,
                    ':store_id'         => $storeId,
                    ':sku'              => $effectiveSku,
                    ':name'             => $name,
                    ':category'         => $category,
                    ':type'             => $type,
                    ':price'            => $price,
                    ':cost'             => $cost,
                    ':stock'            => $stock,
                    ':mra_updated_at'   => $mraUpdatedAt,
                    ':error'            => $e->getMessage(),
                    ':error_dup'        => $e->getMessage(),
                ]
            );

            BridgeLogger::audit(
                $this->pdo,
                'CREATE_PRODUCT',
                'failed',
                "Failed creating product '{$name}'",
                $e->getMessage(),
                $syncId,
                $mraInventoryId
            );

            return [
                'status'           => 'failed',
                'mra_inventory_id' => $mraInventoryId,
                'error'            => $e->getMessage(),
            ];
        }
    }

    /**
     * Finds existing WooCommerce product by SKU to prevent duplicate SKU errors,
     * or creates a new one.
     */
    private function resolveOrCreateWooProduct(
        string $sku,
        string $name,
        float $price,
        int $stock,
        ?int $wooCategoryId,
        bool $isActive
    ): int {
        try {
            // Attempt direct creation first (fast path: saves 1 redundant API call per item)
            $created = $this->productManager->create([
                'name'           => $name,
                'sku'            => $sku,
                'regular_price'  => number_format($price, 2, '.', ''),
                'stock_quantity' => $stock,
                'categories'     => $wooCategoryId ? [$wooCategoryId] : [],
                'status'         => $isActive ? 'publish' : 'draft',
                'description'    => "Imported from MyRepairApp",
            ]);

            return (int)$created['id'];
        } catch (WooCommerceException $e) {
            // If creation failed due to duplicate SKU, fall back to lookup by SKU
            if ($e->getStatusCode() === 400 || str_contains(strtolower($e->getMessage()), 'sku')) {
                $existingProduct = $this->productManager->findBySku($sku);
                if ($existingProduct && isset($existingProduct['id'])) {
                    $existingId = (int)$existingProduct['id'];
                    BridgeLogger::info("Found existing WooCommerce product with SKU '{$sku}' (ID: {$existingId}). Linking.");
                    return $existingId;
                }
            }
            throw $e;
        }
    }

    /**
     * Record failure state in the database mapping row.
     */
    private function handleFailure(int $rowId, string $mraId, ?int $wooId, string $action, Throwable $e, ?string $syncId): void
    {
        BridgeLogger::error("Sync failed for MRA ID {$mraId}: " . $e->getMessage());

        BridgeDatabase::execute(
            "UPDATE mra_woocommerce_sync SET 
                sync_status = 'failed', 
                last_error  = :error, 
                retry_count = retry_count + 1, 
                last_sync_at = NOW() 
             WHERE id = :id",
            [':error' => $e->getMessage(), ':id' => $rowId]
        );

        BridgeLogger::audit($this->pdo, $action, 'failed', "Sync error: " . $e->getMessage(), $e->getMessage(), $syncId, $mraId, $wooId);
    }
}
