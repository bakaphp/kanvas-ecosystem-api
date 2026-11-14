# Communication — Kanvas Ecosystem API

Loads when work touches `src/Domains/Communication/`. The first-party way to message a person: one
API for every domain, whatever the channel or the vendor behind it.

## Sending from another domain

Never pick a vendor in the caller. Send through the facade:

```php
use Kanvas\Communication\Communication;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\Sender;

// A new message, on the company's connection for that channel.
$operation = Communication::for($app, $company)->send(
    $people,
    Content::text($body)->withSubject($subject),
    channels: ['sms'],                 // ['email'], ['whatsapp'], several, or 'all'
    from: Sender::agent($agent),       // Sender::system() when nobody is speaking
    sync: true                         // only for a caller that waits on the outcome
);

// An answer inside a conversation: same number or address the customer wrote to.
Communication::for($app, $company)->reply($inboundMessage, $body, from: Sender::agent($agent));

// Whether the company can send on a channel right now.
Communication::for($app, $company)->isReady(ChannelTypeEnum::SMS);
```

- The recipient is a `People`; the address comes from their contacts. A `Lead` sends to `$lead->people`.
- `send()` returns an `Operation`: the request, and one delivery per channel with its status. A
  channel with no connection, a switched-off connection and a recipient who asked not to be
  contacted are **failed or skipped deliveries on the operation, not exceptions**.
- Two sends are one only when they share an `idempotencyKey`. Identical content is never deduped.
- `reply()` never falls back to the company default: an answer from another number is a new
  conversation to whoever receives it.
- A delivery whose outcome was lost is `unknown` and is never retried automatically.
- Which connection: `fromAccount` when the caller names the number or mailbox, else the one the
  speaking agent owns (`from: Sender::agent($agent)`), else the channel's default. A named account
  the company does not have is no connection, never a fallback to another number.
- `toAddress` reaches the person on an address that is not their first contact of that kind.
- Email-only extras ride on the content: `Content::text($body)->withEmail(new EmailOptions(cc: ...,
  template: ..., leadId: ..., trackOpens: true))`. Ids and scalars only — it is stored on the request.
  Without a `template` the text goes out as written (an agent's own mailbox); with one it is placed
  in the company's email template.
- `file: false` (sync only) is for a caller that files the message itself and links the delivery to
  it afterwards with `LinkDeliveryMessageAction` — the lead send does. Everyone else lets the
  ledger file it.

## What lives here and what lives in a connector

| Here (vendor-neutral) | In `Connectors/{Vendor}` |
|---|---|
| `Communication` facade, `Router`, `SendMessageAction`, the ledger (`Request`, `Delivery`, `DeliveryEvent`) | the vendor client and its handler |
| `Contracts/` — `CommunicationProvider`, `Transport`, `InboundSource`, `Provisioner` | the classes that implement them |
| `Webhooks/ProcessCommunicationWebhookJob`, `Messages/Actions/ReceiveMessageAction` | signature check and payload parsing (`InboundSource`) |
| `Connections/` — setup, claims, cleanup, shared registrations | vendor registration calls (`Provisioner`) |
| channel rules that hold for any vendor (`Channels/Sms/Rules/BodyLength`) | anything that names the vendor |

**This domain imports no `Kanvas\Connectors\*` class.** `CommunicationLayoutTest` fails CI on one.
The two meet in `config/communication.php`, the same way `config/scribe.php` joins Scribe to its
e-CF providers:

- `providers` — the handler classes that can carry messages. `ProviderRegistry` reads this list.
- `opt_in` — providers held back from automatic setup until the app or the company lists the slug in
  its `communication_providers` setting. Empty today; a provider goes here while its cutover is
  being planned, because moving a tenant's webhook is a cutover. Whether an integration is
  provisioned here is one question with one answer, `ProviderRollout::providerFor()` (it carries
  messages, it is this app's, its provider is not held back); never re-derive it.
- `default_rules` — the few rules setup still gives a company (`DefaultRuleEnum`): what each runs and
  the company settings it waits for. The activities are named here because some belong to a
  connector. Two are left, each for a stated reason: the Google Calendar event (triggers an
  external system, so it stays a rule) and privatizing messages (not an agent's behaviour; it
  follows the message-audience work). Do not add an agent behaviour here — see "What an agent
  does on its own".
- `retention` — how long a provider can keep reporting on a message.

Editing that file needs `php artisan config:cache` wherever config is cached.

## Email without an integration: the built-in SMTP carrier

Every company can email before it connects anything. `Channels/Email/Smtp/SmtpCarrier` is a
`BuiltInCarrier`: no integration, no webhook, no provisioner. Its connection (provider `smtp`,
`integrations_company_id = 0`, account = the company's from address) is made by
`EnsureBuiltInConnectionAction` the first time an email needs it, and the router only reaches it
when the company has no default connection for email — a connected provider always wins.

- `EmailComposer` turns a delivery into the email (subject, template, signature, copies) for every
  email transport. A transport only decides how it leaves; never compose in one.
- `SmtpTransport` sends in the delivery's own process (`notifyNow`), so the outcome on the ledger is
  the server's answer. It fixes the Message-ID itself: that is what a host's later events carry.
- `'all'` channels reads chosen defaults only, so it never starts emailing through the built-in.
- `CommunicationCarrier` is what both kinds share (slug, transports, retention);
  `CommunicationProvider` adds everything that needs an integration. Ask the registry for
  `provider($slug)` when you need the integration side, `get($slug)` when any carrier will do.

## Adding a provider

A provider is a connector, never a folder in this domain:

1. In `Connectors/{Vendor}`, the integration handler extends `Baka\Contracts\BaseIntegration` and
   implements `CommunicationProvider`: its `slug()`, the `transports()` it has per channel, the
   `accounts()` a tenant selected, and the `provisioner()` and `inbound()` classes.
2. A `Transport` per channel sends and names the conversation; an `InboundSource` authenticates and
   parses the webhook; a `Provisioner` registers and releases the vendor's webhook. The last two
   `use` `Contracts\Concerns\HasInboundDefaults` / `HasProvisionerDefaults` and write only the
   methods where the vendor differs.
3. Add the handler to `providers` in `config/communication.php`, and its retention.

The pieces every provider would otherwise write again:

| Need | Use |
|---|---|
| debounce windows from company settings (`{prefix}_burst_{idle,max,jitter}_seconds`) | `BurstPolicy::fromCompanySettings()` |
| the integration row for a company served by app-level credentials | `Connections\Actions\EnsureProviderIntegrationAction` |
| a lead for a conversation with none (type, source, receiver, pipeline) | `Guild\Leads\Actions\OpenLeadFromConversationAction` |
| retiring the receivers and the responder rule from before the cutover | `RetireLegacyInboundAction`, then `Connection::candidateOwner()` / `handTo()` |

Nothing under `src/Domains/Communication` changes. If it has to, the contract is missing something:
extend the contract, not the core.

- A vendor whose webhook carries more than one-to-one messages (a contact changed, a conversation
  closed, a group chat it runs itself) implements `Contracts\HandlesProviderEvents` on its
  `InboundSource`: the job hands it the payload first, and a non-null answer ends the job before the
  shared pipeline. RespondIO uses it for its CRM events; WaSender returns every event that way.
- A vendor with no webhook API returns `onboardable(): false` and registers nothing (`RespondIOProvisioner`).
- A receiver's configuration can say how the leads it opens are filed: `lead_type`, `receiver_id`
  (the lead receiver whose rotation owns them) and `pipeline_id`. Without them a Warm lead is opened.

## How a message arrives and gets answered

Completing the integration is the only setup a tenant does. `EnsureCommunicationSetupAction` has the
provisioner point the vendor at the company's communication receiver, whose job is
`ProcessCommunicationWebhookJob` for every provider. The job reads the provider from the receiver
(never from the request), has that provider's `InboundSource` authenticate and parse, and
`ReceiveMessageAction` files the person, lead, consent and conversation the same way for all of them.

## Who may write in: the sender policy

A provider's `InboundSource` can put an `InboundSenderPolicy` on the message it parses. Without one
the account is a customer line: whoever writes is a person, and a person with no open lead gets one.
`InboundSenderPolicy::assistant()` is an agent's own address, which is not a lead form: a user of
the company is filed as themselves (no person, no lead, no consent to settle), a person on file is
left as found and gets no new lead, and a stranger is turned away (`unknown_sender`) unless the
mailbox is open. Which accounts are which is the provider's knowledge — Mailgun reads it off the
agent's mailbox access — never a check on the provider's name in this domain.

## Who answers: the agent that owns the number or mailbox

There is no auto-reply rule, no responder activity and no company setting. A connection carries the
agent that owns it (`config.agent_id`, read by `Connection::agent()`), which the provider's
`accounts()` supplies: Twilio returns the company's sending number plus every agent's
`sms_config.phone_number`, each with its agent. Once a message (or the burst it closes) is filed,
`RespondToInboundMessageJob` is queued when the connection it arrived on has an agent, and
`RespondToInboundMessageAction` answers as that agent through `Communication::reply()`.

- Two agents with two numbers each answer their own. A connection nobody owns is received and
  filed, never answered — do not add a company-wide default agent.
- The workflow event (`AFTER_ADDING_MESSAGE_TO_CHANNEL`) still fires, for rules a tenant wrote. The
  reply never depends on one. First-party behaviour consumes the event or the pipeline directly; an
  activity is for triggering an external system.
- Ownership is read from the agent (`sms_config.phone_number`, `whatsapp_config.phone_number`) by the
  provider's `accounts()`. After an agent's number changes, `RefreshAgentConnectionsAction`
  reconciles the company's connections; nothing else may point the vendor's webhook anywhere once
  this domain manages the provider (`ProviderRollout::manages()`, asked by `IntegrationsEnum`).
- When a connection becomes ready, `Provisioner::retireLegacy()` lets the connector retire what
  served the account before (its old receiver, its responder rule) and carry the answering agent
  over. The domain only calls it; what "legacy" means is the connector's knowledge, passed by class
  name to the shared `RetireLegacyInboundAction`, which does the switching off for every provider.
- The job checks ownership again when it runs: a connection can be switched off or handed to
  another agent while the turn is queued.

## An email answer

The same responder answers every medium (`AgentChannelResponderAction` → `DeliverAgentReplyAction`);
email adds four things, all of them there and none in a connector:

- **It can wait for a person.** A company in approval mode gets the reply as a locked draft. The
  draft keeps the id of the mail it answers (`DeliverAgentReplyAction::ANSWERS_KEY`), and approving
  it runs the same `DeliverAgentReplyAction`, so an approved draft and a live answer leave the same
  way. A text is never held.
- **A failed turn sends nothing.** The kernel's fallback copy is written for staff; for an email the
  job fails instead (`fallbackOnFailure: false`).
- **The agent can open what was attached**: file markers go into the prompt next to the native blocks.
- **The thread subject is the lead's** (`Lead::emailThreadSubject()` / `anchorEmailThread()`, first touch wins): the outreach
  writes it, a cold inbound gives it, the follow-up engine reads it. Without a lead the transport
  threads under the mail being answered.

How the answer is written depends on whose address it leaves from: `Connection::personalOwner()`.
From an agent's own mailbox it is the text as written; from a company line an agent answers it is
the company's email (`EmailComposer::REPLY_TEMPLATE`). The same question decides the template of a
lead or people email (`Communication::speaksFromOwnAddress()`). Which addresses are personal is the
provider's knowledge (`config.personal`, set by its `accounts()`).

## What an agent does on its own: listeners, switched on the agent

First-party behaviour needs no rule row and is not tied to the workflow engine. Each one is a queued
listener (`ShouldQueue`, on the `workflow` queue) for a domain event (`EventServiceProvider`): its
`shouldQueue()` reads the switch, so nothing is queued when it is off, and its `handle()` does the
work on the worker. There is no job class beside it. The switch is always on the agent, never a
company setting:

| Behaviour | Event | Switch |
|---|---|---|
| First message to a new lead (`ReachOutToNewLeadListener`) | `Guild\Leads\Events\LeadCreatedEvent` | the agent's `reach_out_config` column: `new_leads`, with `lead_source_allowlist` beside it (`Agent::forNewLeads()`) |
| Answer on a lead's AI Assist channel (`RespondOnAiAssistChannelListener`) | `Social\Channels\Events\ChannelMessageAttachedEvent`, a person's message | the agent the channel's session was opened with |
| A lead's AI mode (`ApplyLeadAiModeListener`) | `Intelligence\Triggers\Events\LeadAiTriggeredEvent` | an active agent serves the company |
| Index a file attached to an agent (`IndexAgentDocumentsListener`) | `Filesystem\Events\FileAttachedToEntityEvent` | the agent the file is on |
| Prune a deleted file from the knowledge base (`PruneKnowledgeDocumentListener`) | `Filesystem\Events\FileDeletedEvent` | none: every deleted file |

- Raise an AI trigger with `LeadAiTriggeredEvent::announce($lead, $triggerType)`, never a bare
  `fireWorkflow(TRIGGER_AI)`: it dispatches the event and still fires `trigger-ai` for a rule a
  tenant wrote. A creator that also dispatches `LeadCreatedEvent` uses `announceNewLead($lead)`
  instead: when an agent reaches out, the reach-out applies the lead's mode itself, and the event
  would apply it a second time. An import that raises the trigger alone keeps `announce()`.
- Say an agent reached out with `AgentReachedOutToLeadEvent::announce($lead, $agent, $result)`: it
  dispatches the event for what Kanvas does about it, and fires `agent-reached-out` on the lead for
  what an outside system needs to hear. A CRM that reacts declares an activity on that event in its
  integration's `defaultRules()` (eLead: `StopResponseClockActivity`), so only a company with that
  integration gets it. Never call a connector from the listener.
- A new way to create a lead dispatches `LeadCreatedEvent` once the lead is ready to be worked
  (`CreateLeadAction` does); an import that wants no follow-on work does not.
- A company where no agent has reach-out on does not reach out. Setup switches it on for the
  first-message agent only when no agent of the company has a say yet; `false` is someone's choice.
- What a connector adds after a reach-out (SalesAssist's legacy channels, the eLead clock) listens
  to `AgentReachedOutToLeadEvent`. The reach-out names no vendor.
- The activities that remain (`SalesAssistAgentReachOutActivity`, `TriggerIntelligenceActivity`)
  are for a rule a tenant wrote with its own conditions; they call the same actions.

## Migration state

Callers that still send through a connector class, and the legacy per-connector webhook jobs and
responder activities that serve tenants not yet opted in, are tracked in `docs/TODO.md`
("Communication first-party migration"). New code never adds to them.
