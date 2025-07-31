FROM php:8.4.10-cli

# Add docker PHP extension installer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions mbstring pdo_mysql zip exif pcntl gd memcached redis swoole opcache curl readline sqlite3 msgpack igbinary pcov sockets bcmath soap imagick

# Install additional dependencies
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
    xvfb \
    ffmpeg && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# Install Node.js and chokidar for file watching
RUN curl -fsSL https://deb.nodesource.com/setup_18.x | bash - && \
    apt-get install -y nodejs && \
    npm install -g chokidar-cli

# Copy application files
COPY . /var/www/html/

WORKDIR /var/www/html/

# Install chokidar locally for Laravel Octane with debugging
RUN echo "Installing chokidar for Laravel Octane..." && \
    npm init -y && \
    npm install chokidar@^3.5.3 && \
    echo "Verifying chokidar installation..." && \
    ls -la node_modules/ | grep chokidar && \
    node -e "console.log('chokidar version:', require('chokidar/package.json').version)" && \
    echo "NODE_PATH: $NODE_PATH" && \
    echo "Current directory: $(pwd)" && \
    echo "node_modules exists: $(test -d node_modules && echo 'YES' || echo 'NO')"

# Install composer globally
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set appropriate ownership for the application
RUN chown -R www-data:www-data /var/www/html

# Copy configuration files
# COPY ./docker/unit.json /docker-entrypoint.d/
COPY docker/docker-php-ext-opcache-prod.ini /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini
COPY docker/php.ini /usr/local/etc/php/conf.d/zx-app-config.ini

# Set git safe directory
RUN git config --global --add safe.directory /var/www/html

# Set proper permissions
RUN chmod -R 755 /var/www/html/ && \
    chmod -R 777 /var/www/html/storage/ && \
    chmod -R 777 /var/www/html/storage/logs/

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Expose the required port
EXPOSE 8000

# Keep container running without starting a web server
CMD ["tail", "-f", "/dev/null"]