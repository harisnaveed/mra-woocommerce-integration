# MRA -> WooCommerce Integration Bridge

> A robust, standalone, high-performance inventory synchronization microservice built in **Pure Core PHP (8.1+)** with **PDO** connecting **MyRepairApp (MRA)** and **WooCommerce**.

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://www.php.net/)
[![Architecture](https://img.shields.io/badge/Architecture-Core%20PHP%20%7C%20Microservice-green.svg)](#architecture--philosophy)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-REST%20API%20v3-purple.svg)](https://woocommerce.github.io/woocommerce-rest-api-docs/)
[![Security](https://img.shields.io/badge/Security-Prepared%20Statements%20%7C%20HMAC--SHA256-red.svg)](#security--safety-guarantees)
[![Audit](https://img.shields.io/badge/Audit-12%2F12%20Checks%20Passed-brightgreen.svg)](#testing--verification)

---

## Table of Contents
1. [Project Mission & Purpose](#project-mission--purpose)
2. [Architecture & Philosophy](#architecture--philosophy)
3. [Key Features](#key-features)
4. [Directory & File Structure](#directory--file-structure)
5. [Local Development Setup](#local-development-setup)
6. [Database Schema & Migrations](#database-schema--migrations)
7. [Environment Configuration (`.env`)](#environment-configuration-env)
8. [Running Synchronization](#running-synchronization)
   - [Via CLI (Terminal)](#1-via-cli-terminal)
   - [Via Web Browser](#2-via-web-browser-live-streaming-console)
9. [Reverse Order Sync (Webhook)](#reverse-order-sync-webhook)
10. [Testing & Verification Suite](#testing--verification-suite)
11. [Production Deployment (SiteGround)](#production-deployment-siteground)
12. [Security & Safety Guarantees](#security--safety-guarantees)

---

## Project Mission & Purpose

Retail stores and repair shops managing their physical inventory in **MyRepairApp (MRA)** often face inventory discrepancies when selling products online through **WooCommerce**. 

This bridge provides an automated, resilient translation layer that:
- **Synchronizes Catalog & Stock**: Automatically fetches all inventory items, categories, prices, and stock levels from MRA and populates/updates WooCommerce via its official REST API.
- **Prevents Overselling**: Listens for WooCommerce customer orders via webhooks and calculates real-time stock deductions in Staged Simulation Mode without risking live MRA inventory.
- **Operates Independently**: Runs as an isolated server microservice co-located with WordPress, meaning WordPress errors, updates, or plugin conflicts will **never** bring down inventory synchronization.

---

## Architecture & Philosophy

The bridge is built around **12 foundational engineering rules**:

1. **Pure Core PHP (8.1+)**: Zero composer framework bloat (no Laravel, Symfony, etc.). Extremely lightweight and fast.
2. **Never a WordPress Plugin**: Does not load WordPress core (`wp-load.php`), eliminating memory overhead and plugin fatal-error dependencies.
3. **Never Directly Modify WooCommerce MySQL Tables**: All interactions with WooCommerce occur through the **WooCommerce REST API v3**. This ensures that WooCommerce transients, webhooks, price recalculations, and cache systems execute properly.
4. **Primary Identity Anchor is `mra_inventory_id`**: Product names and SKUs can change. The immutable MRA UUID is the single source of truth mapped via `mra_woocommerce_sync`.
5. **Strict MRA Read-Only Safety Guard**: Mutating API calls to live MRA (`createInventory`, `updateInventory`, `deleteInventory`) are blocked by `MRA_ALLOW_WRITES=false`.
6. **Native Prepared Statements**: All database operations use PDO with `PDO::ATTR_EMULATE_PREPARES => false`, preventing SQL injection.
7. **Zero Secret Logging**: API keys, consumer secrets, and auth tokens are automatically scrubbed (`***REDACTED***`) before being written to files or database audit tables.
8. **Strict Idempotency**: Running synchronization multiple times will never duplicate products, categories, or order adjustments.
9. **Sliding-Window Rate Governor**: Throttles MRA requests to a maximum of 55 requests / 60 seconds with exponential backoff on HTTP 429.
10. **Non-Blocking Concurrency Locks**: Uses OS-level file locking (`flock(LOCK_EX | LOCK_NB)`) to guarantee only one cron runs at a time.
11. **Dual CLI & Browser Execution**: Supports both crontab and browser triggers with real-time streaming progress.
12. **Cryptographic HMAC-SHA256 Signatures**: Incoming webhooks are verified with `hash_equals()` to block spoofed requests.

---

## Directory & File Structure

```text
mra-woocommerce/
├── .env                              # Active environment credentials (git-ignored)
├── .env.example                      # Template configuration file
├── .htaccess                         # Apache 2.2/2.4 web access security rules
├── README.md                         # Project documentation and quickstart
├── AGENTS.md                         # Single source of truth for AI agents & developers
├── DEPLOYMENT_GUIDE.md               # 5-step SiteGround live deployment guide
├── codebase_master_guide.md          # In-depth architectural & component documentation
│
├── config.php                        # Configuration loader with dot-notation lookup
├── database.php                      # Singleton PDO connection wrapper
├── cron.php                          # Dual CLI & Browser scheduled sync runner
├── webhook.php                       # Authenticated WooCommerce order reverse webhook
│
├── api/
│   └── MRAClient.php                 # MRA API v2 client (rate limiter & retry backoff)
│
├── database/
│   └── migrations.sql                # SQL schema for the 4 integration tables
│
├── helpers/
│   ├── logger.php                    # Multi-level logger with credential scrubbing & DB audit
│   └── response.php                  # Consistent JSON response emitter
│
├── logs/
│   ├── .htaccess                     # Denies public browser access to logs
│   ├── index.html                    # 403 Forbidden directory listing guard
│   ├── cron.lock                     # Concurrency process lock file
│   └── sync.log                      # Rolling local application log
│
├── sync/
│   ├── categories.php                # Category sync engine & duplicate prevention
│   ├── products.php                  # Granular product change detector (SHA-256 payload hash)
│   └── inventory.php                 # Master batch orchestrator with live progress callback
│
└── woocommerce/
    ├── CategoryManager.php           # CRUD wrapper for WooCommerce /products/categories
    ├── ProductManager.php            # CRUD wrapper for WooCommerce /products
    └── WooCommerceClient.php         # Dual HTTP (OAuth 1.0a) & HTTPS (Basic Auth) client
```

---

## Local Development Setup

### 1. Clone the Repository
```bash
git clone https://github.com/harisnaveed/mra-woocommerce-integration.git
cd mra-woocommerce-integration
```

### 2. Configure Environment (`.env`)
Copy the example configuration to `.env`:
```bash
cp .env.example .env
```
Fill in your local settings (see [Environment Configuration](#environment-configuration-env) below).

### 3. Run the Database Migrations
Execute the migrations against your local MySQL database:
```bash
mysql -u root -p mra_woocommerce_dev < database/migrations.sql
```

---

## Database Schema & Migrations

The integration relies on **4 isolated MySQL tables**:

| Table | Purpose |
|---|---|
| **`mra_woocommerce_sync`** | Translates `mra_inventory_id` (UUID) to `woo_product_id` (Integer). Stores price, stock, and SHA-256 `payload_hash` for change detection. |
| **`mra_woocommerce_categories`** | Maps MRA category names (e.g. `Part`, `Repair`) to `woo_category_id`, preventing duplicate categories. |
| **`mra_woocommerce_sync_logs`** | Complete audit trail recording every `CREATE_PRODUCT`, `UPDATE_PRODUCT`, `SKIP`, or `FAILED` action. |
| **`mra_woocommerce_webhook_events`** | Records reverse order events from WooCommerce, prevents duplicate deliveries, and stores stock deduction snapshots. |

---

## Environment Configuration (`.env`)

```ini
# Environment mode (development | production)
APP_ENV=development

# Database Credentials
DB_HOST=localhost
DB_PORT=3306
DB_NAME=mra_woocommerce
DB_USER=root
DB_PASS=
DB_CHARSET=utf8mb4

# MyRepairApp (MRA) API Settings
MRA_API_BASE_URL=https://www.myrepairapp.com/api/v2
MRA_API_KEY=your_mra_api_key_here
MRA_STORE_ID=

# WooCommerce REST API Settings
WC_API_BASE_URL=http://localhost:8000/wp-json/wc/v3
WC_CONSUMER_KEY=ck_your_consumer_key_here
WC_CONSUMER_SECRET=cs_your_consumer_secret_here
WC_VERIFY_SSL=false

# Sync Engine Configuration
SYNC_PAGE_SIZE=50
SYNC_TIMEOUT=30
SYNC_MAX_RETRIES=3

# Cryptographic Secrets
WEBHOOK_SECRET=your_long_random_webhook_secret_here
CRON_SECRET=your_long_random_cron_secret_here

# Safety Guard: STRICT READ-ONLY MODE
# Keep false to prevent any unintended stock deductions on live MRA
MRA_ALLOW_WRITES=false

# Logging Settings (DEBUG | INFO | WARNING | ERROR)
LOG_LEVEL=INFO
LOG_FILE=logs/sync.log
```

---

## Running Synchronization

### 1. Via CLI (Terminal)
Run from the project root:
```bash
# Sync entire catalog (all pages)
php mra-woocommerce/cron.php --page-size=50 --max-pages=0

# Sync only the first 50 items (testing)
php mra-woocommerce/cron.php --page-size=50 --max-pages=1
```

**Real-Time Terminal Output:**
```text
========================================================
   MRA -> WooCommerce Scheduled Sync [cron-20260928-112554]        
========================================================
Started at:   2026-09-28 11:25:54
Page Size:    50
Max Pages:    1
Store Filter: All Stores

  [1/50] [UPDATED] Replacement Battery For iPad Pro 12.9" ...    (SKU: 107082053123)
  [2/50] [UPDATED] Replacement Battery For iPhone SE (2022...    (SKU: 107082074635)
  [3/50] [SKIPPED] Replacement Battery For iPhone 8 (AmpSe...    (SKU: 107082001510)
  [4/50] [SKIPPED] Replacement Battery For iPhone SE (2020...    (SKU: 107082074628)
  [5/50] [CREATED] Home Button Flex Cable For iPad 7 (2019...    (SKU: 107084000343)
...
--------------------------------------------------------
                   Execution Summary                    
--------------------------------------------------------
Duration:        15.25 seconds
Total Items:     50
Created:         1
Updated:         2
Skipped:         47
Failed:          0
--------------------------------------------------------
```

### 2. Via Web Browser (Live Streaming Console)
Open in your browser:
```text
http://localhost:8000/mra-woocommerce/cron.php?page_size=50&max_pages=0
```
*(In production, append your secret token: `?secret=YOUR_CRON_SECRET`)*

---

## Reverse Order Sync (Webhook)

When a customer places an order on WooCommerce:
1. WooCommerce sends an `order.created` webhook to `https://yourdomain.com/mra-woocommerce/webhook.php`.
2. The endpoint verifies the **HMAC-SHA256 signature** against `WEBHOOK_SECRET`.
3. It checks `payload_hash` to guarantee **idempotency** (duplicate deliveries are skipped).
4. Each ordered line item is resolved to its MRA UUID.
5. In **Staged Simulation Mode** (`MRA_ALLOW_WRITES=false`), it logs:
   - Ordered item name & SKU
   - Quantity purchased
   - Pre-order stock in MRA
   - Projected post-order stock
   - Safely blocks live MRA API write calls.

---

## Testing & Verification Suite

The codebase includes full test runners for each phase, culminating in a comprehensive 12-point production audit:

```bash
# Run the complete Phase 8 Production Audit
php mra-woocommerce/test_phase8.php
```

**Audit Checks Verified (12/12 PASS):**
- [x] Database Schema Integrity
- [x] SQL Injection Protection (Prepared Statements)
- [x] Strict MRA Read-Only Safety Guard
- [x] Sensitive Credential Scrubbing in Logs
- [x] Cryptographic Webhook HMAC-SHA256 Verification
- [x] Webhook Idempotency & Duplicate Delivery Guard
- [x] Product Payload Hashing (Change Detection)
- [x] Category Duplicate Prevention
- [x] File Security (`.htaccess` & Directory Traversal Protection)
- [x] Non-Blocking Concurrency Locking (`flock`)
- [x] Failed Sync Recovery Tracking
- [x] MRA Sliding-Window Rate Limit Compliance

---

## Production Deployment (SiteGround)

For complete step-by-step instructions on deploying to SiteGround, refer to:
👉 [mra-woocommerce/DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md)

### Quick Summary:
1. Upload `mra-woocommerce/` to `public_html/`.
2. Import `database/migrations.sql` in phpMyAdmin.
3. Configure `mra-woocommerce/.env` with live credentials.
4. Set up the 15-minute cron job in SiteGround **Site Tools → Devs → Cron Jobs**:
   ```bash
   /usr/local/bin/php /home/customer/www/yourdomain.com/public_html/mra-woocommerce/cron.php >> /home/customer/www/yourdomain.com/public_html/mra-woocommerce/logs/cron.log 2>&1
   ```
5. Add the order webhook in **WP-Admin → WooCommerce → Settings → Advanced → Webhooks**.

---

## Security & Safety Guarantees

- **No Public Credential Exposure**: `.htaccess` actively forbids browser requests to `.env`, `.sql`, `.git`, and `logs/`.
- **Zero Live MRA Mutation**: Live writes remain locked until explicit client authorization.
- **Auto-Redaction**: Plain API keys and secrets never enter log files or audit tables.
- **Timing-Attack Proof**: Webhook signatures use PHP's constant-time `hash_equals()` function.

---

## License & Support

Developed for **TechRestore** client. Built with Core PHP 8.1.
For technical details and component specifications, consult [codebase_master_guide.md](codebase_master_guide.md) and [AGENTS.md](AGENTS.md).
