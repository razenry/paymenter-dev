# ─────────────────────────────────────────────
# Stage 1: PHP builder — installs composer deps
# Build tools stay isolated here, never reach final image
# ─────────────────────────────────────────────
FROM --platform=$TARGETOS/$TARGETARCH php:8.4-fpm-bookworm AS php-builder
WORKDIR /app

# 1. Install system deps + PHP extensions in ONE layer
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates cron curl git supervisor tar unzip \
    nginx libpng-dev libxml2-dev libzip-dev libicu-dev \
    autoconf make g++ gcc libc-dev libgmp-dev \
    && docker-php-ext-configure zip \
    && docker-php-ext-install bcmath gd pdo_mysql zip intl sockets gmp \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y autoconf make g++ gcc libc-dev \
    && apt-get autoremove -y && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/pear /usr/local/lib/php/test /usr/local/lib/php/doc

# 2. Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -f /usr/local/bin/composer-setup.php

# 3. Copy lock files FIRST for better layer caching
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-scripts

COPY . ./
RUN composer install --no-dev --optimize-autoloader \
    && rm -rf /root/.composer/cache

# ─────────────────────────────────────────────
# Stage 2: Node builder — compiles frontend assets only
# ─────────────────────────────────────────────
FROM --platform=$TARGETOS/$TARGETARCH node:22-slim AS node-builder
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . ./
COPY --from=php-builder /app/vendor /app/vendor
RUN npm run build \
    && rm -rf node_modules /root/.npm

# ─────────────────────────────────────────────
# Stage 3: Lean final runtime image
# Everything runs as root — it's Docker, the container IS the sandbox.
# ─────────────────────────────────────────────
FROM --platform=$TARGETOS/$TARGETARCH php:8.4-fpm-bookworm AS final
WORKDIR /app

# Runtime-only packages
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates cron curl supervisor tar unzip \
    nginx libpng16-16t64 libxml2 libzip4 libicu72 libgmp10 netcat-openbsd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY .github/docker/custom-php.ini /usr/local/etc/php/conf.d/custom-php.ini

# Copy compiled artifacts from prior stages
COPY --from=php-builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=php-builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=php-builder /app/vendor /app/vendor
COPY --from=node-builder /app/public /app/public

# Copy app source
COPY . ./

RUN cp .env.example .env \
    && chmod 775 -R bootstrap storage \
    && rm -rf .env bootstrap/cache/*.php \
    && rm /usr/local/etc/php-fpm.conf \
    && rm -f /etc/nginx/sites-enabled/default \
    && echo "* * * * * /usr/local/bin/php /app/artisan schedule:run >> /dev/null 2>&1" \
    >> /var/spool/cron/crontabs/root \
    && mkdir -p /var/run/php /var/run/nginx

COPY .github/docker/default.conf /etc/nginx/conf.d/default.conf
COPY .github/docker/www.conf     /usr/local/etc/php-fpm.conf
COPY .github/docker/supervisord.conf /etc/supervisord.conf

ENTRYPOINT ["/bin/bash", ".github/docker/entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisord.conf"]