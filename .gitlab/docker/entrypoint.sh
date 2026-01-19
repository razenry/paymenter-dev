#!/bin/ash -e
cd /app

mkdir -p /var/log/supervisord/ /var/log/nginx/ 


## check for .env file and generate app keys if missing
if [ -f /app/.env ]; then
    echo "Found existing /app/.env"
    export $(grep -v '^#' /app/.env | xargs)
fi

# In Docker Compose, services communicate using service names, not 127.0.0.1
# Always use 'database' as the hostname for internal Docker network connections
# The .env file might have 127.0.0.1 for external connections, but internally we need the service name
# Check if we're in Docker (service name 'database' should be resolvable)
if [ "$DB_HOST" = "127.0.0.1" ] || [ "$DB_HOST" = "localhost" ] || [ -z "$DB_HOST" ]; then
    echo "DB_HOST is set to $DB_HOST, using 'database' (Docker service name) for internal connection"
    DB_HOST=database
    export DB_HOST=database
fi

# Always use port 3306 for internal Docker network connections (not the external mapped port like 3309)
if [ -z "$DB_PORT" ] || [ "$DB_PORT" = "3309" ]; then
  echo "Using port 3306 for internal Docker network connection"
  DB_PORT=3306
  export DB_PORT=3306
fi

## check for DB up before starting the panel
echo "Checking database status."
echo "Attempting to connect to database at $DB_HOST:$DB_PORT"
until nc -z -v -w5 $DB_HOST $DB_PORT 2>&1
do
  echo "Waiting for database connection..."
  # wait for 1 seconds before check again
  sleep 1
done
echo "Database connection established!"

## check if storage symlink exists, if not create it
if [ ! -L /app/public/storage ]; then
  echo -e "Creating storage symlink."
  rm -rf /app/public/storage
  ln -s /app/storage/app/public /app/public/storage
  echo -e "Storage symlink created."
else
  echo -e "Storage symlink already exists."
fi

## create necessary storage directories
echo -e "Creating storage directories."
mkdir -p /app/storage/app/public
mkdir -p /app/storage/framework/cache/data
mkdir -p /app/storage/framework/sessions
mkdir -p /app/storage/framework/views
mkdir -p /app/storage/framework/testing
mkdir -p /app/storage/logs
echo -e "Storage directories created."

## set storage permissions to 777 and user nginx:nginx
echo -e "Setting storage permissions."
chmod -R 777 /app/storage
chown -R nginx:nginx /app/storage

## make sure the db is set up
echo -e "Migrating and Seeding D.B"
php artisan migrate --seed --force

## start cronjobs for the queue
echo -e "Starting cron jobs."
crond -L /var/log/crond -l 5

echo -e "Starting supervisord."
exec "$@"