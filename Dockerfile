# Stage 1: PHP Dependencies (Composer)
FROM --platform=$TARGETOS/$TARGETARCH composer:2 AS vendor
WORKDIR /app

# Copy only the files needed for composer to leverage Docker layer caching.
COPY composer.json composer.lock ./

# Install production dependencies without scripts or autoloader for caching.
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

# Generate optimized autoloader after copying source code.
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative


# Stage 2: Frontend Assets (Node.js)
FROM --platform=$TARGETOS/$TARGETARCH node:22-alpine AS frontend
WORKDIR /app

# Copy package files first for caching.
COPY package.json package-lock.json ./
RUN npm ci --no-audit && rm -rf /root/.npm

# Copy source files needed for Vite compilation and Tailwind scanning.
COPY vite.js ./
COPY themes/ themes/
COPY resources/ resources/
COPY public/ public/
COPY app/ app/
COPY extensions/ extensions/

# Copy Composer vendor directory as some frontend assets (like Filament) 
# scan for classes inside vendor packages.
COPY --from=vendor /app/vendor /app/vendor

# Execute the theme building process.
RUN npm run build


# Stage 3: Production Runtime (Alpine-based PHP-FPM)
FROM --platform=$TARGETOS/$TARGETARCH php:8.4-fpm-alpine AS production
WORKDIR /app

# Define dependencies as variables for maintainability.
ENV RUNTIME_DEPS="nginx supervisor dcron curl git libpng libxml2 libzip icu gmp netcat-openbsd"
ENV BUILD_DEPS="autoconf make g++ gcc libc-dev linux-headers libpng-dev libxml2-dev libzip-dev icu-dev gmp-dev"

# Install runtime dependencies and build PHP extensions in one clean layer.
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
    && rm -rf /tmp/pear /var/cache/apk/* \
    && mkdir -p /var/run/php /var/run/nginx storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log

# Copy configuration files
COPY .gitlab/docker/default.conf /etc/nginx/http.d/default.conf
COPY .gitlab/docker/www.conf /usr/local/etc/php-fpm.conf
COPY .gitlab/docker/supervisord.conf /etc/supervisord.conf
COPY .gitlab/docker/custom-php.ini /usr/local/etc/php/conf.d/custom-php.ini

# Copy application source using ownership flag to avoid massive chown layers.
COPY --chown=nginx:nginx . .
COPY --chown=nginx:nginx --from=vendor /app/vendor /app/vendor
COPY --chown=nginx:nginx --from=frontend /app/public /app/public

# Setup scheduler crontab (running as nginx to avoid permission errors)
RUN echo "* * * * * /usr/local/bin/php /app/artisan schedule:run >> /dev/null 2>&1" > /var/spool/cron/crontabs/nginx

HEALTHCHECK --interval=60s --timeout=10s --start-period=30s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

ENTRYPOINT [ "/bin/ash", ".gitlab/docker/entrypoint.sh" ]
CMD [ "supervisord", "-n", "-c", "/etc/supervisord.conf" ]
