# Agent Harness — coding agents Kanvas owns

Loads when work touches `src/Domains/Intelligence/AgentRuntime/Harness/`. Design and the decisions
behind it: [`docs/intelligence/coding-agent-runtime-plan.md`](../../../../../docs/intelligence/coding-agent-runtime-plan.md).

Kanvas owns the container, the workspace, the credentials, the approvals, the memory and the cost
record. The harness owns only the reasoning loop. That split is the whole point — `AgentHarness` exists
so the backend can be replaced without touching anything above it.

## Harness ≠ AgentRuntimeProvider

`AgentRuntimeProvider` is **deployment**-shaped: one long-lived container per agent, with backups,
telemetry, config sync and kanban. `AgentHarness` is **task**-shaped: a session that starts, does one
job and ends. Implementing the provider interface here would stub twenty methods. Keep them apart.

| Concern | Lives in |
|---|---|
| Contract, DTOs, session model, poller, cost | `Intelligence/AgentRuntime/Harness/` (this tree) |
| The opencode implementation | `Connectors/OpenCode/` |

A connector must not own a table the platform reads, which is why `AgentTaskSession` is here and not
under `Connectors/OpenCode/`.

## The Task is the record; the session is the runtime state

A coding job is a NervousSystem **Plan + Task** (same shape as pi.dev and Claude). `agent_task_sessions`
hangs off the Task and holds what the sweeper, the concurrency cap and the endpoint need — none of
which can be queried from Task custom fields, and custom fields live on a different database.

**A task has many sessions.** A retry is a new row. Do not mutate the old one.

## Things that will bite you

- **`TaskStatusEnum::BLOCKED` is terminal.** Every poller's first guard is `isTerminal()`, so a task
  parked there never resumes. A session waiting on a permission or a question keeps the Task
  `IN_PROGRESS` and records the waiting state on the **session row** —
  `HarnessStatusEnum::toTaskStatus()` encodes this, don't "simplify" it.
- **The runtime substitutes models silently.** With provider wiring wrong, opencode answers from its
  own hosted model, reports `cost: 0`, and logs nothing the API can see. `PollHarnessSessionJob`
  compares `message.model.id` against the pinned model every tick and kills the session on a mismatch.
  That check is a tenant-data-egress control, not a nicety.
- **Failures are only sometimes visible.** A streaming fault shows up in `history` as
  `session.next.step.failed`; a provider that cannot be resolved shows up **only** in the container log.
  So: run the server with `--print-logs`, and never treat "no error" as "healthy" — the wall clock and
  the heartbeat are what actually catch a wedged run.
- **Cost must be computed here.** A custom (openai-compatible) provider reports `cost: 0` and leaves
  the session's own totals at zero. `SessionCostService` sums the per-message tokens and prices them
  through `ModelPricingCalculator`. Trusting the runtime's numbers means every coding job looks free.
- **One write path.** `AbsorbHarnessTickAction` is used by both the queued poller and the foreground
  smoke-test command. It exists because the second copy drifted within an hour of being written — the
  command recorded every session as costing $0.
- **Plan status is not rolled up for you.** `UpdateTaskStatusAction` moves the task and the percentage
  but never the plan's status, and `saveQuietly()` fires no events — hence the explicit
  `broadcastChange()` in `FinalizeHarnessSessionAction`, without which the board shows a done task under
  an active plan.
- **`from_ia => true` on every plan post.** Otherwise an @mention inside the agent's own narration wakes
  the agent it names and two agents talk until the budget is gone.

## The opencode API, as it actually is

Verified against **2.0.16** via `GET /openapi.json` (`/doc` now serves the web UI). **v2 is not
compatible with 1.18.x** and the connector will not work against it — 1.18.32 notes are kept below only
where they explain a decision.

- **A session is pinned to a directory: `location: {directory}` at create.** This is the field the whole
  design rests on — one container per agent, tasks isolated by directory, each resolving its own project
  config and therefore its own provider and permissions. **1.18.x had no equivalent**, which is why the
  per-agent container could not work there: every session ran in the server's own working directory.
- Prompting is async and the body is flat `{text}`; it was `{prompt: {text}}`. `delivery: "steer"` joins
  the running turn, `"queue"` waits for it.
- **`/session/{id}/history` is gone.** It was the durable, seq-numbered log the poller resumed from.
  `/message` replaces it with cursor pagination, so the session stores an opaque `last_cursor` string
  instead of an integer `last_seq`. Store the cursor; never parse or compare it.
- **A turn ends with an explicit `idle` message** carrying `outcome` (`succeeded`, `aborted`, …). Much
  better than v1, where the end had to be inferred from every assistant message having `time.completed`
  — a turn between steps looks finished by that test.
- Per-message `tokens{input,output,reasoning,cache{read,write}}` are real and unchanged. `cost` is still
  `0` for a custom provider, so `SessionCostService` is still the only honest source of cost.
- `/vcs/*` moved under `/api` and takes a **`location`** object query param
  (`location[directory]=/path`), so a diff belongs to one task rather than to the whole container.
  `mode` is `working | branch | committed` — **not** `git`.
- Permission replies are `{decision}` (once|always|reject, **not** `allow`) at
  `/permission/{requestID}/reply`. Replying twice returns `PermissionNotFoundError`, which the harness
  treats as already-decided.
- **There is no `/question` surface.** v2 asks through typed **forms** (`/session/{id}/form`), answered
  with `{answer: {field: value}}`. The harness surfaces a form's title so a session parks as
  AWAITING_ANSWER rather than hanging, and refuses to answer a multi-field form from a single string.
- There is no `/health`; `/api/info` answers once the server is serving.
- `--log-level` must be **lowercase**; v2 exits on `ERROR`.
- `/experimental/workspace` exists but **cannot be used headless** — it waits on an event only a
  connected TUI/desktop client emits, and always fails with "Timed out waiting for global event". Its
  only adapter is `worktree`. Kanvas prepares worktrees itself on the host and points the session at
  one with `location.directory`.

## The workspace must be a git repository

**This is the one that will waste your afternoon.** opencode resolves a project config — and therefore
a custom provider — only inside a git repository. Without one the config is read and served correctly
on `/config` and `/config/providers`, and every turn still dies with
`ModelUnavailableError: Model unavailable`, which points nowhere near git.

A session backed by a repository gets a worktree, so it is a repo already. A session with no repository
gets `git init` on its bare workspace for exactly this reason — do not remove that line as tidying up.

## Provider wiring

Declare the provider **explicitly as openai-compatible** in the project config:

```jsonc
"provider": { "oai": {
    "npm": "@ai-sdk/openai-compatible",
    "env": ["OPENAI_API_KEY"],
    "options": { "baseURL": "https://api.openai.com/v1" },
    "models": { "gpt-4.1": { "name": "gpt-4.1" } } } }
```

Three things were learned the expensive way here:

- **It has to be a project-level `opencode.json`.** `OPENCODE_CONFIG_CONTENT` and a global
  `~/.config/opencode/opencode.json` both register the provider and show it on `/config` — and the
  session runner still refuses the model. `ProvisionCodingSessionAction` writes the file into the
  workspace and adds it to `.git/info/exclude`, so it never reaches the agent's diff.
- **Do not rely on the provider opencode auto-detects from the API key.** It resolves, reports itself
  authenticated in `opencode auth list`, and then sends the request with **no Authorization header** —
  a 401 whose message ("Missing bearer or basic authentication") reads like a bad key.
- **Do not install `@ai-sdk/*` into the image's config dir.** opencode ships its own. (An earlier note
  here claimed the install was required, and a later one claimed it was actively harmful; both were
  wrong — the actual variable was git, above.)

## Running one by hand

Point an app at a machine and dispatch in the foreground — this is the real path, the one a customer
would use:

```bash
php artisan kanvas:coding:setup --app=2 --machine=<id> --provider=oai --model=gpt-6-luna \
  --api-key=sk-... --api-key-env=OPENAI_API_KEY

php artisan kanvas:coding:build-image --app=2 --machine=<id>   # until the image is in a registry
php artisan kanvas:coding:smoke-test --agent=<id> --repo=<slug> --task="..."
```

The smoke test polls in the foreground and prints each tick. **Launch is asynchronous** — provisioning
happens in `LaunchTaskSessionJob` on the `agent-runtime` queue, so the first few ticks print nothing
while the container comes up, and a launch failure lands on the session row rather than in the
command's output. When a run ends badly, read `agent_task_sessions.error_message`; the agent's own
account of why is frequently invented.

**Attach mode** — `opencode_static_endpoint` pointing at an already-running server — is still a
supported path in `ProvisionCodingSessionAction`, but nothing ships a container for it any more and
there is no dev service in `docker-compose.yml`. It was removed on purpose: attach mode has no
per-task directory, no branch, no push and no pull request, so a green run there says almost nothing
about the path that actually runs. It gave exactly that false confidence during the spike, while
launch mode was entirely unproven. If you want it, start a container by hand and set the endpoint.
