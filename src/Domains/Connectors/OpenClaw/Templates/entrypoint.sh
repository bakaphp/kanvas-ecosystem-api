#!/bin/sh
sudo service cron start
exec node /app/dist/index.js "$@"
