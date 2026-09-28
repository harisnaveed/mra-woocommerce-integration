<?php
/**
 * WooCommerce REST API Client
 *
 * Implements communication with WooCommerce REST API (v3).
 * Supports standard HTTP verbs (GET, POST, PUT, DELETE),
 * Basic Auth & query param authentication, timeout management,
 * and error handling.
 *
 * Core PHP — no external dependencies.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/logger.php';

class WooCommerceException extends RuntimeException
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

class WooCommerceClient
{
    private string $baseUrl;
    private string $consumerKey;
    private string $consumerSecret;
    private bool $verifySsl;
    private int $timeout;

    public function __construct(?array $overrideConfig = null)
    {
        $this->baseUrl        = rtrim($overrideConfig['base_url'] ?? bridge_config('woocommerce.base_url', 'http://localhost/wp-json/wc/v3'), '/');
        $this->consumerKey    = $overrideConfig['consumer_key'] ?? bridge_config('woocommerce.consumer_key', '');
        $this->consumerSecret = $overrideConfig['consumer_secret'] ?? bridge_config('woocommerce.consumer_secret', '');
        $this->verifySsl      = (bool) ($overrideConfig['verify_ssl'] ?? bridge_config('woocommerce.verify_ssl', false));
        $this->timeout        = (int) ($overrideConfig['timeout'] ?? bridge_config('sync.timeout', 30));

        if (empty($this->consumerKey) || empty($this->consumerSecret)) {
            throw new InvalidArgumentException("WooCommerce Consumer Key or Secret is missing. Please configure WC_CONSUMER_KEY and WC_CONSUMER_SECRET in .env.");
        }
    }

    /**
     * Perform GET request.
     *
     * @param string $endpoint API endpoint (e.g. '/products', '/products/categories')
     * @param array $params Query string parameters
     * @return array Decoded response
     * @throws WooCommerceException
     */
    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, $params);
    }

    /**
     * Perform POST request.
     *
     * @param string $endpoint API endpoint
     * @param array $data Request payload
     * @return array Decoded response
     * @throws WooCommerceException
     */
    public function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', $endpoint, [], $data);
    }

    /**
     * Perform PUT request.
     *
     * @param string $endpoint API endpoint
     * @param array $data Request payload
     * @return array Decoded response
     * @throws WooCommerceException
     */
    public function put(string $endpoint, array $data = []): array
    {
        return $this->request('PUT', $endpoint, [], $data);
    }

    /**
     * Perform DELETE request.
     *
     * @param string $endpoint API endpoint
     * @param array $params Query string parameters (e.g. ['force' => true])
     * @return array Decoded response
     * @throws WooCommerceException
     */
    public function delete(string $endpoint, array $params = []): array
    {
        return $this->request('DELETE', $endpoint, $params);
    }

    /**
     * Low-level cURL execution wrapper for WooCommerce REST API.
     *
     * Handles:
     * - HTTPS: Basic Auth (Authorization header)
     * - HTTP (non-SSL / local dev): OAuth 1.0a HMAC-SHA256 one-legged signature
     *
     * @throws WooCommerceException
     */
    private function request(string $method, string $endpoint, array $queryParams = [], array $body = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $isHttps = str_starts_with(strtolower($this->baseUrl), 'https://');

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Connection: close',
        ];

        if ($isHttps) {
            // Standard Basic Auth over HTTPS
            $auth = base64_encode($this->consumerKey . ':' . $this->consumerSecret);
            $headers[] = 'Authorization: Basic ' . $auth;

            if (!empty($queryParams)) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($queryParams);
            }
        } else {
            // OAuth 1.0a one-legged signature over HTTP (WooCommerce requirement)
            $url = $this->signOAuth(strtoupper($method), $url, $queryParams);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifySsl ? 2 : 0);

        if (!empty($body) && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }

        $rawResponse = curl_exec($ch);
        $curlError   = curl_error($ch);
        $curlErrno   = curl_errno($ch);
        $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($curlErrno !== 0) {
            BridgeLogger::error("cURL error communicating with WooCommerce: {$curlError} (Code: {$curlErrno})", [
                'endpoint' => $endpoint,
            ]);
            throw new WooCommerceException("cURL network error communicating with WooCommerce: {$curlError}", $curlErrno);
        }

        $parsedData = json_decode((string)$rawResponse, true);

        // Check for HTTP errors (4xx / 5xx)
        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = "WooCommerce REST API responded with HTTP {$httpCode}";
            if (is_array($parsedData) && isset($parsedData['message'])) {
                $errorMessage .= ": " . $parsedData['message'];
            }

            BridgeLogger::error($errorMessage, [
                'endpoint'  => $endpoint,
                'http_code' => $httpCode,
            ]);

            throw new WooCommerceException($errorMessage, $httpCode, is_array($parsedData) ? $parsedData : null);
        }

        if ($parsedData === null && !empty(trim((string)$rawResponse))) {
            throw new WooCommerceException("Failed to decode JSON response from WooCommerce: " . json_last_error_msg(), 500);
        }

        return is_array($parsedData) ? $parsedData : [];
    }

    /**
     * Signs HTTP request with OAuth 1.0a HMAC-SHA256 signature according to RFC 5849 / WooCommerce spec.
     */
    private function signOAuth(string $method, string $url, array $params): string
    {
        $params['oauth_consumer_key']     = $this->consumerKey;
        $params['oauth_timestamp']        = (string) time();
        $params['oauth_nonce']            = md5(microtime() . mt_rand());
        $params['oauth_signature_method'] = 'HMAC-SHA256';

        uksort($params, 'strcmp');

        $encodedPairs = [];
        foreach ($params as $key => $val) {
            $encodedPairs[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$val);
        }
        $queryString = implode('&', $encodedPairs);

        $baseUrl = strtok($url, '?');
        $stringToSign = strtoupper($method) . '&' . rawurlencode($baseUrl) . '&' . rawurlencode($queryString);
        $signingKey = rawurlencode($this->consumerSecret) . '&';

        $params['oauth_signature'] = base64_encode(hash_hmac('sha256', $stringToSign, $signingKey, true));

        return $baseUrl . '?' . http_build_query($params);
    }
}
