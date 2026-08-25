#!/bin/sh
set -eu

# Railway may run this script with either the repository root or backend/ as
# its service root. Resolve the Laravel application without assuming either
# layout, so a harmless service-root change cannot prevent the API from
# starting.
if [ -f artisan ]; then
    :
elif [ -f backend/artisan ]; then
    cd backend
else
    echo "Laravel artisan file was not found." >&2
    exit 1
fi

# Railway injects variables at runtime. Remove any cache produced by an older
# deployment before rebuilding it, otherwise APP_URL, FRONTEND_URL, and SMTP
# values can remain stale even after Railway variables are corrected.
php artisan config:clear
php artisan config:cache

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
