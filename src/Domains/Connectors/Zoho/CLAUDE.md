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
   open row that already carries `ZOHO_STATUS_NAME = <label>`, else a new company-owned open row. The
   label is written only on a row the company owns; a platform row (`apps_id`/`companies_id` 0) is never
   relabelled from one tenant's setting.

Why both sides move: every lead push rebuilds `Lead_Status` from the Kanvas status row
(`ZohoLead::fromLead`, `ZOHO_STATUS_NAME` or "New Lead"). A Zoho-only write is undone by the next edit.

What it never does: reopen a closed lead (Sold, Closed - Not Sold), reuse a status row flagged closed,
touch a lead Zoho does not have yet, or change a deal's Stage. A second submission is a no-op. Every skip
is in the activity result as `leadStatus.reason`: `not_configured`, `lead_not_in_zoho`, `lead_closed`,
`already_set`.

The label must exist in the tenant's Zoho `Lead_Status` picklist, or Zoho rejects the write.
