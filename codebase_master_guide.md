# MRA -> WooCommerce Integration Bridge: Codebase Master Guide

---

## 1. Project Overview & Architectural Philosophy

The **MRA -> WooCommerce Integration Bridge** is a standalone, high-performance integration system written in **Core PHP (PHP 8.1+)** with **PDO** and zero external framework dependencies.

### Core Architectural Decisions:
1. **Core PHP Only**: No Laravel, Symfony, or heavy packages. Keeps memory consumption tiny and execution speed sub-second.
2. **Never a WordPress Plugin**: The bridge runs as an independent microservice alongside WordPress. A plugin crash in WordPress will never corrupt or interrupt inventory sync.
3. **Never Directly Modify WooCommerce MySQL Tables**: Uses official **WooCommerce REST API v3**. This ensures WooCommerce hooks, price calculations, taxes, transients, and cache invalidation run cleanly without corrupting the store.
4. **Primary Identity Anchor (`mra_inventory_id`)**: Product titles and slugs can change; UUID `mra_inventory_id` from MyRepairApp is the sole source of truth for synchronization.
5. **Strict Safety Profile (`MRA_ALLOW_WRITES=false`)**: Blocks all unauthorized live modifications or deletions to MyRepairApp. Reverse order processing defaults to **Staged Simulation Mode**.

---

## 2. Directory Structure

```text
mra-woocommerce/
├── .env                              # Active environment configuration (ignored by git)
├── .env.example                      # Template configuration with all available keys
├── .htaccess                         # Apache 2.2/2.4 security rules (blocks .env, .sql, logs)
├── DEPLOYMENT_GUIDE.md               # 5-step SiteGround live deployment guide
├── codebase_master_guide.md          # Comprehensive technical and architectural guide
├── config.php                        # Central configuration loader with dot-notation lookup
├── cron.php                          # Dual CLI & Web Browser scheduled synchronization runner
├── database.php                      # Singleton PDO database wrapper with prepared statements
├── webhook.php                       # HMAC-SHA256 authenticated WooCommerce order webhook
│
├── api/
│   └── MRAClient.php                 # MyRepairApp API v2 client (sliding rate governor & retry)
│
├── database/
│   └── migrations.sql                # SQL schema definitions for the 4 bridge tables
│
├── helpers/
│   ├── logger.php                    # Multi-level logger with credential scrubbing & DB audit
│   └── response.php                  # Consistent JSON response helper for web/API endpoints
│
├── logs/
│   ├── .htaccess                     # Apache rules completely denying public HTTP access
│   ├── index.html                    # 403 Forbidden fallback page
│   ├── cron.lock                     # Concurrency lock file (auto-cleaned on shutdown)
│   └── sync.log                      # Rolling local text log file
│
├── sync/
│   ├── categories.php                # Category mapper & duplicate prevention engine
│   ├── inventory.php                 # Master batch sync orchestrator with real-time progress
│   └── products.php                  # Granular product change detector (SHA-256 payload hashing)
│
└── woocommerce/
    ├── CategoryManager.php           # CRUD wrapper for WooCommerce /products/categories
    ├── ProductManager.php            # CRUD wrapper for WooCommerce /products
    └── WooCommerceClient.php         # Dual HTTP (OAuth 1.0a) & HTTPS (Basic Auth) REST client
```

---

## 3. Detailed Component Breakdown

### Configuration & Foundation

#### 1. `config.php`
- **Purpose**: Loads `.env` file variables into `$_ENV`, `$_SERVER`, and `getenv()`.
- **Functions**:
  - `bridge_env(string $key, mixed $default = null)`: Parses strings (`true`, `false`, `null`) safely.
  - `bridge_config(?string $key, mixed $default = null)`: Supports dot-notation config lookup (e.g. `bridge_config('database.host')`).

#### 2. `database.php` (`BridgeDatabase`)
- **Purpose**: Thread-safe singleton PDO connection manager.
- **Key Settings**:
  - `PDO::ATTR_EMULATE_PREPARES => false`: Enforces real MySQL server-side prepared statements, eliminating SQL injection.
  - `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`: Strict exception bubbling.
- **Methods**:
  - `getConnection(): PDO`
  - `fetchAll(string $sql, array $params): array`
  - `fetchOne(string $sql, array $params): ?array`
  - `execute(string $sql, array $params): int`
  - `transaction(callable $callback): mixed`

#### 3. `helpers/logger.php` (`BridgeLogger`)
- **Purpose**: Dual logging to local rolling log file (`logs/sync.log`) and database audit table (`mra_woocommerce_sync_logs`).
- **Security Feature**: **Automatic Credential Scrubbing**. Any key or substring containing `api_key`, `secret`, `password`, `consumer_secret`, or authorization tokens is replaced with `***REDACTED***`.
- **Methods**:
  - `info()`, `warning()`, `error()`, `debug()`
  - `audit(PDO $pdo, string $action, string $status, ...)`: Records persistent audit records.

---

### API Clients

#### 4. `api/MRAClient.php` (`MRAClient`)
- **Purpose**: Communicates with MyRepairApp API v2 (`https://www.myrepairapp.com/api/v2`).
- **Key Features**:
  - **Rate Limit Governor**: Enforces maximum 55 requests / 60-second sliding window with microsecond sleep pacing to comply with MRA's 60 req/min rule.
  - **Exponential Backoff**: Automatically sleeps and retries up to 3 times on HTTP 429 or temporary 5xx errors.
  - **Strict Read-Only Safety**: If `MRA_ALLOW_WRITES=false`, calling `createInventory()`, `updateInventory()`, or `deleteInventory()` immediately throws a `RuntimeException`.
- **Methods**:
  - `searchInventory(array $params): array`
  - `fetchAllInventory(array $params, int $pageSize, int $maxPages): array`

#### 5. `woocommerce/WooCommerceClient.php` (`WooCommerceClient`)
- **Purpose**: Communicates with WooCommerce REST API v3.
- **Dual Authentication**:
  - **HTTPS (Production)**: Uses standard HTTP Basic Authentication (`Authorization: Basic ...`).
  - **HTTP (Local Dev)**: Automatically computes RFC 5849 **OAuth 1.0a HMAC-SHA256 one-legged query signatures** (`oauth_consumer_key`, `oauth_nonce`, `oauth_signature`, etc.).
- **Deadlock Protection**: Enforces `Connection: close`, `CURLOPT_FORBID_REUSE = true`, and `CURLOPT_FRESH_CONNECT = true` to immediately release sockets.
- **Methods**: `get()`, `post()`, `put()`, `delete()`

---

### Synchronization Engines

#### 6. `sync/categories.php` (`CategorySync`)
- **Purpose**: Maps MRA category names (e.g. `Device`, `Part`, `Repair`, `Service`, `Tool`, `Accessory`) into WooCommerce categories.
- **Duplicate Prevention Guarantee**: Always checks `mra_woocommerce_categories` table before calling WooCommerce. If the category exists, it reuses the `woo_category_id`. Never creates duplicates.

#### 7. `sync/products.php` (`ProductSynchronizer`)
- **Purpose**: Core sync engine for individual inventory items.
- **Payload Hash Change Detection**:
  - Generates a SHA-256 fingerprint from: `sku`, `name`, `price`, `stock`, `woo_category_id`, and `active`.
  - If existing product has the same `payload_hash` and was previously synced: **SKIPS** the product without making any WooCommerce API calls.
  - If any attribute changed: performs targeted `PUT /products/{id}` update and stores the new hash.
- **SKU Deduplication**: Directly attempts creation; if WooCommerce returns a duplicate SKU error, automatically resolves and links to the existing WooCommerce product ID.

#### 8. `sync/inventory.php` (`InventorySyncEngine`)
- **Purpose**: High-level batch controller.
- **Features**: Fetches inventory pages from MRA, coordinates category mapping and product sync, and supports a live `$onProgress` callback for terminal/browser progress display.

---

### Automation & Webhooks

#### 9. `cron.php`
- **Purpose**: Scheduled sync entry point.
- **Dual Execution Modes**:
  1. **CLI Mode**: `php cron.php [--page-size=50] [--max-pages=0]` (Prints real-time progress for each item).
  2. **Browser Mode**: `https://yourdomain.com/mra-woocommerce/cron.php?secret=TOKEN` (Streams live HTML terminal).
- **Concurrency Locking**: Uses non-blocking OS lock (`flock(LOCK_EX | LOCK_NB)` on `logs/cron.lock`). If another cron is running, new execution safely exits immediately.

#### 10. `webhook.php`
- **Purpose**: Reverse order notification endpoint from WooCommerce.
- **Security & Idempotency**:
  - Validates `X-WC-Webhook-Signature` using HMAC-SHA256 (`hash_equals()`).
  - Computes `payload_hash` of the raw order JSON. Duplicate webhook deliveries are detected in `mra_woocommerce_webhook_events` and skipped as idempotent no-ops.
- **Staged Simulation Mode**:
  - Resolves each ordered line item to its corresponding `mra_inventory_id`.
  - Calculates pre-order stock, ordered quantity, and projected stock.
  - Records the snapshot in the database while safely blocking live MRA writes.

---

## 4. Database Schema (4 Tables)

| Table | Purpose | Key Columns |
|---|---|---|
| `mra_woocommerce_sync` | Primary mapping & change detection | `mra_inventory_id` (VARCHAR 100 UNIQUE), `woo_product_id` (BIGINT), `sku`, `name`, `mra_price`, `mra_stock`, `payload_hash` (CHAR 64), `sync_status` |
| `mra_woocommerce_categories` | Category mapping | `mra_category` (VARCHAR 100), `woo_category_id` (BIGINT), `created_at` |
| `mra_woocommerce_sync_logs` | Audit trail | `sync_id`, `mra_inventory_id`, `woo_product_id`, `action`, `status`, `message`, `error`, `created_at` |
| `mra_woocommerce_webhook_events` | Order staging & idempotency | `webhook_id`, `event_type`, `order_id` (BIGINT), `payload_hash` (CHAR 64 UNIQUE), `line_items_summary`, `mra_action_taken`, `processed_at` |

---

## 5. End-to-End Data Flows

### Forward Flow: MRA -> WooCommerce Sync (Every 15 minutes)
```text
Cron Triggers (cron.php)
       ↓
Acquire Process Lock (flock)
       ↓
MRAClient::fetchAllInventory()  [Enforces 55 req/min sliding rate limit]
       ↓
For each Item:
   CategorySync::getOrCreateCategory()  [Checks mra_woocommerce_categories]
   Compute SHA-256 payload_hash
       ↓
   Does item exist in mra_woocommerce_sync?
   ├── NO  → POST /wp-json/wc/v3/products → Insert mapping row
   └── YES → Has payload_hash changed?
             ├── NO  → SKIP (0 API calls)
             └── YES → PUT /wp-json/wc/v3/products/{id} → Update mapping row & hash
       ↓
Write Audit Log to DB & logs/sync.log
       ↓
Release Lock & Exit
```

### Reverse Flow: WooCommerce Order -> Staged Simulation (Real-Time)
```text
Customer Completes Checkout in WooCommerce
       ↓
WooCommerce sends HTTPS POST /webhook.php
       ↓
Validate HMAC-SHA256 signature against WEBHOOK_SECRET
       ↓
Check payload_hash against mra_woocommerce_webhook_events
├── Already exists? → Return HTTP 200 "Already processed" (Idempotent Skip)
└── New Event:
       ↓
Extract order line items
       ↓
For each line item:
   Lookup woo_product_id in mra_woocommerce_sync
   Retrieve mra_inventory_id, current MRA stock, and price
   Calculate projected stock = (currentStock - quantity)
   Record staging snapshot [Live MRA writes blocked]
       ↓
Record event in mra_woocommerce_webhook_events
Return HTTP 200 Success
```
