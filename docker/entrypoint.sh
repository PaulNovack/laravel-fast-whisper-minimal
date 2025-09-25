#!/usr/bin/env bash
set -euo pipefail

# Ensure SQLite exists (no-op if it already does)
[ -f database/database.sqlite ] || touch database/database.sqlite

# Safe permissions for Laravel
chown -R www-data:www-data storage bootstrap/cache database || true
chmod -R ug+rwX storage bootstrap/cache database || true

# Optional: generate key
php artisan key:generate --force || true

# Run migrations (includes sessions table if you've added it)
php artisan migrate --force || true

# Cache (optional)
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

exec /usr/bin/supervisord -n
