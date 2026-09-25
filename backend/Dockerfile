FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC
ENV LANG=C.UTF-8

# Railway volume is mounted at /app/storage/app/public.
WORKDIR /app

RUN apt-get update && apt-get install -y \
    ca-certificates \
    curl \
    unzip \
    git \
    libgd3 \
    php8.3-cli \
    php8.3-gd \
    php8.3-mbstring \
    php8.3-xml \
    php8.3-curl \
    php8.3-zip \
    php8.3-bcmath \
    php8.3-intl \
    php8.3-sqlite3 \
    php8.3-mysql \
    php8.3-pgsql \
    && rm -rf /var/lib/apt/lists/*

# PHP ships with upload_max_filesize=2M / post_max_size=8M, which silently
# truncates media uploads long before ImageUploader gets to validate them:
# PHP discards the whole request body, so Laravel sees an empty form and
# reports "a post needs some text or at least one photo" rather than a size
# error. These sit above ImageUploader's 25MB ceiling so the application
# returns its own clear message instead.
RUN { \
      echo "upload_max_filesize = 30M"; \
      echo "post_max_size = 60M"; \
      echo "memory_limit = 256M"; \
    } > /etc/php/8.3/cli/conf.d/99-uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./

RUN composer install \
    --optimize-autoloader \
    --no-scripts \
    --no-interaction

COPY . .

RUN chmod -R 775 storage bootstrap/cache

EXPOSE 8080

CMD ["sh", "start.sh"]
