# Mailgun Connector

`MailgunHandler` is a Communication provider: it implements `Communication\Contracts\CommunicationProvider`
and is named in `config/communication.php`, with `EmailTransport`, `MailgunInbound` and
`MailgunProvisioner` behind it. The Communication domain never imports this connector
(`CommunicationLayoutTest`). Loads when work touches the Mailgun provider.

Every address is a connection, and everything about an email (in, out, its events) goes through the
one communication receiver. There are no Mailgun webhook jobs for conversations any more: the four
that existed (company inbox, agent inbox, delivery events, opens) are retired per company by
`RetireLegacyEmailInboundAction` the first time its connection is ready.

## Which addresses are connections

`MailgunHandler::accounts()` returns:

- the **company's address** on its Mailgun domain (`MAILGUN_FROM_ADDRESS`, else its from email when
  that is on the domain) — the default email connection;
- **every agent mailbox** (`MAILGUN_MAILBOX_ADDRESS` custom field on the agent), carrying the agent.

Never the **accounting inbox** (`AccountingInboxService`): it turns attached PDFs into bills on its
own receiver (`ScribePdfInboundWebhookJob`), is not a conversation, and stays exactly as it was.

## An agent's own mailbox vs the company line an agent answers

`MailboxAccessEnum` on the agent decides what its address is:

| Access | What the address is | A stranger writes | Thread | Goes out as |
|---|---|---|---|---|
| `restricted` | the agent's own mailbox | turned away (`unknown_sender`) | per agent and sender | the agent, text as written, Reply-To the mailbox |
| `open` | the agent's own mailbox | answered | per agent and sender | same |
| `customer_line` | the **company's** address, which this agent answers | a person and a lead | the company's | the company, in the `agent-email-response` template |

The domain only sees `Connection::PERSONAL` (`config.personal`), which `accounts()` sets from the
access. `Connection::personalOwner()` is what `EmailTransport` and the reply delivery read; never
check `agent()` to decide how an email is written.

`customer_line` is how the agent a company's old responder rule named keeps answering the company's
mail: `RetireLegacyEmailInboundAction` gives the company address to that agent when it has no
mailbox of its own. An agent that already has one is left alone, and the company address is then
filed and not answered until someone assigns it.

## Giving an agent a mailbox

`ProvisionAgentMailboxAction` sets the address and access on the agent and reconciles the company's
connections (`RefreshAgentConnectionsAction`). It takes `restricted` or `open` and refuses
`customer_line`: that access is only ever written by `RetireLegacyEmailInboundAction`, on the
company's address. The route in Mailgun is registered by the setup job
through `MailgunProvisioner::registerInbound()`, never by the mailbox itself; `DisconnectAgentMailboxAction`
clears the fields and reconciles, and the connection's cleanup removes the route.

Mailgun can be configured once for a whole app, so a company may have mailboxes without ever having
integrated it. `Communication\...\EnsureProviderIntegrationAction` gives such a company the integration row its
connection hangs off, with its first mailbox. For mailboxes that existed before this, run
`kanvas:mailgun:adopt-agent-mailboxes` once at the cutover.

## Outbound

- **Through a connection** (everything a customer or a lead receives): `EmailTransport`, composed by
  the domain's `EmailComposer`. The delivery is on the ledger with `provider = 'mailgun'` and its
  Message-Id (without angle brackets — `FileEmailDeliveryAction::normalizeMessageId()`), which is
  what events carry back.
- **Company SMTP** (`provider = 'smtp'`): the domain's built-in carrier. Mailgun events still find
  it when the SMTP host is Mailgun's, because `RecordEmailEventOnDeliveryAction::findDelivery()`
  matches both providers.
- **`SendAsAgentMailboxAction`** is the one send left outside a connection: the email an agent sends
  a **teammate** (`send_email_to_user`). A teammate is a user, never a People row, and the facade
  sends to people. It takes the agent, the recipient, a subject and the text, writes the body with
  the same `EmailComposer::writtenHtml()` / `writtenText()` an agent's own mailbox uses, and files
  its own delivery through `FileEmailDeliveryAction`. Do not add callers.

## Events

`MailgunInbound::recordStatuses()` handles a domain event on the communication receiver:
`RecordMailgunDeliveryEventAction` for `delivered` / `failed` / `complained` / `unsubscribed`,
`RecordMailgunOpenAction` for `opened`. The delivery is found by Message-Id **within the receiver's
app** and the company comes from the delivery, because one sending domain can serve several
companies and Mailgun calls one URL per domain. An event with no delivery is ignored, never guessed
onto a tenant. Events go through `RecordDeliveryEventAction`: deduped, and the status only moves
forward.

| Event | Delivery / message | Contact |
|---|---|---|
| `delivered` | `delivered`, pushed as `message.delivery.updated` | — |
| `failed`, temporary | event recorded, stays `accepted` (Mailgun retries) | — |
| `failed`, permanent | `failed` + SMTP code, pushed | that address → `hard_bounce` |
| `complained` / `unsubscribed` | event recorded, reads as `delivered` | `ApplyDoNotContactAction` — person-wide, like a STOP |
| `opened` | `read`, pushed; note on the lead or person; campaign slot opened; a bot open is ignored | — |

A complaint or an unsubscribe is the person asking us to stop, so it follows the consent rule in
`Guild/Customers/CLAUDE.md` rather than opting out the one address.

**One events URL per domain.** Each address has its own route, but Mailgun keeps a single webhook
URL per domain and event. When several companies share a sending domain, the first to register
keeps the events (`MailgunProvisioner::receiverStillServing()`): a later company gets its route and
leaves the events where they are, which works because the tenant is read off the delivery. The
events only move to the company provisioning when Mailgun no longer sends to the registered URL or
the receiver behind it is switched off.

**The signing key** is resolved in one place, `ConfigurationEnum::signingKeyFor($app, $company)`:
the company's own, else the app's for a company that has none because it sends through the app's
Mailgun. The webhook check and every "is Mailgun configured" check read it from there.

## Replies

There is no Mailgun responder. `MailgunInbound` parses the mail, the shared pipeline files it, and
the agent that owns the connection answers through the domain (the app default rule's
`RespondToInboundMessageActivity` → `DeliverAgentReplyAction`). What is specific to email lives there, not here: the approval hold, no
fallback copy on a failed turn, the file markers in the prompt, and the lead's thread subject
(`Lead::emailThreadSubject()`). See `src/Domains/Communication/CLAUDE.md`.
