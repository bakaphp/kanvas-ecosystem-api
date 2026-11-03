# Mailgun Connector — outbound email events

Loads when work touches `src/Domains/Connectors/Mailgun/`. Covers what happens to an email after Mailgun
accepts it; the inbound receivers and agent mailboxes are documented in their classes.

## Two event receivers, one per concern

| Receiver | Events | Setup |
|---|---|---|
| `EmailOpenedWebhookJob` | `opened` → note on the lead/person, campaign slot opened | `kanvas:mailgun-setup-email-opened-webhook` |
| `EmailDeliveryWebhookJob` | `delivered`, `failed` (temporary / permanent), `complained`, `unsubscribed` | `kanvas:mailgun-setup-delivery-webhooks` |

Keep them apart: each command registers only its own Mailgun webhook ids, so setting up one never
repoints the other.

## How an event finds its email

- **Send:** `SendAsAgentMailboxAction` is the one place mail leaves through Mailgun's API. After the send
  it files a `message_attempts` row (`provider = 'mailgun'`) keyed by the Message-Id the API returned,
  without the angle brackets (`RecordEmailAttemptAction::normalizeMessageId()`), because events spell it
  that way, and starts it `accepted`: a 200 from the send API is exactly Mailgun's `accepted` ("placed in
  queue"), which is why that webhook is not registered. A send Mailgun refuses (`Client::assertOk`) is
  filed `failed` under the sender's attempt uuid, since there is no Message-Id; a connection error is not
  a verdict and files nothing. A failure to file is reported, never thrown.
- **Link:** the Message is usually written after the send. The send result carries `provider` +
  `provider_message_id` (or `attempt_uuid` for a refused send), and `StoreMessageSidAction` /
  `PersistOutboundMessageAction` hand it to `LinkMessageAttemptAction`, which also copies the attempt's
  state onto the message. An agent reply already has its Message, passed as `trackingMessage`.
  `kanvas.message_delivery_statuses` maps `provider` to its status enum for that copy.
- **Event:** `EmailDeliveryWebhookJob` matches `message.headers.message-id` to the attempt **within the
  receiver's app** and takes the company from the attempt, not the receiver: one sending domain can serve
  several companies, and Mailgun calls one URL per domain. An event with no attempt is ignored, never
  guessed onto a tenant.

The event's own id is the dedupe key (Mailgun retries until it gets a 2xx). A status only moves forward
(`DeliveryStatusEnum::canFollow()`): a late `accepted` or a temporary failure Mailgun recovered from never
overwrites `delivered`.

## What each outcome does

| Event | Attempt / message | Contact |
|---|---|---|
| `delivered` | `delivered`, pushed as `message.delivery.updated` | — |
| `failed`, temporary | `temporary_failure` (Mailgun retries) | — |
| `failed`, permanent | `failed` + SMTP code, pushed | that address → `hard_bounce` |
| `complained` / `unsubscribed` | status kept, classification set | `ApplyDoNotContactAction` — person-wide, like a STOP |

A complaint or an unsubscribe is the person asking us to stop, so it follows the consent rule in
`Guild/Customers/CLAUDE.md` rather than opting out the one address.

## Not covered

Mail sent on the company SMTP identity (`Blank` notifications, agents without a mailbox) has no attempt:
nothing captures its Message-Id. Its events are ignored.
