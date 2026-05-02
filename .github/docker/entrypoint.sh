#!/bin/ash -e
cd /app

echo "== Preparing filesystem =="
# Base dirs (only what is actually needed)
mkdir -p \
  /var/log/nginx \
  /var/run/nginx \
  /var/log/supervisord \
  /app/storage \
  /app/bootstrap/cache

echo "== Loading environment =="
# System environment variables (set via Dokploy/Docker) always take precedence.
# We don't manually source .env here to avoid overwriting them.
# Laravel's DotEnv loader will handle the .env file internally.

if [ -z "$DB_CONNECTION" ]; then
  echo "WARNING: DB_CONNECTION is not set, falling back to 'mariadb'"
  export DB_CONNECTION="mariadb"
fi

if [ -z "$DB_HOST" ]; then
  echo "WARNING: DB_HOST is not set, falling back to 'database'"
  export DB_HOST="database"
fi

if [ -z "$DB_PORT" ]; then
  export DB_PORT=3306
fi

echo "== Waiting for database ($DB_CONNECTION on $DB_HOST:$DB_PORT) =="
until nc -z -w5 "$DB_HOST" "$DB_PORT"; do
  echo "Waiting for database connection at $DB_HOST:$DB_PORT..."
  sleep 2
done
echo "Database is up"

echo "== Storage setup =="

# Storage symlink
if [ ! -L /app/public/storage ]; then
  rm -rf /app/public/storage
  ln -s /app/storage/app/public /app/public/storage
fi

# Storage structure
mkdir -p \
  /app/storage/app/public \
  /app/storage/framework/{cache/data,sessions,views,testing} \
  /app/storage/logs


echo "== Running migrations =="
php artisan migrate --seed --force

# Ownership and Permissions
# We do this AFTER migrations to ensure any files created by artisan are also covered.
echo "== Setting permissions =="
chown -R nginx:nginx /app/storage /app/bootstrap/cache
chmod -R 777 /app/storage /app/bootstrap/cache


echo "== Starting cron =="
crond -L /var/log/crond -l 5

echo "== Starting supervisord =="
exec "$@"
