#!/bin/sh
# Refresh the base images on a compose host: pull nginx, rebuild the PHP image on the newest
# php base, then replace only the running containers whose image actually changed.
#
#   sh scripts/deploy/update-images.sh docker-compose.1.x.yml
#   sh scripts/deploy/update-images.sh docker-compose.development.yml --no-cache
#
# A deploy never does this: `up -d` reuses whatever copy of a tag the host already has.
# --no-cache also reruns the apt layers, picking up Debian fixes when the php base did not move.
set -eu

FILE=${1:?usage: update-images.sh <compose-file> [--no-cache]}
NO_CACHE=${2:-}

# Same compose binary the deploy workflows use for each file, so both act on one project.
if [ "$FILE" = "docker-compose.1.x.yml" ]; then
    COMPOSE="docker-compose -f $FILE"
else
    COMPOSE="docker compose -f $FILE"
fi

$COMPOSE pull nginx
$COMPOSE build --pull $NO_CACHE php

STALE=""
for SERVICE in $($COMPOSE config --services); do
    CONTAINER=$($COMPOSE ps -q "$SERVICE" | head -n 1)
    [ -n "$CONTAINER" ] || continue
    [ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER")" = "true" ] || continue

    TAG=$(docker inspect -f '{{.Config.Image}}' "$CONTAINER")
    RUNNING=$(docker inspect -f '{{.Image}}' "$CONTAINER")
    CURRENT=$(docker image inspect -f '{{.Id}}' "$TAG")

    [ "$RUNNING" = "$CURRENT" ] || STALE="$STALE $SERVICE"
done

if [ -z "$STALE" ]; then
    echo "Every running container is already on the current image."
    exit 0
fi

echo "Replacing:$STALE"

# Remove then create instead of --force-recreate: docker-compose 1.29 dies with
# KeyError 'ContainerConfig' when it recreates a container onto an image from a newer engine.
$COMPOSE rm -sf $STALE
$COMPOSE up -d --no-deps $STALE

docker image prune -f
