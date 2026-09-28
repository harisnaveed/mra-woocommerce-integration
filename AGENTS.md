# AGENTS.md — Single Source of Truth for MRA-WooCommerce Integration

This document defines the authoritative architecture, mandatory constraints, coding standards, and operational guidelines for both AI agents and human developers maintaining or extending the **MRA -> WooCommerce Integration Bridge** (`mra-woocommerce`).

---

## 1. Project Mission & Identity

- **Primary Mission**: Synchronize inventory (stock, prices, categories, and products) bidirectionally between **MyRepairApp (MRA)** and **WooCommerce**.
- **Location**: `mra-woocommerce/` (co-located alongside WordPress in the web root or accessible via HTTP/CLI).
- **Core Technology**: Pure **Core PHP (8.1+)** with **PDO** and zero external composer framework dependencies.

---

## 2. Inviolable Architectural Rules

Any contribution or modification to this codebase **MUST** adhere to the following 12 rules:

### Rule 1: Use Core PHP Only
- **DO NOT** introduce heavy frameworks (no Laravel, Symfony, Yii, etc.).
- **DO NOT** add unvetted composer dependencies. Keep the runtime lightweight, portable, and fast.

### Rule 2: Never Build a WordPress Plugin
- The bridge runs as an independent microservice. It must **NEVER** be converted into a WordPress plugin or depend on WordPress core lifecycle (`wp-load.php`).
- Rationale: WordPress plugin crashes or fatal errors must never interrupt inventory synchronization.

### Rule 3: Never Directly Modify WooCommerce MySQL Tables
- **DO NOT** execute direct `INSERT`/`UPDATE` queries into `wp_posts`, `wp_postmeta`, or WooCommerce tables.
- **ALWAYS** communicate with WooCommerce using the official **WooCommerce REST API v3**.
- Rationale: The REST API guarantees that WooCommerce hooks, webhooks, transients, tax recalculations, and cache invalidation execute correctly.

### Rule 4: Primary Identity Anchor is `mra_inventory_id`
- Product names, slugs, and SKUs can be edited or mutated by store managers.
- The immutable primary key connecting MRA and WooCommerce is **`mra_inventory_id`** (UUID in MRA).
- The mapping table `mra_woocommerce_sync` is the translation layer.

### Rule 5: Strict MRA Read-Only Safety Guard
- **NEVER** execute unverified write operations (`POST`, `PUT`, `DELETE`) or stock deductions on live MyRepairApp unless explicitly mandated by the user.
- All mutating calls in `MRAClient.php` must remain protected by `MRA_ALLOW_WRITES=false`.
- Reverse order processing in `webhook.php` must operate in **Staged Simulation Mode** until the client gives formal authorization.

### Rule 6: Native Prepared SQL Statements
- Every SQL query interacting with MySQL **MUST** use PDO prepared statements with bound parameters (`:param`).
- Emulated prepares are permanently disabled (`PDO::ATTR_EMULATE_PREPARES => false`) to enforce server-side parameterized queries and prevent SQL injection.

### Rule 7: Zero Secret Logging
- Sensitive credentials (`api_key`, `secret`, `password`, `consumer_secret`, tokens) **MUST NEVER** appear in plain text in log files, console output, or database audit tables.
- All log outputs must pass through `BridgeLogger::scrubString()` and `BridgeLogger::scrubSensitiveData()`.

### Rule 8: Strict Idempotency Across All Routines
- Running `cron.php` or `sync/inventory.php` multiple times **MUST NEVER** produce duplicate categories or duplicate products.
- Product change detection **MUST** use SHA-256 `payload_hash` to skip unchanged records.
- Order webhooks **MUST** verify SHA-256 `payload_hash` in `mra_woocommerce_webhook_events` to ignore duplicate deliveries.

### Rule 9: Respect MRA Rate Limits
- MyRepairApp enforces a maximum of 60 requests per minute.
- `MRAClient.php` enforces a strict **55 requests / 60-second sliding window** with microsecond sleep pacing and exponential backoff retry on HTTP 429.

### Rule 10: Non-Blocking Concurrency Locking
- Scheduled cron routines **MUST** acquire a non-blocking process lock (`flock(LOCK_EX | LOCK_NB)` on `logs/cron.lock`).
- If an instance is already running, subsequent invocations must exit immediately without queueing or blocking.

### Rule 11: Dual CLI & Browser Execution Support
- `cron.php` must function seamlessly whether invoked via server crontab (CLI) or web browser (HTTP).
- Browser execution in production requires a secret token (`?secret=YOUR_CRON_SECRET`).
- cURL connections must enforce `'Connection: close'` to prevent single-worker dev server deadlocks.

### Rule 12: Cryptographic Webhook Authentication
- `webhook.php` **MUST** authenticate all incoming payloads against `WEBHOOK_SECRET` using timing-attack safe `hash_equals()` on the `X-WC-Webhook-Signature` header.
- Unauthenticated or forged signatures must return HTTP 401 Unauthorized immediately.

---

## 3. Key Files & Responsibilities

| File | Class / Function | Responsibility |
|---|---|---|
| `config.php` | `bridge_config()`, `bridge_env()` | Loads `.env` and provides dot-notation config access. |
| `database.php` | `BridgeDatabase` | Singleton PDO instance, prepared statements, and transactions. |
| `helpers/logger.php` | `BridgeLogger` | File logging (`logs/sync.log`) and DB audit with auto-scrubbing. |
| `helpers/response.php` | `BridgeResponse` | Standardized JSON response emitter for HTTP endpoints. |
| `api/MRAClient.php` | `MRAClient` | MRA API v2 client with rate governor, backoff, and safety lock. |
| `woocommerce/WooCommerceClient.php` | `WooCommerceClient` | REST API client with dual HTTP OAuth 1.0a & HTTPS Basic Auth. |
| `woocommerce/CategoryManager.php` | `CategoryManager` | WooCommerce category retrieval and creation. |
| `woocommerce/ProductManager.php` | `ProductManager` | WooCommerce product CRUD operations and SKU lookup. |
| `sync/categories.php` | `CategorySync` | Category mapping and duplicate prevention engine. |
| `sync/products.php` | `ProductSynchronizer` | Product change detection via SHA-256 hash and delta updates. |
| `sync/inventory.php` | `InventorySyncEngine` | Master batch orchestrator with live real-time progress callbacks. |
| `cron.php` | Entry script | Dual CLI & Browser cron runner with `flock` concurrency locking. |
| `webhook.php` | Entry script | WooCommerce order reverse webhook with HMAC-SHA256 & staging mode. |

---

## 4. Operational Instructions

### Running Sync via CLI
```bash
php mra-woocommerce/cron.php --page-size=50 --max-pages=0
```

### Running Sync via Browser
```text
https://yourdomain.com/mra-woocommerce/cron.php?secret=YOUR_CRON_SECRET&page_size=50&max_pages=0
```

### Running the Test & Audit Suite
```bash
php mra-woocommerce/test_phase8.php
```

### Live SiteGround Deployment
Refer to [DEPLOYMENT_GUIDE.md](file:///d:/Projects/mra-woocommerce/DEPLOYMENT_GUIDE.md) for the 5-step production setup.
