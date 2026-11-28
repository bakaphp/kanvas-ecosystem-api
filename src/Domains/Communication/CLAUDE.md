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
- A domain that writes to a customer and needs "sent or not" as its answer does not call the facade
  itself: a person with no lead goes through `Guild\Customers\Actions\Communication\SendMessageToPeopleAction` (any
  channel; an email is composed by `SendEmailToPeopleAction`, which sends through it), a lead through
  `Guild\Leads\Actions\Communication\SendMessageToLeadAction`. Both throw or report when the message
  did not leave and return what names the delivery, so the caller can file the message and link it.
  The lead action adds the lead's rules and sends through the person action's `deliver()`: that
  method is the one place a person is written to through the facade. Do not add another.
- **A message of a lead's conversation lives on three channels**, one row attached three times: the
  conversation (the vendor's slug, found by slug alone with `FindOrCreateConversationChannelAction`,
  where a send's `channel_slug` points), the lead's history (`lead-channel-{id}`) and the person's
  (`people-channel-{id}`). The two histories go through `LeadChannelService::recordOnLeadAndPerson()`
  (`PeopleChannelService::recordOnPerson()` with no lead): it attaches without firing the channel's
  `updated` rule, so nothing is delivered twice. A new path that files a customer message calls it.
  Three channels is still one message to an agent: its history and the lead summary read the
  message's record links, and `Message::addEntity()` keeps one link per record however many paths
  file it (`ConversationHistoryHasEachMessageOnceTest`).
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
- **A customer message about a lead is linked to the lead and to its person, always both.** The
  ledger (`RecordSentMessageAction`) files a send on the person and on the lead the email names,
  else the one the person has (`LeadsRepository::getPeopleCurrentLead()`). A path that files its
  own message links both with `Message::addLeadAndPerson()`. The lead's AI mode is read off the
  message's lead, so a message linked to the person alone is answered whatever the lead says.

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
  follows the message-audience work). Do not add an agent behaviour here: it is an app default rule,
  see "What an agent does on its own".
- `retention` — how long a provider can keep reporting on a message.

Editing that file needs `php artisan config:cache` wherever config is cached.

## Email without an integration: the built-in mail carrier

Every company can email before it connects anything. `Channels/Email/Mail/MailCarrier` is a
`BuiltInCarrier`: no integration, no webhook, no provisioner. It is the same Kanvas mailer every
notification uses (`SmtpRuntimeConfiguration`: the company's mail server, else the app's, else the
default in `.env`), put on the ledger. Its connection (provider `mail`,
`integrations_company_id = 0`, account = the company's from address) is made by
`EnsureBuiltInConnectionAction` the first time an email needs it, and the router only reaches it
when the company has no default connection for email — a connected provider always wins.

- `EmailComposer` turns a delivery into the email (subject, template, signature, copies) for every
  email transport. A transport only decides how it leaves; never compose in one.
- `MailTransport` sends in the delivery's own process (`notifyNow`), so the outcome on the ledger is
  the server's answer. It fixes the Message-ID itself: that is what a host's later events carry.
- `'all'` channels reads chosen defaults only, so it never starts emailing through the built-in.
- `CommunicationCarrier` is what both kinds share (slug, transports, retention);
  `CommunicationProvider` adds everything that needs an integration. Ask the registry for
  `provider($slug)` when you need the integration side, `get($slug)` when any carrier will do.

## Adding a provider

A provider is a connector, never a folder in this domain:

1. In `Connectors/{Vendor}`, the integration handler extends `Baka\Contracts\BaseIntegration` and
   implements `CommunicationProvider`: its `slug()`, the `transports()` it has per channel, the
   `accounts()` a tenant selected, and the `provisioner()` and `inbound()` classes. `accounts()` is
   also asked about a row with no app (`ResolveIntegrationAppAction`, before any app is swept): a
   provider whose accounts need the app returns `[]` there, as Mailgun and Respond.io do. A throw
   stops `communication:setup --all-apps` for every app.
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
| a lead for a conversation with none (type, source, receiver, pipeline) | `Guild\Leads\Actions\Communication\OpenLeadFromConversationAction` |
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
It files under a cache lock on the vendor's message id (`filingLockKey()`), so a message handed over
twice at once is filed once and the second delivery reads as a redelivery.

## Who may write in: the sender policy

A provider's `InboundSource` can put an `InboundSenderPolicy` on the message it parses. Without one
the account is a customer line: whoever writes is a person, and a person with no open lead gets one.
`InboundSenderPolicy::assistant()` is an agent's own address, which is not a lead form: a user of
the company is filed as themselves (no person, no lead, no consent to settle), a person on file is
left as found and gets no new lead, and a stranger is turned away (`unknown_sender`) unless the
mailbox is open. A person who already has a lead is still filed on it — the one being worked, else
their latest — so that lead's AI mode and history apply to the mailbox too. Which accounts are which is the provider's knowledge — Mailgun reads it off the
agent's mailbox access — never a check on the provider's name in this domain.

## Who answers: the agent that owns the number or mailbox

No company setting and no rule a tenant writes decide it. A connection carries the agent that owns
it (`config.agent_id`, read by `Connection::agent()`), which the provider's `accounts()` supplies:
Twilio returns the company's sending number plus every agent's `sms_config.phone_number`, each with
its agent. Once a message (or the burst it closes) is filed, `AnnounceInboundTurnAction` fires
`AFTER_ADDING_MESSAGE_TO_CHANNEL` on the conversation (a closed burst's prompt rides as `burst_text`,
its messages as `burst_message_ids`; every hop to the agent carries both, because the agent leaves
those rows out of the history it loads and a dropped id puts that line in front of the model twice),
and the app default rule "Communication: answer inbound messages" runs
`Messages/Activities/RespondToInboundMessageActivity`: `RespondToInboundMessageAction::executeIfOwned()`
answers as the owning agent through `Communication::reply()`.

- Two agents with two numbers each answer their own. A connection nobody owns is received and
  filed, never answered — do not add a company-wide default agent. That run, and our own side of a
  conversation, is recorded as skipped and leaves the message as filed.
- A turn that is refused (switched off, stale, no counterpart) is tagged `communication-<reason>` on
  the message and recorded as skipped with that reason. `$tries = 1`: a retry after a send would
  message the customer twice.
- Every other channel that fires the event (Microsoft email) has no connection on its messages, so
  the activity skips it. A rule a tenant wrote on the event still hears every turn.
- Ownership is read from the agent (`sms_config.phone_number`, `whatsapp_config.phone_number`) by the
  provider's `accounts()`. After an agent's number changes, `RefreshAgentConnectionsAction`
  reconciles the company's connections; nothing else may point the vendor's webhook anywhere once
  this domain manages the provider (`ProviderRollout::manages()`, asked by `IntegrationsEnum`).
- When a connection becomes ready, `Provisioner::retireLegacy()` lets the connector retire what
  served the account before (its old receiver, its responder rule) and carry the answering agent
  over. The domain only calls it; what "legacy" means is the connector's knowledge, passed by class
  name to the shared `RetireLegacyInboundAction`, which does the switching off for every provider.
- Ownership is read when the run starts, never when the event fires: a connection can be switched
  off or handed to another agent while the turn is queued. A support-mode delayed turn that finds
  the number handed over runs the new owner's turn inline (`SendUnrespondedAgentMessageJob`).

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
- **An email to an agent's own mailbox says it is one.** The body alone reads like a chat message, and
  an agent written to at its own address answered that it had no inbox. `asEmailToOwnMailbox()` puts
  one line ahead of the body: the sender, the address it reached, the subject, and that the answer is
  emailed back. Only on `Connection::isPersonalAddressOf()`; a company line reaches the model as
  written, its agents are tuned on the bare text. `Communication::ownAddress()` is how an agent's
  prompt learns the address outside a turn; it never falls back to the default connection.
- **The thread subject is the lead's** (`Lead::emailThreadSubject()` / `anchorEmailThread()`, first touch wins): the outreach
  writes it, a cold inbound gives it, the follow-up engine reads it. Without a lead the transport
  threads under the mail being answered.

How the answer is written depends on whose address it leaves from: `Connection::personalOwner()`.
From an agent's own mailbox it is the text as written; from a company line an agent answers it is
the company's email (`EmailComposer::REPLY_TEMPLATE`). The same question decides the template of a
lead or people email (`Communication::speaksFromOwnAddress()`). Which addresses are personal is the
provider's knowledge (`config.personal`, set by its `accounts()`).

## What an agent does on its own: app default rules, switched on the agent

Every behaviour a company has without writing anything is a workflow rule the platform installs, so
each run is in the integration history. `config('workflows.app_default_rules')` lists them, and
`EnsureAppDefaultRulesAction` installs one rule per app per entry with `companies_id = 0` (every
company of the app), `params.app_default = true`, async. It is idempotent and brings back a rule or
step someone deleted. It runs from `kanvas:workflow-ensure-app-default-rules` (both deploys, after
`kanvas:workflow-sync-actions`, which creates the catalog rows it needs), every `communication:setup`
sweep per app, and `ScheduleCommunicationSetupAction`.

The rule is only a trigger. Whether anything happens is decided in the activity, and the switch is
always on the agent (or the knowledge base), never a company setting. A run with nothing to do
returns `skipWorkflow($reason)`: recorded as status `skipped` (gray, never reported, kept 7 days),
apart from `failWorkflow()` (FAILED, a real but expected failure). True faults throw. A condition on
the rule is only a cheap filter on plain values the event carries, so an event that concerns nobody
starts no run at all; all of an entry's conditions must hold.

| Rule (activity) | Event on | Switch |
|---|---|---|
| Communication: answer inbound messages (`Communication\Messages\Activities\RespondToInboundMessageActivity`) | `after-adding-message-to-channel` on the Channel | the agent that owns the connection |
| Leads: deliver a person's reply (`Guild\Leads\Activities\DeliverHumanReplyActivity`) | `updated` on the Channel, condition `from_human == true` | none: a person's message on a conversation channel (never the default or an internal one) |
| Agents: answer on the AI Assist channel (`Intelligence\Agents\Activities\RespondOnAiAssistChannelActivity`) | `updated` on the Channel, conditions `from_human == true` and `name == 'AI Assist'` | the agent the channel's session was opened with |
| Agents: reach out to new leads (`Intelligence\Agents\Activities\ReachOutToNewLeadActivity`) | `created` on the Lead | the agent's `reach_out_config` column: `new_leads`, with `lead_source_allowlist` beside it (`Agent::forNewLeads()`) |
| Agents: apply a lead's AI mode (`Intelligence\Triggers\Activities\ApplyLeadAiModeActivity`) | `trigger-ai` on the Lead | an active agent serves the company |
| Knowledge: index an agent's documents (`Intelligence\Knowledge\Activities\IndexAgentDocumentsActivity`) | `attach-file` on the Agent | the app has a knowledge base |
| Knowledge: prune a deleted file (`Intelligence\Knowledge\Activities\PruneDeletedFileKnowledgeActivity`) | `deleted` on the Filesystem | the app has a knowledge base |

- **Company precedence**: a company that writes its own rule running the same activity gets only its
  own (`RuleRepository::getRulesByModelAndType()` drops the app default), or the activity would run
  twice for one event.
- `Channel::addMessage()` fires `updated` with `from_human` beside `message`, which is what the two
  Channel conditions read.
- A person's text or WhatsApp reply leaves on the conversation's own number (the connection stamped
  on its last message, `SendMessageToLeadAction::inConversationOn()`), not the company's default. The
  default is used when the conversation has none or its number can no longer send. Email is left out:
  a person does not write from the mailbox an agent was written to on.
- Raise an AI trigger with `$lead->announceAiTrigger($triggerType)`, never a bare
  `fireWorkflow(TRIGGER_AI)`. It fires even on a lead whose own writes are switched off (an import
  that still says its lead is new). A creator that also fires `created` passes
  `reachOutAppliesMode: true` (`CreateLeadAction` does): when an agent reaches out to the company's
  new leads, the reach-out applies the lead's mode right before reading it, and the mode rule skips.
  Both decide when they run, so the mode is applied once whoever wins. An import that raises the
  trigger alone keeps the default.
- A new way to create a lead fires `created` on it once the lead is ready to be worked
  (`CreateLeadAction` does, unless the import asked for no follow-on work; the eLead download command does).
- Say an agent reached out with `AgentReachOutAction::announceReachOut($lead, $agent, $result)`: it
  fires `agent-reached-out` on the lead with `agent_id`, `message_id`, `message_ids_sent` and
  `message_ids_scheduled`, and nothing when the result reached nobody. What a CRM does about it is an
  activity its integration declares in `defaultRules()`, so only a company with that integration gets
  it: eLead's `StopResponseClockActivity`, and SalesAssist's `RecordAgentReachOutActivity` (the
  legacy per-protocol channels) for every dealer CRM through `DeclaresSalesAssistPullRules`. The
  reach-out names no vendor.
- A company where no agent has reach-out on does not reach out. Setup switches it on for the
  first-message agent only when no agent of the company has a say yet; `false` is someone's choice.
- The older activities (`SalesAssistAgentReachOutActivity`, `TriggerIntelligenceActivity`) remain
  for a rule a tenant wrote with its own conditions; they call the same actions. The deploy's rule
  migrations soft-delete the rules and catalog rows that ran the old names, so an app default never
  reuses one.

## Migration state

Callers that still send through a connector class, and the legacy per-connector webhook jobs and
responder activities that serve tenants not yet opted in, are tracked in `docs/TODO.md`
("Communication first-party migration"). New code never adds to them.
