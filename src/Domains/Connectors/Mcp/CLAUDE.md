# Remote MCP servers — production debugging reference

Loads when work touches `src/Domains/Connectors/Mcp/`. **In production** since PR #11248 (merge `05cb86b76`,
released via #11249). Read this before debugging a connection, a failed turn, or a vendor rejection — most of
what looks like our bug here has turned out to be a vendor gate, and each one below cost an evening to find.

## The model in one paragraph

An MCP server is an `integrations` row (`type = mcp`, behaviour in `metadata`) paired with a
`nervous_system_tools` row (`tool_type = mcp`, `integrations_id`). **Every connection belongs to one agent** —
there is no company-level credential. Connecting grants the tool; turning the tool off deletes that agent's
credential (`AgentToolObserver`). At turn time `MergesRegisteredTools` expands the grant into a
`RemoteMcpToolkit`, whose `CachedMcpConnector` builds tools from a cached `tools/list` and calls the server
through `GuardedHttpMcpTransport`.

## Where every piece of state lives

| What | Where | Key / shape |
|---|---|---|
| Server definition | `integrations` (workflow DB) | `name` is the stable slug (`google_sheets_mcp`); `metadata` = `url`, `auth_methods`, `prefix`, `exclude`, `timeout_ms`, `oauth`, `url_per_connection`, `auth_query_param` |
| Catalog / grantable row | `nervous_system_tools` (intelligence) | human title (`Google Sheets`); `is_active = 0` hides it |
| Agent's grant + status | `nervous_system_agent_tools.config.mcp` | `{auth, status: active\|failed, last_error, connected_at}` |
| Agent's credential | agent custom field (ecosystem) | `mcp_credentials_{integrations_id}` = `{access_token, refresh_token, expires_at, server_url}` |
| Hand-made OAuth client | app settings | shared: `mcp_oauth_client_id_{client_key}` / `_secret_`; otherwise per server, suffixed with a hash of the address when self-hosted |
| Self-registered client | app settings | same prefixes + `mcp_oauth_redirect_uri_…`, `mcp_oauth_registration_endpoint_…` |
| Tool list, hot | Redis | `mcp:tools:{apps}:{companies}:{agents}:{integrations}:{toolVersion}`, plus breaker / lock / revalidate keys |
| Tool list, durable | `nervous_system_mcp_tool_snapshots` | per `agents_id` + `integrations_id`, `payload` JSON |
| OAuth endpoints | cache, **24 h** | `McpOAuthDiscoveryService` — call `->forget()` after changing a row's `oauth` block |
| Tool calls | ledger | `mcp.tool.invoked` (`tool`, `remote_tool`), actor = the agent |

Never print a credential while debugging — check presence (`rawToken() !== null`), not the value.

## Log lines and what they mean

| You see | Meaning | Next step |
|---|---|---|
| `MCP server returned an error status` (warning) | Vendor answered ≥ 400. The **`body` field is the vendor's response** and usually names the cause outright | Read `body` first — it is where "API not enabled in project N" and similar live |
| `MCP server rejected the credential (HTTP 401/403). <reason>` | `McpAuthException`. On refresh/connect this marks the grant `failed` and strips the capability | 403 is often **not** the token — see the Google rows below |
| `MCP server returned HTTP 5xx` / `MCP request failed: cURL error 28` | `McpFetchException`. The cache serves the last good snapshot and opens the breaker | Timeout → raise `metadata.timeout_ms` (see Timeouts) |
| `MCP OAuth refresh failed` | Refresh token rejected; the agent must reconnect | `invalid_grant` = revoked, or the OAuth client was re-registered underneath it |
| `MCP client registration was rejected (HTTP 400). <reason>` | The vendor refused RFC 7591 registration | Vendor gate (Meta) — nothing on our side |
| `does not support dynamic client registration … set mcp_oauth_client_id_X` | No `registration_endpoint`; needs a hand-made client | Create the vendor app, set both app settings |
| Gemini `400 … function_declarations[N].parameters …` | One tool's schema broke the whole turn | `McpToolSchema` conversion — see Known limits |

## Symptom playbook

- **Consent page 404s on `https://<mcp-host>/authorize`.** The host publishes no OAuth metadata, so discovery
  fell back to the spec default. Pin `metadata.oauth.authorization_server` and **forget the discovery cache**,
  or the bad endpoint survives a day. (Happened with Google Analytics Admin.)
- **`redirect_uri_mismatch`.** Register `{app.url}/v1/oauth/callback` exactly in the vendor app. There is one
  callback for every receiver (`OAuthIntegrationController::callbackByState`, receiver found from `state`).
- **A tool call fails with "caller does not have permission" but the token is fine.** Prove the token with
  the vendor's regular REST API using the same credential. If REST works, the gate is the vendor's MCP layer:
  preview enrollment or a disabled API, not us.
- **The agent passes an id like `properties/0`.** It invented a placeholder because it has no discovery tool.
  Connect the sibling server that lists real ids (Google Analytics Admin).
- **Tools missing, or stale after a vendor change.** `refreshNervousSystemMcpTools(tool_id, agent_id)`, or
  `php artisan kanvas:mcp:refresh-tool-cache --integration=<id>` for every agent on a server.
- **"Unknown argument agent_id on field mcp" right after a deploy.** Octane workers still hold the old schema —
  reload them. Not a code bug.
- **Every agent on one server flips to `failed` at once.** Vendor outage or revoked app, not individual tokens —
  compare `last_error` across the grants before telling anyone to reconnect.

## Vendor quirks — verified live, don't re-debug

| Server | Quirk |
|---|---|
| Google Workspace + Analytics | One hand-made client shared through `client_key: google`. **Drive and Sheets MCP need the Cloud project enrolled in the Workspace Developer Preview**: Sheets says so, Drive only says "caller does not have permission". Each underlying API must also be enabled (Analytics Data API failed on this). |
| Google Calendar | Google's setup guide lists only three **read-only** scopes, but the server publishes four tools that write (`create_event`, `update_event`, `delete_event`, `respond_to_event`). With read-only scopes each fails `403 insufficient_scope` while `list_events` works. The row adds `calendar.events`, the narrowest scope Google's challenge accepts for writes. Agents connected earlier must **reconnect** to gain it. |
| Google Analytics | Two servers: data answers on `/mcp/v1`, admin on `/mcp`. **Admin publishes no metadata**, so its row pins `authorization_server: https://accounts.google.com`. |
| Google Ads (official) | No hosted server — self-host only, read-only. `googleads.googleapis.com/mcp` has reserved metadata but every path 404s. |
| Meta Ads (official) | **Hidden.** Metadata advertises registration, then refuses it: "Dynamic registration is not available for this client". Use `Meta Ads (Pipeboard)`. |
| Pipeboard (Meta, Google Ads) | A third party holds the advertiser's platform tokens. Google Ads is granted `mcp:read` only on purpose. |
| GitHub, DocuSign, HubSpot | No dynamic registration → hand-made client via `client_key`. DocuSign's metadata points at **production** `account.docusign.com`, so a demo-only key will not authorise. HubSpot's path is `/anthropic`; `/mcp` 404s. |
| Browserbase | Key goes in the query string (`auth_query_param: browserbaseApiKey`), appended at send time and redacted from errors. Reports "connected" even with a wrong key — the key is only checked when a browser opens. |
| TikTok Ads | Points at the progressive `tt-ads-mcp-layer` endpoint — the flat one's ~400 tools overwhelm a prompt. Issuer is `{server}/oauth`, resolved through the OIDC-suffixed well-known. **Writes with no paused-by-default.** |
| Higgsfield | Must be `mcp.higgsfield.ai/mcp`; `higgsfield.ai/mcp` 307-redirects and the transport refuses redirects by design. Clerk registers the client. |
| Atlassian | OAuth fails on Atlassian's side; the bearer (service-account key) method works. |
| n8n | `server_url` must be the resource its metadata names (e.g. `…/mcp-server/http`), not the instance root, or you get `invalid_target`. |
| Deel | Granted read-only scopes out of ~90 published; widening to payroll/payment writes is deliberate, not a fix. |
| Slack | No dynamic registration → hand-made Slack app (MCP enabled, redirect `{app.url}/v1/oauth/callback`) as `client_key: slack`; token endpoint wants `client_secret_post`. Issues a **user** token, so the agent posts as whoever signed in. Catalog title is `Slack (MCP)` — unrelated to the agent's bot channel. |

## Timeouts

`metadata.timeout_ms` is the per-call ceiling. The default is 20 s (CRUD-style servers). Search and reporting
servers (Tavily, Analytics, ads, n8n, SQL Server, SAP) use 30 s. Anything that renders or searches heavily uses
60 s: Browserbase, Playwright, Higgsfield, and **Sentry**, whose `search_events` translates natural language
server-side and overran 20 s.

## Adding or changing a server

Two migrations per server: an `integrations` row (workflow) and a catalog row (intelligence). Before writing
either, **probe the live endpoint**. Vendor docs were wrong or silent more often than right:

1. POST an `initialize` → expect `401` with a `WWW-Authenticate` naming `resource_metadata`.
2. Fetch the protected-resource metadata, then the authorization server's metadata. Discovery tries RFC 8414
   path-inserted, then both OIDC placements.
3. A `registration_endpoint` means one click. None means a hand-made client plus `client_key`. A listed
   registration endpoint can still refuse (Meta) — try one.
4. Request only the scopes the resource advertises. Scopes it never asked for are how consent screens start refusing.
5. Check the catalog title against existing tool names: grants resolve by label, so a collision makes them
   ambiguous. That is why the calendar row is `Google Calendar (MCP)`.
6. After changing a row's `oauth` block in place, forget its discovery cache.

## Known limits — not bugs, don't "fix" in passing

- **A tool-level error kills the whole agent turn.** Some vendors wrap a well-formed MCP error result in HTTP 403
  (Google), and timeouts throw. Both surface as a dead turn instead of reaching the model. Changing that touches
  the path that drives `markFailed` and credential deletion, so it needs a deliberate decision.
- **`McpToolSchema` does not resolve `$ref` / `$defs`.** Referenced parameters reach the model as plain strings.
  That is Gemini-safe but lossy, and it blunts Google Analytics' report tool.
- **`auth_methods: ["none"]` is not rendered by the frontend yet.** Microsoft Learn and self-hosted Playwright
  cannot be connected from the UI until it is.
- **Not added, on purpose:** LinkedIn (no official server; community options breach its User Agreement §8.2) and
  Mailchimp Marketing (no official server; Mandrill's endpoint is not MCP).
- **Overlaps with native connectors** (Tavily, Google Sheets, Salesforce, Shopify, Zoho, WordPress, Microsoft):
  grant one or the other per agent, never both.
- `2026_09_10_100002_make_atlassian_mcp_token_optional` shipped although later migrations superseded it. It is
  harmless and must not be deleted now that it has run in production.
