<?php
/**
 * MRA (MyRepairApp) API Client
 *
 * Implements communication with MyRepairApp API v2.
 * Handles authentication (X-Api-Key), rate limiting (60 req/min),
 * exponential backoff on 429 status, pagination, and response normalization.
 *
 * Includes strict READ-ONLY safety guards as requested by the user.
 *
 * Core PHP — no external dependencies.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/logger.php';

class MRAException extends RuntimeException
{
    private int $statusCode;
    private ?array $responseBody;

    public function __construct(string $message, int $statusCode = 0, ?array $responseBody = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }
}

class MRAClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $storeId;
    private int $timeout;
    private int $maxRetries;
    private bool $allowWrites;

    /**
     * Sliding window tracker for 60 requests per 60 seconds rate limit.
     */
    private static array $requestTimestamps = [];
    private const RATE_LIMIT_MAX_REQUESTS = 55; // Safety margin under 60 req/min
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    public function __construct(?array $overrideConfig = null)
    {
        $this->baseUrl     = rtrim($overrideConfig['base_url'] ?? bridge_config('mra.base_url', 'https://www.myrepairapp.com/api/v2'), '/');
        $this->apiKey      = $overrideConfig['api_key'] ?? bridge_config('mra.api_key', '');
        $this->storeId     = $overrideConfig['store_id'] ?? bridge_config('mra.store_id', '');
        $this->timeout     = (int) ($overrideConfig['timeout'] ?? bridge_config('sync.timeout', 30));
        $this->maxRetries = (int) ($overrideConfig['max_retries'] ?? bridge_config('sync.max_retries', 3));
        
        // Strict read-only mode by default: no POST, PUT, or DELETE to MRA
        $this->allowWrites = (bool) bridge_env('MRA_ALLOW_WRITES', false);

        if (empty($this->apiKey)) {
            throw new InvalidArgumentException("MyRepairApp API Key is missing. Please configure MRA_API_KEY in .env.");
        }
    }

    /**
     * Search and retrieve inventory items from MRA.
     *
     * Supported query parameters:
     * - query: string (search keyword)
     * - relevanceSort: bool (sort by relevance)
     * - storeIds: array|string (filter by store ID)
     * - categories: array|string (filter by category)
     * - types: array|string (filter by type, e.g. Inventory, Service)
     * - catalogOnly: bool
     * - inventoryOnly: bool
     * - instockOnly: bool
     * - page: int (1-based index)
     * - pageSize: int (default 20, max typical 100)
     *
     * @param array $params Query filter parameters
     * @return array List of inventory items
     * @throws MRAException
     */
    public function searchInventory(array $params = []): array
    {
        // If storeId is configured in bridge and not explicitly passed, apply it
        if (!empty($this->storeId) && !isset($params['storeIds'])) {
            $params['storeIds'] = $this->storeId;
        }

        $response = $this->request('GET', '/inventory/search', $params);

        if (!is_array($response)) {
            BridgeLogger::warning("Unexpected non-array response from MRA inventory search.");
            return [];
        }

        return $response;
    }

    /**
     * Fetch all inventory items across multiple pages with deduplication.
     *
     * @param array $params Filter parameters
     * @param int $pageSize Number of items per page
     * @param int $maxPages Maximum pages to fetch (0 for all)
     * @return array Full array of deduplicated inventory items
     * @throws MRAException
     */
    public function fetchAllInventory(array $params = [], int $pageSize = 50, int $maxPages = 0): array
    {
        $all = [];
        $seenIds = [];
        $page = 1;

        $params['pageSize'] = $pageSize;

        BridgeLogger::info("Starting full MRA inventory retrieval.", [
            'pageSize' => $pageSize,
            'maxPages' => $maxPages,
        ]);

        while (true) {
            if ($maxPages > 0 && $page > $maxPages) {
                BridgeLogger::info("Reached requested maxPages limit ({$maxPages}). Halting fetch.");
                break;
            }

            $params['page'] = $page;
            $items = $this->searchInventory($params);

            if (empty($items)) {
                // No more items
                break;
            }

            $newItemsInPage = 0;
            foreach ($items as $item) {
                $id = $item['id'] ?? null;
                if ($id && !isset($seenIds[$id])) {
                    $seenIds[$id] = true;
                    $all[] = $item;
                    $newItemsInPage++;
                }
            }

            BridgeLogger::debug("Fetched MRA page {$page}", [
                'items_in_page' => count($items),
                'new_unique'    => $newItemsInPage,
                'total_so_far'  => count($all),
            ]);

            // If this page returned fewer than requested pageSize, we've reached the end
            if (count($items) < $pageSize || $newItemsInPage === 0) {
                break;
            }

            $page++;
        }

        BridgeLogger::info("Completed MRA inventory retrieval.", ['total_unique_items' => count($all)]);
        return $all;
    }

    /**
     * Retrieve a specific inventory item by ID.
     *
     * Note: MRA's public API does not provide a direct GET /inventory/{id} route,
     * so this searches by item name/SKU or fetches the item from the catalog.
     *
     * @param string $inventoryId MRA item UUID
     * @param string|null $sku Optional SKU to optimize the search query
     * @return array|null The item data or null if not found
     */
    public function getInventoryItem(string $inventoryId, ?string $sku = null): ?array
    {
        $searchParams = [];
        if (!empty($sku)) {
            $searchParams['query'] = $sku;
        }

        $results = $this->searchInventory($searchParams);
        foreach ($results as $item) {
            if (($item['id'] ?? '') === $inventoryId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Create an inventory item in MRA.
     *
     * SAFETY GUARD: Blocked until explicitly authorized by the user.
     *
     * @throws RuntimeException
     */
    public function createInventory(array $data): array
    {
        if (!$this->allowWrites) {
            throw new RuntimeException("SAFETY GUARD: POST operations to MyRepairApp are disabled. Only read operations are permitted.");
        }

        return $this->request('POST', '/inventory', [], $data);
    }

    /**
     * Update an inventory item in MRA.
     *
     * SAFETY GUARD: Blocked until explicitly authorized by the user.
     *
     * @throws RuntimeException
     */
    public function updateInventory(array $data): array
    {
        if (!$this->allowWrites) {
            throw new RuntimeException("SAFETY GUARD: PUT operations to MyRepairApp are disabled. Only read operations are permitted.");
        }

        return $this->request('PUT', '/inventory', [], $data);
    }

    /**
     * Delete an inventory item in MRA.
     *
     * SAFETY GUARD: Blocked until explicitly authorized by the user.
     *
     * @throws RuntimeException
     */
    public function deleteInventory(string $inventoryId): array
    {
        if (!$this->allowWrites) {
            throw new RuntimeException("SAFETY GUARD: DELETE operations to MyRepairApp are disabled. Only read operations are permitted.");
        }

        return $this->request('DELETE', "/inventory/{$inventoryId}");
    }

    /**
     * Low-level cURL execution wrapper with throttling, retries, and error parsing.
     *
     * @throws MRAException
     */
    private function request(string $method, string $endpoint, array $queryParams = [], array $body = [], int $attempt = 1): array
    {
        $this->throttle();

        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        if (!empty($queryParams)) {
            $queryString = $this->buildQuery($queryParams);
            if ($queryString !== '') {
                $url .= (strpos($url, '?') === false ? '?' : '&') . $queryString;
            }
        }

        $ch = curl_init();
        $headers = [
            'X-Api-Key: ' . $this->apiKey,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_HEADER, true); // Include response headers for rate limit inspection

        if (!empty($body) && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }

        $rawResponse = curl_exec($ch);
        $curlError   = curl_error($ch);
        $curlErrno   = curl_errno($ch);
        $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        curl_close($ch);

        if ($curlErrno !== 0) {
            BridgeLogger::error("cURL error communicating with MRA: {$curlError} (Code: {$curlErrno})", [
                'endpoint' => $endpoint,
                'attempt'  => $attempt,
            ]);

            if ($attempt < $this->maxRetries) {
                sleep((int) pow(2, $attempt));
                return $this->request($method, $endpoint, $queryParams, $body, $attempt + 1);
            }

            throw new MRAException("cURL network error communicating with MRA: {$curlError}", $curlErrno);
        }

        $responseHeaders = substr((string)$rawResponse, 0, $headerSize);
        $responseBody    = substr((string)$rawResponse, $headerSize);

        // Record request for sliding window rate limiting
        self::$requestTimestamps[] = microtime(true);

        // Handle 429 Rate Limiting with exponential backoff
        if ($httpCode === 429) {
            BridgeLogger::warning("MRA API rate limit exceeded (HTTP 429). Attempt {$attempt} of {$this->maxRetries}.", [
                'endpoint' => $endpoint,
            ]);

            if ($attempt <= $this->maxRetries) {
                $retryAfter = $this->extractHeader($responseHeaders, 'Retry-After');
                $waitTime = $retryAfter !== null && is_numeric($retryAfter)
                    ? (int) $retryAfter
                    : (int) pow(2, $attempt) * 2;

                BridgeLogger::info("Backing off for {$waitTime}s before retrying MRA request...");
                sleep($waitTime);
                return $this->request($method, $endpoint, $queryParams, $body, $attempt + 1);
            }

            throw new MRAException("MRA API rate limit exceeded after {$this->maxRetries} retries.", 429);
        }

        $parsedData = json_decode($responseBody, true);

        // Check for HTTP errors (4xx / 5xx)
        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = "MRA API responded with HTTP {$httpCode}";
            if (is_array($parsedData) && isset($parsedData['message'])) {
                $errorMessage .= ": " . $parsedData['message'];
            } elseif (is_array($parsedData) && isset($parsedData['error'])) {
                $errorMessage .= ": " . $parsedData['error'];
            }

            BridgeLogger::error($errorMessage, [
                'endpoint'  => $endpoint,
                'http_code' => $httpCode,
            ]);

            // Retry temporary 5xx server errors
            if ($httpCode >= 500 && $attempt < $this->maxRetries) {
                sleep((int) pow(2, $attempt));
                return $this->request($method, $endpoint, $queryParams, $body, $attempt + 1);
            }

            throw new MRAException($errorMessage, $httpCode, is_array($parsedData) ? $parsedData : null);
        }

        if ($parsedData === null && !empty(trim($responseBody))) {
            throw new MRAException("Failed to decode JSON response from MRA: " . json_last_error_msg(), 500);
        }

        return is_array($parsedData) ? $parsedData : [];
    }

    /**
     * Sliding window rate limiter to guarantee we never exceed 60 requests per minute.
     */
    private function throttle(): void
    {
        $now = microtime(true);
        $cutoff = $now - self::RATE_LIMIT_WINDOW_SECONDS;

        // Discard timestamps older than 60 seconds
        self::$requestTimestamps = array_values(array_filter(
            self::$requestTimestamps,
            fn($timestamp) => $timestamp > $cutoff
        ));

        // If we've reached our threshold within the current 60s window, pause until slot frees
        if (count(self::$requestTimestamps) >= self::RATE_LIMIT_MAX_REQUESTS) {
            $oldestTimestamp = min(self::$requestTimestamps);
            $sleepMicroseconds = (int) ceil(($oldestTimestamp + self::RATE_LIMIT_WINDOW_SECONDS - $now) * 1000000);

            if ($sleepMicroseconds > 0) {
                BridgeLogger::info(sprintf("Rate limit threshold approached (%d requests in 60s). Throttling for %.2f seconds.", count(self::$requestTimestamps), $sleepMicroseconds / 1000000));
                usleep($sleepMicroseconds);
            }
        }
    }

    /**
     * Helper to extract a header value from raw HTTP response headers.
     */
    private function extractHeader(string $headers, string $headerName): ?string
    {
        $lines = explode("\r\n", $headers);
        foreach ($lines as $line) {
            if (stripos($line, $headerName . ':') === 0) {
                return trim(substr($line, strlen($headerName) + 1));
            }
        }
        return null;
    }

    /**
     * Builds query string ensuring booleans are strings ('true'/'false')
     * and array parameters format correctly for REST endpoints.
     */
    private function buildQuery(array $params): string
    {
        $queryParts = [];
        foreach ($params as $key => $val) {
            if ($val === null || $val === '') {
                continue;
            }
            if (is_bool($val)) {
                $queryParts[] = urlencode((string)$key) . '=' . ($val ? 'true' : 'false');
            } elseif (is_array($val)) {
                foreach ($val as $subVal) {
                    $queryParts[] = urlencode((string)$key) . '=' . urlencode((string)$subVal);
                }
            } else {
                $queryParts[] = urlencode((string)$key) . '=' . urlencode((string)$val);
            }
        }
        return implode('&', $queryParts);
    }
}
