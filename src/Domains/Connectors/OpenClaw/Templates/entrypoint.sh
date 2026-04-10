#!/bin/sh
sudo service cron start
exec "$@"
