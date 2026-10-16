@echo off
REM Start the API with OPcache enabled -- run this instead of `php artisan serve`.
REM XAMPP's php.ini ships without opcache; see dev-php\opcache.ini for the why.
setlocal
set "PHP_INI_SCAN_DIR=%~dp0dev-php"
php artisan serve %*
