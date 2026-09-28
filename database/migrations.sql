-- MRA -> WooCommerce Sync Database Migrations
-- Target Database: MySQL 5.7+ / 8.0+ / MariaDB 10.3+

-- -----------------------------------------------------------------------------
-- Table 1: mra_woocommerce_sync
-- Master mapping and state tracking between MRA Inventory and WooCommerce Products
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mra_woocommerce_sync` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `mra_inventory_id` VARCHAR(100) NOT NULL COMMENT 'Primary inventory identifier from MyRepairApp',
    `mra_store_id` VARCHAR(100) DEFAULT NULL COMMENT 'MRA store or location identifier',
    `sku` VARCHAR(100) DEFAULT NULL COMMENT 'Product SKU / Part Number',
    `name` VARCHAR(255) DEFAULT NULL COMMENT 'Cached item name from MRA',
    `category` VARCHAR(150) DEFAULT NULL COMMENT 'Cached MRA category name',
    `type` VARCHAR(100) DEFAULT NULL COMMENT 'Cached MRA item type',
    
    `mra_price` DECIMAL(10, 2) DEFAULT NULL COMMENT 'Selling price from MRA',
    `mra_cost` DECIMAL(10, 2) DEFAULT NULL COMMENT 'Cost price from MRA',
    `mra_stock` INT DEFAULT 0 COMMENT 'Current stock quantity in MRA',
    `mra_updated_at` VARCHAR(100) DEFAULT NULL COMMENT 'Last updated timestamp reported by MRA',
    
    `woo_product_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Corresponding WooCommerce product ID',
    `woo_category_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Primary assigned WooCommerce category ID',
    
    `payload_hash` VARCHAR(64) DEFAULT NULL COMMENT 'SHA-256 hash of core sync fields to detect changes',
    
    `sync_status` ENUM('pending', 'synced', 'failed', 'skipped') NOT NULL DEFAULT 'pending' COMMENT 'Current sync status',
    `last_sync_at` DATETIME DEFAULT NULL COMMENT 'Timestamp when sync was last evaluated',
    `last_success_at` DATETIME DEFAULT NULL COMMENT 'Timestamp when sync last successfully completed',
    `last_error` TEXT DEFAULT NULL COMMENT 'Last error encountered during synchronization',
    `retry_count` INT UNSIGNED DEFAULT 0 COMMENT 'Number of consecutive failed sync attempts',
    
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY `uq_mra_inventory_id` (`mra_inventory_id`),
    INDEX `idx_sku` (`sku`),
    INDEX `idx_woo_product_id` (`woo_product_id`),
    INDEX `idx_sync_status` (`sync_status`),
    INDEX `idx_mra_store_id` (`mra_store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='MRA to WooCommerce product mapping and state';

-- -----------------------------------------------------------------------------
-- Table 2: mra_woocommerce_categories
-- Maps MRA category and type combinations to WooCommerce product categories
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mra_woocommerce_categories` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `mra_category` VARCHAR(150) NOT NULL COMMENT 'Category name from MRA',
    `mra_type` VARCHAR(100) DEFAULT NULL COMMENT 'Optional item type from MRA',
    `woo_category_id` BIGINT UNSIGNED NOT NULL COMMENT 'Assigned WooCommerce category ID',
    `woo_parent_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'WooCommerce parent category ID if nested',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX `idx_mra_category` (`mra_category`),
    INDEX `idx_mra_type` (`mra_type`),
    INDEX `idx_woo_category_id` (`woo_category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='MRA to WooCommerce category mappings';

-- -----------------------------------------------------------------------------
-- Table 3: mra_woocommerce_sync_logs
-- Audit and debugging trail for all synchronization and webhook actions
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mra_woocommerce_sync_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sync_id` VARCHAR(64) DEFAULT NULL COMMENT 'Unique identifier per sync execution session',
    `mra_inventory_id` VARCHAR(100) DEFAULT NULL COMMENT 'Referenced MRA inventory ID if applicable',
    `woo_product_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Referenced WooCommerce product ID if applicable',
    `action` VARCHAR(50) NOT NULL COMMENT 'Action type, e.g. CREATE_PRODUCT, UPDATE_PRODUCT, STOCK_UPDATE',
    `status` ENUM('success', 'failed', 'skipped') NOT NULL COMMENT 'Result status of the action',
    `message` VARCHAR(255) DEFAULT NULL COMMENT 'Human readable summary of the result',
    `error` TEXT DEFAULT NULL COMMENT 'Technical error or exception details (secrets scrubbed)',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX `idx_sync_id` (`sync_id`),
    INDEX `idx_mra_inventory_id` (`mra_inventory_id`),
    INDEX `idx_woo_product_id` (`woo_product_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit and error logs for sync operations';

-- -----------------------------------------------------------------------------
-- Table 4: mra_woocommerce_webhook_events
-- Idempotency tracking and reverse order synchronization staging
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mra_woocommerce_webhook_events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `webhook_id` VARCHAR(100) DEFAULT NULL COMMENT 'Unique delivery ID from WooCommerce (X-WC-Webhook-ID)',
    `event_type` VARCHAR(100) NOT NULL COMMENT 'WooCommerce topic, e.g. order.created',
    `order_id` BIGINT UNSIGNED NOT NULL COMMENT 'WooCommerce Order ID',
    `status` ENUM('received', 'processed', 'skipped', 'failed') NOT NULL DEFAULT 'received',
    `payload_hash` VARCHAR(64) NOT NULL COMMENT 'SHA-256 hash of payload for idempotency',
    `line_items_summary` JSON DEFAULT NULL COMMENT 'JSON snapshot of line items, quantities, and MRA mappings',
    `mra_action_taken` VARCHAR(150) NOT NULL DEFAULT 'STAGED_SIMULATION_MODE',
    `processed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_payload_hash` (`payload_hash`),
    INDEX `idx_order_id` (`order_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='WooCommerce incoming webhook event logs and deduplication';

