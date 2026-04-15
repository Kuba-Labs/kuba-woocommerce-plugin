# Kuba Labs WooCommerce Plugin

Local development environment for the Kuba Labs WooCommerce plugin.

## Prerequisites

- Docker & Docker Compose
- Local Supabase running (`kuba-backend/local_supa/commands.sh start`)

## First-time setup

```bash
docker compose up -d
./setup.sh
./setup-local-dev.sh
```

This installs WordPress, WooCommerce, sample products, activates the plugin, creates test API keys, and points webhooks to your local backend.

### Backend DB setup

The backend needs a `wc_test` ecom in the database. This is handled by the fixtures (`fixtures/initial.sql`). If using the real local DB, run the manual product sync test to insert the setup:

```bash
cd ../kuba-backend
cargo test test_sync_woocommerce_products -- --ignored
```

## Start / Stop

```bash
docker compose up -d     # start
docker compose stop      # stop (keeps data)
docker compose down      # stop and remove containers (keeps volumes)
docker compose down -v   # full reset — run setup.sh again after this
```

After a container restart, WP-CLI needs to be reinstalled (it doesn't persist). Run `./setup.sh` again, or just the WP-CLI install part:

```bash
docker compose exec wordpress bash -c 'curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp'
```

## Flushing the event queue

WooCommerce Action Scheduler doesn't auto-flush in local dev (no traffic = no WP-Cron). After placing an order, flush manually:

```bash
./flush.sh
```

## Access

| Service         | URL                                            |
| --------------- | ---------------------------------------------- |
| WordPress       | http://localhost:8082                           |
| WP Admin        | http://localhost:8082/wp-admin (admin / admin)  |
| phpMyAdmin      | http://localhost:8081                           |
| Plugin settings | WooCommerce > Settings > Kuba Labs              |

## Development

The plugin source at `./plugin/` is live-mounted into the container. Edit PHP files and refresh -- no rebuild needed.

## Testing orders

Add items to cart via URL:

```
http://localhost:8082/?kuba_cart=31           # single product
http://localhost:8082/?kuba_cart=v29:2,v28:3  # variations with quantities
```

Then checkout with Cash on Delivery and run `./flush.sh` to send events to the backend.

## Fixed test credentials

These are deterministic across all setups (set by `setup.sh`):

| Key              | Value                                          |
| ---------------- | ---------------------------------------------- |
| Store ID         | `00000000-0000-0000-0000-000000000001`         |
| Webhook Secret   | `test-webhook-secret-for-local-e2e`            |
| Consumer Key     | `ck_localdev_kuba_e2e_fixed_key`               |
| Consumer Secret  | `cs_localdev_kuba_e2e_fixed_secret_for_testing`|
| Widget Key       | `a0000000-0000-0000-0000-000000000001`         |
