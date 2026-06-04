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
- **One-hour lag without the "always advance" prompt nudge** — without it, send happens on tick N (`count=0→1`), advance happens on tick N+1 (`count=1≥1` fires the exhaust gate). The ledger then carries one `lead.follow_up.exhausted` per day-stage with `reason: max_retries`.
- **Customer inbound pauses the day clock** — `followUpSilenceMinutesSince` uses `last_inbound_at` as the reference. A reply during Day 2 resets `silence_minutes`, so the Day 2 nudge waits another 24h. Usually desired (don't pile on the engaged customer). If you need strict calendar advance regardless of conversation activity, swap the silence reference to `stage_entered_at` — this is the only line that needs changing.
- **Last-stage exhaust is a no-op** — `moveToNextPipelineStage()` silently does nothing when there's no next stage. The lead just parks there exhausted.

### What the infra does NOT do today

**"Always advance every calendar day, send or no send."** The advance gate is coupled to `count >= max_retries`, and `count` only bumps on a successful send. A lead that misses Day 1's send (work hours, AI manual mode, agent declined) will NOT auto-tick to Day 2 the next morning — it'll wait in Day 1 until a send actually happens.

If a tenant needs pure calendar-driven walking regardless of message outcome, that's a separate cron/command (~50 lines): iterate drip-enabled leads at midnight, bump `pipeline_stage_id` based on `stage_entered_at + N days`. Not in v1.

## `exhausted_action` is an enum — extend it deliberately

[`ExhaustedActionEnum`](Enums/ExhaustedActionEnum.php) currently has **two** cases. Always type-hint against the enum (DTOs already do); don't compare against raw strings in new code.

| Case | Value | Behavior |
|---|---|---|
| `STOP` | `'stop'` | Lead exhausts in current stage. `exhausted_at` set, no advance. Operator (or customer reply) is the only way out. Default. |
| `ADVANCE` | `'advance'` | Lead exhausts in current stage AND `moveToNextPipelineStage()` is called. Used by drip pipelines + first-touch "auto-bump cold leads" shape. |

**Likely future cases — discuss before adding, but keep them in mind so we don't reinvent:**

- `ADVANCE_TO_STAGE` — jump to a specific named/id'd stage (not just next-by-weight). Needs a companion `exhausted_target_stage_id` config field. Use case: "if 4 retries in Pending Commitment exhaust, jump straight to Lost (not the next-by-weight stage)."
- `MARK_LOST` — set `leads_status_id` to a closed-lost status + emit `lead.pipeline.lost`. Use case: drip ended without engagement → operationally distinct from "stuck at end of pipeline".
- `LOOP_BACK` — go back to a previous stage (re-qualify). Niche; might be solvable with `ADVANCE_TO_STAGE` instead.
- `HAND_OFF_TO_HUMAN` — set `agent_hand_off: true` + notify the lead owner. The "soft escalation" path that's currently expressed only via the agent's `should_respond: false, advance_stage: false` decision.

When adding any new case: update the enum, update `FollowUpLeadAction::handleExhaustedAction()` to handle it, update [`docs/intelligence/follow-up-setup-guide.md`](../../../../docs/intelligence/follow-up-setup-guide.md) §1f, and add a test class under `tests/Intelligence/FollowUp/Actions/` covering the new behavior path.
