FROM dunglas/frankenphp as base

ENV SERVER_NAME="http://"

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    build-essential \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    locales \
    zip \
    jpegoptim optipng pngquant gifsicle \
    unzip \
    git \
    curl \
    lua-zlib-dev \
    libmemcached-dev \
    redis \
    vim

RUN chmod +x /usr/local/bin/install-php-extensions && sync && \
    install-php-extensions mbstring pdo_mysql zip exif pcntl gd memcached redis swoole opcache curl readline sqlite3 msgpack igbinary pcov sockets

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY Caddyfile /etc/caddy/Caddyfile

COPY . /app

WORKDIR /app

RUN composer install --optimize-autoloader

# COPY docker/weroad-php.ini $PHP_INI_DIR/conf.d/
COPY docker/docker-php-ext-opcache.ini $PHP_INI_DIR/conf.d/

RUN cp docker/php.ini $PHP_INI_DIR/php.ini; \
    sed -i 's/variables_order = "GPCS"/variables_order = "EGPCS"/' $PHP_INI_DIR/php.ini;

CMD /bin/bash -c "php artisan cache:clear \
    && frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile"