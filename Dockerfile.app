# =========================
# PHP-FPM + Nginx (Debian)
# =========================
FROM php:8.3-fpm-bookworm AS app

# System deps
RUN apt-get update && apt-get install -y --no-install-recommends \
    git curl bash tzdata ca-certificates \
    nginx supervisor \
    libicu-dev libonig-dev libzip-dev \
    libpng-dev libjpeg-dev libfreetype6-dev \
    unzip gnupg \
 && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl zip gd opcache

# Optional Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---- Composer install (two-phase to avoid artisan missing during scripts)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts

# Copy full app (now artisan exists)
COPY . ./

# Finish composer with scripts + optimized autoload
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# ---- Install Node 20 and build assets (Tailwind v4/lightningcss needs glibc)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
 && apt-get install -y --no-install-recommends nodejs \
 && npm ci --no-audit --no-fund \
 && npm run build \
 && npm cache clean --force

# Permissions
RUN chown -R www-data:www-data storage bootstrap/cache \
 && find storage -type d -exec chmod 775 {} \; \
 && find storage -type f -exec chmod 664 {} \; \
 && chmod -R 775 bootstrap/cache

# Nginx config (serves Laravel /public)
RUN mkdir -p /run/nginx /etc/nginx/conf.d
RUN cat > /etc/nginx/nginx.conf <<'NGINX'
user  www-data;
worker_processes  auto;
error_log  /var/log/nginx/error.log warn;
pid        /run/nginx.pid;

events { worker_connections 1024; }

http {
  include       /etc/nginx/mime.types;
  default_type  application/octet-stream;
  sendfile        on;
  tcp_nopush      on;
  tcp_nodelay     on;
  keepalive_timeout  65;
  client_max_body_size 100m;
  gzip on;
  gzip_types text/plain text/css application/javascript application/json image/svg+xml;

  server {
    listen 8000;
    server_name _;

    root /var/www/html/public;
    index index.php index.html;

    location / {
      try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
      fastcgi_pass   127.0.0.1:9000;
      fastcgi_read_timeout 300;
    }

    location ~* \.(?:ico|css|js|gif|jpe?g|png|svg|webp|woff2?)$ {
      expires 7d;
      access_log off;
      add_header Cache-Control "public";
      try_files $uri =404;
    }
  }
}
NGINX

# Supervisord
RUN mkdir -p /etc/supervisor/conf.d
RUN cat > /etc/supervisor/conf.d/supervisord.conf <<'SUP'
[supervisord]
nodaemon=true
logfile=/var/log/supervisord.log

[program:php-fpm]
command=docker-php-entrypoint php-fpm
autostart=true
autorestart=true
priority=10
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:nginx]
command=/usr/sbin/nginx -g "daemon off;"
autostart=true
autorestart=true
priority=20
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
SUP

# Opcache & PHP settings
RUN cat > /usr/local/etc/php/conf.d/opcache.ini <<'OPC'
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=192
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.jit_buffer_size=64M
opcache.jit=1255
OPC

RUN cat > /usr/local/etc/php/conf.d/uploads.ini <<'PHPINI'
memory_limit=512M
post_max_size=100M
upload_max_filesize=100M
max_execution_time=300
PHPINI

HEALTHCHECK --interval=30s --timeout=5s --retries=5 CMD wget -qO- http://127.0.0.1:8000/up || exit 1
EXPOSE 8000

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
