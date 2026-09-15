# Approvals — generic, cross-domain approval gating

Any entity registered as a system module can be submitted for approval, tracked to whoever approved
it, and fire a workflow on the decision. Lives in `src/Kanvas/` (not `src/Domains/`) because every
domain depends on it and nothing may depend backwards on a `Domains/` sibling.

## Making a model approvable

1. A `system_modules` row for the model. **Per app** — `getByModelName` keys on `(model_name, apps_id)`
   and the `apps_id=0` rows never resolve for a concrete app, so
   `kanvas:create-global-system-modules-from-template --app_id=X` must have run for that app.
2. `use HasApprovals;` on the model.
3. An `approval_policies` row. This is the real registration: what "approving an X" means here.

Nothing happens without step 3. The trait is inert for a tenant with no policy, which is what makes
adopting it on a production model safe. The exception is a model that is already held before the
policy is read — see the agent-message section.

A very-hot model should also turn the lifecycle triggers off
(`approvalUsesLifecycleTriggers(): false`) and gate explicitly; otherwise every save costs a policy
lookup.

## Tables

| Table | Holds |
|---|---|
| `approval_requests` | One request. Polymorphic via `system_modules_id` + `entity_id`. |
| `approval_request_approvers` | Who was asked, at which step, what they said. The "approved by who" answer. |
| `approval_policies` | Who signs, in what order, what runs on success. |

All three are on the `ecosystem` connection. **Tests touching an approvable model must list BOTH
`'ecosystem'` and `'intelligence'` in `$connectionsToTransact`** (plus the entity's own connection —
`'social'` for messages) — `ecosystem` for the approval rows
(it points at the same database as `mysql`, but Laravel treats it as a separate connection, so
without it every row commits for real) and `intelligence` for the ledger events every decision emits.
This has bitten three times; assume any new approvals test needs both.

`entity_id` is an integer, deliberately unlike `filesystem_entities`' `char(36)`:
`pendingApproval()` runs on every save of every approvable model and a string-vs-int compare loses the
index on that hot path.

## The three lanes on a decision

- **Sync — `ApprovalHandlerInterface`** (the policy's `handler`). Runs in-process and returns a result
  array, so an LLM tool or mutation learns whether the downstream write actually landed. This is where
  the Acumatica push lives.
- **Async — the workflow.** Fires after the handler with its result in `params`. The
  tenant-configurable extension point.

- **Sync, on reject — `ApprovalRejectionHandlerInterface`.** Optional, same handler class. Only for an
  approval where saying no has a side effect of its own (discarding a draft). Most have nothing to undo.

A handler that throws does **not** undo a recorded decision; the failure comes back as `handler_error`
so the caller can say "approved, but the push failed". A caller for whom that split is meaningless —
approving a message draft means sending it — checks `handlerResult['handler_error']` and raises.

`ApproveAction` also takes an optional `$context`: what the *approver* supplied, as opposed to what the
requester froze in `payload`. It is persisted to `metadata.decision_context` before the handler runs
and read back with `ApprovalRequest::decisionContext()`, so what the handler acted on is auditable
rather than a value that only passed through a call.

## Where the workflow fires — and what is temporary

`ApprovalWorkflowService::fire()` fires on **both** the `ApprovalRequest` row and the target entity.

- The **request row is permanent**. One rule on that system module catches every approval; per-type
  targeting comes from a rule *condition* (`approval_type == 'approve_bill'`), and `target` is passed
  in params so conditions can also read the approved record's own fields.
- The **entity fire is rollout compatibility only** — it keeps rules a tenant already attached to a
  Bill/Expense working during adoption. Delete it once
  `kanvas:approvals:list-entity-fired-rules` reports none. Do not build new behaviour on it.

## `allow_authority_override` — when an owner or admin may take a request nobody gave them

Off by default, per policy, and the default is the load-bearing part: adopting `HasApprovals` must
never quietly make every admin an approver of everything. On a bill the approver list **is** the
control — "only these people sign" is worthless if an admin can wave one through.

Turn it on only where the approval is *review* rather than a control, and where the resolvers cannot
be relied on to find the right humans. The agent-message policy sets it because `channel_members`
resolves nobody on tens of thousands of channels, so the request lands on whichever account owns the
company while the person actually looking at the card is refused it.

`ApproverSelfAssignService` runs inside `ApproveAction`/`RejectAction`, so every entry point behaves
the same and no caller can forget it. Two properties make it safe to have at all:

- **It self-assigns, it does not bypass.** The user gets a real approver row and then goes through
  `requireApproverRow()` like anyone else, so authorization still comes from the rows and nowhere else.
- **It records the authority.** `metadata.self_assigned_approvers` (and the ledger payload) carries who
  took it, whether as `owner` or `admin`, and when — so a decision nobody was asked for stays
  distinguishable afterwards from one that was resolved normally.

Company membership is checked *before* the role, because `isAdmin()` reads app-scoped Bouncer state
and would otherwise let an admin of one company decide another's.

## Approving from code

`ApproveAction` is the single entry point — GraphQL, LLM tools, commands and tinker all go through it,
so the approver check can't be bypassed. Convenience: `$entity->approve($user)` / `$entity->reject(...)`.

Approving without a human is a **separate class**, `SystemApproveAction`, requiring a reason — so
`grep -r SystemApproveAction src/` lists every approval granted without a person. Prefer expressing
rule-shaped auto-approval as a step `when` condition instead; a step whose condition fails is simply
skipped and needs no bypass code.

## Foot-guns

- **Never memoize the policy lookup in a static.** Octane and queue workers reuse the process, so a
  static keeps serving a policy that has since been edited or deleted. If it ever shows in a profile,
  cache in Redis with invalidation on policy write.
- **Kanvas soft-delete fires `updated` as well as `softDeleted`** (it goes through `saveOrFail()`), so
  the `on_update` trigger guards on `is_deleted` — otherwise deleting a record opens an approval on
  its way out.
- **Origin is passed, never sniffed.** There is no container-bound "current agent", and an agent's
  user is shared across agents. `ApprovalOriginService` derives only console/UI/API; anything that
  knows better wraps its work in `ApprovalOriginService::during(...)`. To gate on provenance without
  threading a caller, condition on the entity's own data (Scribe stamps `source_email_message_id`).
- **Everything in the trigger path is best-effort.** A broken policy must never fail the create it was
  meant to gate — a missing approval is visible and recoverable, a failed create is lost work.
- **Commands iterating apps must `overwriteAppService($app)` per iteration** — `RoleApproverResolver`
  reads Bouncer-scoped roles.

## Every decision goes to the ledger

`ApprovalWorkflowService::fire()` is the single announcement point: it writes a NervousSystem ledger
event *and* fires the workflow rule engine. Both, because they answer different questions — the rule
engine is the tenant's extension point, the ledger is the durable audit.

The audit matters here because nothing else answers it. A domain's own event (`scribe.bill.received`)
records the resulting state change, never the decision that produced it — not who signed, not who
else was asked, not who declined — and a non-Scribe approvable entity emits nothing at all. The
payload carries the full approver trail for exactly that reason.

Event types: `approvals.approval_requested`, `.approval_unassigned`, `.approval_step_completed`,
`.approved`, `.rejected`, `.approval_expired`, `.approval_cancelled`.

- Cancellation is recorded but fires **no** workflow — nobody decided anything, so there is no rule to
  run, but a withdrawn request still has to be auditable.
- The actor is `resolved_by_users_id ?? requested_by_users_id`. The trait's default reads
  `users_id`/`agent_id`, which this table does not have, so without the override every approval would
  be filed against `System`.
- Emission is best-effort. A ledger write must never roll back the decision it describes.
- `EventStatusEnum` has only `INFO`/`SUCCESS`/`ERROR`. A rejection is `INFO`, not an error — `ERROR`
  stays reserved for the ledger's own failures so a dashboard filtering on it does not fill up with
  people declining bills.

## Agent surface

| Tool | Does |
|---|---|
| `check_approval_status` | Read-only. Is this approved, and if not who are we waiting on, at which step, and what did everyone else say. Reads the generic domain, falls back to the legacy queue, so it answers the same either side of a tenant's cutover. |
| `approve_pending_item` | Decides. Generic first, legacy fallback. |

They are deliberately separate, and wired differently on the accounting agents: `approve_pending_item`
is gated on `requestingHuman()` because approving must authorize against the real person, never the
agent's own user on an @mention surface. `check_approval_status` authorizes nothing, so it is always
available — an agent should be able to report where something stands without the tool that decides it
being in reach.

## Building a document timeline on top of this

`ledgerEvents` is `@guard` and filters on `source_entity_type` + `source_entity_id`, so one query
returns every domain's events for one record, in order. That is the timeline primitive — the approvals
domain contributes to it, it does not own it.

What an AP bill can render today: `scribe.document.email_received` (anchored to the PDF, from the
Mailgun inbound job), `scribe.bill.received`, and the whole `approvals.*` sequence including the
downstream push outcome in `payload.result`.

Still unemitted, so a timeline shows gaps: bill *creation* (only `received`/`voided` emit), the agent's
extraction step and its confidence, and the Google Sheets write. Each is one `emitLedgerEvent()` call
at a point that already exists.

## Where a handler lives

Approving is domain work; pushing to an ERP is connector work. A handler that does both lives with the
**connector**, not the domain — `Kanvas\Connectors\Acumatica\Approvals\ApproveAndPushBillHandler`,
not `Kanvas\Scribe\Bills\Approvals\...`. Naming it in the policy row is then what makes the ERP
dependency explicit: a tenant on a different ERP seeds the same policies with a different handler class
and nothing else changes. A handler with no external push (approving an expense) stays in its domain.

Do not put approval handlers in a connector's `Handlers/` folder — that name is taken by the
connector's own `BaseIntegration`. Use `{Connector}/Approvals/`.

## Agent messages — the second adopter, and the one with a UI contract

A held agent draft (an outbound reply a company in AI approval mode wants signed off, or a card an
agent posts to ask a human for a decision) is an `approval_requests` row like any other. `Message`
uses `HasApprovals`; there used to be a second engine here — a lock flag plus a JSON blob with its own
approve/reject actions — and it is gone.

| Piece | Where |
|---|---|
| Opening one | `Social\Messages\Actions\RequestMessageApprovalAction` — the ONLY way a message gets held |
| What approving does | `Social\Messages\Approvals\AgentMessageApprovalHandler` (the policy's handler) |
| Per-card action | `Intelligence\Agents\Contracts\AgentApprovalHandler` — one class per kind |
| The card payload | `Social\Messages\Support\MessageApproval` |
| Default policy | `Social\Messages\Approvals\MessageApprovalPolicyService`, or `kanvas:approvals:seed-message-policy` |

### The handler is per-policy here, but the action is per-message

`approval_policies.handler` is one class per approval type, while each card runs something different.
`AgentMessageApprovalHandler` is therefore a **dispatcher**: it reads the card's own
`approval.handler` and runs it, or ships the draft down its channel verb when there is none. A new
approval kind is a new `AgentApprovalHandler` and nothing else — no policy edit, no arm added to a
match. Do not add per-kind branching to the dispatcher.

### `is_locked` is derived state with exactly one writer

`RequestMessageApprovalAction` sets it; the decision clears it. Nothing else may `setLock()` to gate a
message — a second writer is how the card and the record drift apart. (`setLock()` still has unrelated
non-approval uses — a scheduled first-touch, a WaSender lead hold — those are holds, not approvals,
and stay as they are.)

### The card payload is a projection, not the record

The frontend renders off `message.message.approval` — `{kind, status, context}`, `status` exactly
`pending` — and that contract is fixed in another codebase. It is written when the request opens and
settled when it resolves. `approveAgentMessage` / `rejectAgentMessage` stay as adapters over
`ApproveAction`/`RejectAction`: they carry an edited draft, which the generic mutation has no place
for. **Settling matters**: unlocking alone leaves an approved draft still offering a live Approve
button for work already done.

### One approval_type, kind in the payload

`agent_message`. A type per kind would need a policy row per kind per tenant, and the approver
question is the same shape either way. A tenant that does want to treat one kind differently puts a
`when` on `payload.kind` on a step — mutually exclusive `when`s across steps express *alternative*
reviewer sets, since exactly one becomes live and the rest are recorded SKIPPED.

### Approvers are the channel's members, plus the authority override

One step, `channel_members`, falling back to `company_owner`, and `allow_authority_override` on —
without the override the default alone strands most requests, since most channels have no members.

Resist the temptation to route held outbound to the company instead on the grounds that a customer's
DM channel holds no reviewers: on a real dealership tenant the owner is an account nobody signs into,
and the channel member is the person doing the reviewing. Narrowing this is what produces *"You are
not an approver for this request at its current step."* on a card the reviewer can plainly see.

Editing the policy does **not** repair requests already open — `RequestApprovalAction` materializes
the whole chain up front, deliberately, so the audit can say who was asked on the day. Pending rows
keep the approver list they were opened with.

### Two rules this adopter breaks, on purpose

- **The policy is auto-provisioned**, unlike every other approvable model. For a bill, no policy means
  no gate, which is the normal case. A message is already locked by the time the policy is looked up:
  no policy would mean a draft nobody can approve and nothing can send.
- **Lifecycle triggers are off** (`Message::approvalUsesLifecycleTriggers()` returns false). Messages
  are gated explicitly; this is the highest-write table on the platform and every save would otherwise
  cost an `approval_policies` lookup to learn there is no on-create policy. Any other very-hot
  approvable model should do the same.

### Rejecting has a side effect here

`ApprovalRejectionHandlerInterface` — the optional mirror of `ApprovalHandlerInterface`. A rejected
draft is soft-deleted, because leaving it recoverable in the feed invites it being sent later by a
path that only checks the lock. Most approvals have nothing to undo and implement neither.

## Scribe AP/AR adoption status

`accounting.approval_queue` is **deprecated**. The table stays for the history in it, and it still
receives rows for tenants with no policy configured — but a tenant that has one writes only
`approval_requests`. A tenant moves over by having a policy seeded, not by a deploy.

Nothing is written twice, which is the point: a legacy row that the generic engine later resolved
would sit `pending` forever, since only `ResolveApprovalAction` knows how to close it.

| Piece | State |
|---|---|
| `SubmitBillForApprovalAction`, `SubmitExpenseForApprovalAction`, `CreateArInvoiceTool` | **Either/or, not both.** A tenant with a policy writes only `approval_requests`; one without still writes the legacy queue row exactly as before. |
| `ApprovePendingItemTool` | Generic first, legacy fallback. Same result shape either way, so the AP/AR agent guidance is unchanged. |
| Handlers | `Connectors\Acumatica\Approvals\{ApproveAndPushBill,IssueAndPushInvoice}Handler`, `Scribe\Expenses\Approvals\ApproveExpenseHandler`. |
| Slack DM | **Still Scribe's `NotifyApproverAction`**, fired from the intake paths. Seeded policies therefore use `notify: 'none'` so the generic mail layer does not double-notify real people. |

### Rolling a tenant over

```
kanvas:approvals:seed-scribe-policies {apps_id} {company_id}   # switches the tenant on
kanvas:approvals:backfill {apps_id} --company_id=X --dry-run   # inspect
kanvas:approvals:backfill {apps_id} --company_id=X             # open requests for still-open queue items
```

Rolling back is deleting the policy rows: the tool falls straight back to the legacy engine.

Only OPEN queue items are backfilled. Closed ones stay put — `approval_queue` remains the accounting
audit of what happened before the cutover, and copying settled history into a second place would give
two answers to the same question.

### Not done yet

- The legacy writers still exist for unmigrated tenants. Deleting them, plus `ResolveApprovalAction`
  and `ResolveApproverEmailAction`, is the final cutover once every tenant has a policy.
- Slack notification has not moved into this domain, so generic notifications are mail-only and carry
  no invoice PDF. That move is what lets `notify` go back to `'all'`.
- An expired request does not walk its entity back to draft — the request closes, the bill stays
  `PENDING_APPROVAL`. Needs a decision on what expiry should mean per entity.
- Nothing stops the entity being edited between submission and sign-off (see the plan's §B.11).
