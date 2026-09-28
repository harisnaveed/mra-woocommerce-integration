<?php
/**
 * WooCommerce Webhook Endpoint — Order Reverse Synchronization
 *
 * Receives order event webhooks from WooCommerce, authenticates the cryptographic HMAC signature,
 * enforces strict idempotency, resolves WooCommerce products to MRA Inventory items,
 * and records safe stock deduction snapshots.
 *
 * STRICT SAFETY GUARANTEE:
 * Live write calls (POST/PUT/DELETE) to MRA are completely blocked.
 * Actions are recorded in Staged Simulation Mode to protect live MRA inventory.
 *
 * Core PHP — no external dependencies.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers/logger.php';
require_once __DIR__ . '/helpers/response.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    BridgeResponse::error('Method not allowed. Webhooks require POST.', 405);
}

// 1. Read raw request payload
$rawPayload = file_get_contents('php://input');
if ($rawPayload === false || trim($rawPayload) === '') {
    BridgeLogger::warning("Received empty webhook request body.");
    BridgeResponse::error('Empty request payload.', 400);
}

// 2. Validate cryptographic signature
$webhookSecret = (string)bridge_config('webhook.secret');
$receivedSignature = $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? '';

if (!empty($webhookSecret)) {
    if (empty($receivedSignature)) {
        BridgeLogger::warning("Rejected unauthenticated webhook: missing X-WC-Webhook-Signature header.");
        BridgeResponse::error('Missing X-WC-Webhook-Signature header.', 401);
    }

    $expectedSignature = base64_encode(hash_hmac('sha256', $rawPayload, $webhookSecret, true));

    if (!hash_equals($expectedSignature, $receivedSignature)) {
        BridgeLogger::error("Rejected webhook with invalid HMAC-SHA256 signature.");
        BridgeResponse::error('Invalid webhook signature.', 401);
    }
}

// 3. Parse JSON payload
$orderData = json_decode($rawPayload, true);
if (!is_array($orderData)) {
    BridgeLogger::error("Received invalid JSON payload in webhook: " . json_last_error_msg());
    BridgeResponse::error('Malformed JSON payload.', 400);
}

$orderId   = (int)($orderData['id'] ?? 0);
$eventType = (string)($_SERVER['HTTP_X_WC_WEBHOOK_TOPIC'] ?? 'order.created');
$webhookId = (string)($_SERVER['HTTP_X_WC_WEBHOOK_ID'] ?? $_SERVER['HTTP_X_WC_WEBHOOK_DELIVERY_ID'] ?? '');

if ($orderId <= 0) {
    BridgeResponse::error('Missing or invalid order ID in webhook payload.', 422);
}

// 4. Enforce strict Idempotency (Prevent double processing)
$payloadHash = hash('sha256', $rawPayload);

$existingEvent = BridgeDatabase::fetchOne(
    "SELECT id, status, processed_at FROM mra_woocommerce_webhook_events WHERE payload_hash = :hash LIMIT 1",
    [':hash' => $payloadHash]
);

if ($existingEvent && $existingEvent['status'] === 'processed') {
    BridgeLogger::info("Webhook event for Order #{$orderId} was already processed (Payload Hash: {$payloadHash}). Skipping duplicate.");
    BridgeResponse::success('Webhook event already processed (idempotent no-op).', [
        'order_id'     => $orderId,
        'status'       => 'skipped',
        'processed_at' => $existingEvent['processed_at'],
    ], 200);
}

// 5. Extract Line Items & Map to MRA Inventory
$lineItems = $orderData['line_items'] ?? [];
$stagedItems = [];
$pdo = BridgeDatabase::getConnection();

BridgeLogger::info("Processing WooCommerce Order #{$orderId} [Webhook ID: {$webhookId}, Topic: {$eventType}, Items: " . count($lineItems) . "]");

foreach ($lineItems as $lineItem) {
    $wooProductId = (int)($lineItem['product_id'] ?? 0);
    $quantity     = (int)($lineItem['quantity'] ?? 1);
    $itemName     = (string)($lineItem['name'] ?? 'Unknown Item');

    if ($wooProductId <= 0) {
        continue;
    }

    // Lookup mapping in bridge table
    $mapping = BridgeDatabase::fetchOne(
        "SELECT id, mra_inventory_id, sku, name, mra_stock, mra_cost, mra_price FROM mra_woocommerce_sync WHERE woo_product_id = :woo_id LIMIT 1",
        [':woo_id' => $wooProductId]
    );

    if ($mapping) {
        $mraInventoryId = (string)$mapping['mra_inventory_id'];
        $currentStock   = (int)$mapping['mra_stock'];
        $projectedStock = max(0, $currentStock - $quantity);

        $stagedItems[] = [
            'woo_product_id'   => $wooProductId,
            'mra_inventory_id' => $mraInventoryId,
            'sku'              => $mapping['sku'],
            'item_name'        => $itemName,
            'ordered_qty'      => $quantity,
            'pre_order_stock'  => $currentStock,
            'projected_stock'  => $projectedStock,
            'mra_cost'         => $mapping['mra_cost'],
            'mra_price'        => $mapping['mra_price'],
            'action_taken'     => 'STAGED_SIMULATION_MODE',
        ];

        // Audit Log Entry
        $logMessage = sprintf(
            "Order #%d: '%s' (MRA ID: %s, SKU: %s) ordered qty: %d. Pre-order stock: %d, Projected stock: %d. [SAFETY GUARD: Live MRA write safely blocked]",
            $orderId,
            $itemName,
            $mraInventoryId,
            $mapping['sku'],
            $quantity,
            $currentStock,
            $projectedStock
        );

        BridgeLogger::info($logMessage);
        BridgeLogger::audit(
            $pdo,
            'ORDER_WEBHOOK_STAGED',
            'success',
            $logMessage,
            null,
            "order-{$orderId}",
            $mraInventoryId,
            $wooProductId
        );
    } else {
        BridgeLogger::warning("Order #{$orderId}: Product ID {$wooProductId} ('{$itemName}') has no mapping in mra_woocommerce_sync.");
    }
}

// 6. Record Event in Database
$insertSql = "INSERT INTO mra_woocommerce_webhook_events 
    (webhook_id, event_type, order_id, status, payload_hash, line_items_summary, mra_action_taken, processed_at, created_at)
    VALUES 
    (:webhook_id, :event_type, :order_id, 'processed', :payload_hash, :line_items_summary, 'STAGED_SIMULATION_MODE', NOW(), NOW())
    ON DUPLICATE KEY UPDATE status = 'processed', processed_at = NOW()";

BridgeDatabase::execute($insertSql, [
    ':webhook_id'          => $webhookId !== '' ? $webhookId : null,
    ':event_type'          => $eventType,
    ':order_id'            => $orderId,
    ':payload_hash'        => $payloadHash,
    ':line_items_summary'  => json_encode($stagedItems, JSON_UNESCAPED_SLASHES),
]);

// 7. Send Success Response to WooCommerce
BridgeResponse::success("Order #{$orderId} webhook processed successfully (Staged Simulation Mode).", [
    'order_id'         => $orderId,
    'event_type'       => $eventType,
    'items_staged'     => count($stagedItems),
    'live_mra_updated' => false,
    'safety_notice'    => 'Live writes to MyRepairApp are blocked by safety configuration. Order recorded in staging mode.',
], 200);
