<?php
/**
 * WooCommerce Product Manager
 *
 * Provides high-level abstractions for managing WooCommerce products
 * via the WooCommerce REST API.
 *
 * Core PHP — no external dependencies.
 */

declare(strict_types=1);

require_once __DIR__ . '/WooCommerceClient.php';
require_once __DIR__ . '/../helpers/logger.php';

class ProductManager
{
    private WooCommerceClient $client;

    public function __construct(?WooCommerceClient $client = null)
    {
        $this->client = $client ?? new WooCommerceClient();
    }

    /**
     * Retrieve a WooCommerce product by ID.
     *
     * @param int $productId
     * @return array|null
     */
    public function findById(int $productId): ?array
    {
        try {
            $response = $this->client->get("/products/{$productId}");
            return is_array($response) && isset($response['id']) ? $response : null;
        } catch (WooCommerceException $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            BridgeLogger::error("Failed to find WooCommerce product ID {$productId}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Search for a WooCommerce product by SKU.
     *
     * @param string $sku
     * @return array|null
     */
    public function findBySku(string $sku): ?array
    {
        if (trim($sku) === '') {
            return null;
        }

        try {
            $products = $this->client->get('/products', ['sku' => trim($sku)]);
            return !empty($products) && isset($products[0]['id']) ? $products[0] : null;
        } catch (WooCommerceException $e) {
            BridgeLogger::error("Failed to find WooCommerce product by SKU '{$sku}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create a new product in WooCommerce.
     *
     * @param array $productData
     * @return array Created product record from WooCommerce
     * @throws WooCommerceException
     */
    public function create(array $productData): array
    {
        $payload = $this->preparePayload($productData);
        BridgeLogger::info("Creating WooCommerce product: '{$payload['name']}' (SKU: {$payload['sku']})");

        return $this->client->post('/products', $payload);
    }

    /**
     * Update an existing product in WooCommerce.
     *
     * @param int $productId WooCommerce Product ID
     * @param array $productData
     * @return array Updated product record from WooCommerce
     * @throws WooCommerceException
     */
    public function update(int $productId, array $productData): array
    {
        $payload = $this->preparePayload($productData, false);
        BridgeLogger::info("Updating WooCommerce product ID {$productId}: '{$payload['name']}'");

        return $this->client->put("/products/{$productId}", $payload);
    }

    /**
     * Delete a product in WooCommerce.
     *
     * @param int $productId
     * @param bool $force True to permanently delete; false to send to trash
     * @return array
     */
    public function delete(int $productId, bool $force = true): array
    {
        BridgeLogger::info("Deleting WooCommerce product ID {$productId} (Force: " . ($force ? 'yes' : 'no') . ")");
        return $this->client->delete("/products/{$productId}", ['force' => $force]);
    }

    /**
     * Format and normalize product attributes for the WooCommerce REST API.
     */
    private function preparePayload(array $data, bool $isCreate = true): array
    {
        $payload = [];

        if (isset($data['name'])) {
            $payload['name'] = trim((string)$data['name']);
        } elseif ($isCreate) {
            $payload['name'] = 'Untitled Product';
        }

        if (isset($data['sku'])) {
            $payload['sku'] = trim((string)$data['sku']);
        }

        if (isset($data['type'])) {
            $payload['type'] = $data['type'];
        } elseif ($isCreate) {
            $payload['type'] = 'simple';
        }

        if (isset($data['regular_price'])) {
            $payload['regular_price'] = (string)$data['regular_price'];
        }

        if (isset($data['description'])) {
            $payload['description'] = (string)$data['description'];
        }

        if (isset($data['short_description'])) {
            $payload['short_description'] = (string)$data['short_description'];
        }

        // Inventory / Stock management
        $payload['manage_stock'] = true;
        if (isset($data['stock_quantity'])) {
            $payload['stock_quantity'] = (int)$data['stock_quantity'];
            $payload['stock_status']   = $payload['stock_quantity'] > 0 ? 'instock' : 'outofstock';
        }

        // Assigned categories
        if (!empty($data['categories'])) {
            $catList = [];
            foreach ($data['categories'] as $catId) {
                if (is_numeric($catId)) {
                    $catList[] = ['id' => (int)$catId];
                } elseif (is_array($catId) && isset($catId['id'])) {
                    $catList[] = ['id' => (int)$catId['id']];
                }
            }
            if (!empty($catList)) {
                $payload['categories'] = $catList;
            }
        }

        if (isset($data['status'])) {
            $payload['status'] = $data['status'];
        } elseif ($isCreate) {
            $payload['status'] = 'publish';
        }

        return $payload;
    }
}
