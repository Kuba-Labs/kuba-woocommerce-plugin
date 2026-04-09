#!/bin/bash
# Configure the WP plugin to talk to local backend/frontend for development.
# Run this AFTER setup.sh has completed.
#
# Prerequisites:
#   - Kuba backend running on localhost:8080
#   - Kuba frontend running on localhost:5173 (or wherever `npm run dev` serves)

set -e

echo "Configuring local dev overrides..."

# host.docker.internal lets the WP container (Docker) reach the host machine.
# This is where your Rust backend and SvelteKit dev server run.
docker compose exec wordpress bash -c '
  if ! grep -q "KUBA_LABS_API_BASE" /var/www/html/wp-config.php; then
    sed -i "/\/\* That'\''s all, stop editing/i\\
define( '\''KUBA_LABS_API_BASE'\'', '\''http://host.docker.internal:8080'\'' );\\
define( '\''KUBA_LABS_FRONTEND_BASE'\'', '\''http://localhost:5173'\'' );" /var/www/html/wp-config.php
    echo "Added local dev constants to wp-config.php"
  else
    echo "Local dev constants already present in wp-config.php"
  fi
'

echo ""
echo "Done! Plugin now points to:"
echo "  API:      http://host.docker.internal:8080  (your local Rust backend)"
echo "  Frontend: http://localhost:5173              (your local SvelteKit dev server)"
echo ""
echo "The callback_url from the plugin will use http://localhost:8082"
echo "(the WordPress container), which your backend can reach directly."
