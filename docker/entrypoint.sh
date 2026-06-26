#!/bin/sh

# Exit on error
set -e

# Run optimizations
echo "Running Laravel configuration caching..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Execute migrations if RUN_MIGRATIONS environment variable is true
if [ "${RUN_MIGRATIONS}" = "true" ]; then
    echo "Running migrations..."
    php artisan migrate --force
fi

# Execute the main container CMD
exec "$@"
