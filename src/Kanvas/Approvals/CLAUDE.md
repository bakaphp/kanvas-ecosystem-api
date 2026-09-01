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
adopting it on a production model safe.

## Tables

| Table | Holds |
|---|---|
| `approval_requests` | One request. Polymorphic via `system_modules_id` + `entity_id`. |
| `approval_request_approvers` | Who was asked, at which step, what they said. The "approved by who" answer. |
| `approval_policies` | Who signs, in what order, what runs on success. |

All three are on the `ecosystem` connection. **Tests touching an approvable model must list BOTH
`'ecosystem'` and `'intelligence'` in `$connectionsToTransact`** — `ecosystem` for the approval rows
(it points at the same database as `mysql`, but Laravel treats it as a separate connection, so
without it every row commits for real) and `intelligence` for the ledger events every decision emits.
This has bitten three times; assume any new approvals test needs both.

`entity_id` is an integer, deliberately unlike `filesystem_entities`' `char(36)`:
`pendingApproval()` runs on every save of every approvable model and a string-vs-int compare loses the
index on that hot path.

## The two lanes on approval

- **Sync — `ApprovalHandlerInterface`** (the policy's `handler`). Runs in-process and returns a result
  array, so an LLM tool or mutation learns whether the downstream write actually landed. This is where
  the Acumatica push lives.
- **Async — the workflow.** Fires after the handler with its result in `params`. The
  tenant-configurable extension point.

A handler that throws does **not** undo a recorded approval; the failure comes back as `handler_error`
so the caller can say "approved, but the push failed".

## Where the workflow fires — and what is temporary

`ApprovalWorkflowService::fire()` fires on **both** the `ApprovalRequest` row and the target entity.

- The **request row is permanent**. One rule on that system module catches every approval; per-type
  targeting comes from a rule *condition* (`approval_type == 'approve_bill'`), and `target` is passed
  in params so conditions can also read the approved record's own fields.
- The **entity fire is rollout compatibility only** — it keeps rules a tenant already attached to a
  Bill/Expense working during adoption. Delete it once
  `kanvas:approvals:list-entity-fired-rules` reports none. Do not build new behaviour on it.

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

## Where a handler lives

Approving is domain work; pushing to an ERP is connector work. A handler that does both lives with the
**connector**, not the domain — `Kanvas\Connectors\Acumatica\Approvals\ApproveAndPushBillHandler`,
not `Kanvas\Scribe\Bills\Approvals\...`. Naming it in the policy row is then what makes the ERP
dependency explicit: a tenant on a different ERP seeds the same policies with a different handler class
and nothing else changes. A handler with no external push (approving an expense) stays in its domain.

Do not put approval handlers in a connector's `Handlers/` folder — that name is taken by the
connector's own `BaseIntegration`. Use `{Connector}/Approvals/`.

## Scribe AP/AR adoption status

The generic domain and `accounting.approval_queue` run **side by side**, and a tenant moves over by
having a policy seeded — not by a deploy.

| Piece | State |
|---|---|
| `SubmitBillForApprovalAction`, `SubmitExpenseForApprovalAction`, `CreateArInvoiceTool` | Dual-write. Legacy queue row always; generic request only when a policy exists. |
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

- The legacy `ApprovalQueueItem` write is still there. Removing it (and `ResolveApprovalAction` /
  `ResolveApproverEmailAction`) is the final cutover, once every tenant has a policy.
- Slack notification has not moved into this domain, so generic notifications are mail-only and carry
  no invoice PDF. That move is what lets `notify` go back to `'all'`.
- An expired request does not walk its entity back to draft — the request closes, the bill stays
  `PENDING_APPROVAL`. Needs a decision on what expiry should mean per entity.
- Nothing stops the entity being edited between submission and sign-off (see the plan's §B.11).
