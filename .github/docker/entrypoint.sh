#!/bin/bash -e
cd /app

echo "== Preparing filesystem =="
mkdir -p \
  /var/log/nginx \
  /var/run/nginx \
  /var/log/supervisord \
  /app/storage/logs \
  /app/storage/framework/{cache,sessions,views} \
  /app/bootstrap/cache

echo "== Loading environment =="
if [ -f /app/.env ]; then
  echo "Found existing /app/.env"
  export $(grep -v '^#' /app/.env | xargs)
fi

if [ -z "$DB_PORT" ]; then
  echo "DB_PORT not specified, defaulting to 3306"
  DB_PORT=3306
  export DB_PORT
fi

## check for DB up before starting the panel
echo "Checking database status."
until nc -z -w30 $DB_HOST $DB_PORT
do
  echo "Waiting for database connection..."
  sleep 1
done

## check if storage symlink exists, if not create it
if [ ! -L /app/public/storage ]; then
  echo "Creating storage symlink."
  rm -rf /app/public/storage
  ln -s /app/storage/app/public /app/public/storage
  echo "Storage symlink created."
else
  echo "Storage symlink already exists."
fi

## make sure the db is set up
echo "Migrating and Seeding D.B"
php artisan migrate --seed --force

## start cronjobs for the queue
echo "Starting cron jobs."
cron

echo "Starting supervisord."
exec "$@"