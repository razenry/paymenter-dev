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

if [ -z "$DB_PORT" ]; then
  DB_PORT=3306
  export DB_PORT
fi

echo "== Waiting for database =="
until nc -z -w5 "$DB_HOST" "$DB_PORT"; do
  echo "Waiting for database connection..."
  sleep 1
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

# Set Ownership and Permissions BEFORE running any commands 
# to prevent root-owned log files from being created.
chown -R nginx:nginx \
  /app/storage \
  /app/bootstrap/cache \
  /var/log/nginx \
  /var/run/nginx

# More permissive permissions for Docker volumes logic across OSes
chmod -R 777 /app/storage /app/bootstrap/cache


echo "== Running migrations =="
su-exec nginx php artisan migrate --seed --force


echo "== Starting cron =="
crond -L /var/log/crond -l 5

echo "== Starting supervisord =="
exec "$@"
