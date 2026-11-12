# Mailgun Connector — outbound email events

Loads when work touches `src/Domains/Connectors/Mailgun/`. Covers what happens to an email after Mailgun
accepts it; the inbound receivers and agent mailboxes are documented in their classes.

## Two event receivers, one per concern

| Receiver | Events | Setup |
|---|---|---|
| `EmailOpenedWebhookJob` | `opened` → note on the lead/person, campaign slot opened, delivery `read` | `kanvas:mailgun-setup-email-opened-webhook` |
| `EmailDeliveryWebhookJob` | `delivered`, `failed` (temporary / permanent), `complained`, `unsubscribed` | `kanvas:mailgun-setup-delivery-webhooks` |

Keep them apart: each command registers only its own Mailgun webhook ids, so setting up one never
repoints the other. An open still reaches the delivery: `EmailOpenedWebhookJob` matches it by Message-Id through the
same `RecordEmailEventOnDeliveryAction`, so `opened` is never added to the delivery receiver's events.

**The delivery receiver is one per app and sending domain** (`DeliveryWebhookReceiverService`,
`configuration.mailgun.domain`), not per company: Mailgun keeps one URL per domain and event, and Mailgun
has no per-message status callback the way Twilio does (routes only forward inbound mail). Its company is
only whoever set it up first. It is set up by hand, once per domain, never from a send: run the command
for any company on that domain. The domain defaults to the company's from email (`from_email_address`,
e.g. `tom@mg.salesassist.io` → `mg.salesassist.io`), which is what company SMTP mail goes out as, then
the company's Mailgun domain; `--domain=` overrides both (an agent mailbox domain, for instance). Running
it again repoints the domain's events at the same receiver; a receiver switched off stays off.

## Where it is recorded: the communication ledger

Email deliveries live in Communication, not in a Mailgun table (a connector must not own a table the
platform reads): one `communication_requests` row for the person, one `communication_deliveries` row
(`channel = email`, `provider = 'mailgun'`, `connection_id` null — Mailgun is not a registered
communication provider), and its events in `communication_delivery_events`. Twilio SMS still uses its own
`twilio_message_attempts` until it moves onto deliveries.

## How an event finds its email

- **Send through the API:** `SendAsAgentMailboxAction` is the one place mail leaves through Mailgun's API. After the send
  `FileEmailDeliveryAction` files the request + delivery keyed by the Message-Id the API returned, without
  the angle brackets (`FileEmailDeliveryAction::normalizeMessageId()`), because events spell it that way,
  and starts it `accepted`: a 200 from the send API is exactly Mailgun's `accepted` ("placed in queue"),
  which is why that webhook is not registered. A send Mailgun refuses (`Client::assertOk`) is filed
  `failed` under the sender's attempt uuid (the delivery's uuid), since there is no Message-Id; a
  connection error is not a verdict and files nothing. A failure to file is reported, never thrown.
- **Send on the company SMTP identity:** an agent without a mailbox (`SendAgentEmailAction`) and a lead
  send without one (`SendMessageToLeadAction::sendEmailMessage()`) go out as a queued `Blank`. When the
  company's (or app's) SMTP host is Mailgun's (`FileEmailDeliveryAction::sendsOnMailgunSmtp()`, checked
  by the caller), `FileEmailDeliveryAction::sending()` fixes our own
  Message-ID on the notification (`Notification::setMessageId()`, written as the header in `toMail()`)
  and files the delivery `sending` before the job is queued, so the send result can link it like a
  mailbox send. Communication's `MarkSmtpEmailAcceptedListener` (`NotificationSent`) moves any email
  delivery `sending` under that Message-ID to `accepted` once the SMTP server takes it. Any other SMTP host never reports back, so nothing is filed.
- **Campaign:** a campaign send stores its `lead_campaign_recipients` id on the request
  (`campaign_recipient_id`). The campaign tables are not written; delivered/failed per campaign is read
  from the ledger. Opens stay with `EmailOpenedWebhookJob` (`v:campaign_recipient_id`).
- **Link:** the Message is usually written after the send. The send result carries `provider` +
  `provider_message_id` (or `attempt_uuid` for a refused send), and `StoreMessageSidAction` /
  `PersistOutboundMessageAction` hand it to Communication's `LinkDeliveryMessageAction`, which also writes
  the delivery's state onto the message. An agent reply already has its Message, passed as `trackingMessage`.
- **Event:** `EmailDeliveryWebhookJob` matches `message.headers.message-id` to the delivery **within the
  receiver's app** and takes the company from the delivery, not the receiver: one sending domain can serve
  several companies, and Mailgun calls one URL per domain. An event with no delivery is ignored, never
  guessed onto a tenant.

Events go through `RecordDeliveryEventAction`: deduped on delivery + Mailgun event + timestamp (Mailgun
retries until it gets a 2xx), and the status only moves forward, so a late `accepted` or a temporary
failure Mailgun recovered from never overwrites `delivered`.

## What each outcome does

| Event | Delivery / message | Contact |
|---|---|---|
| `delivered` | `delivered`, pushed as `message.delivery.updated` | — |
| `failed`, temporary | event recorded, stays `accepted` (Mailgun retries) | — |
| `failed`, permanent | `failed` + SMTP code, pushed | that address → `hard_bounce` |
| `complained` / `unsubscribed` | event recorded, reads as `delivered` | `ApplyDoNotContactAction` — person-wide, like a STOP |
| `opened` (on the opened receiver) | `read`, pushed; a bot open is ignored | — |

A complaint or an unsubscribe is the person asking us to stop, so it follows the consent rule in
`Guild/Customers/CLAUDE.md` rather than opting out the one address.

## Not covered

- A queued SMTP send that never goes out (the job fails for good) stays `sending`: the generic
  notification layer has no hook into Communication, and marking it `failed` on the first failed try
  would freeze it there even when a retry sends it.
- Other `Blank` emails (notifications to users, `SendEmailToPeopleAction`, ...) are not filed; their
  events are ignored.
