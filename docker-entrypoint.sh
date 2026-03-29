#!/bin/sh
set -e

# Create SQLite database if it doesn't exist
if [ ! -f /app/database/database.sqlite ]; then
    touch /app/database/database.sqlite
    echo "Created database.sqlite"
fi

# Run migrations
php artisan migrate --force --no-interaction

# Execute the main command
exec "$@"
