# ─────────────────────────────────────────────
# Stage 1: PHP builder — installs composer deps
# Build tools stay isolated here, never reach final image
# ─────────────────────────────────────────────
FROM --platform=$TARGETOS/$TARGETARCH php:8.4-fpm-alpine AS php-builder
WORKDIR /app

# 1. Combine all apk, ext, pecl, and cleanup into ONE RUN layer
#    Avoids intermediate layers carrying build tool bloat
RUN apk add --no-cache ca-certificates dcron curl git supervisor tar unzip \
        nginx libpng-dev libxml2-dev libzip-dev icu-dev autoconf make g++ gcc \
        libc-dev linux-headers gmp-dev \
    && docker-php-ext-configure zip \
    && docker-php-ext-install bcmath gd pdo_mysql zip intl sockets gmp \
    && pecl install redis \
    && docker-php-ext-enable redis \
    # 2. Remove build-only tools in the SAME layer so they never persist
    && apk del autoconf make g++ gcc libc-dev \
    && rm -rf /tmp/pear /usr/local/lib/php/test /usr/local/lib/php/doc

# 3. Install composer (no need to store the installer script)
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -f /usr/local/bin/composer-setup.php

# 4. Copy lock files FIRST for better layer caching — only re-runs if deps change
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-scripts

COPY . ./
RUN composer install --no-dev --optimize-autoloader \
    # 5. Drop composer and its cache from the builder (won't be copied forward)
    && rm -rf /root/.composer/cache

# ─────────────────────────────────────────────
# Stage 2: Node builder — compiles frontend assets only
# ─────────────────────────────────────────────
FROM --platform=$TARGETOS/$TARGETARCH node:22-alpine AS node-builder
WORKDIR /app

COPY package.json package-lock.json ./
# 6. Use `npm ci` (faster, reproducible) and skip dev deps at install time
RUN npm ci

COPY . ./
COPY --from=php-builder /app/vendor /app/vendor
RUN npm run build \
    # 7. Wipe node_modules and npm cache — only /app/public goes forward
    && rm -rf node_modules /root/.npm

# ─────────────────────────────────────────────
# Stage 3: Lean final runtime image
# Starts fresh from base — no build tools, no npm, no composer
# ─────────────────────────────────────────────
FROM --platform=$TARGETOS/$TARGETARCH php:8.4-fpm-alpine AS final
WORKDIR /app

# 8. Runtime-only apk packages — no build tools at all
RUN apk add --no-cache ca-certificates dcron curl supervisor tar unzip \
        nginx libpng libxml2 libzip icu-libs gmp

COPY .gitlab/docker/custom-php.ini /usr/local/etc/php/conf.d/custom-php.ini

# 9. Copy only compiled artifacts from prior stages — not the full build context
COPY --from=php-builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=php-builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=php-builder /app/vendor /app/vendor
COPY --from=node-builder /app/public /app/public

# 10. Copy app source last (changes most often, keep near end for cache efficiency)
COPY . ./

RUN cp .env.example .env \
    && chmod 777 -R bootstrap storage/* \
    && rm -rf .env bootstrap/cache/*.php \
    && chown -R nginx:nginx . \
    && rm /usr/local/etc/php-fpm.conf \
    && echo "* * * * * /usr/local/bin/php /app/artisan schedule:run >> /dev/null 2>&1" \
        >> /var/spool/cron/crontabs/root \
    && mkdir -p /var/run/php /var/run/nginx \
    # 11. Purge apk cache in final image too
    && rm -rf /var/cache/apk/*

COPY .gitlab/docker/default.conf /etc/nginx/http.d/default.conf
COPY .gitlab/docker/www.conf     /usr/local/etc/php-fpm.conf
COPY .gitlab/docker/supervisord.conf /etc/supervisord.conf

ENTRYPOINT ["/bin/ash", ".gitlab/docker/entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisord.conf"]