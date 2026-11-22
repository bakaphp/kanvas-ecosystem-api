# Deploy host scripts

Run on the compose hosts, from the repo directory. The deploy workflows live in `.github/workflows/*-deploy.yaml`.

## Updating nginx and the PHP base image — `update-images.sh`

```bash
sh scripts/deploy/update-images.sh docker-compose.1.x.yml           # prod
sh scripts/deploy/update-images.sh docker-compose.development.yml   # dev
```

**A deploy never updates a base image.** `up -d` reuses whatever copy of a tag the host already
holds, so `nginx:latest` stays at the version first pulled and the PHP image keeps its old base until
someone runs this. Reach for it when nginx, PHP or a Debian package ships a fix.

It pulls `nginx:latest`, rebuilds the PHP image with `--pull`, then replaces only the running
containers whose image changed. Nothing changed means nothing restarts.

- **A changed PHP image restarts the API, every queue worker and the scheduler**, since they all run
  `php-app-image`. Same interruption as a deploy; pick the moment.
- **It does not change the PHP version.** The Dockerfiles pin `FROM php:x.y.z-cli`; a new PHP
  release is an edit to that line in both `1.x.Dockerfile` and `development.Dockerfile`.
- **`--no-cache` as a second argument** reruns the apt layers too. Needed for a Debian package fix
  when the php base tag did not move, because Docker otherwise reuses the cached layers.
- **Never swap its remove-then-create for `--force-recreate`.** The 1.x host runs docker-compose
  1.29, which dies with `KeyError: 'ContainerConfig'` recreating a container onto an image from a
  newer engine, and leaves the old container stopped and renamed.
- A config reload is not an upgrade: nginx keeps the old binary until its container is replaced.

## `prune-stopped-containers.sh`

Called by every deploy. Removes this compose project's stopped containers and dangling images;
never delete from `/var/lib/docker` by hand.
