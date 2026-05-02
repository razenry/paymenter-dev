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
if [ -f /app/.env ]; then
  echo "Found existing /app/.env"
  export $(grep -v '^#' /app/.env | xargs)
fi

if [ -z "$DB_HOST" ]; then
  echo "WARNING: DB_HOST is not set, falling back to 'database'"
  DB_HOST="database"
fi

if [ -z "$DB_PORT" ]; then
  DB_PORT=3306
fi

echo "== Waiting for database ($DB_HOST:$DB_PORT) =="
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

# Ownership (ONLY writable paths)
chown -R nginx:nginx \
  /app/storage \
  /app/bootstrap/cache \
  /var/log/nginx \
  /var/run/nginx

# Permissions (no recursive chown again)
chmod -R 775 /app/storage /app/bootstrap/cache


echo "== Starting cron =="
crond -L /var/log/crond -l 5

echo "== Starting supervisord =="
exec "$@"
