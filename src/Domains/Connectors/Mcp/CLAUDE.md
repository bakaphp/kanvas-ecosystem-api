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
| Server definition | `integrations` (workflow DB) | `name` is the stable slug (`google_sheets_mcp`); `metadata` = `url`, `auth_methods`, `prefix`, `exclude`, `timeout_ms`, `oauth`, `url_per_connection`, `auth_query_param`, `auth_header`, `async_jobs`, `artifacts_handler`, `tool_arguments` |
| Catalog / grantable row | `nervous_system_tools` (intelligence) | human title (`Google Sheets`); `is_active = 0` hides it |
| Agent's grant + status | `nervous_system_agent_tools.config.mcp` | `{auth, status: active\|failed, last_error, connected_at}` |
| Agent's credential | agent custom field (ecosystem) | `mcp_credentials_{integrations_id}` = `{access_token, refresh_token, expires_at, server_url}` |
| Hand-made OAuth client | app settings | shared: `mcp_oauth_client_id_{client_key}` / `_secret_`; otherwise per server, suffixed with a hash of the address when self-hosted |
| Self-registered client | app settings | same prefixes + `mcp_oauth_redirect_uri_…`, `mcp_oauth_registration_endpoint_…` |
| Tool list, hot | Redis | `mcp:tools:{apps}:{companies}:{agents}:{integrations}:{toolVersion}`, plus breaker / lock / revalidate keys |
| Tool list, durable | `nervous_system_mcp_tool_snapshots` | per `agents_id` + `integrations_id`, `payload` JSON |
| OAuth endpoints | cache, **24 h** | `McpOAuthDiscoveryService` — call `->forget()` after changing a row's `oauth` block |
| Tool calls | ledger | `mcp.tool.invoked` (`tool`, `remote_tool`), actor = the agent |
| Background jobs | `nervous_system_mcp_async_jobs` (intelligence) | one row per job a tool started; `status` running/completed/failed/timed_out, `live_url`, `result`; ledger `mcp.async_job.*`. **The model is `NervousSystem\Capability\Models\McpAsyncJob`, not a connector class** — see the TODO below |

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
| `uses an OAuth client registered with the vendor by hand … set mcp_oauth_client_id_X` | The row declares `oauth.client_key`, so the client is hand-made by definition — and it is missing. We refuse to self-register rather than store a self-made client under a shared key | Create the vendor app with the callback the message names, set both app settings |
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
| Google Calendar | Google's setup guide lists only three **read-only** scopes, but the server publishes four tools that write (`create_event`, `update_event`, `delete_event`, `respond_to_event`). With read-only scopes each fails `403 insufficient_scope` while `list_events` works. The row adds `calendar.events`, the narrowest scope Google's challenge accepts for writes. Agents connected earlier must **reconnect** to gain it. A bad argument comes back as **HTTP 400 wrapping an `isError` result**; its text is the exception's reason. `attendees` is a list of `{email}` objects behind a `$ref` (a bare email list was KANVAS-ECOSYSTEM-6EC). |
| Google Analytics | Two servers: data answers on `/mcp/v1`, admin on `/mcp`. **Admin publishes no metadata**, so its row pins `authorization_server: https://accounts.google.com`. |
| Google Ads (official) | No hosted server — self-host only, read-only. `googleads.googleapis.com/mcp` has reserved metadata but every path 404s. |
| Meta Ads (official) | **Hidden.** Metadata advertises registration, then refuses it: "Dynamic registration is not available for this client". Use `Meta Ads (Pipeboard)`. |
| WhatsApp Business (official) | **Hidden**, key only. Same registration allow-list as Meta Ads (checked 2026-09-17, every payload refused). A fake bearer gets `403 Unauthorized Access`, which does not say whether a real system-user token from a non-allow-listed app is accepted. Activate the catalog row only after a real token completes `tools/list`. Webhook, payment and `system_user_token` tools are excluded on purpose. |
| Pipeboard (Meta, Google Ads) | A third party holds the advertiser's platform tokens. Google Ads is granted `mcp:read` only on purpose. |
| GitHub, DocuSign, HubSpot | No dynamic registration → hand-made client via `client_key`. DocuSign's metadata points at **production** `account.docusign.com`, so a demo-only key will not authorise. HubSpot's path is `/anthropic`; `/mcp` 404s. |
| Browserbase | Key goes in the query string (`auth_query_param: browserbaseApiKey`), appended at send time and redacted from errors. Reports "connected" even with a wrong key — the key is only checked when a browser opens. |
| Kernel | The one that behaves: 401 → `oauth-protected-resource/mcp` → `auth.onkernel.com` (Clerk-backed), whose registration endpoint accepted `{app.url}/v1/oauth/callback` first try — one click, no hand-made client, no pinned authorization server. **Do not add `offline_access`**: the resource advertises `openid` alone and anything else is `invalid_scope: Requested scope is not registered` (the opposite of Vercel). No scope parameter at all is accepted, so the row needs no `oauth` block. Authorizing goes through an org picker, so the token belongs to whichever Kernel org the person chooses. |
| Browserless | Token in the query string (`auth_query_param: token`), like Browserbase — and like it, a wrong token still completes `initialize` and lists all 14 tools; it is only checked when a browser runs. **`tools/list` needs the `Mcp-Session-Id` from the handshake** or the server answers with an empty list rather than an error (checked 2026-09-20, server 1.30.0). No `async_jobs`: `browserless_agent` is a loop OUR model drives, so a long flow is bounded by the per-turn MCP call budget, not by a background job. Saved logins live in its own profiles (`browserless_profiles` → pass the name as `profile`). |
| Browser Use | Key goes bare in its own header (`auth_header: x-browser-use-api-key`), not `Authorization: Bearer`. The 401 advertises OAuth metadata with a registration endpoint, but `/oauth/register` and `/oauth/authorize` 404 (checked 2026-09-19) — key only. `tools/list` answers without a key, so a wrong one first fails on `run_session`. `run_session`/`send_task` are `async_jobs`; `live_url` reads `null` once the session idles, so it is caught during the poll. |
| TikTok Ads | Points at the progressive `tt-ads-mcp-layer` endpoint — the flat one's ~400 tools overwhelm a prompt. Issuer is `{server}/oauth`, resolved through the OIDC-suffixed well-known. **Writes with no paused-by-default.** |
| Higgsfield | Must be `mcp.higgsfield.ai/mcp`; `higgsfield.ai/mcp` 307-redirects and the transport refuses redirects by design. Clerk registers the client. |
| Vercel | **Hidden.** Registration is allow-listed **by redirect URI**, which is how "only clients Vercel has approved" is enforced: `claude.ai/api/mcp/auth_callback` and `localhost` register, `{app.url}/v1/oauth/callback` gets `invalid_redirect_uri`. Re-probed 2026-09-20, unchanged — `api.vercel.com/login/oauth/register` still answers 201 for the two approved hosts and 400 for ours, so an `invalid_redirect_uri` here is never our bug. No bearer fallback either — a token in the header returns `401 "No authorization provided"`, so the server never reads it as a credential. Needs a hand-made client (`client_key: vercel`) whose redirect Vercel accepts; discovery itself is clean, so don't pin an authorization server. `offline_access` is added to the advertised `openid` or there is no refresh token. **Two things to check before assuming a hand-made client is enough**: the authorization server publishes no `none` in `token_endpoint_auth_methods_supported`, so the client is confidential and its secret must be set alongside the id (`client_secret_post` is what we send); and `response_modes_supported` is `web_message.opener` **alone** — a popup `postMessage` flow, not the query redirect our shared callback is, which may be the real shape of the approval. Prove both against a real Vercel client before flipping the catalog row active. The MCP path is the host root (`https://mcp.vercel.com`); `/mcp` and `/sse` 404. |
| Mailgun | **No hosted server** — `@mailgun/mcp-server` is stdio only, so it is self-hosted per connection behind an HTTP bridge (`supergateway --outputTransport streamableHttp`, path `/mcp`); the Mailgun key lives on the bridge. Bearer only. Webhook/route/tracking writes are excluded because Kanvas's native Mailgun inbound and tracking depend on them. Catalog title `Mailgun (MCP)`. |
| Atlassian | OAuth fails on Atlassian's side; the bearer (service-account key) method works. |
| n8n | `server_url` must be the resource its metadata names (e.g. `…/mcp-server/http`), not the instance root, or you get `invalid_target`. |
| Deel | Granted read-only scopes out of ~90 published; widening to payroll/payment writes is deliberate, not a fix. |
| Slack | No dynamic registration → hand-made Slack app (MCP enabled, redirect `{app.url}/v1/oauth/callback`) as `client_key: slack`; token endpoint wants `client_secret_post`. Issues a **user** token, so the agent posts as whoever signed in. Catalog title is `Slack (MCP)` — unrelated to the agent's bot channel. |

## Tools that start background jobs (`async_jobs`)

**Getting this wrong is not cosmetic.** Without the declaration the model polls the status tool itself,
and Neuron's per-turn run cap (10 identical calls) kills the turn with "I kept retrying the same lookup…"
while the vendor's job finishes unseen and unpaid-for work is thrown away.

Some tools start a job and return before it finishes — Browser Use's `run_session` ran ~10 minutes. Left to
the model, it polls the status tool until Neuron's per-turn run cap (10 identical calls) kills the turn with
"I kept retrying the same lookup…". A server row declares those tools under `metadata.async_jobs.{remote
tool}` (`status_tool`, `id_field`, `status_field`, `done_statuses`, `live_url_field`, `poll_seconds`,
`timeout_seconds`); an incomplete block is ignored.

The model lives in the Nervous System (`Capability\Models\McpAsyncJob`, beside `McpToolSnapshot`); only the
behaviour — `Start`/`FollowMcpAsyncJobAction`, `PollMcpAsyncJob`, `ResumeAgentFromMcpAsyncJob` — sits in this
connector, and `McpAsyncJobConfig` is metadata parsing like `McpServerConfig`.

`CachedMcpConnector::invokeTool` hands such a call to `StartMcpAsyncJobAction`, which records the job and
tells the model to stop and end its turn. `PollMcpAsyncJob` (`agent-chat` queue) re-dispatches itself every
`poll_seconds`; `FollowMcpAsyncJobAction` posts the **live URL into the conversation as soon as the vendor
reports one** (the person may need to log in there), and on a done status, timeout or 5 failed checks in a
row dispatches `ResumeAgentFromMcpAsyncJob`, which wakes the agent in the same session with
`resumeInstruction()` via `WakeAgentInSessionAction` (shared with scheduled agent tasks).

Nothing is handed off without a conversation to resume in (no session in the turn) or when the job already
finished — the model gets the vendor's own answer. Status checks go through `callRemoteTool`, which skips
the turn budget and the `mcp.tool.invoked` ledger.

### Arguments Kanvas pins (`tool_arguments`)

Some settings a vendor needs are not the model's business, and telling it in a prompt is advice it can
ignore at the person's expense. `metadata.tool_arguments.{remote tool}` is a map merged into every call
of that tool — **only keys the model left out**, so an explicit choice still wins.

What it is for: Kernel browsers prompt "Needs permission to download" until `chrome_policy`
(`DownloadRestrictions: 0`, `DefaultPopupsSetting: 1`) is set at creation; a Browser Use `model` the
account cannot use fails the whole turn. Both are config, not conversation.

Vendor-specific values that must be *computed* (a workspace id, a credential) belong in the artifacts
collector's `defaultArguments()` instead — it runs on the same hook and takes precedence.

### The files a job leaves behind (`artifacts_handler`)

A job's output must never travel through the conversation — a day's export would bury the turn, and
`MAX_RESULT_CHARS` would silently cut it. A server row can name a class implementing
`CollectsMcpJobArtifacts`, which does two things: it adds arguments Kanvas insists on when a job starts
(only filling keys the model left out), and it lists the files the finished job produced.

`FollowMcpAsyncJobAction` pulls those files into Kanvas the moment the job ends and attaches them to a
**plan** (`plan_type: mcp_job`, pointing back at the job row) — addressable, so the agent can hand it to
another agent or a later run. The agent is told the plan id and the file names, never the contents.
Download links are short-lived (Browser Use: 60s for workspace files, 15 min for browser downloads) AND
signed with the vendor's own credentials, so each file is **downloaded into Kanvas storage**
(`FilesystemServices::uploadFileFromUrl`, which is SSRF-guarded) before it is attached. Do not reach for
`addFileFromUrl()` / `addMultipleFilesFromUrl()` here: they only record the url, so the plan ends up
holding a presigned link that is dead a minute later and needs the vendor's account to open.

Browser Use is the reference: a session writes to a sandbox that dies with it, so
`BrowserUseArtifactCollector` attaches the company's workspace (created once, kept in company config as
`browser_use_workspace_id`) to every `run_session` — `send_task` inherits the session's — and collects
both what the agent wrote (workspace files) and what the browser downloaded (an Export button, which
hangs off the BROWSER session, a different id reached via `agentSessionId`). **The workspace is per
company and permanent**, so what it returns is filtered to files modified since the job started —
without that bound, every run re-attaches every file the company ever produced.

Related but separate: PiDev's `PollPiDevJobJob` also follows a remote job, but mirrors it onto a Plan task
rather than resuming a conversation — different target, not a duplicate to merge.

## Timeouts

`metadata.timeout_ms` is the per-call ceiling. The default is 20 s (CRUD-style servers). Search and reporting
servers (Tavily, Analytics, ads, n8n, SQL Server, SAP, Vercel — docs search, runtime logs and Agent Run
traces) use 30 s. Anything that renders or searches heavily uses
60 s: Browserbase, Playwright, Higgsfield, and **Sentry**, whose `search_events` translates natural language
server-side and overran 20 s. **Browserless sits at 130 s**, deliberately above its own 120 s session
timeout: `browserless_agent` holds a session open across calls, and giving up first abandoned a result the
account had already been billed for.

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
7. **Decide whether any of its tools start a background job** — see the section below. The tells, in the
   `tools/list` you just probed: a tool whose description says it returns before the work finishes ("poll
   for completion", "check status", "long-running"), a **second tool that reports on the first** by id
   (`get_*`, `*_status`), or a result carrying an id plus a `status` that is not terminal. Anything driving
   a browser, a render, a crawl or an export is a candidate. Confirm by calling it once with a real key and
   reading the result — Browser Use's `run_session` answers in a second and the work takes ten minutes.
   Declare those under `metadata.async_jobs` in the same migration; a server whose tools all answer with
   the finished work needs nothing.

## TODO — move this connector into the Nervous System

**A connector must not own a table or a model the rest of the platform reads.** That is why
`McpAsyncJob` and `McpAsyncJobStatusEnum` live under `NervousSystem\Capability` even though the jobs and
actions driving them are here, and why `CachedMcpConnector` already sits in `Intelligence\Agents`. MCP is
not really an external service like Shopify — it is how agents hold capabilities, so the tree belongs in
the Nervous System (`NervousSystem\Mcp` or `Capability\Mcp`), leaving `Connectors/Mcp` at most a thin
handler.

Not done yet because it is a wide rename across a tree that is in production (grants, credentials, the
OAuth receiver, every `integrations.handler` string pointing at `McpHandler`, and the per-agent credential
keys). Revisit when the next change touches this tree broadly; until then **do not add new models or
migrations under `Connectors/Mcp`** — put them in `NervousSystem\Capability` as the async-job ones are.

The async-job table itself is deliberate rather than permanent: reusing `ScheduledAction` with a handler
per action type is the agreed direction once the feature matures.

## Known limits — not bugs, don't "fix" in passing

- **A tool-level error kills the whole agent turn.** Some vendors wrap a well-formed MCP error result in HTTP 403
  (Google), and timeouts throw. Both surface as a dead turn instead of reaching the model. Changing that touches
  the path that drives `markFailed` and credential deletion, so it needs a deliberate decision.
- **`McpToolSchema` inlines local `$ref`s, but a recursive one stops at the first repeat.** Past that point
  the nested object travels as a JSON string and is decoded before the call (Google Analytics' filter
  expressions). `readOnly` fields are never offered to the model. Remote (`https://…`) refs stay unresolved.
- **`auth_methods: ["none"]` is not rendered by the frontend yet.** Microsoft Learn and self-hosted Playwright
  cannot be connected from the UI until it is.
- **Not added, on purpose:** LinkedIn (no official server; community options breach its User Agreement §8.2) and
  Mailchimp Marketing (no official server; Mandrill's endpoint is not MCP).
- **Overlaps with native connectors** (Tavily, Google Sheets, Salesforce, Shopify, Zoho, WordPress, Microsoft):
  grant one or the other per agent, never both.
- `2026_09_10_100002_make_atlassian_mcp_token_optional` shipped although later migrations superseded it. It is
  harmless and must not be deleted now that it has run in production.
