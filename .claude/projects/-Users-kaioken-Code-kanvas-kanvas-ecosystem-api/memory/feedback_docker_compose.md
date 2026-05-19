---
name: Docker compose file
description: Use docker-compose.yml (not docker-compose.development.yml or docker-compose.local.yml) for all Docker commands
type: feedback
---

Use `docker-compose.yml` as the default compose file — do not use `docker-compose.development.yml` or `docker-compose.local.yml`.

**Why:** User corrected that they don't use the development compose file locally.

**How to apply:** When suggesting any docker-compose commands, use plain `docker-compose` (which defaults to `docker-compose.yml`) without `-f` flags.
