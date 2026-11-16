# Zoho Connector

## Zoho-scheduled SMS or email campaigns, sent by a Kanvas agent

Use this when a client keeps its cadence in Zoho (a workflow rule with scheduled actions) and wants a
Kanvas agent to send each touch, for example when replacing a Zoho SMS extension.

Zoho decides **when**. Kanvas decides **what**: it writes the message and sends it. Each scheduled action
calls one Deluge function, which posts to a receiver running `TriggerZohoLeadFollowUpWebhookJob`.

```text
Zoho scheduled action → Deluge function → receiver → move lead to the step's stage
                                                   → LeadFollowUpJob (force) → agent sends, message filed on the lead
```

Nothing here needs a code change for a new client. One campaign is one pipeline, one step is one stage.

### 1. Map the cadence

List the rule's scheduled actions and choose three keys for each. Keep the existing message text: it
becomes the template.

| Key | Must equal |
|---|---|
| `campaign_key` | the pipeline's `slug` |
| `step_key` | the stage's `config.stage_meta.step_key` |
| `template_key` | the `template_name` of an enabled channel on that stage |

### 2. Set up Kanvas

**Pipeline.** Pass `slug` to `createPipeline` explicitly, because a generated slug turns `_` into `-`. Give
stages increasing `weight` in cadence order; a lead never moves back to a lower one. Make it the company's
default pipeline so synced leads start on it, because a webhook never moves a lead between pipelines.

**Stage `config`**, one per step:

```json
{
  "stage_meta": { "step_key": "day_30" },
  "follow_up": {
    "enabled": true,
    "max_retries": 1,
    "exhausted_action": "stop",
    "channels": [{ "type": "sms", "enabled": true, "template_name": "day_30_sms" }],
    "prompt_template": "..."
  }
}
```

- `max_retries: 1` with `stop` makes a repeated webhook send nothing.
- `prompt_template` must tell the agent never to advance the stage. Only the prompt holds that back.
- Enable only the campaign's channel.

**Templates.** One per `template_key`, created in Kanvas under that exact name. The agent writes the
message, and a template with neither an agent message slot nor a link only guides tone.

A link must arrive intact, so it goes in the template and never in the agent's text. Use `|raw` on both
values, because templates render as HTML and an SMS would otherwise show `I&#039;m` and `&amp;`:

```twig
{{ agent_message|raw }}
{{ follow_up_links.consent|raw }}
```

Use the dotted form: a template naming a link the call did not send stops with `link_missing: consent`, and
`follow_up_links['consent']` gets past that check. The stage's `prompt_template` must tell the agent not to
write URLs, or the customer gets two.

**Receiver.** `createReceiverWebhook` with the action `Zoho Trigger Lead Follow Up` and:

```json
{ "shared_secret": "<long random value>", "receiver_id": 123 }
```

An empty secret refuses every call. Authentication is the `X-Webhook-Secret` header or a `secret` body field.
`receiver_id` is a lead receiver in this company. A lead Kanvas lacks is pulled from Zoho through it, onto
the default pipeline; without it a missing lead stops the step.

**Company.**

- `lead_follow_up_disabled = true`, so the hourly cron does not send on top of Zoho's schedule. It covers
  the whole company, including its other pipelines. The webhook path still runs.
- Keep `follow_up.enabled`, AI mode and `ai_follow_up` on; those gates also block the webhook path.
- Confirm no workflow rule runs `Agent Reach Out To Lead` or `TriggerLeadFollowUpActivity`.
- SMS needs the channel connected and a sending number. See [`../Twilio/CLAUDE.md`](../Twilio/CLAUDE.md).
- For inbound replies to be answered, the company needs a rule running `AgentChannelResponderActivity`.

### 3. Set up Zoho

Create an org variable `kanvas_webhook_secret` holding the receiver's secret, then an Automation function
named `kanvasLeadFollowUp`:

```javascript
void automation.kanvasLeadFollowUp(String leadId, String campaignKey, String stepKey, String templateKey, String channel)
{
    receiverUrl = "https://YOUR-KANVAS-API/v1/receiver/YOUR-RECEIVER-UUID";
    secret = zoho.crm.getOrgVariable("kanvas_webhook_secret");
    lead = zoho.crm.getRecordById("Leads", leadId.toLong());

    links = Map();
    links.put("form", ifnull(lead.get("Form_URL"), ""));
    links.put("calendly", ifnull(lead.get("Lead_Owner_Calendly_Link"), ""));
    links.put("consent", "https://YOUR-APPLICATION-URL/?lead_id=" + leadId);
    links.put("apply", "https://YOUR-APPLICATION-URL/");

    payload = Map();
    payload.put("zoho_lead_id", leadId);
    payload.put("campaign_key", campaignKey);
    payload.put("step_key", stepKey);
    payload.put("template_key", templateKey);
    payload.put("channel", channel);
    payload.put("links", links);

    headers = Map();
    headers.put("Content-Type", "application/json");
    headers.put("X-Webhook-Secret", secret);

    response = invokeurl
    [
        url: receiverUrl
        type: POST
        parameters: payload.toString()
        headers: headers
    ];
    info response;
}
```

Map `leadId` to the module's record id, and type the others as fixed values on each action. `channel` is
`sms`, `email` or `both`. The link names, field names and URLs above are examples: use the client's own.

The webhook body the function produces, which is also what to send when testing with curl:

```json
{
  "zoho_lead_id": "123",
  "campaign_key": "new_lead_sms",
  "step_key": "day_30",
  "template_key": "day_30_sms",
  "channel": "sms",
  "links": {
    "form": "https://example.com/form",
    "calendly": "https://example.com/book",
    "consent": "https://example.com/?lead_id=123",
    "apply": "https://example.com/"
  }
}
```

Send every link on every call. The receiver replaces the lead's `follow_up_links` with this map each time, so
a link left out of one call is gone for that step, and an empty or non-https link is dropped. A new link needs
a line in this function and a template, no code. The receiver does not read `channel` yet, so a step still
sends on its stage's channels.

### 4. Cut over

1. Run the function from Zoho's editor against one lead you own. This sends a real message.
2. Read the result on the receiver's webhook call: `Lead follow-up queued`, or a stop `reason`.
3. Replace the old send action with the function, one step at a time. **Never run both on one step**: the
   lead gets two messages. Check in Zoho what happens to sends the old actions already scheduled.

### Stop reasons

Every stop is recorded on the webhook call, and nothing is moved or sent. Zoho is not told.

| `reason` | Meaning |
|---|---|
| `lead_not_found` | No Kanvas lead has this Zoho id and it could not be pulled: no `receiver_id`, or Zoho returned nothing |
| `campaign_not_found` | No pipeline with slug `campaign_key` in this company and app |
| `step_not_found` | No stage with this `step_key` on that pipeline |
| `template_mismatch` | No enabled channel on the stage uses `template_key` |
| `lead_on_another_pipeline` | Move the lead, or make this pipeline the default |
| `step_behind_current_stage` | A late or retried call for an earlier step |
| `customer_replied` | The customer wrote back. This step and all later ones stop for that lead |
| `already_queued` | The same lead and stage were queued in the last 5 minutes |

`Lead follow-up queued` does not mean sent. The follow-up job can still skip after the lead has moved, and
that is recorded in the lead's `lead.follow_up.*` ledger events, not on the webhook call. For these two, a
private note is also left on the lead, because Zoho will not retry the step:

| `reason` | Meaning |
|---|---|
| `link_missing: name` | The template names a link this call did not send, or sent empty or non-https |
| `template_render_failed` | The template failed to render. Also reported to Sentry |

### Reference configuration: a Kanvas agent sending a client's Zoho SMS cadence

A complete configuration of this recipe, to copy for the next client and to pick up on the next iteration.
It runs on app 9, company 7855, Zoho org657511937, with the agent Sally.

| Piece | Value | State 2026-11-12 |
|---|---|---|
| Pipeline | slug `zoho_sms_campaign`, 21 stages, `is_default = 0`, agent_name Sally | built (`docs/zoho/zoho_pipeline.php`) |
| Templates | `zoho_sms_{step_key}` | built with the pipeline |
| Receiver | `https://graphapi.kanvas.dev/v1/receiver/01a1d2e8-5589-7277-9962-ab6b84a6bcec`, config `receiver_id: 3` | created |
| Shared secret | `docs/zoho/finance-factory-zoho-campaign-setup.md` (gitignored), never in this file | set on the receiver |
| Zoho org variable `kanvas_webhook_secret` | the same secret | created |
| Zoho function `kanvasLeadFollowUp` | the function above plus a sixth argument `email` and an email allow-list checked first | created 2026-11-12 |
| Zoho rules | one `Kanvas - {step_key}` function action added **beside** the existing SMS action on every step of the five live rules (21 steps) | done 2026-11-12; criteria, triggers and the old SMS actions unchanged |

| Zoho email rules | one `Kanvas Email - {step_key}` action beside each customer-facing email (13 steps, campaign `zoho_email_campaign`, channel `email`) | done 2026-11-12, **switched off**: the function returns for any channel in `disabledChannels` (`email`) |

Email steps, by rule: Lead Created - Welcome Email (`welcome_email`, 15 min); Follow Up Emails Day 1-17
(`fu_email_day_1`..`_4` at 2, 4, 8, 17 days; fires on a `Lead_Status` change); Ready and Waiting
(`ready_waiting_day_45`, `ready_waiting_day_60`, `cif_final_day_90`); Landing Pages (`landing_welcome_email`,
5 min); one-off emails on a field change: `junk_bad_number`, `credit_frozen`, `rabbu_welcome`,
`fiona_bad_credit`. Templates are `zoho_email_{step_key}`. Kanvas has no email pipeline or templates yet;
build them before removing `email` from `disabledChannels`. Owner-addressed notifications (new lead alert,
docs uploaded, duplicate agent lead, hot and dupe) are not wired.

Not wired, and no Kanvas stages for them yet: the SMS on Follow Up Emails Day 1-17 (instant, 1 day, 2 days),
Ready and Waiting (45 days), Credit Report is Frozen (instant) and Lead FU Follow Up Cadence SMS (7 days).

**Pilot gate.** The function runs for every lead the live rules match, but posts to Kanvas only when the
lead's `Email` is in `allowedEmails` at the top of the function; every other lead exits before any Zoho read
or HTTP call. To add a test lead, add its email there. The old SMS actions still fire for every lead, so a
pilot lead gets both messages: use our own phone numbers.

The live rules fire on lead **create** (Landing Pages and LoanLeads.io on create or edit), so a test needs a
newly created lead that matches a rule's criteria. The pipeline is not the default, so a lead the receiver
pulls lands on the default pipeline and its first step stops with `lead_on_another_pipeline`; move the lead
onto `zoho_sms_campaign` and later steps run. Each stage has `time_based` 60 minutes, so without
`lead_follow_up_disabled` the hourly cron can also send to a pilot lead after an hour of silence.

**Going live.** Remove the allow-list check from the function, then remove each step's old SMS action from
the rule, one step at a time, never leaving both on a step. Make `zoho_sms_campaign` the company's default
pipeline so step 1 finds the lead on it.

Action arguments: `campaignKey` = `zoho_sms_campaign`, `stepKey` = the step key, `templateKey` =
`zoho_sms_` + the step key, `channel` = `sms`. Steps, in stage order, with the Zoho rule each replaces and
the link its template appends:

| step_key | Zoho rule | Schedule | Link |
|---|---|---|---|
| `landing_welcome` | Landing Pages journey | Instant | none |
| `new_instant_response` | New SMS Campaign | 2 minutes | form |
| `loan_instant_response` | New SMS Campaign_LoanLeads.io | 2 minutes | none |
| `new_value_add` | New SMS Campaign | 6 business hours | form |
| `loan_value_add` | New SMS Campaign_LoanLeads.io | 6 business hours | form |
| `new_easy_engagement` | New SMS Campaign | 12 business hours | form |
| `loan_easy_engagement` | New SMS Campaign_LoanLeads.io | 12 business hours | form |
| `new_low_effort_cta` | New SMS Campaign | 24 hours | form |
| `landing_attempt_1` | Landing Pages journey | 2 business days | calendly |
| `loan_low_effort_cta` | New SMS Campaign_LoanLeads.io | 3 business days | apply |
| `new_polite_close` | New SMS Campaign | 40 business hours | calendly |
| `loan_polite_close` | New SMS Campaign_LoanLeads.io | 5 business days | calendly |
| `landing_attempt_2` | Landing Pages journey | 5 business days | apply |
| `phase2_day_7` | New SMS Campaign Phase 2 | 7 business days | calendly |
| `landing_attempt_3` | Landing Pages journey | 10 business days | apply |
| `landing_attempt_4` | Landing Pages journey | 21 business days | apply |
| `phase2_day_30` | New SMS Campaign Phase 2 | 30 days | consent |
| `phase2_day_60` | New SMS Campaign Phase 2 | 60 days | consent |
| `phase3_day_120` | New SMS Campaign Phase 3 | 120 days | consent |
| `phase3_day_123` | New SMS Campaign Phase 3 | 123 days | none |
| `phase3_day_127` | New SMS Campaign Phase 3 | 127 days | consent |

Links the function sends: `form` = lead `Form_URL`, `calendly` = lead `Lead_Owner_Calendly_Link`,
`consent` and `apply` = `https://apply2.financefactory.com/apply?lead_id={leadId}`. These are the intended
links: the client's own SMS still point at `financefactory.com/consent-form-zoho/` and `apply.financefactory.com`
and will move to these.

Next: create a new Zoho lead with an allow-listed email that matches one of the rules, move it onto
`zoho_sms_campaign` in Kanvas, and read each step's result on the receiver's webhook calls.

Wiring by hand through Zoho's settings API (same browser session, `X-ZCSRF-TOKEN` from the `CSRF_TOKEN`
cookie) is faster and safer than the rule editor, which drops an argument mapping if you open the function
code from it: `POST /crm/v8/settings/automation/functions` creates a function action (needs `language`,
`function.id` = the CRM function id from `GET /crm/v8/settings/functions`, `static` or `merge_field`
arguments), and `PUT /crm/v8/settings/automation/workflow_rules/{id}` attaches it. The PUT must resend every
existing action with its `details` exactly as read, and leave out an empty `instant_actions`. Zoho allows one
function per scheduled step (`LIMIT_EXCEEDED`, `limit: 1`), and one email action can be reused on several
steps, so match the step by its time as well as by the action id.

### Limits to tell the client

- **From number.** The agent or company number sends, not the record owner's. A2P registration must cover it.
- **Replies.** Zoho's later actions still fire; Kanvas drops them. Its campaign reports will not show this.
- **Hours.** Zoho's schedule owns send hours; nothing in Kanvas enforces them on this path.
- **A skipped touch is not retried.** The next attempt is Zoho's next step.
- **Reply detection needs a person.** A lead with no `people_id` has no inbound history, so a reply cannot be seen.

## Get Docs moves the lead to a status (e.g. "Hot")

A company that wants a lead marked once the customer uploads paperwork sets **one company setting**,
`ZOHO_GET_DOCS_LEAD_STATUS`, to the Zoho `Lead_Status` picklist label, e.g. `Hot`. Nothing happens for a
company without it. The `Zoho Push Lead Notes` rule on message submitted must already be in place; it is
the same rule that uploads the files.

What runs (`PushLeadNotesActivity` → `PushGetDocsLeadStatusAction`), only when the Get Docs submission
carried at least one file:

1. Zoho gets `Lead_Status = <label>` first; a rejected write leaves Kanvas untouched and the retry redoes
   the step.
2. The Kanvas lead moves to the matching `LeadStatus`: the company's own open row by name, else a shared
   open one, else a new company-owned open row. That row gets `ZOHO_STATUS_NAME = <label>` if it has none.

Why both sides move: every lead push rebuilds `Lead_Status` from the Kanvas status row
(`ZohoLead::fromLead`, `ZOHO_STATUS_NAME` or "New Lead"). A Zoho-only write is undone by the next edit.

What it never does: reopen a closed lead (Sold, Closed - Not Sold), reuse a status row flagged closed,
touch a lead Zoho does not have yet, write to a converted lead, or change a deal's Stage. A second
submission is a no-op. Every skip is in the activity result as `leadStatus.reason`: `not_configured`,
`lead_not_in_zoho`, `lead_converted`, `lead_closed`, `already_set`.

The label must exist in the tenant's Zoho `Lead_Status` picklist, or Zoho rejects the write.

## Get Docs on a lead Zoho already converted to a deal

A converted lead is read-only in Zoho and is left out of a plain fetch by id (`leads->get($id)` returns
nothing); only `converted=both` returns it, with `$converted_detail` holding the deal, contact and account
ids. Uploading to it fails, so the documents would land nowhere. The deal webhook that would have created
the Kanvas deal is optional per tenant and often not wired.

So when a Get Docs submission lands on a lead with no Kanvas deal carrying a `ZOHO_DEAL_ID`,
`PushLeadNotesActivity` runs `PullConvertedLeadDealAction` first:

1. Ask Zoho for the lead with `converted=both`. Not converted: nothing changes, the lead is the target.
2. Converted: store `$converted_detail` on the lead as `ZOHO_LEAD_CONVERTED_DETAIL`, then run
   `PullZohoDealAction` for its deal with the lead handed in, so the link does not depend on the client's
   `ZCRM_Lead_ID` custom field. The pull also brings the deal's existing Zoho attachments down and fires
   the deal created workflow, as the webhook would.
3. The files fan out over the lead's deals as before; the marked lead is never a target again, on this
   activity or on `Zoho Push Deal Notes`, and the status move skips with `lead_converted`.
4. Every file that reaches a Zoho deal is also attached to the Kanvas deal (`mirrorUploadedFilesOnDeal`):
   the same `filesystem` row, under the name Zoho got. It is recorded in `ZOHO_DEAL_SYNC_FILES` before the
   attach so the deal push never sends it up again, and the next deal pull skips it by name instead of
   downloading it back. The Kanvas deal therefore shows the file in the same request, with one S3 object.

The mark is read before Zoho, so a lead is looked up once. A lead that is not converted and has no deal
costs one Zoho read per submission.

Files added to the lead itself in Kanvas travel on `Zoho Push Lead` (`SyncLeadToZohoAction`), not on Get
Docs. That push reads the lead back from Zoho before updating it, and Zoho answers empty both for a deleted
lead and for a converted one. The push asks whether the lead was converted before treating the lead as gone:
if so the deal is pulled the same way, the lead's files go to that deal (still tracked on the lead as
`ZOHO_LEAD_SYNC_FILES`), and the Kanvas lead keeps its status. Only a lead Zoho has no record of is closed.

## Zoho `Phone` is a mobile for some companies

The pull stores Zoho's `Mobile` as a cellphone and `Phone` as a home phone. Only a cellphone gets an SMS
channel, so a company whose reps type mobile numbers into `Phone` sets `ZOHO_PHONE_IS_MOBILE = true`, and
`Phone` is then stored as a cellphone too. The number is stored once, never as both types. When a record
has both fields, `Mobile` gets weight 0 and is the one the SMS channel uses.

Turning the setting on changes new pulls only. A re-pulled lead keeps its old home-phone row and gains a
cellphone row, because contacts are merged by type. Retype the company's existing rows first, then open the
SMS channels:

```sql
UPDATE peoples_contacts pc
JOIN peoples p ON p.id = pc.peoples_id
SET pc.contacts_types_id = 3
WHERE p.companies_id = :company_id AND p.apps_id = :app_id AND pc.contacts_types_id = 2 AND pc.is_deleted = 0;
```

then `php artisan kanvas:sales-assist-sync-channels {app_id} {company_id} {agent_id}`.
