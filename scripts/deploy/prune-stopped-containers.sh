#!/bin/sh
# Docker-aware disk cleanup for the compose hosts — never delete from /var/lib/docker by hand.
#
# A stopped container keeps its whole writable layer (every file its jobs left in /tmp) until it is
# removed. Stopped queue containers are how a host reclaimed 76 GB with `docker container prune`.
# Scoped to this compose project so containers belonging to anything else on the host are untouched.
set -eu

PROJECT=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project"}}' phpkanvas-ecosystem)

docker container prune -f --filter "label=com.docker.compose.project=${PROJECT}"
docker image prune -f
