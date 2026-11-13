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

**A run that produced nothing is retried once, by the finalizer.** `retry_of_session_id` marks the
second try. The triggers are exactly two — a completed run with an empty diff, or silence from the
runtime (poller gave up / sweeper) with zero tokens in and out — and a session that is already a retry
never retries again. A run a person stopped, a run that made edits, and a run whose diff merely could
not be read are verdicts, not blanks, and go straight to the ordinary ending. The task, plan, parent
link and `current_attempt_id` are untouched by a retry: to the task it is still one attempt, and the
parent keeps waiting. Same model unless the tenant set `coding_fallback_model`.

## Things that will bite you

- **`TaskStatusEnum::BLOCKED` is terminal.** Every poller's first guard is `isTerminal()`, so a task
  parked there never resumes. A session waiting on a permission or a question keeps the Task
  `IN_PROGRESS` and records the waiting state on the **session row** —
  `HarnessStatusEnum::toTaskStatus()` encodes this, don't "simplify" it.
- **The runtime substitutes models silently.** With provider wiring wrong, opencode answers from its
  own hosted model, reports `cost: 0`, and logs nothing the API can see. `PollHarnessSessionJob`
  compares `message.model.id` against the pinned model every tick and kills the session on a mismatch.
  That check is a tenant-data-egress control, not a nicety.
- **Failures are only sometimes visible, and not where you expect.** A provider fault (402 out of
  credits, 401, 429) closes the turn with `{outcome: "failed"}`. On 2.2.0 the structured error —
  `{type: "provider.auth", message, status: 401}` — is on that idle **and** on the assistant message it
  ended (`finish: "error"`); on 2.0.16 the idle was bare and only the assistant message carried it.
  `OpenCodeHarness::lastError()` reads the idle first and falls back to that message; before it did,
  every provider fault surfaced as `the run ended as "failed"`. A provider that cannot be resolved at
  all shows up **only** in the container log. So: run the server with `--print-logs`, and never treat
  "no error" as "healthy" — the wall clock and the heartbeat are what actually catch a wedged run.
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
- **Limits: the poller owns them, nothing else may.** Time is counted in *active* poll ticks (parked on
  a question/permission doesn't count), cost is Kanvas's own estimate, and both scale by
  `1 + limit_extensions`. The sweeper reaps silence (stale heartbeat) only — a wall-clock ceiling there
  would override all three rules.
- **Hitting a limit is a question when the tenant has a `coding_extension` policy.**
  `RequestSessionExtensionAction` parks the session as `awaiting_extension` *before* interrupting
  opencode, opens the approval with a post-mortem (`SessionPostMortemService`: stuck-vs-busy, files,
  last words), and the poller keeps ticking without touching the runtime. Approve →
  `CodingExtensionApprovalHandler` sets `turn_offset`, bumps `limit_extensions`, prompts "continue";
  reject, no answer in 30 min, or approved-but-not-resumed → the run stops with the post-mortem. No
  policy → the run stops at the limit, post-mortem included. Policy:
  `kanvas:coding:setup-push-policy --type=extension`.
- **`turn_offset` is what makes a second turn readable.** `idle` messages stay in history forever; the
  harness judges status and errors from the idles after the offset only. Without it the `aborted` idle
  the pause produced is read as the resumed turn's outcome.
- **`from_ia => true` on every plan post.** Otherwise an @mention inside the agent's own narration wakes
  the agent it names and two agents talk until the budget is gone.

## Runtime drivers

`agent_machines.runtime` (`MachineRuntimeEnum`: `container`, `host`) says what runs opencode on a
machine, and `CodingRuntimeDriverFactory::forMachine()` picks the `CodingRuntimeDriver` for it. Only
`kanvas:coding:setup` will ever set the column (PR2); the GraphQL machine mutations never see it, and
`CopyDefaultAgentMachineAction` always produces a container machine, because a copied machine is one
company's shared box and the host runtime is the agent-owned Mac.

`agent_task_sessions.container_name` is the **runtime handle**: a container name on a Linux box, a
launchd label on a Mac. Everything that joins on it (`ClosesSessionsInContainer`, the stop action's
"someone else is still in here" check) works on the handle and is scoped to the machine, because the
handle is derived from the agent id and the same agent on two machines carries the same name twice.

Every `docker` string the coding flow sends lives in `ContainerRuntimeDriver` and nowhere else. A
`docker` call anywhere in `Connectors/OpenCode/Actions/` or `Services/` is a bug: the host driver has
to be able to replace it, and the tests that pin the container commands (`ContainerRuntimeDriverTest`,
`ReplaceStaleCodingContainersTest`) are the only place those strings are asserted.

`max_agents` is a real cap for coding on every runtime: `ensure()` refuses a **new** agent when the
box's own listing already holds that many handles. It counts the listing — stopped containers
included, because a stopped container still owns its name and its disk until the reaper takes it —
not the sessions table, and an agent that already has a handle on the box is never refused, so reuse
and a stale rollover always go through. Raise the number before adding an agent to a full machine, or
its first launch fails with "is at its N-agent cap". To see where a box stands:
`docker ps -a --filter label=kanvas.agent --format '{{.Label "kanvas.agent"}}' | sort -u | wc -l`
against `max_agents`.

A dead runtime is a transport exception, never an empty answer. `curl -w` prints the status marker
with `000` when the connection is refused, and `SshExecHarnessTransport` used to decode that as `[]` —
a session with no messages. It now throws "the runtime is not listening", and `HarnessFactory`
branches on the machine's runtime before its network mode: a host process is reached by curl over
SSH on the session's `port`, a container by `docker exec` on 4096.

### The host runtime (a Mac the agent owns)

`HostProcessRuntimeDriver` runs **one launchd job per agent**, `com.kanvas.coding-agent-{id}`, in
the SSH user's GUI domain (`gui/<uid>`) — the only domain where the Simulator and anything else that
needs a window server works. Its plist is `~/Library/LaunchAgents/{label}.plist` (`chmod 600`, it
carries the provider key), `HOME` is `{root}/agents/{id}/worktrees/.home` exactly as in the container,
logs are `{root}/agents/{id}/log/opencode.{out,err}.log`, and the process is
`{root}/bin/opencode-{v} serve --hostname 127.0.0.1 --port {port}`. The port is allocated from the
machine's range by probing `nc -z` under a machine-level lock, and `ensure()` does not return until
`/api/info` answers 200 — a port collision or a bad plist is a launch failure with a reason, not a
sweep ten minutes later.

Reuse is the container rule in plist form: the job is kept when `KANVAS_VERSION` and `KANVAS_KEYFP`
in the plist match the Dockerfile version and the agent's current key fingerprint, `launchctl print`
still finds it loaded, **and** one `/api/info` probe answers. A plist on disk whose job is gone (a
reboot before auto-login) is replaced, and so is a job that is loaded but not listening (crash-looping
under `KeepAlive` after its binary or port went away). `remove()` is `launchctl bootout` **and** `rm` of
the plist, because `RunAtLoad` would bring a lingering plist back at the next login. A free port is
also reserved in the cache for two minutes for the agent about to bind it, because `nc` cannot see a
job that has not started listening yet.

**The host version is the Dockerfile's, only.** `CodingRuntimeVersionService::upstreamVersion()` reads
the `FROM` line; the app's `opencode_image` is the container pin and is never read on a host.
`kanvas:coding:build-image --machine=<host>` installs instead of builds: it downloads the official
`opencode-darwin-arm64.zip` for that version from the GitHub release, verifies it against the
release's `SHA256SUMS` (or the sha recorded in `HOST_ARCHIVE_SHA256` for a release without one),
unpacks it to `{root}/bin/opencode-{v}` beside any older version, and records the binary's sha256 on
the machine's `CODING_IMAGE_DIGEST`. `upgrade-image` then replaces stale jobs through the driver like
it replaces stale containers.

The reaper's disk numbers are kilobytes × 1024 (`du -sk`, `df -k`) so they mean the same on GNU and
BSD, and on a host machine it also truncates any `opencode.*.log` over 50 MB in place — launchd does
not rotate, and the job keeps the file open.

**Xcode is a tool set the machine advertises, not a runtime.** `CODING_HOST_TOOLS = ["xcode"]`
(`setup --host-tools=xcode`) is read once per provision by `CodingHostToolsService`, the one place
that knows what the set means: the `bash` allow-list gains `xcodebuild *`, `xcrun *`, `swift *`,
`swiftc *`, `fastlane *`, `pod *`, `xcbeautify *`, `open -a *Device*Hub*` (still denied: `brew`, `sudo`, `osascript`,
`launchctl`, `curl`); `permission.read` pre-answers `**/.env*` and `**/.xcode.env*` inside the
workspace, because opencode asks before reading a dotenv file and a turn once waited twenty minutes
for that answer; the agent document gains a "Xcode and the Simulator" section; readiness gains two
rows (`xcode-select -p`, at least one available device); and `check_self_hosted_coding_setup`
reports `machine` as `{name, runtime, host_tools}` so the PM promises a Simulator only when one is
listed. A repository's protected-path denies are appended AFTER the tool set's allows — opencode takes
the last matching rule.

Three facts from the live box that shape the Simulator rules (Xcode 27.2, 2026-11-04):
**CoreSimulator ignores `$HOME`** — devices live under the login user's own Library, so every agent
on the Mac shares one device list and a device is only known to be ours by its name. Kanvas names
each session's device `Kanvas-{session id}` (`simulatorNameFor()` — the integer id, never a uuid
slice: a uuid v7 opens with its timestamp, so sessions started in the same minute share a prefix), writes it
into the agent document, and the reaper's `housekeep()` lists `xcrun simctl list devices -j`, keeps
a `Kanvas-…` device only while that session is live on that machine, and shuts down and
deletes the rest — a device the agent names otherwise is its own and is never reaped. **Xcode 27
renamed Simulator.app to Device Hub**, at `Xcode.app/Contents/Applications/DeviceHub.app` (not under
`Contents/Developer/`); `simctl` create/boot/screenshot work headless without it, and
`open -a "Device Hub" --args -CurrentDeviceUDID <udid>` puts the device on the Mac's monitor for a
person watching (`open -a *Device*Hub*` is on the allow-list — the name has a space, so the agent
quotes it, and a literal `Device Hub*` refused `open -a "Device Hub"` on the first live run). **`read` of a PNG reaches
the model as an image**, so screenshots go to `.kanvas/shots/` and the agent reads them itself.

What the Mac needs before any of this runs, and who does it (a human, once): a runtime user with
auto-login and FileVault off, so a GUI session exists after a reboot; Remote Login for that user;
`/srv/kanvas` (or the app's `opencode_workspace_root`) writable by it; Homebrew `node` and `npm`
(`git`, `curl`, `unzip`, `shasum` ship with macOS); and a stable address. `check_self_hosted_coding_setup`
reports each of these when missing, plus "only Apple silicon" on an Intel Mac.

## Coding chat: an agent's chat as a front end for the opencode factory

`CODING_CHAT_MODE` on an agent hands **every** `AgentChatKernel` call to `RunCodingChatAction`: no model
of the agent's own reads the message, it is the next prompt to one opencode session in a real checkout
of the opencode factory on the agent's machine (`~/Documents/Projects/<App>`, the same checkout, persona,
rules and baseline as running `akira` there). Kanvas writes nothing into the checkout: the factory's
`scripts/serve.sh` does, and `FactoryServeClient` is its only caller (`open`, `release`, `list`, `health`).

| Agent custom field | Notes |
|---|---|
| `CODING_CHAT_MODE` | The switch. A chat-mode agent's chat no longer reaches its own model or tools. |
| `CODING_MACHINE_ID` | The factory Mac. Its `runtime` must be `host`, which only `kanvas:coding:setup` or the row itself sets: a container machine is reached by `docker exec` and every turn fails. Its `ssh_user` must own the factory. |
| `CODING_CHAT_DEFAULT_REPO` | The checkout a message opens when the conversation has none; `/repo <App>` switches. |
| `CODING_CHAT_FACTORY_DIR` | Only when the factory is not at `Documents/Projects/opencode-factory`. |

The agent's own `soul` + `instructions` are sent on every `open` as the factory's "Who you are"
(`CodingChatSettingEnum::personaFor()`), never the type's: a type's soul is orchestrator text.

**Two shapes of session, one row.** A chat (`chat_session_id` set) parks `IDLE` after each turn and
posts through the plan's origin channel. A **task run** is the same row whose task has a parent: a
workflow handed the agent a task (`RunTaskWorkerJob`, `WakeAgentForTaskJob`), the run is that task's
child, and its first turn closes it — the reply becomes `worker_summary`, the child's outcome resumes the
worker, which closes the task from that answer instead of running the factory again. Paths that do not
go through those two (plan-owner wakes, `VerifyPlanAction`, receivers, "run agent on record") are not
factory-aware, and **@mentions never reach the factory**: `RespondToMentionJob` calls Neuron directly.

Things that bite:
- **The domain reaches the factory through `Contracts\CodingFactory`**, built by `HarnessFactory`
  (`codingFactoryFor($session)`, `codingFactoryOn($machine, $agent)`, `codingMachineFor($agent)`); never
  import `FactoryServeClient` from Harness code. Tests bind a fake on `FactoryServeClient::class`.
- **Held messages change hands under `HeldChatMessages::handOver()`.** The launch takes the held list and
  flips the row, the chat holds a message and reads the row: both under the same lock, or a message is
  lost, delivered twice, or starts a second opencode session in one checkout. A launch re-reads its row
  after `open` (minutes of baseline): a chat ended meanwhile is released, never written back live.
- **A running chat turn says so every minute.** Narration goes to the plan board, not the chat, so a long
  turn showed only "On it" until it ended. `PostCodingChatProgressAction` (from the poller, on RUNNING)
  posts "⏳ Still working · N min · <latest step>" plus the agent's latest note; quiet for the first 45s,
  each minute said once, never for a workflow task run. The poller lands each chat tick 5s past the
  turn's next whole minute (`secondsToNextMinute()`): a flat 60s plus the poll's run time drifts and
  skips a minute every ten or so. The step is read from the 2.2 tool part's `name` + `state.input`
  (fixtures under `opencode-2.2.0/`); any other shape reads as "using a tool".
- **A stopped factory server is Kanvas's to restart, never the agent's** (the agent runs inside it).
  `RestartFactoryServerAction::retrying()` wraps every call that reaches it (the chat relay, the launch's
  first prompt): on `RuntimeNotListeningException` it runs `serve.sh start`, posts "⚙️ restarted" and
  retries once. `WatchCodingChatJob` does the same within a minute when `health` reports `server: false`.
  Everything on the device side (emulator, adb, Metro, builds) the agent repairs itself with the factory's
  `.kanvas/device`; its chat rules tell it to, and to only report what it fixed.
- **A task run never touches the shared checkout.** `open --worktree <branch>` gives it its own worktree
  on `<agent name>/task-<task id>-<slug>` (e.g. `akira/task-42-login-screen`), cut from the app's base branch (`develop`, else `main`), with
  `node_modules` linked from the checkout; `release` commits anything left uncommitted on that branch
  before removing the folder. Task runs still take turns per Mac (`CODING_CHAT_MAX_TASK_RUNS`, default 1,
  first come first served) because the simulator, emulator and Metro's port are machine-wide; a run
  whose turn has not come waits in `waiting_for_factory` and re-checks every minute — never a failure.
- **One lock per checkout, on the Mac — for chats.** `open` takes `locks/<App>`; every environment pointing at the
  same Mac shares it, so a local test chat holds a checkout production then cannot open ("in use"). A
  refusal carries the lock's `holder` (`CheckoutInUseException`); `CheckoutHolderNotice` turns it into
  words, describing only a holder in the same app and company, and links the person's own conversation;
  `/take <App>` moves it from their own other chat, never someone else's or a task's.
- **The opencode server is started from the login session, never launchd.** macOS privacy protection
  denies a background job `~/Documents`, and only the login session has the unlocked keychain (provider
  key, `gh`). `serve.sh start` runs it in tmux; `start-at-login.command` as a Login Item restarts it.
- **The factory is watched while a chat is open.** `WatchCodingChatJob` polls `serve.sh health` every
  minute and `ReportFactoryProblemsAction` posts crashes and lost devices/Metro; mid-turn it also steers
  the agent, between turns it does not (that would start a paid turn).

## The `coding` queue is not optional

`LaunchTaskSessionJob`, `PollHarnessSessionJob` and `VerifyHarnessDiffJob` ride a queue of their own,
`coding`, with its own worker service in all three compose files. They used to share `agent-runtime`
with 21 other job types — deployment image builds with a 900s budget, config backups, telemetry — and
on 2026-11-03 three production tasks in a row were swept as "no activity" at ten minutes because their
launch job was still queued behind a build. The launch then ran, found its session closed, and returned
without a word: rows with no machine, no container and no error, which the PM read as a hung runtime.

Three guards now make that visible, but none of them replaces the worker: a launch that arrives after
its session closed appends "Launch job ran at … but the session was already cancelled" to the row, the
sweeper says "the launch never started" for a row with no endpoint instead of "no activity", and the
smoke test prints "provisioning" until the row has one. **If no worker consumes `coding`, nothing
launches at all** — that is the first thing to check when every session sits in `starting`. The second
is `failed_jobs` on the `coding` queue: a launch that crashes before its first write looks identical
from the row. The one seen so far was the `launch_stage` migration not having run — the job's first
write threw, and `fail()` would have died on the same dirty attribute, which is why it now calls
`discardChanges()` before recording the reason.

## A launch has stages, and the sweeper's patience depends on them

`agent_task_sessions.launch_stage` (`LaunchStageEnum`) is written by the launch job as it moves —
resolving the repository, starting the runtime, preparing the worktree, sending the prompt — and
cleared once the prompt is out. Each write touches the heartbeat. A live row with a stage and no
endpoint is still launching; the PM's status tool shows the stage's label instead of "starting".

The sweeper's ten-minute rule is the default, not the whole rule: `LaunchStageEnum::staleMinutes()`
lets a stage ask for more, and `preparing the worktree` asks for 16 because a first mirror clone runs
with a 900-second allowance during which git reports nothing. Do not "fix" that by touching the
heartbeat from inside the clone — the exec is blocking, so there is nothing to touch it from. A row
swept mid-launch says which stage it stalled in; one that never got a stage says the queue never ran it.

**Mirrors are warmed when a repository is allowed.** `kanvas:coding:allow-repo` dispatches
`WarmRepositoryMirrorJob` on the `coding` queue, which clones the bare mirror onto the agent's machine
through `EnsureRepositoryMirrorAction` — the same action `PrepareSessionWorktreeAction` uses — so the
first task starts from a fetch. Best-effort: a failure is a log warning, and the launch clones anyway.

## Disk: what is reclaimed, and the one thing that is not

`kanvas:coding:reap` (every 2h) removes idle containers and the checkouts of sessions that ended more
than 24h ago. Three separate things live on a machine and only two of them are swept:

| | Lifetime | Reclaimed by |
|---|---|---|
| per-session checkout | 24h after the session ends | the reaper |
| idle container | 60min after the agent's last heartbeat | the reaper |
| bare git mirror, per (company, repo) | forever — it is the expensive part | nothing, deliberately |
| **`worktrees/.home`** — opencode's own session store | **forever** | **nothing** |
| `Kanvas-{session}` simulator devices (host + `xcode`) | until the session is no longer live | the reaper, via `housekeep()` |
| Xcode `DerivedData` project folders (host + `xcode`, in the login user's Library) | 7 days unmodified | the reaper, via `housekeep()`; measured as `xcode_bytes` with `CoreSimulator` |

**Checkout size tracks the work, not the task count.** Thirty checkouts of a static-HTML repo came to
25MB; one Next.js task that ran `npm install` was 608MB on its own. So the 24h window matters far more
for JS work than the number of sessions ever will.

**The `.home` decision is deferred on purpose.** It is per agent, survives every container kill, and is
the largest single item (~1GB after two days on one agent). It exists to make opencode sessions
resumable — and nothing resumes them today: a continuation reuses only the branch, PR and repo, gets a
fresh directory and starts a new opencode session. So it is currently buying nothing, and deleting it
would lose nothing that is not already in the branch, the pull request, the handoff and the memories.

It was left alone anyway, because one afternoon's numbers are a bad basis for a retention policy. The
reaper now prints and logs `coding.runtime.disk` (`total_bytes`, `home_bytes`, `available_bytes`) per
machine on every run — revisit with a few weeks of that series rather than another single `du`.

## The opencode API, as it actually is

Verified against **2.2.0** via `GET /openapi.json` and live turns through the image the Dockerfile builds
(`tests/Intelligence/Unit/Harness/fixtures/opencode-2.2.0/` are those captures, verbatim). **v2 is not
compatible with 1.18.x** and the connector will not work against it — 1.18.32 notes are kept below only
where they explain a decision. 2.0.16 → 2.2.0 changed four things the harness depends on:

- **A cursor must travel alone.** `GET /message?cursor=…&order=asc` is `400 InvalidCursorError: Cursor
  cannot be combined with order`. The cursor is an opaque `{id, order, direction}`, so it already knows.
  `Client::messages()` sends `order=asc` on the first page only (the default is newest-first) and the
  cursor by itself after that. Before the fix every run longer than one page died on page two.
- **Idle `outcome` is `succeeded | failed | interrupted`.** There is no `aborted`. `/interrupt` produces
  `interrupted` with a bare idle; the step it cut carries `{type: "aborted", message: "Step interrupted"}`.
- **A permission's tool call is `source.id`**, not `source.callID`. `permissionCallId()` reads both.
- **Answering a settled form is `409 FormAlreadySettledError`**, the twin of `PermissionNotFoundError`;
  `answerQuestion()` tolerates it the same way.

Also observed on 2.2.0: a plain `/prompt` while a turn runs is **not** `SessionBusyError` — it is
accepted with `delivery: "steer"`; `--print-logs` says server logs need `--standalone`, but the
container's `serve` still prints its listen line and faults without it; and the message list now holds
`compaction`, `synthetic`, `system`, `skill`, `shell` and `*_selected` types the poller filters past.

- **A session is pinned to a directory: `location: {directory}` at create.** This is the field the whole
  design rests on — one container per agent, tasks isolated by directory, each resolving its own project
  config and therefore its own provider and permissions. **1.18.x had no equivalent**, which is why the
  per-agent container could not work there: every session ran in the server's own working directory.
- Prompting is async and the body is flat `{text}`; it was `{prompt: {text}}`. `delivery: "steer"` joins
  the running turn, `"queue"` waits for it.
- **`/message` pages cap at 100.** `poll()` follows the cursor to the end; one page loses the closing
  `idle` and every token past message 100 on any long run.
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

## Configuration — every key, and which ones are not optional

Nothing here has a sensible default that works, and a missing key fails late and unhelpfully: the
session provisions, the turn runs, and the model answers from opencode's own hosted model or dies with
`ModelUnavailableError`. Check this table before debugging anything else.

**App-level** (`$app->set(...)`, `ConfigurationEnum`):

| Key | Required | Notes |
|---|---|---|
| `opencode_provider_id` | **yes** | The provider block's name in `opencode.json` (e.g. `oai`). A model pinned with no provider block throws. |
| `opencode_model` | **yes** | e.g. `gpt-6-luna`. Per-agent `CODING_MODEL` overrides it. |
| `opencode_provider_api_key` | **yes** | Unless the agent carries its own `CODING_PROVIDER_API_KEY`. Company config wins over app. |
| `opencode_provider_env_var` | no | Defaults `OPENAI_API_KEY`. Must match what the provider block's `env` names. |
| `opencode_provider_base_url` | no | Defaults to OpenAI. |
| `opencode_provider_npm` | no | `@ai-sdk/openai` for the Responses API (codex models, `gpt-6-luna` tool calls); `@ai-sdk/openai-compatible` otherwise. Both ship in the image. |
| `opencode_image` | **yes** | Throws without it. `kanvas:coding:setup` and `kanvas:coding:build-image` both default it to the tag the Dockerfile builds (`CodingImageService::pinnedTag()`) and **build-image writes it on the app**, so you should never type a version. Until there is a registry the image must also already be built **on that machine**. |
| `opencode_workspace_root` | no | Defaults `/srv/kanvas`. Mirrors, worktrees and `.home` all live under it. |
| `opencode_container_cpus` / `_memory` | no | Default memory is 2g. Larger than the box's free RAM is how MySQL gets OOM-killed. |
| `coding_max_concurrent_sessions` | no | Per app. |
| `coding_max_session_cost_usd` | no | Per session ceiling; default $10. `0` = uncapped. |
| `coding_max_session_minutes` | no | Active minutes before the poller interrupts the run; default 180 (3h). Company wins over app. Ticks spent waiting on a human answer/permission don't count. |
| `coding_fallback_model` | no | The model the one retry of an empty run uses. Unset = the same model. Same provider either way. Company wins over app. |
| `opencode_static_endpoint` / `_password` | no | Attach mode only. Leave unset for the real path, or provisioning attaches instead of launching. |

**Agent-level** (custom fields, `AgentCustomFieldEnum`):

| Key | Required | Notes |
|---|---|---|
| `CODING_MACHINE_ID` | no | Tenant-scoped. Without it provisioning takes any active machine for the company — fine with one, arbitrary with several. |
| `CODING_GIT_TOKEN` | **yes** | The only agent field you must set. Per agent, and it **is** the permission boundary — the agent's reach is exactly this token's reach, with no allow-list behind it. Unset, git falls back to the machine's own access, so a private clone and every push fail. |
| `CODING_MODEL` | no | Overrides the app model for this agent. |
| `CODING_PROVIDER_ID` | no | The agent's own provider (e.g. a frontend agent on OpenRouter). **All-or-nothing**: once set, the app's base URL, npm and env var are ignored and the agent must carry its own key — the app's key is never sent to another provider. Resolved in `CodingModelResolver` only. |
| `CODING_PROVIDER_BASE_URL` / `_NPM` / `_ENV_VAR` | with `CODING_PROVIDER_ID` | The agent's transport. Base URL is required on the compatible npm (the default). Changing the env var rebuilds the container. |
| `CODING_PROVIDER_API_KEY` / `CODING_PROVIDER_KEY_NAME` | no | Agent's own key instead of the app's. Rotating it changes `kanvas.keyfp` and forces a container rebuild. Required with `CODING_PROVIDER_ID`. |
| `CODING_ALLOWED_REPOS` | no | Settings (base branch, rules, protected paths), **not** a gate — the token is the gate. |
| `CODING_ALLOW_TRUNK_PUSH` | no | Off by default. The only way a push reaches main/master/develop/... |
| `CODING_GIT_AUTHOR_NAME` / `_EMAIL` | no | Commit author + committer; default `{agent name} <agent@kanvas.dev>`. Set when a deploy platform (Vercel) blocks commits from an email no team member owns: any email via the admin command `kanvas:coding:git-identity`, or the agent's `set_coding_commit_identity` tool, which can only pick the requesting human (`requestingHuman()`, never the agent's own user) or reset — it deliberately takes no email input. The host's `git config` is never consulted — the push passes `-c user.*` on every commit. |
| `CODING_SYSTEM_PROMPT` | no | Appended to the agent document. |
| `CODING_CONTAINER_PORT` / `_PASSWORD` | never set by hand | Written by the provisioner. On a host machine the port is the launchd job's loopback port. |

**Run-only jobs.** `agent_task_sessions.expects_changes` (default true) is set at dispatch — the PM
tool's `expects_changes: false`, the smoke test's `--no-changes` — and carried through a retry. With
it off, a completed run with an empty diff is the outcome `RAN` (task DONE, "nothing to push"), not
the "answered in prose" failure plus retry that an empty diff means otherwise; a run-only job that
did change files goes through approval and push like any other. Every "build it, run it, screenshot
it" job on the Mac needs the flag, or it reads as three failed sessions.

**Screenshots reach people.** `FinalizeHarnessSessionAction` runs `CollectSessionScreenshotsAction`:
up to 10 `.png` files of at most 10 MB under `{workspace}/.kanvas/shots/` (a host path on both
runtimes) are copied over SFTP into Kanvas Filesystem under the session's user, attached to the plan's
result message and listed by URL in the body that reaches the origin conversation and the asker's
notification. `.kanvas/logs/` is where the agent keeps background-command logs; both directories are
excluded from the diff with the rest of `.kanvas/`.

**Background processes on a Mac.** Every session runs as the one login user, so the agent gets no
`kill`/`pkill` of any shape — `pkill -f expo` would stop a sibling session's packager. The document
tells it to record what it starts (`nohup … & echo $! > .kanvas/logs/<name>.pid`) and to use its own
port (`CodingHostToolsService::packagerPortFor()`, 8100–8999 by session id) instead of Metro's 8081;
the host driver's `housekeep()` reads the pid files of ended sessions and kills those processes
(never one whose command mentions `opencode`). DerivedData is swept by the newest file inside a
project folder, not the folder's mtime, which a build never touches.

**Machine-level** (`agent_machines` columns and custom fields, `MachineCustomFieldEnum`; written only
by `kanvas:coding:setup --machine=<id>` and `build-image`, never by the GraphQL machine mutations):

| Key | Notes |
|---|---|
| `runtime` (column) | `container` (default) or `host`. `--runtime=host` on setup; a host ignores `network_mode`. |
| `CODING_HOST_TOOLS` | List of tool sets a host advertises, e.g. `["xcode"]`; `--host-tools=xcode`. Gates the Xcode allow-list, the dotenv read rules, the agent-document section, two readiness rows and the simulator sweep (`CodingHostToolsService`). Unknown entries are ignored. |
| `CODING_IMAGE_DIGEST` | What the machine runs under the pinned version: the image id on a container machine, `sha256:…` of the binary on a host. Written by `build-image`; compared across machines in its drift report. |

After changing an agent type or a tool, run `kanvas:intelligence:sync-agent-types` and
`kanvas:nervous-system:sync-tools` or the PM cannot see or hire the agent — then reload Octane **and**
the queue worker, which hold their own stale class graph.

## Running one by hand

Point an app at a machine and dispatch in the foreground — this is the real path, the one a customer
would use:

```bash
php artisan kanvas:coding:setup --app=2 --machine=<id> --provider=oai --model=gpt-6-luna \
  --api-key=sk-... --api-key-env=OPENAI_API_KEY

php artisan kanvas:coding:build-image --app=2 --machine=<id>   # until the image is in a registry
php artisan kanvas:coding:smoke-test --agent=<id> --repo=<slug> --task="..."
```

A Mac the agent owns is the same three commands with the machine marked as a host; `build-image`
installs the pinned release there instead of building, and `--prewarm` on setup does the same:

```bash
php artisan kanvas:coding:setup --app=2 --machine=<mac> --runtime=host --host-tools=xcode --prewarm
php artisan kanvas:coding:smoke-test --agent=<id> --repo=<slug> --task="..."
```

**Upgrading opencode** is a `FROM` bump in `docker/opencode/Dockerfile` followed by one command, because
a build alone is not an upgrade: a coding container lives for as long as its agent keeps getting work,
and was created with whatever image was configured at the time.

```bash
php artisan kanvas:coding:upgrade-image --app=2 --all --dry-run   # what is still on the old image
php artisan kanvas:coding:upgrade-image --app=2 --all             # build, point the app at the tag, replace idle containers
php artisan kanvas:coding:upgrade-image --app=2 --all --force     # also the ones with a job running or a push awaiting approval
```

It targets the tag the Dockerfile builds (never the app's current setting — that is the version being
left behind), builds on every selected machine, writes `opencode_image`, then removes each container
not on that image. A container with a live session or a pending push approval is kept and listed;
`ContainerRuntimeDriver::ensure()` compares the image on every dispatch, so it rolls over on its
agent's next task anyway. `--force` removes those too and closes their sessions with a reason.

The smoke test polls in the foreground and prints each tick. **Launch is asynchronous** — provisioning
happens in `LaunchTaskSessionJob` on the **`coding` queue**, so the first few ticks print "provisioning"
while the container comes up, and a launch failure lands on the session row rather than in the
command's output. When a run ends badly, read `agent_task_sessions.error_message`; the agent's own
account of why is frequently invented.

**Attach mode** — `opencode_static_endpoint` pointing at an already-running server — is still a
supported path in `ProvisionCodingSessionAction`, but nothing ships a container for it any more and
there is no dev service in `docker-compose.yml`. It was removed on purpose: attach mode has no
per-task directory, no branch, no push and no pull request, so a green run there says almost nothing
about the path that actually runs. It gave exactly that false confidence during the spike, while
launch mode was entirely unproven. If you want it, start a container by hand and set the endpoint.
