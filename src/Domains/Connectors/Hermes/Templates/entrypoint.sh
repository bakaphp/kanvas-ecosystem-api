#!/bin/sh
sudo service cron start
exec node /app/hermes.mjs "$@"
