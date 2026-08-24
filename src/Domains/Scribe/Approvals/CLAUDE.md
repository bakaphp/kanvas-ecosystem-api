# Approvals — Generic Approval Queue

Lets any Kanvas write-action (a bill, an invoice, and any future action type) sit pending until an
authorized human approves it — over Slack, in natural language, with no chat UI needed. Built on
`ApprovalQueueItem` (`accounting.approval_queue`), a durable, polymorphic queue row keyed by
`action_type` + `target_type` + `target_id`.

## Why this exists

Apex (AP agent) and Arc (AR agent) read invoice emails automatically and used to push straight to
Acumatica. The business wants a human sign-off first: the bill/invoice lands in Kanvas as
`pending_approval`/`draft`, and only a specific, configured person can approve it — from Slack,
without opening any admin UI.

## The pieces

| Class | Role |
|---|---|
| `Enums\ApprovalConfigurationEnum` | App-level config keys (who can approve, where to notify). |
| `Enums\ApprovalCustomFieldEnum` | Entity-level custom field keys stashed on the bill/invoice at creation (source email + attachment), read back at approval time. |
| `Enums\ApprovalQueueStatusEnum` | `pending` / `approved` / `rejected` / `expired`. |
| `Models\ApprovalQueueItem` | The queue row itself — `action_type`, `target_type`, `target_id`, `payload`, `status`, `approved_by_users_id`, `approved_at`. |
| `Actions\RequestApprovalAction` | Creates a pending queue row for any record type. |
| `Actions\ResolveApprovalAction` | Dispatches on `action_type` to the domain action that actually carries out the approval (approve + push a bill, issue + push an invoice, …), then marks the row `approved`. |
| `Actions\NotifyApproverAction` | Best-effort Slack DM to the configured approver — silently does nothing if Slack isn't configured, never blocks the caller. |

`Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ApprovePendingItemTool` (`approve_pending_item`)
is the one LLM-facing tool — generic by design, it never branches on bill-vs-invoice itself. A new
approval type (expense, credit memo, …) is added as a new `match` arm in `ResolveApprovalAction`;
the tool, the Slack notification, and the queue never change.

## The full flow

**Intake** (Apex/Arc, automatic, per their own agent guidance):

1. Read the invoice email, extract the real data from the PDF.
2. `create_ap_bill` / `create_ar_invoice` with `push_to_acumatica: false` — creates the bill/invoice
   in Kanvas only, and stashes `source_email_message_id` + `source_attachment_url` as custom fields
   via `StoresApprovalSourceFields`. For bills, `SubmitBillForApprovalAction` already drops the
   `ApprovalQueueItem` row (`action_type: 'approve_bill'`); for invoices, `CreateArInvoiceTool` calls
   `RequestApprovalAction` explicitly (`action_type: 'approve_invoice'`) since a draft invoice has no
   built-in approval-queue side effect of its own.
3. `NotifyApproverAction` fires automatically — DMs the configured approver on Slack with the
   record's details and its Kanvas id, and uploads the invoice PDF (`source_attachment_url`) as a
   real Slack attachment when one was captured, so the approver can open the actual document
   before deciding. If the upload fails for any reason, it falls back to the plain text DM instead
   of losing the notification entirely.
4. Logged to the tracking sheet as "Pending".

**Approval** (the human, then Apex/Arc again):

5. The approver replies in Slack, in natural language — "approve bill 1072". This reaches Apex
   through the normal Slack↔agent pipeline, same as any other message.
6. `approve_pending_item(target_type, target_id)` — checks the sender's email against
   `ap-bill-approver-email` (`VerifiesApprovalAuthority`), finds the pending `ApprovalQueueItem`, and
   calls `ResolveApprovalAction`, which approves the bill / issues the invoice in Kanvas and pushes
   it to Acumatica in the same call.
7. On success, the agent's own guidance (in `AccountsPayableAgent`/`AccountsReceivableAgent`) drives
   the rest: `add_bill_note`/`add_invoice_note` records "Approved by {email} on {date}";
   `attach_bill_file`/`attach_invoice_file` attaches the stashed PDF (only possible now, since it
   needs an existing Acumatica push); `reply_to_email` replies inside the original email thread
   with the same evidence, sent only to the configured approver — never to the vendor; and
   `update_google_sheet_cell` flips the sheet row to "Approved" with the date and approver's email.

If the Acumatica push fails, the queue item is still **not** marked approved-and-clean — the agent
is told to report the failure plainly instead of updating the sheet, so nothing shows "Approved"
that isn't actually in Acumatica yet.

## Who gets to approve — how the identity check actually works

`approve_pending_item` compares `ap-bill-approver-email` against `$this->user->email` — the Kanvas
user attached to the current turn. **This is not necessarily your normal Kanvas login.** When
someone messages an agent over Slack, `SlackUserResolverService` looks up their Slack **profile**
email (via Slack's own `users.info` API) and matches it to a Kanvas user with that same email —
which can be a different record than the one behind your usual web-app login, if the two emails
differ. The email that matters here is **whatever email is on the approver's Slack profile**, not
their Kanvas admin-panel login.

## Configuration

| # | Key | What it is | How to get it |
|---|---|---|---|
| 1 | `ap-bill-approver-email` | The email that must match the Slack sender for `approve_pending_item` to allow the approval. | The email on the approver's **Slack profile** (not necessarily their Kanvas login — see above). If unsure, ask them to check Slack → profile settings, or have them message the agent and use a "who is user" self-identity tool to see which Kanvas identity Slack resolves them to. |
| 2 | `ap-bill-approver-slack-user-id` | The approver's Slack member id, used to open the DM. | Slack → click your profile photo → "View profile" → `⋮` menu → **"Copy member ID"**. Looks like `U01ABCXYZ`. |
| 3 | `ap-slack-notifier-agent-id` | The Kanvas `Agent` record id whose Slack bot token sends the DM — this is *deliberately* explicit, not auto-detected, because a tenant can have several agents connected to Slack and there is no reliable way to pick "the right one" automatically. | Kanvas admin panel → Settings → Agents → find the AP/AR agent (e.g. "Apex") → copy its id. Or run this GraphQL query (authenticated as an admin/owner): `query { agents(where: { column: NAME, operator: LIKE, value: "%Apex%" }) { data { id name } } }` |

Set all three the same way as every other connector key in this codebase — **Settings → Key
Configurations** in the Kanvas admin panel, or `$app->set('key', 'value')` from tinker in a
non-production environment.

### Sheet columns this flow expects

The tracking sheet (see `Connectors/GoogleSheets/CLAUDE.md`) needs these columns already created:

| Column | Purpose |
|---|---|
| A | ID invoice (the Kanvas bill/invoice id) |
| B | Vendor / customer name |
| C | Total |
| D | Status (`Pending` → `Approved`) |
| E | Approved Date |
| F | Approved By (the approver's email) |

## Extending: adding a new approval type

1. Wherever the new record is created in "pending" form, call `RequestApprovalAction` with a new
   `action_type` string (e.g. `'approve_expense'`) and the record's `target_type`/`target_id`.
2. Add a matching case + a `resolveExpense()`-style private method in `ResolveApprovalAction` that
   does whatever "approving this" actually means for that record type, and returns the same result
   shape (`target_type`, `target_id`, `label`, `pushed`, `reference`, `push_error`, plus the source
   fields if relevant).
3. Nothing else changes — `ApprovePendingItemTool`, `NotifyApproverAction`, and the queue itself are
   already generic.
