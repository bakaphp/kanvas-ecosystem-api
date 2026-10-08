# Playwright MCP service

This container runs Microsoft's official `@playwright/mcp` server. It does not
launch Chromium: `--endpoint=ws://browser:3000/playwright` connects it to the
existing Playwright Browser Server over Docker's internal network.

## Pinned versions

- Browser server: Playwright `1.63.0` (existing service).
- MCP server: `@playwright/mcp` `0.0.80`.
- MCP's Playwright client: `1.63.0-alpha-2026-08-31`.

Playwright requires the client and Browser Server to use the same major/minor
version. MCP `0.0.80` is the newest published MCP release on Playwright 1.63;
MCP `0.0.81` and newer use Playwright 1.64 alpha and are intentionally not used.
The lockfile makes this decision reproducible.

## Internal endpoints

- Playwright MCP: `http://playwright-mcp:8931/mcp`
- Existing Browser Server: `ws://browser:3000/playwright`

The development Compose file uses `expose`, not `ports`, so neither
endpoint is published to the host.

## Build and verify

From the repository root:

```bash
docker compose -f docker-compose.development.yml build playwright-mcp
docker compose -f docker-compose.development.yml up -d browser playwright-mcp php
docker compose -f docker-compose.development.yml ps
```

Run the standalone Node MCP client (independent of Kanvas):

```bash
docker compose -f docker-compose.development.yml run --rm \
  --no-deps playwright-mcp npm run test:connection
```

The test initializes MCP, lists tools, calls `browser_navigate` for
`https://example.com`, then calls `browser_snapshot`. It verifies `Example
Domain` and the page's main link. The current example.com content labels that
link `Learn more`; older content used `More information`.

Run the same flow through Kanvas's existing `laravel/mcp` client:

```bash
docker compose -f docker-compose.development.yml exec php \
  php artisan intelligence:test-playwright-mcp
```

Kanvas registers the client as `playwright`. Code running in a queue worker
should call `ClientManager::build('playwright')` once per agent/job, then
disconnect it in `finally`. Do not use the manager's cached `client()` method
for concurrent agent runs.

## MCP Inspector (development only)

Publish the MCP port only on loopback with the development override:

```bash
docker compose -f docker-compose.development.yml \
  -f docker-compose.mcp.dev.yml up -d playwright-mcp
npx @modelcontextprotocol/inspector
```

In Inspector, choose Streamable HTTP and enter:

```text
http://localhost:8931/mcp
```

Connect and open **Tools** to inspect and invoke the official tool set,
including `browser_navigate`, `browser_snapshot`, `browser_click`, and
`browser_type`. Stop using the override when inspection is complete to remove
the host port mapping:

```bash
docker compose -f docker-compose.development.yml \
  -f docker-compose.mcp.dev.yml down
```

## Configuration

```env
PLAYWRIGHT_MCP_PORT=8931
PLAYWRIGHT_MCP_URL=http://playwright-mcp:8931/mcp
PLAYWRIGHT_MCP_TIMEOUT=30
```

The MCP CLI binds to `0.0.0.0`, restricts accepted HTTP Host headers to the
internal service name and loopback development names, and uses `--isolated` so
MCP sessions do not request persistent profiles. The healthcheck only confirms
that the MCP TCP listener is available; the two smoke tests validate the full
MCP-to-Browser Server path.
