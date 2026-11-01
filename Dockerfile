# TriageDesk: one image that serves the app with FrankenPHP (Caddy + PHP) on port 8080.
# It runs behind a reverse proxy that ends TLS; see docs/deploy-docker.md.

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

FROM dunglas/frankenphp:1-php8.4-bookworm

LABEL org.opencontainers.image.title="TriageDesk" \
      org.opencontainers.image.description="Laravel AI agent demo: support ticket triage with human approval." \
      org.opencontainers.image.licenses="MIT"

# Same extension line as the Easy!Appointments MCP image, so a shared Docker host reuses the layer.
RUN install-php-extensions pdo_mysql pdo_pgsql pdo_sqlite intl opcache pcntl \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /app
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/triagedesk-entrypoint

RUN chmod +x /usr/local/bin/triagedesk-entrypoint \
 && rm -f .env public/hot \
 && mkdir -p /data/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data /data /app/storage /app/bootstrap/cache /config

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/app/database.sqlite \
    SERVER_NAME=":8080"

USER www-data
EXPOSE 8080
VOLUME ["/data/app"]
ENTRYPOINT ["triagedesk-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
