#!/bin/sh

docker exec -i phpkanvas-ecosystem php artisan lighthouse:cache
docker exec -i phpkanvas-ecosystem php artisan config:cache