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

The approver isn't one fixed person for the whole company — each vendor (AP) or customer (AR) has
its own approver, since different vendors are owned by different people on the finance team.

## The pieces

| Class | Role |
|---|---|
| `Enums\ApprovalConfigurationEnum` | App-level config (currently just which agent's Slack bot sends the DM). |
| `Enums\OrganizationApproverCustomFieldEnum` | The custom field on a vendor/customer `Organization` that names its approver's email. |
| `Enums\ApprovalCustomFieldEnum` | Entity-level custom field keys stashed on the bill/invoice at creation (source email + attachment), read back at approval time. |
| `Enums\ApprovalQueueStatusEnum` | `pending` / `approved` / `rejected` / `expired`. |
| `Models\ApprovalQueueItem` | The queue row itself — `action_type`, `target_type`, `target_id`, `payload`, `status`, `approved_by_users_id`, `approved_at`. |
| `Actions\RequestApprovalAction` | Creates a pending queue row for any record type. |
| `Actions\ResolveApprovalAction` | Dispatches on `action_type` to the domain action that actually carries out the approval (approve + push a bill, issue + push an invoice, …), then marks the row `approved`. |
| `Actions\ResolveApproverEmailAction` | Given `target_type`/`target_id`, looks up the record's vendor/customer and returns its approver email — the single place that maps a pending item to who may approve it. |
| `Actions\NotifyApproverAction` | Best-effort Slack DM to an approver email — looks the person up in Slack by email, silently does nothing if Slack isn't configured or the email doesn't match a workspace member, never blocks the caller. Takes an optional `agentId` constructor arg to use a specific notifier agent instead of the app-level `ap-slack-notifier-agent-id` default (e.g. AR's own credit-memo push notification uses its own agent, since AP and AR can have different Slack bots connected). |

`Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ApprovePendingItemTool` (`approve_pending_item`)
is the one LLM-facing tool — generic by design, it never branches on bill-vs-invoice itself. A new
approval type (expense, credit memo, …) is added as a new `match` arm in `ResolveApprovalAction`
and `ResolveApproverEmailAction`; the tool and the queue itself never change.

## The full flow

**Intake** (Apex/Arc, automatic, per their own agent guidance):

1. Read the invoice email, extract the real data from the PDF.
2. `create_ap_bill` / `create_ar_invoice` with `push_to_acumatica: false` — creates the bill/invoice
   in Kanvas only, and stashes `source_email_message_id` + `source_attachment_url` as custom fields
   via `StoresApprovalSourceFields`. For bills, `SubmitBillForApprovalAction` already drops the
   `ApprovalQueueItem` row (`action_type: 'approve_bill'`); for invoices, `CreateArInvoiceTool` calls
   `RequestApprovalAction` explicitly (`action_type: 'approve_invoice'`) since a draft invoice has no
   built-in approval-queue side effect of its own.
3. The tool reads the vendor's/customer's approver email (`OrganizationApproverCustomFieldEnum`) and
   calls `NotifyApproverAction`, which DMs that person on Slack — looked up by email, not a fixed
   Slack user id — with the record's details and its Kanvas id, uploading the invoice PDF
   (`source_attachment_url`) as a real Slack attachment when one was captured, so the approver can
   open the actual document before deciding. If the vendor/customer has no approver email set, the
   tool still creates the record but tells the caller nobody can approve it yet and no DM was sent.
4. Logged to the tracking sheet as "Pending".

**Approval** (the human, then Apex/Arc again):

5. The approver replies in Slack, in natural language — "approve bill 1072". This reaches Apex
   through the normal Slack↔agent pipeline, same as any other message.
6. `approve_pending_item(target_type, target_id)` — resolves the record's vendor/customer approver
   email via `ResolveApproverEmailAction`, checks it against the sender's email
   (`VerifiesApprovalAuthority`), finds the pending `ApprovalQueueItem`, and calls
   `ResolveApprovalAction`, which approves the bill / issues the invoice in Kanvas and pushes it to
   Acumatica in the same call.
7. On success, the agent's own guidance (in `AccountsPayableAgent`/`AccountsReceivableAgent`) drives
   the rest: `add_bill_note`/`add_invoice_note` records "Approved by {email} on {date}";
   `attach_bill_file`/`attach_invoice_file` attaches the stashed PDF (only possible now, since it
   needs an existing Acumatica push); `reply_to_email` replies inside the original email thread
   with the same evidence, sent only to that vendor's/customer's approver — never to the vendor
   itself; and `update_google_sheet_cell` flips the sheet row to "Approved" with the date and
   approver's email.

If the Acumatica push fails, the queue item is still **not** marked approved-and-clean — the agent
is told to report the failure plainly instead of updating the sheet, so nothing shows "Approved"
that isn't actually in Acumatica yet.

## Who gets to approve — how the identity check actually works

`approve_pending_item` looks up the pending record's vendor/customer, reads that organization's
`ap_approver_email` custom field, and compares it against `$this->user->email` — the Kanvas user
attached to the current turn. **This is not necessarily your normal Kanvas login.** When someone
messages an agent over Slack, `SlackUserResolverService` looks up their Slack **profile** email
(via Slack's own `users.info` API) and matches it to a Kanvas user with that same email — which can
be a different record than the one behind your usual web-app login, if the two emails differ. The
email that matters here is **whatever email is on the approver's Slack profile**, not their Kanvas
admin-panel login.

If a vendor/customer has no `ap_approver_email` set, `approve_pending_item` reports
`no_approver_configured` — nobody can approve that record until it's set.

## Configuration

| Key | What it is | How to set it |
|---|---|---|
| `Organization` custom field `ap_approver_email` (`OrganizationApproverCustomFieldEnum::APPROVER_EMAIL`) | The email of the person who approves this vendor's bills (AP) or this customer's invoices (AR). Looked up in Slack by email — no separate Slack user id to configure. | Per vendor/customer, via `$organization->set('ap_approver_email', 'name@company.com')`. For a batch import from a spreadsheet mapping vendor name → approver email, write a one-off Artisan command modeled on `app/Console/Commands/Guild/AgentsImportCommand.php`. |
| `ap-slack-notifier-agent-id` (`ApprovalConfigurationEnum::SLACK_NOTIFIER_AGENT_ID`) | The Kanvas `Agent` record id whose Slack bot token sends the DM — this is *deliberately* explicit, not auto-detected, because a tenant can have several agents connected to Slack and there is no reliable way to pick "the right one" automatically. This is the default `NotifyApproverAction` falls back to when no `agentId` is passed explicitly. | Kanvas admin panel → Settings → Agents → find the AP/AR agent (e.g. "Apex") → copy its id. Or run this GraphQL query (authenticated as an admin/owner): `query { agents(where: { column: NAME, operator: LIKE, value: "%Apex%" }) { data { id name } } }`. Set with `$app->set('ap-slack-notifier-agent-id', '123')`. |
| `ar-slack-notifier-agent-id` (`Kanvas\Scribe\Invoices\Enums\ConfigurationEnum::AR_SLACK_NOTIFIER_AGENT_ID`) | Same idea, scoped to AR — used when AR's Slack-connected agent (e.g. "Arc") is a different `Agent` record than AP's. `CreateArCreditMemoTool` passes this as `NotifyApproverAction`'s `agentId` for its Acumatica-push notification. | Set with `$app->set('ar-slack-notifier-agent-id', '123')`. |

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
3. Add a matching case in `ResolveApproverEmailAction` mapping the new `target_type` to whichever
   `Organization` its approver email lives on.
4. Nothing else changes — `ApprovePendingItemTool`, `NotifyApproverAction`, and the queue itself are
   already generic.
