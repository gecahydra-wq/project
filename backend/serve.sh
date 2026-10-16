#!/bin/sh
# Start the API with OPcache enabled -- run this instead of `php artisan serve`.
# XAMPP's php.ini ships without opcache; see dev-php/opcache.ini for the why.
#
# PHP_INI_SCAN_DIR is the only way to add ini settings to the `php -S` child
# that `artisan serve` spawns: the child inherits this environment variable, so
# it picks up dev-php/opcache.ini without the global D:\xampp\php\php.ini being
# touched.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
if command -v cygpath >/dev/null 2>&1; then
    here="$(cygpath -w "$here")"
fi

PHP_INI_SCAN_DIR="$here\\dev-php"
export PHP_INI_SCAN_DIR

exec php artisan serve "$@"
