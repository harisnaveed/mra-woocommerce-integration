# SiteGround Live Production Deployment Guide

This guide details the exact 5-step process for deploying the **MRA -> WooCommerce Integration Bridge** (`mra-woocommerce`) to your live **SiteGround** hosting environment.

---

## Prerequisites
- Access to your **SiteGround Client Area** and **Site Tools**.
- Access to your live **WordPress Admin** dashboard (`/wp-admin`).
- An active MyRepairApp API Key.

---

## Step 1: Upload the Integration Folder to SiteGround

Upload the entire `mra-woocommerce` directory to your live website's `public_html` root folder:

```text
/home/customer/www/yourdomain.com/public_html/mra-woocommerce/
```

### Methods:
- **Option A (Site Tools File Manager)**: Compress `mra-woocommerce` into a `.zip`, upload it to `public_html/` in **Site Tools → Site → File Manager**, and extract it.
- **Option B (FTP / SFTP)**: Upload the folder using FileZilla or Cyberduck directly into `public_html/`.

---

## Step 2: Run Database Migrations (One-Time Setup)

You must create the 4 bridge tables in your live WordPress MySQL database.

1. In SiteGround **Site Tools**, go to **Site** → **MySQL** → **phpMyAdmin**.
2. In the left panel, select your live WordPress database (e.g. `db_yourdbname`).
3. Click the **Import** tab in the top navigation bar.
4. Click **Choose File** and select:
   ```text
   mra-woocommerce/database/migrations.sql
   ```
5. Leave all settings at default and click **Go** (or **Import**).
6. Verify that the following 4 tables now exist:
   - `mra_woocommerce_sync` (Main mapping & change-detection table)
   - `mra_woocommerce_categories` (Category translation table)
   - `mra_woocommerce_sync_logs` (Audit and history table)
   - `mra_woocommerce_webhook_events` (Reverse order staging table)

---

## Step 3: Configure the Live `.env` File

Inside `/home/customer/www/yourdomain.com/public_html/mra-woocommerce/`, create or edit the `.env` file with your live credentials:

```ini
# ==============================================================================
# MRA -> WooCommerce Bridge Configuration (Live Production)
# ==============================================================================

# Environment
APP_ENV=production

# 1. Live SiteGround Database Settings (from wp-config.php)
DB_HOST=localhost
DB_PORT=3306
DB_NAME=your_live_db_name
DB_USER=your_live_db_user
DB_PASS=your_live_db_password
DB_CHARSET=utf8mb4

# 2. MyRepairApp (MRA) API Settings
MRA_API_BASE_URL=https://www.myrepairapp.com/api/v2
MRA_API_KEY=e2a95450a5715185763d8b2bfb1334be60962b4c56aadd5045ac3c413e0ecf3b
MRA_STORE_ID=

# 3. Live WooCommerce REST API Settings
# (Generate in: WP-Admin -> WooCommerce -> Settings -> Advanced -> REST API)
WC_API_BASE_URL=https://yourdomain.com/wp-json/wc/v3
WC_CONSUMER_KEY=ck_your_live_consumer_key_here
WC_CONSUMER_SECRET=cs_your_live_consumer_secret_here
WC_VERIFY_SSL=true

# 4. Sync Engine Settings
SYNC_PAGE_SIZE=50
SYNC_TIMEOUT=30
SYNC_MAX_RETRIES=3

# 5. Cryptographic Security Secrets (Create your own random strings)
WEBHOOK_SECRET=your_long_random_webhook_secret_here
CRON_SECRET=your_long_random_cron_secret_here

# 6. Safety Guard: STRICT READ-ONLY MODE
# Keep 'false' for Staged Simulation Mode (Recommended until client approves live deduction)
# Set 'true' only when client explicitly authorizes automatic live stock deduction on MRA
MRA_ALLOW_WRITES=false

# 7. Logging Settings
LOG_LEVEL=INFO
LOG_FILE=logs/sync.log
```

### Where do `WEBHOOK_SECRET` and `CRON_SECRET` come from?
> [!IMPORTANT]
> **You create these secrets yourself!** They are not provided by SiteGround or MyRepairApp. They are private security tokens you invent to protect your bridge:
>
> 1. **`WEBHOOK_SECRET`**:
>    - **Purpose**: A shared secret key between WooCommerce and your bridge endpoint (`webhook.php`).
>    - **How to set it**: Invent any long random string (e.g. `whsec_9a7d3f82b1c4e6507812fa9b43de2c18`). Put it in `.env`, and when setting up the webhook in WP-Admin (Step 5), paste the **exact same string** into the **"Secret"** field.
>    - **Why it matters**: WooCommerce uses this secret to cryptographically sign order payloads with HMAC-SHA256, proving the request genuinely came from your store.
>
> 2. **`CRON_SECRET`**:
>    - **Purpose**: A password protecting `cron.php` so random strangers on the internet cannot trigger your cron URL and overload your server.
>    - **How to set it**: Invent any long random string (e.g. `cron_e38b417a92c04d1685fa291b7d503ce8`).
>    - **Why it matters**: When calling `cron.php` via web browser or `curl`, you append `?secret=YOUR_CRON_SECRET`. Anyone attempting to open `cron.php` without this token is immediately blocked with **403 Forbidden**.

> **Security Note**: The included `.htaccess` file automatically prevents any public web browser from viewing your `.env` file or downloading logs.

---

## Step 4: Configure the 15-Minute Cron in SiteGround

Set up SiteGround's server scheduler to automatically keep inventory synced.

1. In SiteGround **Site Tools**, navigate to **Devs** → **Cron Jobs**.
2. Configure the schedule:
   - **Interval**: Select **Every 15 minutes** (Cron expression: `*/15 * * * *`).
3. In the **Command** field, enter:
   ```bash
   /usr/local/bin/php /home/customer/www/yourdomain.com/public_html/mra-woocommerce/cron.php >> /home/customer/www/yourdomain.com/public_html/mra-woocommerce/logs/cron.log 2>&1
   ```
   *(Ensure you replace `yourdomain.com` with your exact SiteGround domain path).*
4. Click **Create**.

### Alternative: Web Cron via URL
If you prefer not using file paths, you can enter this as the cron command in Site Tools:
```bash
curl -s "https://yourdomain.com/mra-woocommerce/cron.php?secret=your_long_random_cron_secret_here&page_size=50&max_pages=0" > /dev/null 2>&1
```

---

## Step 5: Configure the WooCommerce Order Webhook (One-Time)

Enable reverse order tracking so online purchases notify the bridge.

1. Log into your live **WordPress Admin** (`https://yourdomain.com/wp-admin`).
2. Navigate to: **WooCommerce** → **Settings** → **Advanced** → **Webhooks**.
3. Click the **Add webhook** button:
   - **Name**: `MRA Order Inventory Sync`
   - **Status**: `Active`
   - **Topic**: `Order created` *(or `Order updated`)*
   - **Delivery URL**: `https://yourdomain.com/mra-woocommerce/webhook.php`
   - **Secret**: `your_long_random_webhook_secret_here` *(Must exactly match `WEBHOOK_SECRET` in `.env`)*
   - **API Version**: `WP REST API Integration v3`
4. Click **Save Webhook**.

---

## Verification & First Run

### 1. Manual Visual Run via Browser
Open your browser and visit:
```text
https://yourdomain.com/mra-woocommerce/cron.php?secret=YOUR_CRON_SECRET&page_size=20&max_pages=1
```
You will see a live dark-mode streaming console verifying that categories are mapped and products are imported.

### 2. Check WooCommerce Products
Open WP-Admin: **Products → All Products**. Verify that your MRA items appear with proper SKUs, prices, categories, and stock counts.

### 3. Check Order Simulation
Place a test order in WooCommerce. Then check table `mra_woocommerce_webhook_events` in phpMyAdmin to verify that the order was received, validated, and recorded in **Staged Simulation Mode**.
