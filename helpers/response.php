<?php
/**
 * MRA -> WooCommerce Bridge HTTP Response Helper
 *
 * Provides standardized JSON response emitters for API and Webhook endpoints.
 *
 * Core PHP — no external dependencies.
 */

declare(strict_types=1);

class BridgeResponse
{
    /**
     * Send a JSON response with HTTP status code and terminate execution.
     */
    public static function json(array $data, int $statusCode = 200): void
    {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send a standardized success JSON response.
     */
    public static function success(string $message = 'Success', array $extra = [], int $statusCode = 200): void
    {
        self::json(array_merge([
            'status'  => 'success',
            'message' => $message,
        ], $extra), $statusCode);
    }

    /**
     * Send a standardized error JSON response.
     */
    public static function error(string $message, int $statusCode = 400, array $extra = []): void
    {
        self::json(array_merge([
            'status'  => 'error',
            'message' => $message,
        ], $extra), $statusCode);
    }
}
