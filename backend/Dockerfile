FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC
ENV LANG=C.UTF-8

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    ca-certificates \
    curl \
    unzip \
    git \
    libgd3 \
    && apt-get install -y \
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

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./

RUN php -m | grep -i gd

RUN composer install \
    --optimize-autoloader \
    --no-scripts \
    --no-interaction

COPY . .

RUN chmod -R 775 storage bootstrap/cache

EXPOSE 8080

CMD ["sh", "start.sh"]
