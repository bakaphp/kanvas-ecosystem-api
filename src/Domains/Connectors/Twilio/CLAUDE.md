# Twilio Connector (SMS / MMS)

Inbound and outbound SMS. One webhook job files the message, opens or finds the Lead, and hands the
conversation to a workflow rule; the agent answers from there.

## Inbound flow

`ProcessTwilioWebhookJob::execute()` runs per delivery:

1. Session hijack override (`allow_session_hijack` + `overwrite_phone_number`) — test/demo only.
2. People → Lead → `ProcessInboundConsentAction`. **The lead has to exist before consent runs**: a
   stop is person-wide and every outbound guard reads the lead. Several People can share the
   inbound phone, so `resolvePeopleAndLead()` picks the lead whose channel carries the most recent
   message (`LeadsRepository::getLeadWithMostRecentChannel`) and takes its People — active or not.
   Only when no lead of theirs has a channel does it fall back to "whoever holds an active lead",
   and only when nobody matches does it create a People and a lead.
3. Channel (`twilio-{normalizedPhone}`), message filed under a deterministic slug so a Twilio retry
   updates rather than double-files.
4. **Consent halt** — a real stop, a phrase-tier stop, or `HELP` returns here. No burst is armed, so
   no agent turn can ever fire for that message. `HELP` rides along because Twilio answers it itself
   with the carrier advisory; `START` deliberately does not, because someone asking to hear from us
   again should get a real reply.
5. Media download, `agent_communication_channel = sms`, stakeholder notification.
6. `announceMessage()` — outbound fires the workflow immediately, inbound goes into a burst.

**Nothing here replies.** `AFTER_ADDING_MESSAGE_TO_CHANNEL` expects a rule carrying
`AgentChannelResponderActivity`. A receiver with no such rule files texts and stays silent — that is
configuration, not a bug.

## Bursts — the agent answers a flurry once

Someone who sends three texts in a row gets **one** answer. Parts chain onto a head via `parent_id`
and the debounce closes when they go quiet.

This is the **shared** layer, not a Twilio invention:
`Social\Messages\Concerns\ChainsInboundBursts` + `MessageBurstService` + `FlushMessageBurstJob`, the
same machinery WhatsApp uses. What lives in this connector is
`ProcessTwilioWebhookJob::burstPolicy()` (the correlation key) and `FlushSmsBurstAction` (what happens
once the burst closes — here, just announcing it, because the agent pass belongs to the workflow
activity that already owns the AI-mode guards and the support-mode handoff).

- **Correlation key is the sender's number.** SMS has no album id or thread id, so one burst per
  sender per channel is the whole of it.
- The agent receives `burst_text` — every body joined by a blank line — not just the head's.
- **Outbound (`From === To`) never bursts.** It has no flurry to collapse, and rules listening for the
  company's own messages expect them without a delay.

### Config (company-scoped)

| Key | Default | Notes |
|---|---|---|
| `twilio_burst_idle_seconds` | `15` | both the chain window and the close wait |
| `twilio_burst_max_seconds` | `90` | ceiling, so a sender who never pauses still flushes |
| `twilio_burst_jitter_seconds` | `0` | off — jitter is a WhatsApp anti-ban measure; on SMS it is pure user-visible latency |
| `twilio_batch_delay_seconds` | — | **legacy**, superseded by `twilio_burst_idle_seconds`; still read as the idle fallback so a tenant who tuned it keeps their tuning |

**The idle window must exceed the gap between two texts a person sends back to back.** The 3 seconds
this replaced did not, so each text opened its own turn and the agent answered twice — with the
support-mode path that meant two SMS thirty minutes later, which is what a customer actually saw.
Below ~5s you are back to that bug. First reply costs `idle + agent time`; drop it to 8–10 for a
tenant who wants it snappier.

## Foot-guns

**Do not reintroduce a "last message id" batch guard.** The previous design kept a `message_batch:`
array in cache and compared `last_message_id` — it could not collapse anything the 3s window missed,
and its companion cancel flag was set and `Cache::forget`-ed in the same request, so it never
cancelled anything. The token-supersede debounce replaces both.

**Support mode does not answer inline.** When the lead `isAiSupport()`,
`AgentChannelResponderActivity` queues `SendUnrespondedAgentMessageJob` for
`un_responded_salesperson_messages` minutes (default
`Companies\Enums\ConfigurationEnum::UN_RESPONDED_SALESPERSON_MESSAGES_DEFAULT`, 60) to give the human
a window. That job carries its own per-channel supersede token — a later inbound message replaces the
pending turn rather than adding a second one. `is_un_response` alone cannot do that job: it only
closes once the winning turn's model call has returned.

**A `21610` from Twilio is an opt-out, not a fault.** The recipient texted STOP and Twilio
auto-unsubscribed them; `AgentChannelResponderAction` swallows that code, records a lead note and
opts out the phone contacts rather than retrying 3× into Sentry.

**Outbound delivery status is a separate webhook.** `ProcessTwilioMessageStatusWebhookJob` handles
carrier failures and controlled retries; it does not touch bursts.
