# Twilio Connector (SMS / MMS)

Twilio is a Communication provider. `TwilioHandlers` implements
`Communication\Contracts\CommunicationProvider` and is named in `config/communication.php`; behind
it are `SmsTransport` (sending), `TwilioInbound` (the webhook) and `TwilioProvisioner` (the number's
inbound URL). The Communication domain never imports this connector (`CommunicationLayoutTest`).
Everything below is what is specific to Twilio; the flow itself is in
`src/Domains/Communication/CLAUDE.md`.

## Numbers, connections and who answers

`TwilioHandlers::accounts()` returns the company's sending number plus every number one of its
agents owns (`agents.sms_config.phone_number`), each carrying its `agent_id`. Every one becomes a
connection; the agent on the connection answers what arrives on it, and a number nobody owns is
filed and left alone. A Twilio account routinely holds numbers that belong to other tenants, so
"every number on the account" is never the selection.

One receiver per company takes both kinds of request: an inbound text always carries `Body` (empty
for a picture sent alone), a delivery receipt never does (`TwilioInbound::isStatusCallback()`).
Twilio signs over the exact receiver URL with the auth token of the account the company texts
through, which is why the receiver is per company and not per number.

That account is the company's own when it has credentials and the app's otherwise, and
`Client::getKeysFromCompanyOrApp()` is the one place that decides it: sending, the number's inbound
URL, media downloads and the webhook signature all read it there, so they cannot disagree about
which account a number is on.

A number in a Messaging Service is registered on the service (`inboundRequestUrl`), which is a
shared resource; a plain number on its own `smsUrl`.

## Sending

Every text goes through `Communication::send()` / `reply()` and `SmsTransport`. Nothing else calls
the Messages API.

- **Only `mobile` numbers, and `fixedVoip` / `nonFixedVoip` on a carrier known to carry texts, are
  texted.** `SmsTransport::refusalForLine()` is an allow-list on the Lookup line type: any other type
  (`landline`, `tollFree`, `unknown`...), a missing type or a `line_type_intelligence` error is refused
  before the Messages API is called. Keep it an allow-list — a block-list lets every type Twilio adds
  later through. Lookup cannot say whether a VoIP number has texting enabled, so a VoIP line passes
  only when its `carrier_name` contains one of `VOIP_CARRIER_KEYWORDS` (a fixed list in that class,
  not a setting); no carrier name is a refusal. That refusal's reason is exactly `VOIP without SMS`
  (code `voip_without_sms`) — it is what the person who wrote the text reads, so do not add the
  number or the carrier to it. A reply is not looked up: someone who texted us is reachable on that
  number.
- **A number marked invalid accepts no more texts.** `refusalForLine()` refuses,
  before the Lookup, any destination whose phone contact is a permanent failure
  (`People::isPhoneUndeliverable`, code `undeliverable_number`) — the mark `30003` / `30005` / `30006`
  leave, and also what a CRM rejection or the agent's `mark_invalid` leave. Nothing in the SMS flow
  clears it: `Contact::markValid()` is the only way back. A reply skips this with the rest of the
  lookup.
- **`21610` is an opt-out, not a fault.** The recipient texted STOP to Twilio, which unsubscribed
  them itself. The transport flags it, the ledger opts the phone out, and the lead send leaves a
  note. It is never retried.
- **A carrier failure is never retried.** A status callback with `30003`, `30005` or `30006` marks
  the matching phone contacts invalid (`ApplyDestinationRemediationAction`); `21610` opts the phone
  out. A person resends a failed text with the `twilioResendMessage` mutation
  (`ResendFailedMessageAction`), which retries the same delivery: every send guard still applies.
- **MMS**: at most 10 media per message is Twilio's cap. The lead send batches below that
  (`twilio-mms-batch-size`, default 8, because 21623 fires on some carriers before 10).

## Inbound

- **Consent.** Twilio's own `OptOutType` wins when present — it reflects what the carrier did to
  the number. `HELP` is answered by Twilio itself with the carrier advisory
  (`answersHelpItself()`), so no agent turn fires for it; `START` deliberately is not, because
  someone asking to hear from us again should get a real reply.
- **Bursts.** Someone who sends three texts in a row gets one answer. The correlation key is the
  sender's number (SMS has no thread id). Outbound (`From === To`) never bursts.

| Company setting | Default | Notes |
|---|---|---|
| `twilio_burst_idle_seconds` | `15` | both the chain window and the close wait |
| `twilio_burst_max_seconds` | `90` | ceiling, so a sender who never pauses still flushes |
| `twilio_burst_jitter_seconds` | `0` | jitter is a WhatsApp anti-ban measure; on SMS it is pure latency |
| `twilio_batch_delay_seconds` | — | legacy, still read as the idle fallback |

**The idle window must exceed the gap between two texts a person sends back to back.** Below ~5s
each text opens its own turn and the agent answers twice.

- **Test-number override** (`allow_session_hijack` + `overwrite_phone_number`): test and demo only.

## What came before, and what is left of it

Texts used to reach `ProcessTwilioWebhookJob`, receipts `ProcessTwilioMessageStatusWebhookJob`, and
a rule ran a Twilio responder activity; sends were recorded in `twilio_message_attempts`. Those
classes are deleted. What remains:

- `RetireLegacySmsInboundAction` holds their class **names** as strings, because the `actions` rows
  of a company not yet moved still carry them. When a company's connection becomes ready it gives
  an unowned number to the agent the old rule named, removes the rule and switches the old
  receivers off.
- `Models/MessageAttempt` and `Models/MessageDeliveryEvent` read the old tables. Nothing writes to
  them; they are history.
- Voice numbers are not Communication connections: `ConfigureAgentInboundWebhookAction` still
  points an agent's voice number at the voice runtime.
