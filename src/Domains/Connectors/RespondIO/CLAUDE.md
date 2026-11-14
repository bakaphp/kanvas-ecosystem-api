# Respond.io Connector

Respond.io is a Communication provider. `RespondIOHandler` implements
`Communication\Contracts\CommunicationProvider` and is named in `config/communication.php`; behind
it are `WhatsAppTransport` (sending), `RespondIOInbound` (the webhook) and `RespondIOProvisioner`.
The Communication domain never imports this connector (`CommunicationLayoutTest`). The flow itself
is in `src/Domains/Communication/CLAUDE.md`; this file is what is specific to Respond.io.

## The workspace is the account

One bearer token is one workspace, and the workspace is the one connection. Respond.io decides
which of its channels a message leaves on (the contact's last one); Kanvas addresses a contact by
phone (`/contact/phone:{E.164}/message`) and never names a channel. It is filed as the `whatsapp`
channel type, because that is what every workspace on Kanvas runs through it and what a lead's
conversation is addressed on.

- **Account key.** A webhook never names our side. The key is the workspace's WhatsApp number when
  the tenant stated it on the integration (`phone_number`, stored as `respondio_phone_number`);
  otherwise `RespondIOHandler::accountKey()` builds a number no phone can have (`+000` + app id +
  company id). It has to look like E.164: an inbound identity on a phone channel is matched through
  `ChannelTypeEnum::normalizeAddress()`, which would mangle anything else that contains digits.
- **Who answers.** The agent whose `whatsapp_config.phone_number` is the workspace's number. Until
  one claims it, the agent the old responder rule named, which the hand-over wrote on the
  integration row (`config.agent_id`). A workspace nobody owns is filed and never answered.
- **A company with a WhatsApp number of its own as well** (Meta) keeps both: the lead send names the
  Respond.io account (`fromAccount`) whenever `respondio_enabled` is on, so a Respond.io contact is
  never answered from the other number.

## The webhook cannot be registered by API

Respond.io has no API for webhooks: a person creates them in the workspace and pastes the receiver
URL in. So `onboardable()` is `false`, `registerInbound()` registers nothing, and there is nothing
to clean up at the vendor on disconnect.

A workspace that was already posting to Kanvas keeps its URL. `RetireLegacyRespondIOInboundAction`
(run when the connection becomes ready) gives the receiver the workspace already posts to the
communication job and the `communication.provider` marker, switches off and soft-deletes the
receiver setup had made for the provider (nothing ever posted to it), removes the old responder
rule's step, and carries the rule's agent over.
The old class **names** live there as strings because the `actions` rows of a workspace not yet
moved still carry them.

A new workspace has to be given the URL of its communication receiver by hand. Events to subscribe:
`message.received`, `message.sent`, and whichever of the contact / conversation / call / comment
events the tenant wants filed.

**Signature.** When the tenant stored the webhook's signing key (`webhook_signing_key` on the
integration), every request is checked: `X-Webhook-Signature` is the base64 HMAC-SHA256 of the raw
body. Not verified against a live workspace yet — confirm the header name and encoding on the first
tenant that sets a key.

## What goes through the shared pipeline, and what does not

| Event | Where |
|---|---|
| `message.received` | shared pipeline (`RespondIOInbound::messages()`) |
| `message.sent` with the id a delivery is known by | a delivery status on the ledger; nothing is filed again |
| `message.sent` with `sender.source = api` | nothing: it left through the API, so it is ours or another system's, never something a person typed |
| `message.sent` someone typed in the Respond.io inbox (`sender.source = user`) | shared pipeline, filed as ours (`from_me`) |
| comment, conversation opened / closed, contact updated / tag / assignee / lifecycle, call ended | `handleProviderEvent()` → the `Process*Action` classes (`BaseRespondIOAction`, built from the receiver and the payload) |

- A contact with **no phone** (Messenger, Instagram) is not filed: a person is found and answered by
  phone here, and filing a Respond.io contact id as a cellphone poisons every phone lookup.
- Conversations keep the slug they always had (`respondio-{phone}`,
  `SessionChannelService::createChannelSlug('respondio', ...)`) and messages theirs
  (`respondio-{slug(messageId-phone)}`), so history and redeliveries keep matching. Never change
  either: they are dedup keys.
- A conversation filed by the shared pipeline belongs to the **person**; one from before names its
  **lead**. `BaseRespondIOAction::resolveLead()` reads both, which is what the comment,
  call-ended and conversation-closed actions rely on.
- A file arrives as `message.type = attachment` with the kind one level down
  (`MessageTypeEnum::getMessageType()`); it is downloaded through `SafeUrlFetcher` and its type read
  from the bytes.
- `conversation.closed` still raises the human-takeover AI trigger
  (`LeadAiTriggeredEvent::announce()`).

## Sending

One Respond.io message carries one thing. `WhatsAppTransport` sends the files first, one message
each, then the text; the delivery's id is the last message's. Every part comes back as its own
`message.sent`: the last one matches the delivery, the earlier ones are recognised by
`sender.source = api`. A refusal (`Client::handleResponse()` throws `ValidationException` on any
non-2xx, 429 included, without waiting) is a failed delivery; a dropped connection bubbles and is
recorded as unknown. A file Respond.io has no kind for is dropped with a warning, and a send left
with nothing to carry (no text, every file dropped) fails as `attachment_unsupported`.

Bursts use the shared layer, keyed by the contact's phone (`respondio_burst_*` company settings,
same defaults as the other WhatsApp providers).

## Still outside Communication

- `Kanvas\Notifications\Channels\RespondIoSmsChannel` calls `Client` directly (a staff
  notification, not a customer conversation).
- `PushLeadAction` / `PushLeadActivity` push a lead to Respond.io: an external system, so it stays
  an activity.
