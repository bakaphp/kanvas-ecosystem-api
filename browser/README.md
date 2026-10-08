# Playwright Browser Server

Contenedor reutilizable que ejecuta Chromium headless mediante el Browser Server de Playwright. No expone una API HTTP.

## Endpoint interno

Los contenedores de la misma red de Docker pueden conectarse a:

```text
ws://browser:3000/playwright
```

El puerto `3000` se expone en la imagen y en Compose, pero no se publica en el host.

La versión del paquete `playwright` y la etiqueta de la imagen oficial están fijadas en `1.63.0`. Al actualizar Playwright, ambas versiones deben cambiar juntas.

## Iniciar el servicio

Desde la raíz del repositorio:

```bash
docker compose -f docker-compose.development.yml up --build -d browser
docker compose -f docker-compose.development.yml logs browser
```

El log debe incluir:

```text
Chromium headless started
Playwright WebSocket endpoint: ws://0.0.0.0:3000/playwright
```

## Probar desde otro contenedor

Con el servicio activo, ejecute un contenedor cliente desechable en la misma red de Compose:

```bash
docker compose -f docker-compose.development.yml run --rm --no-deps \
  -e PLAYWRIGHT_WS_ENDPOINT=ws://browser:3000/playwright \
  browser npm run test:connection
```

La salida esperada es:

```text
Example Domain
```

La prueba conecta un proceso Playwright externo, crea un `BrowserContext`, abre una página, navega a `https://example.com`, valida el título, cierra el contexto y desconecta el cliente.

## Apagado

El proceso maneja `SIGTERM` y `SIGINT`. Antes de terminar cierra el Browser Server y Chromium de forma ordenada:

```bash
docker compose -f docker-compose.development.yml down
```
