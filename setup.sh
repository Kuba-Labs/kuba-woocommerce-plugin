#!/bin/bash
# First-time setup: install WooCommerce via WP-CLI inside the container.
# Run this after `docker compose up -d` and WordPress has finished initializing.

set -e

echo "Waiting for WordPress to be ready..."
until curl -s -o /dev/null -w "%{http_code}" http://localhost:8082 | grep -q "200\|302"; do
  sleep 2
done

echo "Installing WP-CLI..."
docker compose exec wordpress bash -c '
  curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar &&
  chmod +x wp-cli.phar &&
  mv wp-cli.phar /usr/local/bin/wp
'

echo "Completing WordPress install..."
docker compose exec wordpress wp core install \
  --url="http://localhost:8082" \
  --title="Kuba Dev Store" \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=dev@kubalabs.com \
  --skip-email \
  --allow-root

echo "Enabling pretty permalinks (required for REST API)..."
docker compose exec wordpress wp rewrite structure '/%postname%/' --allow-root

echo "Installing WooCommerce..."
docker compose exec wordpress wp plugin install woocommerce --activate --allow-root

echo "Configuring WooCommerce..."
docker compose exec wordpress wp option update woocommerce_store_address "Via Roma 1" --allow-root
docker compose exec wordpress wp option update woocommerce_store_city "Milano" --allow-root
docker compose exec wordpress wp option update woocommerce_default_country "IT" --allow-root
docker compose exec wordpress wp option update woocommerce_currency "EUR" --allow-root
docker compose exec wordpress wp option update woocommerce_calc_taxes "no" --allow-root
docker compose exec wordpress wp option update woocommerce_coming_soon "no" --allow-root
docker compose exec wordpress wp option set woocommerce_cod_settings '{"enabled":"yes","title":"Cash on Delivery","description":"Pay on delivery","instructions":"Pay on delivery"}' --format=json --allow-root

echo "Publishing policy pages..."
docker compose exec wordpress wp eval '
  $ids = [
    get_option("wp_page_for_privacy_policy", 0),
    get_option("woocommerce_terms_page_id", 0),
    get_option("woocommerce_refund_returns_page_id", 0),
  ];
  foreach (array_filter($ids) as $id) {
    wp_update_post(["ID" => $id, "post_status" => "publish"]);
  }
' --allow-root 2>/dev/null || true

echo "Importing WooCommerce sample products..."
docker compose exec wordpress wp wc tool run install_pages --user=admin --allow-root 2>/dev/null || true
docker compose exec wordpress wp plugin install wordpress-importer --activate --allow-root
docker compose exec wordpress bash -c '
  if [ -f /var/www/html/wp-content/plugins/woocommerce/sample-data/sample_products.xml ]; then
    wp import /var/www/html/wp-content/plugins/woocommerce/sample-data/sample_products.xml --authors=create --allow-root 2>/dev/null || true
  fi
'

echo "Allowing WC REST API over HTTP (local dev only)..."
docker compose exec wordpress bash -c '
  mkdir -p /var/www/html/wp-content/mu-plugins
  cat > /var/www/html/wp-content/mu-plugins/allow-http-rest-api.php << "MUPHP"
<?php
// Allow WooCommerce REST API over HTTP for local development only.
add_filter("woocommerce_rest_check_permissions", "__return_true");
MUPHP
'

echo "Activating Kuba Labs plugin..."
docker compose exec wordpress wp plugin activate kuba-labs --allow-root

echo "Setting up local dev connection..."
# Use fixed, deterministic values so the backend test constants don't need updating.
STORE_ID="00000000-0000-0000-0000-000000000001"
WEBHOOK_SECRET="test-webhook-secret-for-local-e2e"

WIDGET_KEY="a0000000-0000-0000-0000-000000000001"

docker compose exec wordpress wp option update kuba_labs_store_id "$STORE_ID" --allow-root
docker compose exec wordpress wp option update kuba_labs_webhook_secret "$WEBHOOK_SECRET" --allow-root
docker compose exec wordpress wp option update kuba_labs_connected_at "$(date -u +%Y-%m-%dT%H:%M:%S+00:00)" --allow-root
docker compose exec wordpress wp option update kuba_labs_widget_key "$WIDGET_KEY" --allow-root

# Generate WC REST API keys for the backend to use.
# Fixed keys so backend test constants never need updating.
CONSUMER_KEY="ck_localdev_kuba_e2e_fixed_key"
CONSUMER_SECRET="cs_localdev_kuba_e2e_fixed_secret_for_testing"

docker compose exec wordpress php -r "
require_once '/var/www/html/wp-load.php';
global \$wpdb;
\$wpdb->delete(\$wpdb->prefix . 'woocommerce_api_keys', ['description' => 'Kuba Labs'], ['%s']);
\$wpdb->insert(\$wpdb->prefix . 'woocommerce_api_keys', [
    'user_id' => 1, 'description' => 'Kuba Labs', 'permissions' => 'read_write',
    'consumer_key' => wc_api_hash('$CONSUMER_KEY'), 'consumer_secret' => '$CONSUMER_SECRET',
    'truncated_key' => substr('$CONSUMER_KEY', -7),
]);
" 2>/dev/null

echo ""
echo "Done!"
echo ""
echo "  WordPress:   http://localhost:8082"
echo "  WP Admin:    http://localhost:8082/wp-admin  (admin / admin)"
echo "  phpMyAdmin:  http://localhost:8081"
echo ""
echo "  Kuba Labs settings: WooCommerce > Settings > Kuba Labs"
echo ""
echo "  Plugin source is live-mounted from ./plugin/"
echo "  Edit PHP files and refresh — no rebuild needed."
echo ""
echo "  Connection details (update backend test constants if they changed):"
echo "    STORE_ID:        $STORE_ID"
echo "    WEBHOOK_SECRET:  $WEBHOOK_SECRET"
echo "    CONSUMER_KEY:    $CONSUMER_KEY"
echo "    CONSUMER_SECRET: $CONSUMER_SECRET"
