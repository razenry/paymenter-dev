# Stage 1: PHP Dependencies (Composer)
# We use a specific composer image to get the latest version and binary easily.
FROM --platform=$TARGETOS/$TARGETARCH composer:2 AS vendor
WORKDIR /app

# Copy only the files needed for composer to leverage Docker layer caching.
COPY composer.json composer.lock ./

# Install production dependencies without scripts or autoloader to keep it fast and cached.
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

# Copy the entire source to generate a production-ready autoloader.
# This ensures that classmaps and services are correctly discovered.
COPY . .
RUN composer install \
    --no-dev \
    --no-interaction \
    --optimize-autoloader \
    --ignore-platform-reqs


# Stage 2: Frontend Assets (Node.js)
# We use Node to compile the Vite themes and assets.
FROM --platform=$TARGETOS/$TARGETARCH node:22-alpine AS frontend
WORKDIR /app

# Copy package files first for caching.
COPY package.json package-lock.json ./
RUN npm install --no-audit && rm -rf /root/.npm

# Copy source files needed for Vite compilation and Tailwind scanning.
COPY vite.js ./
COPY themes/ themes/
COPY resources/ resources/
COPY public/ public/
COPY app/ app/
COPY extensions/ extensions/

# Copy Composer vendor directory as some frontend assets (like Filament) 
# import CSS or scan for classes inside vendor packages.
COPY --from=vendor /app/vendor /app/vendor

# Execute the theme building process.
RUN npm run build


# Stage 3: Production Runtime (Alpine-based PHP-FPM)
# This is the final image that will be deployed.
FROM --platform=$TARGETOS/$TARGETARCH php:8.4-fpm-alpine AS production
WORKDIR /app

# Define runtime and build-time package variables for clarity.
ENV BUILD_DEPS="autoconf make g++ gcc libc-dev linux-headers libpng-dev libxml2-dev libzip-dev icu-dev gmp-dev"
ENV RUNTIME_DEPS="nginx supervisor dcron curl git libpng libxml2 libzip icu gmp netcat-openbsd"

# Install runtime dependencies and build PHP extensions in a single optimized layer.
RUN apk add --no-cache --update $RUNTIME_DEPS \
    && apk add --no-cache --virtual .build-deps $BUILD_DEPS \
    && docker-php-ext-configure zip \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        gd \
        pdo_mysql \
        zip \
        intl \
        sockets \
        gmp \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear /var/cache/apk/*

# Copy configuration files from the GitLab-specific directory.
COPY .gitlab/docker/default.conf /etc/nginx/http.d/default.conf
COPY .gitlab/docker/www.conf /usr/local/etc/php-fpm.conf
COPY .gitlab/docker/supervisord.conf /etc/supervisord.conf
COPY .gitlab/docker/custom-php.ini /usr/local/etc/php/conf.d/custom-php.ini

# Copy the application source code (filtered by .dockerignore).
COPY . .

# Bring in the PHP dependencies from the vendor stage.
COPY --from=vendor /app/vendor /app/vendor

# Bring in the compiled assets from the frontend stage.
COPY --from=frontend /app/public /app/public

# Final setup: Environment file, directory structure, permissions, and cron.
RUN cp .env.example .env \
    && mkdir -p /var/run/php /var/run/nginx storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R nginx:nginx . \
    && echo "* * * * * /usr/local/bin/php /app/artisan schedule:run >> /dev/null 2>&1" >> /var/spool/cron/crontabs/root

# Optional: Add a healthcheck to ensure the container is healthy.
HEALTHCHECK --interval=60s --timeout=10s --start-period=30s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

ENTRYPOINT [ "/bin/ash", ".gitlab/docker/entrypoint.sh" ]
CMD [ "supervisord", "-n", "-c", "/etc/supervisord.conf" ]

