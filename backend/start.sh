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

# The Dockerfile's chmod runs at BUILD time, before Railway mounts its volume
# over storage/app/public -- so at runtime that directory can arrive empty or
# root-owned, and uploads then fail silently for the whole deployment. Redo it
# here, after the mount, and make sure the upload directory exists at all.
# Uploaded images live on that volume (ImageUploader writes to the 'public'
# disk); anything written outside it is destroyed by the next deploy.
if ! mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs; then
    echo "Warning: unable to create storage directories; continuing startup." >&2
fi

if ! chmod -R 775 storage bootstrap/cache; then
    echo "Warning: unable to reset storage permissions; continuing startup." >&2
fi

# --force so a stale or broken public/storage symlink is replaced rather than
# left in place: without it storage:link just reports "link already exists"
# and every image 404s for the life of the deployment.
if ! php artisan storage:link --force --no-interaction; then
    echo "Warning: unable to create public/storage link; continuing startup." >&2
fi

# Laravel's scheduler (routes/console.php: scheduled announcements, unpaid
# order expiry, expired-token pruning) runs as a background worker in the same
# container. If it ever dies, the web server keeps serving normally.
php artisan schedule:work --no-interaction > storage/logs/scheduler.log 2>&1 &

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
