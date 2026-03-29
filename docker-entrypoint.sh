#!/bin/sh
set -e

# Wait for the database to accept connections
echo "Waiting for database..."
max_tries=30
count=0
until php -r "new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));" 2>/dev/null; do
    count=$((count + 1))
    if [ $count -ge $max_tries ]; then
        echo "Warning: Database not ready after ${max_tries}s, attempting anyway..."
        break
    fi
    sleep 1
done
echo "Database is ready."

# Only the app container (default CMD = artisan serve) runs migrations.
# Bot and scheduler skip to avoid race conditions.
if [ "$1" = "php" ] && [ "$2" = "artisan" ] && [ "$3" = "serve" ]; then
    echo "Running migrations..."
    php artisan migrate --force --no-interaction
    echo "Migrations complete."
else
    # Wait a few seconds for app container to finish migrations
    sleep 5
fi

exec "$@"
