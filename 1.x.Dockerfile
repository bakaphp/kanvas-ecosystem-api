FROM unit:php8.3

COPY ./docker/unit.json /docker-entrypoint.d/

# Add docker php ext repo
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Install php extensions
RUN chmod +x /usr/local/bin/install-php-extensions && sync && \
    install-php-extensions mbstring pdo_mysql zip exif pcntl gd memcached redis swoole opcache curl readline sqlite3 msgpack igbinary pcov sockets bcmath

# Install dependencies
RUN apt-get update && apt-get install -y \
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
    nginx \
    vim

# Set working directory
COPY . /var/www/html/
# COPY chown -R unit:unit /var/www/html/

# add root to www group
# RUN chmod -R ug+w var/www/html/storage
# RUN cp docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-php-fpm-production.conf

WORKDIR /var/www/html/

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer


# add root to www group
# RUN chmod -R ug+w /app/storage

RUN cp docker/docker-php-ext-opcache-prod.ini /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini
RUN cp docker/php.ini /usr/local/etc/php/conf.d/zx-app-config.ini
# RUN cp docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-php-fpm-production.conf

RUN chmod -R 755 /var/www/html/
RUN chmod -R 777 /var/www/html/storage/
RUN chmod -R 777 /var/www/html/storage/logs/

RUN composer install --no-dev --optimize-autoloader

EXPOSE 8000
