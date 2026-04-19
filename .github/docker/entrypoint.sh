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
if [[ -z $DB_PORT ]]; then
  echo -e "DB_PORT not specified, defaulting to 3306"
  DB_PORT=3306
fi

## check for DB up before starting the panel
echo "Checking database status."
until nc -z -v -w30 $DB_HOST $DB_PORT
do
  echo "Waiting for database connection..."
  # wait for 1 seconds before check again
  sleep 1
done

## check if storage symlink exists, if not create it
if [ ! -L /app/public/storage ]; then
  echo -e "Creating storage symlink."
  rm -rf /app/public/storage
  ln -s /app/storage/app/public /app/public/storage
  echo -e "Storage symlink created."
else
  echo -e "Storage symlink already exists."
fi

## set permissions for themes and extensions
echo -e "Setting themes and extensions permissions."
chown -R nginx:nginx /app/themes /app/extensions
chmod -R 755 /app/themes /app/extensions

## set storage permissions — ensure logs dir exists, then fix ownership
## so newly-created daily log files inherit correct perms
echo -e "Setting storage permissions."
mkdir -p /app/storage/logs /app/storage/framework/{cache,sessions,views}
chown -R nginx:nginx /app/storage /app/bootstrap/cache
chmod -R 775 /app/storage /app/bootstrap/cache

## make sure the db is set up
echo -e "Migrating and Seeding D.B"
php artisan migrate --seed --force

## start cronjobs for the queue
echo -e "Starting cron jobs."
crond -L /var/log/crond -l 5

echo -e "Starting supervisord."
exec "$@"