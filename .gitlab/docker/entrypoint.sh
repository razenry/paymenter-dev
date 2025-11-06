#!/bin/ash -e
cd /app

mkdir -p /var/log/supervisord/ /var/log/nginx/ 


## check for .env file and generate app keys if missing
if [ -f /app/.env ]; then
    echo "Found existing /app/.env"
    export $(grep -v '^#' /app/.env | xargs)
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