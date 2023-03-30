FROM mctekk/kanvasapp:8.2-alpine

# Set working directory
WORKDIR /app

# Add user for laravel application
RUN addgroup -g 1000 www
RUN adduser -u 1000 -s /bin/sh --disabled-password -G www www 

# Copy code to /var/www
COPY --chown=www:www-data . /app

# add root to www group
RUN chmod -R ug+w /app/storage

RUN cp docker/docker-php-ext-opcache.ini /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini
RUN cp docker/php.ini /usr/local/etc/php/conf.d/zx-app-config.ini

WORKDIR /var/www/html