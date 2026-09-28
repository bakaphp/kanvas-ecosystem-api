# pi.dev connector — Kanvas Ecosystem API

Loads when work touches `src/Domains/Connectors/PiDev/`. pi.dev is a **stateless coding-agent job
queue** over HTTP (bearer auth): `POST /agents/work` clones a GitHub repo, runs an agent on a task,
opens a PR, finishes; you poll `GET /agents/work/:jobId`, stream SSE events, and `POST …/cancel`.

Full design + decisions: [`docs/connectors/pidev-connector-plan.md`](../../../../docs/connectors/pidev-connector-plan.md).

## It's a plain HTTP connector, NOT an AgentRuntime provider

pi.dev runs **one-shot jobs**, not tenant-owned container deployments — there's no lifecycle to
manage, no telemetry/logs/config-sync/exec. So it does **not** implement `AgentRuntimeProvider` and
has **no `pidevLaunch*` graph**. It's a WaSender-shaped connector. (Contrast OpenClaw/Hermes, which
ARE runtimes — see the parent [`Connectors/CLAUDE.md`](../CLAUDE.md).) If you catch yourself adding a
provider/deployment concept here, stop — that's the wrong shape.

## Where each credential lives (and the casing rule)

| Setting | Scope | Key | Enum |
|---|---|---|---|
| pi.dev endpoint | **App** `$app->set` | `pidev_base_url` | `ConfigurationEnum` (lowercase) |
| Kanvas→pi.dev bearer | **Company** `$company->set` | `pidev_api_token` | `ConfigurationEnum` (lowercase) |
| GitHub PAT | **Agent** `$agent->set` | `PIDEV_GITHUB_TOKEN` | `CustomFieldEnum` (UPPERCASE) |
| Repo allow-list (JSON) | **Agent** `$agent->set` | `PIDEV_ALLOWED_REPOS` | `CustomFieldEnum` (UPPERCASE) |
| Persona / system prompt | **Agent** `$agent->set` | `PIDEV_SYSTEM_PROMPT` | `CustomFieldEnum` (UPPERCASE) |

- **The GitHub token + repos are AGENT-scoped, not company-scoped** — each agent carries a
  least-privilege PAT for only its own repos. Read agent-first.
- **`ConfigurationEnum` = app/company settings (lowercase, `pidev_*`); `CustomFieldEnum` = agent
  custom fields (UPPERCASE, `PIDEV_*`)** — matches the wider connector convention (e.g. Calendly).
  Don't put the GitHub token on `ConfigurationEnum`.
- The GitHub token is **never logged**. It's stored as an agent custom field and set/read through
  the generic agent-settings surface (`setAgentSetting` + `AgentAi.custom_fields`) — same as any other
  agent config; there is no bespoke pi.dev GraphQL.

## Rules of engagement are enforced in tiers — the prompt is the weakest

pi.dev's `systemPrompt` **replaces** its defaults, so it's guidance, not a control. Order of trust:

1. **Hard (real boundary):** the agent's PAT should be a **fine-grained token scoped to only its
   allow-listed repos** (Contents + PR write; no admin/workflow/delete) + branch protection. A rogue
   or prompt-injected coding agent then physically can't touch another repo or merge to main.
2. **Kanvas gate:** `RepoAllowListService::resolve()` — the LLM only ever passes a repo **slug**,
   resolved against the agent's closed allow-list; anything else throws before any HTTP call.
3. **Prompt:** `PromptBuilder::build()` assembles locked policy → per-repo rules → agent persona.

Never loosen tier 2 into a free-typed repo URL — that's the destination-safety rule from
[`Agents/CLAUDE.md`](../../Intelligence/Agents/CLAUDE.md).

## A coding job IS a Plan + Task — no bespoke table

A coding job reuses the **Nervous System Plan/Task** engine (there is no `pidev_coding_jobs` table).
`DispatchCodingJobAction` creates one **Plan** (`plan_type='coding_job'`, owned by the agent) with one
**Task**; the pi.dev linkage rides in **Task custom fields** (`TaskCustomFieldEnum`:
`PIDEV_JOB_ID` / `PIDEV_REPO_SLUG` / `PIDEV_REPO_URL` / `PIDEV_STATUS` / `PIDEV_PULL_REQUEST_URL` /
`PIDEV_AUTO_RETRY_COUNT`),
exactly mirroring the Kanban sync. This is deliberate: pi.dev's own store is in-memory and 404s known
jobs after a redeploy, so the Task is Kanvas' durable record, and jobs show up in the existing
plan/task UI + ledger for free.

**Status mapping** (`JobStatusEnum::toTaskStatus()`) — Task has no first-class failed/cancelled:

| pi.dev | Task status |
|---|---|
| queued | pending |
| running | in_progress |
| completed | done (+ `result` = summary + PR url) |
| failed | **blocked** (+ `blocked_reason`) — unless it is a retryable provider error, see below |
| cancelled | skipped |

**A `failed` job carrying `errorCode: "provider_error"` is not blocked — it is retried.** That code
means pi.dev's *upstream* refused the run (an Anthropic usage cap, a 5xx, an overloaded model), not
that the task was wrong: `usage` comes back all-zero at turn 1, nothing was charged, and the identical
payload succeeds once the condition clears. `PiDevJob::isRetryable()` is the test. The poller then
schedules `RetryPiDevJobJob` on a widening backoff (2min / 15min / 1h, `MAX_PROVIDER_RETRIES = 3`),
spending the `PIDEV_AUTO_RETRY_COUNT` budget, and posts a plan comment each time. Only once that
budget is gone does the task actually go blocked. Every other failure blocks on the first try —
re-running a bad clone or an impossible task would only repeat it.

The check runs **before** `mirrorOntoTask`, deliberately: mirroring would set the task BLOCKED, which
`taskIsTerminal()` treats as final, and the poller would refuse to follow the retried job.

- **The poller (`PollPiDevJobJob`) rides the existing `agent-runtime` queue.**
  `overwriteAppService($this->app)` is the first line of `handle()`. It mirrors pi.dev state onto the
  Task: fine-grained status + PR URL into custom fields, coarse status via `UpdateTaskStatusAction`
  (which stamps timestamps, rolls up the plan, emits `plan.task.*` ledger events, **and broadcasts
  `plan.changed` live over Pusher** on `company-{c}-app-{a}-plan-{id}` / `…-plans` /
  `…-agent-{id}-plans`). On terminal, `finalizePlan()` also calls
  `$plan->broadcastChange(UPDATED, …)` explicitly — it flips `plan.status` via `saveQuietly()` (no
  model events), so without the explicit call the board would show the task done but the plan header
  stuck on `active` until refresh.
- **Result posted as a plan comment + owner notification.** On terminal the poller (`announce()`) posts
  a human-readable summary (PR link, or failure/cancel reason) onto the plan's **Activities channel** via
  `PostPlanActivityMessageAction`, AND notifies the plan's **human owner** out-of-band (`database` + `mail`
  + `push`) via `PlanProgressNotification` — so whoever dispatched hears back without watching the UI. The
  plan owner is the **requesting human** (`DispatchCodingJobAction`'s `requestedBy`, passed by the tool as
  the conversation user), falling back to the agent's user for autonomous runs. That owner is also what
  makes `PlanObserver` create the Activities channel. Best-effort: a missing channel/notify never breaks
  polling.
- Poll loop re-dispatches every **30s (2/min) to terminal, caps at 62 attempts (~31min)** — just past
  pi.dev's own 30-min limit so we capture its terminal state — and treats a **404 mid-poll as BLOCKED**
  (pi.dev forgot the job).
- **Progress → plan comments (not a custom-field blob).** Each tick the poller drains pi.dev's SSE
  `/events` (`Client::fetchJobEvents`, bounded ~4s read via `?after=<cursor>`) and posts the agent's NEW
  `text` narration since last tick as a single progress comment on the plan's Activities channel. Raw
  `tool_start`/`tool_end` pings are dropped to keep the feed readable. A `PIDEV_EVENTS_CURSOR` custom
  field (just the last event id) prevents re-posting the replay. Best-effort/isolated — a flaky stream
  never breaks polling. There is no progress-log tool or blob: humans read progress in the plan feed,
  and the agent recalls its finished jobs from the **ledger** (`read_my_ledger` + the
  `plan.task.completed` events the poller emits, whose result carries the PR URL).

## The agent tools (`Neuron/Tools/Coding/`)

`check_coding_setup` / `dispatch_coding_task` / `check_coding_job_status` / `list_my_coding_jobs` /
`cancel_coding_job` / `retry_coding_job`. They take the running
`Agent` by constructor injection, return `['status'=>…]` (never throw). The `job_id` they pass around
is the **Task id** (int); check/cancel resolve it via `ResolvesTaskForTool` and then verify
`task->agent_id === agent->getId()` — one agent can't see or cancel another's job. Registered by
`kanvas:nervous-system:sync-tools`.

The **agent type** that ships these tools intrinsically is
[`ProgrammingAgent`](../../Intelligence/Agents/Neuron/Coding/ProgrammingAgent.php) (`#[AgentTypeDefinition]`,
name **"pi.dev Programming Agent"**, extends `SystemUserAgent` so it's a mentionable teammate with its
own user + ledger memory). Synced via `kanvas:intelligence:sync-agent-types` (create-only — to change
its name/soul later, delete the row and re-sync, or update the row directly). Note a separate
Hermes-provider "Programming Agent" row exists — this one is the Neuron/pi.dev variant.

## Gotchas

- **`PiDevApiException` carries the HTTP `status` as a property.** Kanvas' `ValidationException`
  stores its 2nd ctor arg as `reason`, not the exception code, so `getCode()` is always 0. Callers
  that branch on status (cancel-on-409, poll-on-404) read `$e->status`, not `getCode()`.
- **`Client` is built fresh per use** (Octane stale-creds rule). For tests it accepts an injected
  `GuzzleClient`; `DispatchCodingJobAction`/`CancelCodingJobAction`/`RetryCodingJobAction` accept an
  injected `Client`. The poller cannot take one on its constructor — a Guzzle handler stack does not
  survive queue serialization, which is exactly the landmine the root CLAUDE.md warns about — so it
  exposes a `protected makeClient()` seam instead, and `PollPiDevJobJobTest` subclasses the job to
  override it. Do not "fix" this by adding a `?Client` constructor param.
- **Retrying is not re-dispatching.** `DispatchCodingJobAction` always mints a *fresh* Plan, so
  sending the same task again through it abandons the original plan and its history. Recovering a
  failed job goes through `RetryCodingJobAction`, which POSTs a new pi.dev job and rewires the
  *existing* Task onto it — resetting the task out of BLOCKED (nothing else can, and the poller will
  not follow a task it considers terminal), clearing `blocked_reason`, the stale PR url and the SSE
  cursor, and reopening the plan.
- **Two separate retry budgets, on purpose.** `PIDEV_AUTO_RETRY_COUNT` gates only the *automatic*
  provider-error retries. A human- or agent-triggered `retry_coding_job` never spends it and is never
  blocked by it — an exhausted automatic budget must not stop someone deciding the job is worth
  another run hours later.
- **The connector ships NO bespoke GraphQL.** Infra connect flows through the generic
  `createIntegrationCompany` + the seeded `integrations` row → `PiDevHandler::setup()`. Per-agent config
  (token / allowed repos / system prompt) is set via the **generic agent-settings** mutations
  (`setAgentSetting`, keyed on the agent uuid) and read via `AgentAi.custom_fields`. Dispatch/cancel are
  **agent-only** (the `dispatch_coding_task` / `cancel_coding_job` tools) — there is no human-facing
  dispatch mutation. Read job progress + results in the plan's Activities feed
  (`nervousSystemPlans`/task graph). `ConfigureAgentAction` remains as the validated programmatic
  configure helper (tests, seeders), not wired to GraphQL.

## Setup flow (runtime config, no code)

1. `createIntegrationCompany` with `base_url` + `api_token` → runs `PiDevHandler::setup()`.
2. Create an agent of type **"pi.dev Programming Agent"** (it ships the coding tools intrinsically).
3. Set the agent's `PIDEV_GITHUB_TOKEN` + `PIDEV_ALLOWED_REPOS` (+ optional `PIDEV_SYSTEM_PROMPT`) via
   the agent key-config UI (`setAgentSetting`) or `$agent->set(...)` in tinker.
4. Chat with the agent → it calls `dispatch_coding_task` (there is no manual dispatch mutation).

## Which coding backend: pi.dev or Claude?

Kanvas has two async coding backends and they are NOT interchangeable. Without a stated rule an
agent picks by tool-name similarity, which is the same failure mode as `create_google_sheet_tab`
one layer up. The rule lives in both dispatch tools' descriptions (that is what the model actually
reads at decision time); this table is the engineer-facing version.

| The work... | Backend | Why |
|---|---|---|
| is a narrow change inside ONE repository | **pi.dev** (`dispatch_coding_task`) | cheaper, endpoint is ours (`pidev_base_url` is app-scoped), and `check_coding_setup` gives a preflight |
| needs to read live Kanvas data WHILE it runs | **Claude** (`dispatch_long_task`) | only the Claude path has `CustomToolBridgeService` — the sandbox parks, the call comes back over the event stream, and PHP runs it under tenant scope. Credentials never enter the sandbox |
| has checkable acceptance criteria | **Claude** | `rubric` grades and iterates. Criteria must be verifiable INSIDE a sandbox with no database — "the CSV has a numeric price column" works, "the tests pass" does not |
| spans more than one repository | **Claude** | `repoSlugs` is a list; pi.dev takes one slug |
| must hand back a produced file | **Claude** | `PullSessionOutputsAction` attaches `/mnt/session/outputs/` to the Plan; pi.dev returns only a PR |

Both create a NervousSystem `Plan` + `Task`, both poll to a terminal status, and both end in a pull
request. The difference is entirely what the agent can reach while it works.

**Both are supported deliberately.** If that ever stops being worth two allow-list services, two poll
jobs and two custom-field sets, consolidate on the Claude path — it is the strict superset — rather
than leaving the choice unstated.
