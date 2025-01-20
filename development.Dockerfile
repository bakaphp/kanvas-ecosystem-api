FROM mctekk/kanvasapp:8.4-unit

COPY ./docker/unit.json /docker-entrypoint.d/

COPY . /var/www/html/
# COPY chown -R unit:unit /var/www/html/

# add root to www group
# RUN chmod -R ug+w var/www/html/storage
# RUN cp docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-php-fpm-production.conf

RUN git config --global --add safe.directory /var/www/html

RUN cp docker/docker-php-ext-opcache-prod.ini /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini
RUN cp docker/php.ini /usr/local/etc/php/conf.d/zx-app-config.ini

RUN chmod -R 755 /var/www/html/
RUN chmod -R 777 /var/www/html/storage/
RUN chmod -R 777 /var/www/html/storage/logs/


RUN composer install --optimize-autoloader

EXPOSE 8000