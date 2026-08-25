#!/bin/sh
set -u

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

# These are deployment-maintenance steps, never reasons for the HTTP process
# to stay down. With `set -e`, an existing storage link, a transient volume
# permission issue, or a cache cleanup error exits this script before the
# final `php artisan serve` command and Railway returns a 502. Keep the
# diagnostics in Railway logs but continue to the actual application process.
if ! php artisan config:clear --no-interaction; then
    echo "Warning: unable to clear Laravel configuration cache; continuing startup." >&2
fi

if ! php artisan config:cache --no-interaction; then
    echo "Warning: unable to cache Laravel configuration; continuing startup." >&2
fi

# The link is safe to request on every deploy. Laravel returns successfully
# when it already exists; this guard also avoids a storage-link condition
# preventing the API from binding Railway's assigned port.
if ! php artisan storage:link --no-interaction; then
    echo "Warning: unable to create public/storage link; continuing startup." >&2
fi

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
