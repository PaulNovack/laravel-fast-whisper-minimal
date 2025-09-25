#!/usr/bin/env bash
set -e

# Clear cached manifests that can reference packages not present in the image
rm -f bootstrap/cache/packages.php bootstrap/cache/config.php bootstrap/cache/services.php bootstrap/cache/events.php || true

# Ensure storage/cache dirs exist & are writable
mkdir -p storage/framework/{cache,sessions,views}
chown -R www-data:www-data storage bootstrap/cache || true
find storage -type d -exec chmod 775 {} \; || true
find storage -type f -exec chmod 664 {} \; || true
chmod -R 775 bootstrap/cache || true

mkdir -p /var/www/html/database
touch /var/www/html/database/database.sqlite
chown -R www-data:www-data /var/www/html/database || true
chmod 775 /var/www/html/database || true
chmod 664 /var/www/html/database/database.sqlite || true

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
