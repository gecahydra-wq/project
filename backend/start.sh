#!/bin/sh
set -u

if [ -f artisan ]; then
    :
elif [ -f backend/artisan ]; then
    cd backend
else
    echo "Laravel artisan file was not found." >&2
    exit 1
fi

if ! php artisan config:clear --no-interaction; then
    echo "Warning: unable to clear Laravel configuration cache; continuing startup." >&2
fi

if ! php artisan config:cache --no-interaction; then
    echo "Warning: unable to cache Laravel configuration; continuing startup." >&2
fi

if ! php artisan storage:link --no-interaction; then
    echo "Warning: unable to create public/storage link; continuing startup." >&2
fi

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
