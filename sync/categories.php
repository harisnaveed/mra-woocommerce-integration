<?php
/**
 * Category Synchronization Engine
 *
 * Synchronizes categories between MyRepairApp (MRA) and WooCommerce.
 * Manages category mapping in `mra_woocommerce_categories` table
 * and strictly prevents duplicate category creation in WooCommerce.
 *
 * Core PHP — no external dependencies.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../helpers/logger.php';
require_once __DIR__ . '/../woocommerce/CategoryManager.php';

class CategorySync
{
    private CategoryManager $categoryManager;
    private PDO $pdo;

    /**
     * Local cache for category mappings: key "category|type" => woo_category_id
     */
    private array $mappingCache = [];

    public function __construct(?CategoryManager $categoryManager = null, ?PDO $pdo = null)
    {
        $this->categoryManager = $categoryManager ?? new CategoryManager();
        $this->pdo             = $pdo ?? BridgeDatabase::getConnection();
        $this->loadExistingMappings();
    }

    /**
     * Pre-load existing database mappings into memory for maximum performance.
     */
    private function loadExistingMappings(): void
    {
        $rows = BridgeDatabase::fetchAll("SELECT mra_category, mra_type, woo_category_id FROM mra_woocommerce_categories");
        foreach ($rows as $row) {
            $key = $this->buildKey((string)$row['mra_category'], $row['mra_type'] ? (string)$row['mra_type'] : null);
            $this->mappingCache[$key] = (int)$row['woo_category_id'];
        }
    }

    /**
     * Resolve a WooCommerce Category ID for an MRA category/type pair.
     * If mapped already, returns immediately without making API calls.
     * If unmapped, checks WooCommerce to prevent duplicates, creates if needed, and stores the mapping.
     *
     * @param string $mraCategory Category name from MRA
     * @param string|null $mraType Optional item type from MRA
     * @param string|null $syncId Audit session identifier
     * @return int WooCommerce category ID
     */
    public function getOrCreateCategory(string $mraCategory, ?string $mraType = null, ?string $syncId = null): int
    {
        $trimmedCategory = trim($mraCategory);
        if ($trimmedCategory === '') {
            $trimmedCategory = 'Uncategorized';
        }

        $trimmedType = $mraType !== null ? trim($mraType) : null;
        $key = $this->buildKey($trimmedCategory, $trimmedType);

        // 1. Check in-memory cache
        if (isset($this->mappingCache[$key])) {
            return $this->mappingCache[$key];
        }

        // 2. Check database table directly
        $sql = "SELECT woo_category_id FROM mra_woocommerce_categories 
                WHERE LOWER(mra_category) = LOWER(:category) 
                  AND (mra_type IS NULL OR LOWER(mra_type) = LOWER(:type))
                LIMIT 1";

        $row = BridgeDatabase::fetchOne($sql, [
            ':category' => $trimmedCategory,
            ':type'     => $trimmedType ?? '',
        ]);

        if ($row && !empty($row['woo_category_id'])) {
            $wooCategoryId = (int)$row['woo_category_id'];
            $this->mappingCache[$key] = $wooCategoryId;
            return $wooCategoryId;
        }

        // 3. Not in mapping table -> Check WooCommerce REST API by name to prevent duplicating existing store categories
        $existingWcCat = $this->categoryManager->findByName($trimmedCategory);
        $wooCategoryId = null;

        if ($existingWcCat && isset($existingWcCat['id'])) {
            $wooCategoryId = (int)$existingWcCat['id'];
            BridgeLogger::info("Category '{$trimmedCategory}' already exists in WooCommerce (ID: {$wooCategoryId}). Storing bridge mapping.");

            BridgeLogger::audit(
                $this->pdo,
                'MAP_EXISTING_CATEGORY',
                'success',
                "Mapped existing WooCommerce category '{$trimmedCategory}' (ID: {$wooCategoryId})",
                null,
                $syncId
            );
        } else {
            // 4. Create new category in WooCommerce
            try {
                $created = $this->categoryManager->create([
                    'name'        => $trimmedCategory,
                    'description' => "Synchronized from MyRepairApp",
                ]);

                $wooCategoryId = (int)$created['id'];
                BridgeLogger::info("Created new WooCommerce category: '{$trimmedCategory}' (ID: {$wooCategoryId})");

                BridgeLogger::audit(
                    $this->pdo,
                    'CREATE_CATEGORY',
                    'success',
                    "Created WooCommerce category '{$trimmedCategory}' (ID: {$wooCategoryId})",
                    null,
                    $syncId
                );
            } catch (Throwable $e) {
                BridgeLogger::error("Failed to create WooCommerce category '{$trimmedCategory}': " . $e->getMessage());
                BridgeLogger::audit(
                    $this->pdo,
                    'CREATE_CATEGORY',
                    'failed',
                    "Failed to create category '{$trimmedCategory}'",
                    $e->getMessage(),
                    $syncId
                );
                throw $e;
            }
        }

        // 5. Persist mapping in database
        $insertSql = "INSERT INTO mra_woocommerce_categories 
                      (mra_category, mra_type, woo_category_id, created_at, updated_at)
                      VALUES (:category, :type, :woo_category_id, NOW(), NOW())";

        BridgeDatabase::execute($insertSql, [
            ':category'        => $trimmedCategory,
            ':type'            => $trimmedType,
            ':woo_category_id' => $wooCategoryId,
        ]);

        $this->mappingCache[$key] = $wooCategoryId;
        return $wooCategoryId;
    }

    /**
     * Batch process multiple categories from a list of MRA inventory items.
     *
     * @param array $mraItems List of raw inventory item arrays from MRA
     * @param string|null $syncId
     * @return array Map of category name => WooCommerce category ID
     */
    public function syncFromInventoryItems(array $mraItems, ?string $syncId = null): array
    {
        $uniqueCategories = [];

        foreach ($mraItems as $item) {
            $cat  = trim((string)($item['category'] ?? ''));
            $type = trim((string)($item['type'] ?? ''));

            if ($cat === '') {
                $cat = 'General';
            }

            $uniqueCategories[$cat] = $type !== '' ? $type : null;
        }

        $results = [];
        foreach ($uniqueCategories as $categoryName => $typeName) {
            $wcId = $this->getOrCreateCategory($categoryName, $typeName, $syncId);
            $results[$categoryName] = $wcId;
        }

        return $results;
    }

    /**
     * Build cache key.
     */
    private function buildKey(string $category, ?string $type = null): string
    {
        return strtolower($category) . '|' . ($type !== null ? strtolower($type) : '');
    }
}
