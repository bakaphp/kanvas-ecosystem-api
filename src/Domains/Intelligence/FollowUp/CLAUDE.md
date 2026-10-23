# FollowUp Domain — Architectural Guide

**This folder hosts the agent-driven, pipeline-stage follow-up engine.** It is NOT a "lead follow-up" domain — Lead is just the first entity to use it. Other entities (Deal, Order, Subscription, Task, Ticket, ...) can adopt the same primitives.

**Companion docs:**
- v1 implementation spec: [docs/intelligence/follow-up-v1-spec.md](../../../../docs/intelligence/follow-up-v1-spec.md)
- Deprecation + kill list for legacy code: [docs/intelligence/follow-up-deprecation-spec.md](../../../../docs/intelligence/follow-up-deprecation-spec.md)

## Core split: generic primitives vs per-entity executors

The folder mixes two kinds of code intentionally. Know which kind a file is before you edit it.

### GENERIC (reusable across entities — do not couple to Lead)

| File | What it does |
|---|---|
| `Traits/HasFollowUpState.php` | Adds `follow_up_state` custom-field accessors/mutators to any `HasCustomFields` model |
| `DataTransferObject/FollowUpConfig.php` | Typed reader for `pipelines_stages.config.follow_up` JSON |
| `DataTransferObject/TimeBasedConfig.php`, `GoalBasedConfig.php`, `ChannelConfig.php` | Sub-DTOs |
| `DataTransferObject/FollowUpOutcome.php`, `AgentFollowUpResult.php` | Return types |
| `Enums/FollowUpMode.php`, `ExhaustedAction.php`, `ChannelSelection.php` | Discriminators |

**Rules for generic files:**
- No `Lead` / `Deal` / `Order` references in code or type hints. If you reach for one, you're in the wrong file.
- No `Lead::class` / `pipeline_stage_id` assumptions in the DTOs. Stage config is read off whatever model the caller hands in.
- The trait is the only place that touches `follow_up_state`. Don't reach into the raw custom field from anywhere else.

### PER-ENTITY (one set per entity that adopts follow-up)

| File pattern | Example (Lead) | Example (future Deal) |
|---|---|---|
| `Actions/FollowUp{Entity}Action.php` | `FollowUpLeadAction` | `FollowUpDealAction` |
| `Actions/Build{Entity}FollowUpDailySummaryAction.php` | `BuildLeadFollowUpDailySummaryAction` | `BuildDealFollowUpDailySummaryAction` |
| `Jobs/DispatchApp{Entity}FollowUpsJob.php` | `DispatchAppLeadFollowUpsJob` | `DispatchAppDealFollowUpsJob` |
| `Jobs/{Entity}FollowUpJob.php` | `LeadFollowUpJob` | `DealFollowUpJob` |

Per-entity files are where the entity's specific knowledge lives: stage relation name, session resolver, "advance stage" logic, candidate query, anything else that can't be inferred from a generic model.

Plus per-entity commands in `app/Console/Commands/{Entity}/`:
- `{entity}:dispatch-follow-ups` (hourly)
- `{entity}:follow-up-daily-summary` (daily)

Plus per-entity queue: `{entity}_follow_ups`.

## Adding a new entity (the recipe)

When extending follow-up to Deal / Order / etc., follow these steps in order:

1. **Verify the entity has `HasCustomFields`.** If not, you can't use the trait — find another way to persist state (or add custom fields support to the model).
2. **Apply the trait:** `use HasFollowUpState;` on the model.
3. **Make sure the entity's "stage" (or equivalent) has a `config` JSON column** that can hold `follow_up` + `stage_meta` blocks. For deals it's `deal_stages.config`. For orders it might be on `order_statuses` or on the entity itself.
4. **Add the `EmitsLedgerEventsForEntity` trait** to the model if it doesn't already have it.
5. **Write the entity-specific action**: `FollowUp{Entity}Action(app, company, {entity}, force = false)::execute(): FollowUpOutcome`. Mirror the shape of `FollowUpLeadAction`. Substitute the stage relation, session resolver, and "advance stage" logic.
6. **Write the two jobs** (`DispatchApp{Entity}FollowUpsJob` + `{Entity}FollowUpJob`). Mirror the candidate query against the new entity.
7. **Write the daily summary action** (`Build{Entity}FollowUpDailySummaryAction`). Same Ledger query pattern, different event prefix (`deal.follow_up.*` instead of `lead.follow_up.*`).
8. **Register the two commands** in the relevant `app/Console/Commands/{Entity}/` folder.
9. **Add the queue worker** to all three `docker-compose*.yml` files (and helm when un-dormant) per the [queue worker registration rule](../../../../.claude/projects/-Users-kaioken-Code-kanvas-kanvas-ecosystem-api/memory/feedback_queue_worker_registration.md).
10. **Add a `followUp{Entity}` GraphQL mutation** for the manual trigger.
11. **Wire the inbound re-engagement hook** into the entity's inbound-message handler (whatever path inbound replies travel through for this entity).
12. **Hook the entity's observer** (`{Entity}Observer::updating`) to call `$entity->resetFollowUpState()` + emit `{entity}.stage.changed` + write a system message into its thread when the stage changes.

If you do all 12, the entity participates fully. The generic core doesn't change.

## What NOT to do

- **Don't build a polymorphic `FollowUpEntityAction` that takes `entityType` + `entityId` and switches internally.** It hides per-entity logic (stage relation, session resolver, terminal-stage detection) inside conditionals and makes both Lead and any future entity harder to reason about. Two explicit actions sharing trait + DTO + enums is cleaner than one polymorphic action with `instanceof` checks.
- **Don't add Lead-specific fields to `FollowUpConfig` DTO.** The DTO reads the same JSON shape regardless of entity. If the new entity needs different config, that's a sign the new entity should have its own per-entity sub-DTO field, not that the generic shape should fork.
- **Don't move `HasFollowUpState` to the entity's own domain** (e.g. `Guild/Leads/Traits/`). Keep it here. The trait is the contract for participating in the follow-up engine; it belongs with the engine. Same shape as how `EmitsLedgerEventsForEntity` lives in the Ledger domain and is `use`d from elsewhere.
- **Don't bypass the trait to write `follow_up_state` directly.** All reads/writes go through the trait methods. This is how we keep the JSON shape consistent across future migrations.
- **Don't reach for `Lead` from generic files.** If a generic DTO/enum/trait would benefit from a Lead reference, you're either in the wrong file or the file needs to be moved to per-entity.

## Legacy code in this folder

Until the deprecation window closes (see [follow-up-deprecation-spec.md](../../../../docs/intelligence/follow-up-deprecation-spec.md)), several legacy files coexist marked `@deprecated`:

- `Models/FollowUp.php`, `FollowUpDay.php`, `FollowUpTemplate.php`, `FollowUpLog.php`
- Old DTOs in `DataTransferObject/`
- `Activities/FollowUpPromptActivity.php`

Don't extend these. Don't reference them from new code. They're awaiting deletion.

## v1 scope reminder

V1 ships Lead-only, WhatsApp-only, `time_based` mode only. See the [v1 spec](../../../../docs/intelligence/follow-up-v1-spec.md) for the locked decisions. The generic primitives are designed to support more, but the per-entity executors and channels come incrementally.

## Follow-up agent runs in rollup mode (cross-session history)

`FollowUpLeadAction` calls the kernel with `sourceChannel: $session->channel` — the same hint connector channel responders use. With it, `AgentChatKernel::threadId()` threads the run by the **session entity's uuid** (the lead's person) instead of the session uuid, and `EntityRollupMessageStore` filters by session only when the thread *is* a session uuid — so the agent sees the full cross-session history for the lead's person.

Without this hint, the thread would be `$session->uuid` and the store would drop every message that lives under a different session — typically every inbound customer message and every message from earlier conversation threads. The agent then runs with effectively no history and produces literal-template-copy bland follow-ups.

Bug surfaced 2026-06-08 on lead 25628: agent had 46 People-keyed message rows available but only 16 passed the thread filter (today's cron prompts/responses). The actual 3-day-old customer conversation with the "ping me back Sunday" reply was in the 27 messages with `null` thread_id (legacy inbound, never tagged) — all filtered out. After the fix, the agent sees the full conversation regardless of which session originated the message.

## The agent never terminally exhausts a lead

The lead's `ai_follow_up` custom field (set by `TriggerIntelligenceActivity` via
`ApplyLeadAiModeAction`) gates only once it exists. An absent value means the triggers
never configured the lead, so the remaining gates run. Once present it must be explicitly
on per `Lead::isAiFollowUpEnabled()` — the shapes the legacy engine accepted — so OFF (`0`)
and a `FollowUpTypeEnum::NO_FOLLOW_UP` (`3`) type both skip with `lead_follow_up_disabled`.
This check applies even to forced runs; enabling it does not override a disabled
lead type. `lead_has_follow_up` is the legacy mirror written by the trigger.

The lead's resolved AI mode (`Lead::isAiMuted()`, key `ai_mode`) also gates: IDLE or
OFF skips with `ai_mode_off`, pausable like `ai_mode_manual`. This is the same mute
every channel responder and `AgentReachOutAction` honour; the follow-up agent is not
allowed to speak for a lead whose AI is off.

## "Open" means `leads_status_id`, never the `status` column

Both the fan-out query and the action's `lead_not_active` gate use `hasOpenLeadStatus()` /
`scopeHasOpenLeadStatus($company)`: the global open ids plus the company's
`guild_open_leads_status_ids`. `Lead::isOpen()` reads the `status` integer column, which no app
code writes (758k of 759k local rows sit at the default `0`) — a gate on it passes everyone. CRM
pulls and the UI write `leads_status_id` (`close()` / `open()`), so that is the only column that
reflects a close.

## Third-party leads are pulled from their CRM before the follow-up runs

A dealer CRM without webhooks never tells us a lead was closed or sold, so the action asks it —
**after every free gate has passed** (stage config, `ai_follow_up`, lead type, open status,
exhaustion, hand-off, manual, mute, reachable channel, session, `too_soon`, template) and right
before the agent call. CRM calls are billed per request (Elead), so a lead that would skip anyway
must never cost one. `FollowUpLeadAction::syncLeadSource()` runs
`SyncLeadSourceBeforeFollowUpAction` and then re-runs the three gates a pull can flip (open
status, `ai_follow_up`, mute). "Where is this lead from and how do I ask it" lives in Guild:
`Guild\Leads\Services\CrmLeadSourceSynchronizer`, a concrete class `LeadFollowUpJob`
method-injects and hands to the action (no provider binding). It dispatches on the company's CRM
through the SalesAssist `PullLeadAction` exactly like the pull button, then re-applies
`ApplyLeadClosingStatusAction` so a CRM close flips `ai_mode` / `ai_follow_up`. The forced-run
mutation passes no synchronizer, so a manual click never pulls.

- `canSync()` requires the company to run a pull-capable connector (Elead, VinSolutions,
  DealerSocket, DriveCentric — Reynolds is webhook-only) AND the lead to carry that connector's
  external id. Without the id the pull becomes a people search by phone, which can create or
  close leads; an hourly cron must not trigger that.
- Throttle: the lead custom field `guild_follow_up_source_synced_at` is stamped after a pull and
  skips the next one for `lead_follow_up_sync_interval_minutes` (company setting, default 55) —
  one CRM call per lead per tick, forced runs in between share it.
- A failed pull skips with `reason: crm_sync_failed` and is reported; the next tick retries.
  Nudging a lead whose state could not be verified is the thing the step exists to prevent. The
  Elead 404 case (opportunity gone) closes the lead inside the pull.
- Tests swap the seam by passing a Mockery mock of `CrmLeadSourceSynchronizer` to the action's
  `synchronizer:` or to the job's `handle()`; a bare lead on a company with no CRM config is simply
  not syncable, so the rest of the suite needs nothing.

## A follow-up is filed on the channel it was sent through

Each sent body is added to the protocol channel of its target — `twilio-<phone>`, `email-<address>`,
`wa-chat-<phone>-at-swhatsappnet` (`SessionChannelService::createChannelSlug()`), the same slugs the
inbound connectors write — never to the follow-up session's channel, which may be another protocol.
An existing channel with that slug is reused whatever entity opened it; a first touch opens it on the
lead's person. The lead's default channel still gets it too, for the UI.

## Outbound SMS carries the opt-out clause on the first text

`SmsOptOutNoticeService` is applied per SMS target before the message is persisted, so the
stored row matches what shipped. "First" is judged on the lead's **SMS channel**
(`SessionChannelService::createChannelSlug('sms', $phone)`), not the follow-up session's
channel — the session may live on email/WhatsApp and a prior email must not suppress the
clause on the first text. No SMS channel yet means nothing has ever been texted, so the
clause is appended. `FollowUpOutcome::$message` reports the primary target's body as sent.

## `after-follow-up` workflow — the CRM hook

Once at least one channel shipped, the action fires `WorkflowEnum::AFTER_FOLLOW_UP`
(`after-follow-up`) **on the Lead**, so a tenant can hang connector activities on it
(mirror the touch as a note in Elead / VinSolutions / DealerSocket, etc.) without the engine
knowing about any CRM. Rule type row: `database/migrations/Workflow/2026_10_13_120000_*`.
Advance-only runs do not fire it; the `lead.follow_up.sent` ledger event covers those.

Params the activity receives (besides the Lead entity and the app):

| key | value |
|---|---|
| `message` | the persisted `Message` for the primary target |
| `body` | the body as shipped on the primary target (opt-out clause included for SMS) |
| `channels` | channel types dispatched this touch, e.g. `['sms']` or `['sms', 'email']` |
| `template` | template name used, or `null` |
| `stage_id` | the lead's stage after any advance |
| `advanced_stage` | whether this touch moved the lead to the next stage |
| `agent` | the follow-up `Agent` |
| `company` | the lead's company (also what scopes rule lookup) |

A failing tenant rule is reported and swallowed — the touch is already sent and bumped.

Lead-type continued-follow-up defaults can veto execution before retry handling or
stage advancement. `LeadConfigurationService::allowsContinuedFollowUp()` reads
`*_con_fu_active_default`, `*_con_fu_cns_default`, or
`*_con_fu_closed-sold_default` for the lead state. Missing settings allow the
remaining gates to run; explicit false values skip with
`lead_type_follow_up_disabled`, even for forced runs. First-message defaults are
independent. This veto does not override the engine's open-leads-only restriction.

## The fan-out runs the lead gates before queueing

`DispatchAppLeadFollowUpsJob` calls `LeadFollowUpEligibilityService::dispatchSkipReason()` for every
SQL candidate and only queues leads with no skip reason, so a lead followed up (or messaged) inside
the stage interval, exhausted, manual or muted never takes a `lead_follow_ups` slot. Each candidate
still leaves a ledger record: `lead.follow_up.skipped` with the reason and `source: dispatch`, or
`lead.follow_up.queued` (counted as `queued` in the daily summary). Leads at
`max_retries` or handed off are still queued inside the interval: only the action exhausts them and
applies `exhausted_action`. The action runs the same service methods, so the gates live in one place;
channel, session, template and CRM checks stay in the action.

The company setting `lead_follow_up_start_date` (`IntelligenceConfigurationEnum::LEAD_FOLLOW_UP_START_DATE`)
is a cutoff: a lead created before it skips with `lead_created_before_follow_up_start_date`, forced
runs included, and the fan-out query never queues it. Unset, empty or unparseable means no cutoff, so
a typo in the setting never silences every lead. Read through
`LeadConfigurationService::getFollowUpStartDate()` in both places.

`should_respond: false` + `advance_stage: false` from the LLM is treated as a **transient SKIP**, not a terminal exhaustion. The next cron tick re-evaluates the lead. The skip emits `lead.follow_up.skipped` with `reason: 'agent: <text>'` for ledger visibility — no `exhausted_at` set, no operator reset required.

This was a real bug: the agent was using `should_respond: false` to mean "wait longer, customer is still digesting" and the action treated that as terminal exhaustion. Leads got stuck after a single agent timing-feedback decision. Fixed 2026-06-08.

**Terminal exhaustion has only three sources:**
- `max_retries` hit (count of actual sends, configured per stage), unless `exhausted_action: advance` successfully moves to the next stage. Successful advancement returns a non-terminal skip (`max_retries_stage_advanced`); the stage observer resets the count and wait window.
- `agent_hand_off` flag set on the lead (explicit human takeover)
- Operator calls `resetLeadFollowUp` / clears state manually

**Risk to watch:** since skipping doesn't bump `count`, an agent that keeps declining indefinitely produces unlimited cron-tick LLM calls without ever sending. If you see leads with `count: 0` and many `lead.follow_up.skipped` events with `agent:` reasons piling up, add an `agent_declines` counter to `follow_up_state` with its own cap (e.g. 5) that auto-exhausts. Deferred until the pattern shows up in production.

The inbound re-engagement hook in `BaseAgentChannelReplyAction` (which checks `str_starts_with($reason, 'agent:')` on the exhaustion reason) stays in place for legacy data — leads exhausted via the old `agent:` path will still resume cleanly on customer reply.

## Reports: the daily summary email and `lead:follow-up-status`

Both read the ledger, and both split real failures from skips with
[`FollowUpFailureReasonEnum`](Enums/FollowUpFailureReasonEnum.php): `agent_call_failed`, `crm_sync_failed`,
`no_reachable_channel`, `no_session`, `send_failed`, `job_failed`. Those count as **failed** and never as
skipped; every other skip reason (too_soon, ai_mode_off, agent declined...) is a gate doing its job.

- `lead.follow_up.send_failed` — `dispatchOutbound()` writes it per target the provider refused, with
  `channel` and `error`. `SendMessageToLeadAction` returns `success: false` for a rejection it will not
  retry instead of throwing, so the result is checked as well as the exception. The touch still counts
  as `sent` (the message was stored and the count bumped), so a refused send shows in both columns.
- `lead.follow_up.failed` — `LeadFollowUpJob::failed()` writes it once the last try is spent, so a run
  that crashed is not left looking like one still `queued`.
- `lead:follow-up-daily-summary` (00:30 UTC) builds `follow_up.daily.summary` with a `failed` bucket
  (`total`, `by_reason`, the latest 20 in `recent`) and emails it through
  `SendLeadFollowUpDailySummaryAction` to the company's active members holding the app's `FollowUpDev`
  role (`UsersRepository::getActiveCompanyAppUsersByRole`, the same lookup as the Engage usage
  report). New apps get the role from `CreateAppsAction::acl()`; an existing one needs
  `kanvas:create-role FollowUpDev {app_id}`. No role holders, or a day with nothing queued, sent,
  failed or exhausted, sends nothing. Template `lead-follow-up-daily-summary`
  (`resources/views/emails/leadFollowUpDailySummary.twig`); an existing app gets it with
  `kanvas:email-template-sync`.
- `lead:follow-up-status [--app_id] [--company_id] [--hours=24]` is the `kanvas:status` of follow-ups:
  one row per company (queued, sent, skipped, failed, exhausted, last sent, failures by reason), plus
  the latest failures when filtered by company. Counts are grouped in SQL by
  `LeadFollowUpActivityService` — the dispatch writes a row per candidate per hour, too many to load.
  The queue line (`lead_follow_ups` waiting and `failed_jobs`) is global: a failed job row cannot be
  told apart by company.

## Agent resolution — current design + planned evolution

**V1 (current):** The job resolves the Agent via:

```php
$agentName = $config?->agentName ?? AgentEnum::FOLLOW_UP_ENGAGER->value;
$agent     = Agent::getByNameFromCompanyApp($agentName, $this->company, $this->app);
```

…then passes the `Agent` into the action constructor. The action never knows about `AgentEnum` — it just uses the agent it was given. Stage config can override per stage via `follow_up.agent_name`.

**V1.5 evolution — role mapping per tenant:**

Today `AgentEnum` is a flat list of agent names. Long-term, we want a company-level (or app-level) `role → agent_name` mapping so different tenants can point the same role at different agents without code changes:

```jsonc
// company.config or app.settings
{
  "agent_roles": {
    "follow_up": "FollowUpEngagerAgent",   // tenant A
    "sales":     "SalesAgent",
    "support":   "SupportAgent"
  }
}
```

The job would resolve via:
```php
$role      = $config?->agentRole ?? 'follow_up';                              // role from stage config
$agentName = $company->get('agent_roles')[$role] ?? AgentEnum::FOLLOW_UP_ENGAGER->value;
$agent     = Agent::getByNameFromCompanyApp($agentName, $this->company, $this->app);
```

Action signature doesn't change. Job's resolution gains a role-mapping lookup. Stage config keeps an optional `agent_name` for direct override (skip the role table) AND/OR an `agent_role` for tenant-mapped lookup.

When implementing v1.5: add the `agent_roles` company config, add `agent_role` to `FollowUpConfig` DTO + stage JSON, update the job to do the two-step lookup. Action stays unchanged.

## Pipeline configuration patterns

A pipeline's `stage.config.follow_up` JSON drives behavior. Two canonical shapes the engine is built to handle — keep both in mind when designing for a new tenant. The full worked configs live in [`docs/intelligence/follow-up-setup-guide.md`](../../../../docs/intelligence/follow-up-setup-guide.md) §7; the rules below are the durable ones.

### Pattern A — Sales funnel (one stage = one funnel position)

Stages model *intent state* (New / Qualified / Demo Scheduled / Pending Commitment / In Negotiation / Won). Each stage can take multiple touches (`max_retries > 1`); the agent's `advance_stage` decision is what drives forward motion within the funnel, not the calendar.

Typical config per stage:
- `interval_minutes`: hours-to-days, varies per stage (faster early, slower late)
- `max_retries`: 2–5 sends, varies per stage
- `exhausted_action`: usually `STOP` (let the operator decide what happens next), occasionally `ADVANCE` on the earliest stage (auto-bump cold leads out of "New")
- `prompt_template`: optional per-stage voice tweak (e.g. negotiation = surface blockers, don't push)

The engine reads conversation history and lets the agent judge readiness. Calendar is a gate, not a driver.

### Pattern B — Date-based drip (one stage = one day)

Stages model *time elapsed* (Day 1 / Day 2 / Day 3 / Day 4 / …). Each stage takes exactly one touch; advancement happens on schedule, not on agent judgment.

Required config per stage:
- `interval_minutes`: 1440 (or whatever the drip cadence is)
- `max_retries`: **1** — the mechanism that triggers the auto-advance
- `exhausted_action`: **`ADVANCE`** — moves to the next day-stage on exhaust
- `prompt_template`: instruct the agent to always return `advance_stage: true` on its single send. This collapses send-and-advance into one tick instead of two, and avoids spamming `lead.follow_up.exhausted` events per day-transition.

**Caveats unique to Pattern B:**
- **One-hour lag without the "always advance" prompt nudge** — without it, send happens on tick N (`count=0→1`), advance happens on tick N+1 (`count=1≥1` fires the exhaust gate). A successful transition records `lead.follow_up.skipped` with `reason: max_retries_stage_advanced`; it does not exhaust the new stage.
- **The interval restarts on stage entry, follow-up, or any message in either direction** — `LeadFollowUpEligibilityService::silenceMinutes()` feeds the person's last message to `followUpSilenceMinutesSince`, which uses the latest of these timestamps. Without any, it falls back to entity creation time. Missing or future references yield zero elapsed minutes. Resetting state on a stage change therefore starts the full next-stage interval.
- **Last-stage exhaust is a no-op** — `moveToNextPipelineStage()` silently does nothing when there's no next stage. The lead just parks there exhausted.

### What the infra does NOT do today

**"Always advance every calendar day, send or no send."** The advance gate is coupled to `count >= max_retries`, and `count` only bumps on a successful send. A lead that misses Day 1's send (work hours, AI manual mode, agent declined) will NOT auto-tick to Day 2 the next morning — it'll wait in Day 1 until a send actually happens.

If a tenant needs pure calendar-driven walking regardless of message outcome, that's a separate cron/command (~50 lines): iterate drip-enabled leads at midnight, bump `pipeline_stage_id` based on `stage_entered_at + N days`. Not in v1.

## Templates: style guide by default, wrapper when slotted

Stage `channels[].template_name` resolves to a `Templates` row. How it gets used depends on the body:

| Body contains `$agent_message`? | Behavior |
|---|---|
| **No** (static body) | Style-guide-only. Agent sees the rendered body in the prompt as a "tone reference" with placeholders pre-rendered. Outbound dispatches the **agent's own composed message**, not the template body — the template is purely there to set the tone. Use this when you want consistent voice without locking the body. |
| **Yes** (`{{ $agent_message }}` slot) | Wrapper. Outbound renders the template at send time with the agent's message in the slot. Use this when the agent should only compose an inner blurb and the template handles salutation/signature. |

The agent prompt shows the rendered body either way — with `[agent message slot]` substituted into the placeholder so the LLM understands where its content lands.

**The agent prompt ALWAYS has a tone reference.** When the stage has no `template_name` configured, [`renderDefaultStyleReference($channelType)`](Actions/FollowUpLeadAction.php) returns channel-specific neutral default copy (email gets a `"Best, {company}"` sign-off; sms/whatsapp gets a shorter inline one). This is critical — a "no template configured" prompt with no tone anchor was provoking Gemini to bail with canned fallback text (`"I ran into a hiccup processing that..."`). The agent never sees an empty reference block.

**The reference is for STYLE only, never for body content.** The prompt explicitly tells the agent: "Use the reference for HOW to write — NOT for WHAT to write." The BODY must come from the conversation history (prior questions, offers made, specific topics discussed). The prompt also bans generic phrases like `"wanted to circle back"` / `"anything I can help clarify"` when prior conversation context exists — those are reserved for genuinely cold leads. This explicit split was added after the agent was producing bland reminders that ignored real conversation context (e.g. a customer who got a concrete "10-min whiteboard session" offer 13h ago received a generic "wanted to circle back" follow-up). The fix is on the prompt-instruction side, not the template-rendering side.

**Important silence-calc behavior:** silence is measured against the person's last message in either direction ([`LeadFollowUpEligibilityService::getLastMessageAt`](Services/LeadFollowUpEligibilityService.php)) across **all** of the person's channels (not just the picked session's channel), so an agent reply or reach-out restarts the interval just like a customer inbound. The WhatsApp 24h window still uses the last **inbound** only (`getLastInboundAt`).

The verb filter (`whatsapp-text`) was dropped from the inbound query. `from_me = false` on a People-keyed channel is the only test; this catches email/SMS/WhatsApp inbound uniformly.

## Only open leads are nudged (`isOpen()` / status < 2)

Follow-up (and the outbound outreach in `AgentReachOutAction`) only act on **open** leads — `Lead::isOpen()` (`status < 2`). A closed lead (won/lost/inactive) sitting in a non-terminal nudge stage is excluded two ways:
- [`DispatchAppLeadFollowUpsJob`](Jobs/DispatchAppLeadFollowUpsJob.php) SQL-filters candidates to `status IS NULL OR status < 2` (null = open, matching the model; the column is nullable default 0) so closed leads never spawn a job.
- [`FollowUpLeadAction`](Actions/FollowUpLeadAction.php) re-checks `isOpen()` as a hard gate → `skip('lead_not_active')`. **Not bypassed by `force`** — force only relaxes the silence gate.

Inbound agent **replies** are intentionally NOT gated on status — if a customer writes back even on a closed lead, the agent still answers. Only proactive touches (outreach + follow-up) require an open lead.

`Lead::isOpen()` reads the `status` column explicitly (`getAttributeValue`) because `status` is both a column and a `status()` relation — `$this->status` on a partially-hydrated model resolves to the `LeadStatus` object and TypeErrors on `< 2`.

## Email follow-ups thread under the original outreach (`title_email_follow_up`)

Email follow-ups must land in the **same thread** as the agent's first outreach, not start a fresh one. The mechanism is a single lead custom field, `title_email_follow_up`, that all three email send-paths agree on:

| Path | File | Role |
|---|---|---|
| Outreach (first touch) | [`AgentReachOutOnChannelAction`](../Agents/Actions/Outreach/AgentReachOutOnChannelAction.php) | **Writes** the anchor — persists the agent's email subject to `title_email_follow_up`. First touch wins (guarded by `! $lead->get(...)`) so later reach-outs don't clobber it. |
| Inbound auto-reply | [`Mailgun/AgentChannelResponderAction`](../../Connectors/Mailgun/Actions/AgentChannelResponderAction.php) | **Reads** it as the outbound subject (falls back to `Re: <incoming subject>`). Read-only — never overwrites the anchor. |
| Follow-up (cron, days later) | [`FollowUpLeadAction::resolveEmailTitle()`](Actions/FollowUpLeadAction.php) | **Reads** it, prefixes `Re: ` (idempotent — won't double-prefix). Falls back to the company name when no anchor exists. |

Because all three read the same persisted field, the subject stays constant across outreach → customer reply → follow-up, and Gmail/Apple Mail group them into one thread (they strip `Re:` when threading). The anchor is a lead custom field, so it survives the multi-day gap between touches.

**Known limitations (deferred, by decision):**
- **Inbound-first leads have no anchor.** If a lead is created from a cold *inbound* email (no agent outreach ran), `AgentReachOutOnChannelAction` never fires, so `title_email_follow_up` is never persisted — the inbound responder uses `Re: <incoming subject>` but doesn't save it, so a later follow-up falls back to the company name and starts a new thread. Fix when cold-inbound becomes a real path: have the Mailgun inbound responder persist the anchor when it's not already set.
- **No RFC threading headers.** Threading is subject-based only; `In-Reply-To` / `References` aren't emitted because the outbound `message_id` is hardcoded `'--'`. Works in Gmail/Apple Mail; strict header-only clients (some Outlook configs) may not thread. Capturing the real Mailgun Message-ID on send is a separate task.

## Channel resolution lives on the Lead, NOT the Session

Reachable channels come from the person's `Contact` rows + stage-enabled channels + opt-outs — never from `Session::getChannel()` (which stays valid for inspection but is `@deprecated for outbound channel selection`).

Resolved in [`LeadOutboundChannelResolver`](Services/LeadOutboundChannelResolver.php) — contact-type priority per channel:

| Channel | Eligible contact types (best → fallback) |
|---|---|
| `email` | `PRIMARY_EMAIL` (9) → `EMAIL` (1) → `SECONDARY_EMAIL` (10) |
| `sms` | `CELLPHONE` (3) → `PHONE` (2) → `WORK_PHONE` (8) |
| `whatsapp` | `CELLPHONE` (3) only |

Within each type, ordered by `weight DESC`, then `id DESC`. Opt-outs (`is_opt_out = 1`) excluded. Returns `ResolvedChannel[]` in stage-config order.

`channel_selection` strategies:

| Strategy | Behavior |
|---|---|
| `priority_only` | One channel per touch — always `candidates[0]`. |
| `sticky_then_priority` (default) | **v1: aliases to `priority_only`.** True sticky requires a lead-scoped "last channel the customer reached out on" signal — the previous session-UUID-marker approach was unreliable in the new sales-agent infra. See v1.5 TODO. |
| `agent_picks` | **v1: aliases to `priority_only`.** Future agent-aware routing — see v1.5 TODO. |
| `fan_out_all` | **All reachable channels per touch.** Same agent message dispatched to every candidate. One touch = one bump (does NOT consume N retry slots for N channels). Same template + same body across channels — if you need per-channel templates, use a pick-one strategy instead. Reserved for high-urgency stages (demo reminder, deal closing). Tenants opt in per stage; default stays pick-one to avoid accidental cross-channel spam / TCPA-class compliance exposure. |

**Skip reasons:** `no_session` (no Session row for the person), `no_reachable_channel` (no stage-enabled channel has a matching non-opted-out contact). The old `channel_not_configured` skip is dead — was a session-driven false positive.

**Opt-out behavior change:** leads who unsubscribed but were still being nudged on opted-out contacts will now skip with `no_reachable_channel` if no other channel is reachable. Pre-fix the action didn't consult opt-outs at all.

### Open v1.5 work — channel routing intelligence

1. **Proper sticky implementation.** `ChannelSelectionEnum::STICKY_THEN_PRIORITY` should pick the channel the CUSTOMER last messaged on, not the session's UUID-marker channel. Query the most recent inbound `Message` across all the person's sessions/channels (lead-scoped, not session-scoped), determine its channel type from the message_type/channel join, and prefer that if it matches a candidate. Falls back to `candidates[0]` for cold leads. Remove the v1 alias-to-priority once implemented.
2. **Agent-aware routing.** `ChannelSelectionEnum::AGENT_PICKS` should consult per-channel agent decision history (`should_respond: false` outcomes from prior touches in this stage) and de-prioritize channels the agent already declined. Needs a "per-channel decision" sub-state in `follow_up_state`. Design alongside the v1.5 role-mapping work above.
3. **Touch-number rotation.** Add `ChannelSelectionEnum::ROTATE_BY_TOUCH` — `$candidates[$lead->getFollowUpStateCount() % count($candidates)]`. Deterministic "email first, sms second, whatsapp third, wrap" pattern. No sticky/priority signal consulted.

All three can ship independently — enum case (or branch for #1 which case already exists) + branch in `selectTargets` + test. Don't pre-build before a tenant actually needs it.

## `exhausted_action` is an enum — extend it deliberately

[`ExhaustedActionEnum`](Enums/ExhaustedActionEnum.php) currently has **two** cases. Always type-hint against the enum (DTOs already do); don't compare against raw strings in new code.

| Case | Value | Behavior |
|---|---|---|
| `STOP` | `'stop'` | Lead exhausts in current stage. `exhausted_at` set, no advance. Operator (or customer reply) is the only way out. Default. |
| `ADVANCE` | `'advance'` | Calls `moveToNextPipelineStage()`. On success, leaves the new stage eligible after its wait interval; exhausts only if there is no next stage. Used by drip pipelines + first-touch "auto-bump cold leads" shape. |

**Likely future cases — discuss before adding, but keep them in mind so we don't reinvent:**

- `ADVANCE_TO_STAGE` — jump to a specific named/id'd stage (not just next-by-weight). Needs a companion `exhausted_target_stage_id` config field. Use case: "if 4 retries in Pending Commitment exhaust, jump straight to Lost (not the next-by-weight stage)."
- `MARK_LOST` — set `leads_status_id` to a closed-lost status + emit `lead.pipeline.lost`. Use case: drip ended without engagement → operationally distinct from "stuck at end of pipeline".
- `LOOP_BACK` — go back to a previous stage (re-qualify). Niche; might be solvable with `ADVANCE_TO_STAGE` instead.
- `HAND_OFF_TO_HUMAN` — set `agent_hand_off: true` + notify the lead owner. The "soft escalation" path that's currently expressed only via the agent's `should_respond: false, advance_stage: false` decision.

When adding any new case: update the enum, update `FollowUpLeadAction::handleExhaustedAction()` to handle it, update [`docs/intelligence/follow-up-setup-guide.md`](../../../../docs/intelligence/follow-up-setup-guide.md) §1f, and add a test class under `tests/Intelligence/FollowUp/Actions/` covering the new behavior path.
