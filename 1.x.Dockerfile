FROM unit:php8.4

# Add the docker-php-extension-installer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Install PHP extensions
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions mbstring pdo_mysql zip exif pcntl gd memcached redis swoole opcache curl readline sqlite3 msgpack igbinary pcov sockets bcmath soap

# Install required dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    locales \
    zip \
    jpegoptim \
    optipng \
    pngquant \
    gifsicle \
    unzip \
    git \
    curl \
    lua-zlib-dev \
    libmemcached-dev \
    nginx \
    vim \
    wkhtmltopdf \
    xvfb && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# Copy application files
COPY . /var/www/html/

# Set the working directory
WORKDIR /var/www/html/

# Install Composer globally
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set appropriate ownership and permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html/ && \
    chmod -R 777 /var/www/html/storage/ && \
    chmod -R 777 /var/www/html/storage/logs/

# Copy configuration files
COPY ./docker/unit.json /docker-entrypoint.d/
COPY docker/docker-php-ext-opcache-prod.ini /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini
COPY docker/php.ini /usr/local/etc/php/conf.d/zx-app-config.ini

# Configure Git to allow safe directory for the project
RUN git config --global --add safe.directory /var/www/html

# Install PHP dependencies for production
RUN composer install --no-dev --optimize-autoloader

# Expose the required port
EXPOSE 8000