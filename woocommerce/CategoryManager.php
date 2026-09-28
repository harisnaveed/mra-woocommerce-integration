<?php
/**
 * WooCommerce Category Manager
 *
 * Provides high-level abstractions for managing WooCommerce product categories
 * via the WooCommerce REST API.
 *
 * Core PHP — no external dependencies.
 */

declare(strict_types=1);

require_once __DIR__ . '/WooCommerceClient.php';
require_once __DIR__ . '/../helpers/logger.php';

class CategoryManager
{
    private WooCommerceClient $client;
    private static array $categoryNameCache = [];

    public function __construct(?WooCommerceClient $client = null)
    {
        $this->client = $client ?? new WooCommerceClient();
    }

    /**
     * Retrieve a WooCommerce category by ID.
     *
     * @param int $categoryId
     * @return array|null
     */
    public function findById(int $categoryId): ?array
    {
        try {
            $response = $this->client->get("/products/categories/{$categoryId}");
            return is_array($response) && isset($response['id']) ? $response : null;
        } catch (WooCommerceException $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            BridgeLogger::error("Failed to find WooCommerce category by ID {$categoryId}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Search for a category in WooCommerce by exact name and parent ID.
     *
     * @param string $name Name of category
     * @param int $parentId Parent category ID (default 0 for top-level)
     * @return array|null
     */
    public function findByName(string $name, int $parentId = 0): ?array
    {
        $cacheKey = strtolower(trim($name)) . ':' . $parentId;
        if (isset(self::$categoryNameCache[$cacheKey])) {
            return self::$categoryNameCache[$cacheKey];
        }

        try {
            $categories = $this->client->get('/products/categories', [
                'search'   => $name,
                'parent'   => $parentId,
                'per_page' => 50,
            ]);

            foreach ($categories as $cat) {
                if (strcasecmp(trim((string)$cat['name']), trim($name)) === 0 && (int)($cat['parent'] ?? 0) === $parentId) {
                    self::$categoryNameCache[$cacheKey] = $cat;
                    return $cat;
                }
            }

            return null;
        } catch (WooCommerceException $e) {
            BridgeLogger::error("Failed searching WooCommerce category by name '{$name}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create a new category in WooCommerce.
     *
     * @param array $data e.g. ['name' => 'Phones', 'parent' => 0, 'description' => '...']
     * @return array Created category record from WooCommerce
     * @throws WooCommerceException
     */
    public function create(array $data): array
    {
        if (empty(trim($data['name'] ?? ''))) {
            throw new InvalidArgumentException("Category name cannot be empty.");
        }

        $payload = [
            'name'        => trim($data['name']),
            'parent'      => (int) ($data['parent'] ?? 0),
            'description' => (string) ($data['description'] ?? ''),
        ];

        BridgeLogger::info("Creating WooCommerce category: '{$payload['name']}' (Parent: {$payload['parent']})");

        $response = $this->client->post('/products/categories', $payload);

        // Update local cache
        $cacheKey = strtolower($payload['name']) . ':' . $payload['parent'];
        self::$categoryNameCache[$cacheKey] = $response;

        return $response;
    }

    /**
     * Update an existing WooCommerce category.
     *
     * @param int $categoryId
     * @param array $data Fields to update
     * @return array
     * @throws WooCommerceException
     */
    public function update(int $categoryId, array $data): array
    {
        BridgeLogger::info("Updating WooCommerce category ID {$categoryId}");
        return $this->client->put("/products/categories/{$categoryId}", $data);
    }

    /**
     * Retrieve all categories from WooCommerce.
     *
     * @param array $params
     * @return array
     */
    public function getAll(array $params = []): array
    {
        $params['per_page'] = $params['per_page'] ?? 100;
        return $this->client->get('/products/categories', $params);
    }
}
