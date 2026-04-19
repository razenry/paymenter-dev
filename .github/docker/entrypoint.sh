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

## copy default themes if themes directory is empty or doesn't exist
if [ ! -d /app/themes ] || [ -z "$(ls -A /app/themes 2>/dev/null)" ]; then
  echo -e "Themes directory is empty, copying default themes..."
  mkdir -p /app/themes
  if [ -d /app/themes_default ]; then
    cp -rp /app/themes_default/. /app/themes/
    chown -R nginx:nginx /app/themes
    chmod -R 755 /app/themes
    echo -e "Default themes copied."
  fi
else
  echo -e "Themes directory already populated."
fi

## copy default extensions if extensions directory is empty or doesn't exist
if [ ! -d /app/extensions ] || [ -z "$(ls -A /app/extensions 2>/dev/null)" ]; then
  echo -e "Extensions directory is empty, copying default extensions..."
  mkdir -p /app/extensions
  if [ -d /app/extensions_default ]; then
    cp -rp /app/extensions_default/. /app/extensions/
    chown -R nginx:nginx /app/extensions
    chmod -R 755 /app/extensions
    echo -e "Default extensions copied."
  fi
else
  echo -e "Extensions directory already populated."
fi

## set permissions for themes and extensions
echo -e "Setting themes and extensions permissions."
chown -R nginx:nginx /app/themes /app/extensions
chmod -R 755 /app/themes /app/extensions

## set storage permissions to 777 and user nginx:nginx
echo -e "Setting storage permissions."
chmod -R 777 /app/storage/*
chown -R nginx:nginx /app/storage

## make sure the db is set up
echo -e "Migrating and Seeding D.B"
php artisan migrate --seed --force

## start cronjobs for the queue
echo -e "Starting cron jobs."
crond -L /var/log/crond -l 5

echo -e "Starting supervisord."
exec "$@"