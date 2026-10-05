# Agents — Kanvas Ecosystem API

Loads when work touches anything under `src/Domains/Intelligence/Agents/`. For unrelated work this stays unloaded.

Documents the **current state** of the agent chat flow and the rules for safely changing it.

## Agent archetypes — internal teammate vs external-facing (identity & memory)

Every Neuron agent **is (or should be) a Kanvas user** — `Agent.user_id` → a real `Users` row. This
holds for BOTH archetypes below; a customer-facing `SalesAgent` gets a dedicated user just like an
internal teammate does. **Identity** lives on `user_id`; **persona** (name/voice) lives in the agent's
`role`/`soul`. Give every agent its OWN dedicated user — never the shared `getAiAgentUser()` — so its
actions are attributed to its identity and its ledger memory accrues to it alone (the shared AI user
has no isolated memory and bleeds work across agents).

Two archetypes, split by **audience**:

| | Internal teammate | External / customer-facing |
|---|---|---|
| Example | `SystemUserAgent` (implements `ConversesWithUser`) | `SalesAgent` (implements `ConversesWithCustomer`) |
| Talks to | company **staff** | a **prospect / customer** |
| Reached via | @mention, channel, DM, task assignment, ownership, follow | inbound connector channel (WhatsApp/email/SMS) on a lead |
| Acts as | itself (its own user) | itself, as a consistent **persona** |
| Conversation memory | per channel/entity | per prospect (`EntityRollupMessageStore` rollup — continuity *within* a lead) |
| Cross-entity memory | ✅ full — `read_my_ledger` is company-wide, and company memory (`remembersForCompany()`) recalls any earlier conversation of the company's agents | ❌ none on the customer surface — prospect-isolated, never ingested into company memory |

### The core rule: memory scope follows AUDIENCE, not agent type

- **Talking to an external prospect → entity-scoped memory only.** Continuity *within* that prospect
  (the rollup) is correct; cross-prospect / cross-entity recall is a **leak** (prospect A's trade-in
  shown to prospect B). Customer-facing context/activity tools MUST be entity-scoped
  (`read_entity_context`, `read_user_activity` bounded to the record) — NEVER company-wide
  `read_my_ledger` in a live customer chat.
- **Talking to internal staff → full cross-entity recall is fine** — it's the company's own data.
  `read_my_ledger` (company-wide) is the agent's durable self-memory across every lead/order it touched.

This is the same guard already in code: `read_user_activity` is entity-scoped, `read_my_ledger` is
company-wide. The **audience** decides which applies — the same user-identity agent could switch
surfaces (answer a teammate with full recall, answer a prospect with only that prospect's thread).

### The two marker contracts — which one does my agent implement?

The archetype above is declared in code by one of two empty marker interfaces in
`Agents/Contracts/`. **Pick by who the agent talks to, not by what it does.**

| | `ConversesWithCustomer` | `ConversesWithUser` |
|---|---|---|
| Audience | **external** — a prospect / customer outside the company | **internal** — company staff, the agent doing work inside the system |
| Typical surface | inbound connector channel on a lead (WhatsApp/email/SMS) | user↔agent DM channel, @mention, task assignment |
| Session keyed on | the entity (lead) timeline | the user thread |
| Memory | prospect-isolated — continuity within the lead only | company-wide recall via `read_my_ledger` |
| Speaks as | a persona, name only, never internal ids | itself, its own user |
| Implemented by | `SalesAgent`, `ReceptionistAgent`, `ShoppingAssistantAgent` — all share `Neuron\Concerns\HasProspectIsolatedHistory` for the per-prospect rollup | `SystemUserAgent` |
| Read at runtime | `Agent::conversesWithCustomer()` | `Agent::conversesWithUser()` |

**They are markers, not a required choice — and internal is the default.** Subclassing inherits the
marker (`CompanyBrainAgent extends SystemUserAgent` is `ConversesWithUser` for free), but an agent
that extends `BaseRagAgent` directly and declares neither (`CFOAgent`, `FollowUpAgent`) is treated as
internal, because the gates are written as a negation of the customer marker:

```php
$internal = ! $this instanceof ConversesWithCustomer;
```

So `ConversesWithCustomer` is the one you must not forget: **every agent whose counterparty is outside
the company has to declare it**, or it silently inherits the internal toolset. `ConversesWithUser` is
the positive signal used to key sessions/persistence on the user thread instead of an entity timeline
(the `ConverseWithSystemAgentAction` funnel) — omitting it costs routing, not isolation.

What the customer marker actually gates today (add to this list, don't invent a parallel flag):

- **`read_my_ledger`** — company-wide cross-entity recall; never on a customer surface.
- **`ReadFileTool`** and the plan/task file tools — `read_file` reaches any file the company owns, so
  a prospect who talks the agent into a `filesystem_id` reads another prospect's quote. These are
  **granted per agent from the catalog**, never baseline: only `ProjectManagerAgent` holds them
  intrinsically. Do not add them to `universalTools()` — a baseline addition silently rewrites the
  toolset of every curated handler (it turned a deliberately single-tool agent into a three-tool one
  in `NeuronDynamicSubAgentTest`) and reaches customer-facing agents before any marker can stop it.
- **Persona rendering** — `HasCustomerPersona` exposes a name and no internal ids.

**For now a `ConversesWithCustomer` agent gets NO file access at all — do not grant it `read_file`.**
`resolveFile()` filters on `fromApp` + `fromCompany` and nothing else, so any `filesystem_id` in the
tenant resolves: granting it to a customer-facing agent hands over the whole company drive — another
prospect's quote, an internal contract — one coaxed id away. There is no entity-scoped variant today
(`PresentsEntityFiles` / `list_*_files` cover plans and tasks only, not leads), so there is no safe
version of this grant to make.

The end state we want is narrower, not wider: an external agent able to read **only the files uploaded
to the conversation / entity it is on** (a customer's licence photo, an insurance PDF), because an
agent that cannot open what it was handed answers from imagination. That needs `read_file` constrained
to the entity in scope first. **Until that is built and reviewed, the rule is flat: no files on a
customer surface.** Don't hand-grant an exception.

### Rules

- **Every agent gets a dedicated Kanvas user + a persona.** Required for external agents (a faceless
  bot is a worse experience); natural for internal ones. Persona = `role`/`soul`; identity = `user_id`.
- **Attribution:** records/events an agent creates are stamped to its dedicated user → clean audit AND
  the substrate for its ledger memory. Wire write-tool actor = `$agent->user`, not the conversation partner.
- **Never expose cross-entity memory to an external counterparty.** Gate context/activity tools to the
  entity in scope whenever the audience is a customer.
- **Internal agents may recall company-wide** via the ledger.
- **Tools ≠ identity.** Giving a user-agent the sales toolset makes a *sales teammate with identity +
  memory*, not a `SalesAgent`; giving `SalesAgent` system tools doesn't grant it a self. The archetype
  is the identity + audience + memory-scope combination, not the tool list.

### Pending convergence (TODO)

`SalesAgent` should adopt the user-identity model: **its own dedicated user + a named persona** (like
`SystemUserAgent`), while KEEPING prospect-isolation on the customer-facing surface. Target end state:
**one identity mechanism (it's a user), two memory surfaces (internal = full recall, external =
prospect-isolated), switched by who it's talking to.** Concretely: add a persona + dedicated user to
`SalesAgent`; do NOT hand it company-wide `read_my_ledger` on the prospect-facing path.

## End-to-end flow

```
Customer ─── inbound ──▶ Webhook
                         (per-connector ProcessXxxWebhookJob)
                         │ persists inbound Message, fires AFTER_ADDING_MESSAGE_TO_CHANNEL
                         ▼
                         Workflow rule → AgentChannelResponderActivity (per connector)
                         │ creates Session, resolves agent, calls action
                         ▼
                         AgentChannelResponderAction extends BaseAgentChannelReplyAction
                         │ guards (AI-mode, is_un_response), extracts inbound text
                         ▼
                         AgentChatKernel — routes on $agent
                         ├── isContainerRuntime()        → RunRuntimeChatAction   (OpenClaw, Hermes)
                         ├── instanceof KanvasLaravelAgent → RunLaravelAgentChatAction
                         ├── instanceof ADKAgent           → RunADKChatAction
                         └── default (Neuron-shaped)       → RunNeuronChatAction
                         │
                         │ returns response string
                         ▼
                         Action: createMessage() persists outbound, then sends via connector client
                         ▼
                                    Customer
```

## Where things live

| Concern | Path |
|---|---|
| Kernel entry point | `Actions/Chat/AgentChatKernel.php` |
| Per-backend chat runners | `Actions/Chat/Run{ADK,Laravel,Neuron,Runtime}ChatAction.php` |
| Base class for connector reply actions | `Actions/BaseAgentChannelReplyAction.php` |
| Per-connector reply actions | `src/Domains/Connectors/{X}/Actions/AgentChannelResponderAction.php` |
| Per-connector activities (workflow entry) | `src/Domains/Connectors/{X}/Workflows/AgentChannelResponderActivity.php` |
| Neuron agents (SalesAgent, generic) | `Neuron/` |
| Neuron tools (`#[AgentTool]`) | `Neuron/Tools/{CRM,System,Accounting,HumanResources,NervousSystem,...}/` |
| Shared tool traits (resolve-or-error, context, admin-guard) | `Neuron/Tools/Traits/` |
| Laravel-AI agents | `Laravel/` |
| Runtime handlers (OpenClaw, Hermes) | `Types/OpenClawAgentHandler.php` + connector dirs |
| ADK handler (wraps Google ADK HTTP) | `Types/ADKAgent.php` + `Services/GoogleADKService.php` |
| Test stubs | `tests/Stubs/Intelligence/{SalesNeuronAgentStub,FakeNeuronProvider}.php` |

## The four backends

| Backend | Detection | Memory |
|---|---|---|
| **Runtime** (OpenClaw, Hermes) | `$agent->isContainerRuntime()` | Remote, inside the tenant container |
| **Laravel** | `instanceof KanvasLaravelAgent` | Local DB, `agent_history` keyed by `(agent_id, entity_namespace, entity_id)` |
| **ADK** | `instanceof ADKAgent` | Remote, on Google ADK server, keyed by `(userId, sessionId)` |
| **Neuron** | fallthrough (any handler — typically `BaseKanvasAgent`) | Local DB, `messages` polymorphic by entity, optionally filtered by `thread_id` |

All four `Run*ChatAction` classes take `(Agent, ?Session, string $message, ..., Users)` and return a `string`. The kernel's contract.

## Kernel contract (`AgentChatKernel`)

**Constructor takes only what cannot be derived from `$agent`:**

```php
new AgentChatKernel(
    agent: $agent,                  // the agent (its app + company are the tenant)
    session: $session,              // null for ad-hoc invocations (e.g. AgentReceiverJob)
    message: $messageText,
    user: $actorUser,               // who is "talking" — staff in userChat, AI agent user on channels
    currentLead: $lead,             // optional, per-turn lead-in-scope
    sourceChannel: $this->channel,  // connector path only
    sourceMessage: $this->message,  // connector path only
    persistConversation: false,     // connector path only — see below
)->execute();
```

**`agent` is the tenant.** `$this->agent->app` and `$this->agent->company` are the only source of truth. Passing a different tenant is meaningless; an agent bound to app X cannot run for app Y. No `Apps` / `Companies` constructor params.

**`persistConversation` (default `true`):** when `true`, the kernel runs `PersistChatTurnToSocialAction` and exposes the reply via `persistedReply()`. When `false` (connector path), persistence is left to the caller — the connector's `BaseAgentChannelReplyAction::createMessage()` writes the reply with the right message-type verb, channel tagging, and fires `MarkLeadMessagesAsRespondedAction` + `NotifyLeadStakeholdersService`.

**`fallbackOnFailure` (default `true`):** a failed turn normally comes back as prose — `RunNeuronChatAction::humanizedFallback()` returns "I ran into a hiccup processing that…" so the person on the other end sees something. That is only right when a **human reads the reply**. A customer-facing agent (`$agent->conversesWithCustomer()` — SalesAgent, ReceptionistAgent) gets only "What do you mean?" for **every** failure: a prospect is talking to a persona, and a hiccup, an overloaded AI or a safety filter all read as a broken system. A caller whose reply feeds a pipeline must pass `false` and handle the exception: the newsroom burst publishes whatever the agent writes, so a Gemini response with no `parts` was filed as the reply and shipped as an article titled "I ran into a hiccup processing that" (KANVAS-ECOSYSTEM-691). `AgentBurstResponderAction` keys it on whether it will actually speak — `fallbackOnFailure: $shouldReply`. The Mailgun email responder passes `false` too: the fallback copy is written for staff, and an internal agent (AP/AR) answering an outside sender emailed "narrow it down — an exact name, email, or date range" to a vendor (KANVAS-ECOSYSTEM-6GW); a failed email turn now sends nothing and fails the webhook call instead. When it is `false` the action rethrows without `report()`, so the caller reports once rather than twice. The partless response itself no longer fails the turn: `KanvasGemini::processChatResult()` turns it into an empty reply, which connector responders already skip via `AgentReplySkippedException`.

**`sourceChannel` / `sourceMessage` (connector path AND any other rollup caller):**
- ADK uses them to compute its remote `userId` exactly the way `ADKAgent::chat()` does today (preserves remote session identity — without this, ADK conversations silently fork to a different memory key).
- Neuron 4 requires a thread id on every run, so the kernel always calls `setThreadId`; `sourceChannel !== null` decides **which** id (`AgentChatKernel::threadId()`). With a channel the thread is the session **entity's own uuid** (the Lead's / People's; `entity:<class>:<id>` for one without a uuid), so every channel and every session of that prospect share one thread and `EntityRollupMessageStore` loads the whole rollup. Without a channel the thread is the session uuid and the store filters to that session.

**Pass `sourceChannel: $session->channel` from any cron/queue-driven caller that needs cross-session history.** Today: connector channel responders (×6) and `FollowUpLeadAction`. Omitting it threads by session uuid, which filters the agent's history to that one session — typically zero useful messages for a cron-spawned agent that didn't originate the prior conversation. The bug surfaces as the agent producing literal-template-copy output because the LLM gets no meaningful prior turns. `WakeAgentForPlanJob` and `AgentReceiverJob` haven't been audited for this yet — check whether they need the same fix.

## Connector contract (`BaseAgentChannelReplyAction` subclasses)

Each connector's `AgentChannelResponderAction::execute()` follows this shape — see [`Connectors/WaSender/Actions/AgentChannelResponderAction.php`](../../Connectors/WaSender/Actions/AgentChannelResponderAction.php) as the canonical reference.

```php
public function execute(array $params = []): array
{
    // 1. Extract inbound text — connector-specific webhook payload shape
    $messageConversation = /* connector-specific */;

    // 2. Validate entity
    $entity = $this->message->entity();
    if ($entity === null) { throw new ValidationException('No entity found'); }
    $currentLead = $entity instanceof Lead ? $entity : null;

    // 3. Delegate ALL agent work to the kernel
    $responseContent = new AgentChatKernel(
        agent: $this->agent,
        session: $this->session,
        message: $messageConversation,
        user: $this->message->company->getAiAgentUserOrFail(),
        currentLead: $currentLead,
        sourceChannel: $this->channel,
        sourceMessage: $this->message,
        persistConversation: false,   // connector persists below
    )->execute();
    $responseText = ChatHelper::extractTextFromResponse($responseContent);

    // 4. Persist outbound exactly once via base class. Pass rawResponse so an agent that answered
    //    with a whole record (a post, a quote) keeps its structure — see below.
    $messageResponse = $this->createMessage(
        $responseText,
        $to,
        $this->message,
        $this->channel,
        rawResponse: $responseContent
    );

    // 5. Send via connector client only if not locked (support-mode + human-takeover)
    if (! $messageResponse->is_locked) {
        /* connector-specific outbound call */
    }

    return ['response' => $responseText, /* ... */];
}
```

### `rawResponse` → `response_json` — the reply text is lossy

`ChatHelper::extractTextFromResponse()` SELECTS one field out of the agent's JSON envelope (never
concatenates — see its docblock for why). That is right for the channel: the customer gets prose, not
a JSON dump. But an agent that answers with a whole **record** — a blog post, a quote, an enrichment —
loses every field but the body, and nothing downstream can recover it.

Passing `rawResponse: $responseContent` to `createMessage()` stores the decoded envelope on the
outbound message as `response_json`, next to the text that was actually sent. Consumers read
`$message->getMessage()['response_json']`; its **presence** is the signal that the agent replied with
structure, so no consumer has to type-check or know about ```` ```json ```` fences.

Keep it connector-agnostic: the responder records *that* the agent answered with structure, never what
some downstream feature wants to do with it. A responder writing a `wordpress` key would be backwards
— see [`Connectors/WordPress/CLAUDE.md`](../../Connectors/WordPress/CLAUDE.md) for the consumer side.

Wired today on **Mailgun** and **WaSender**; the remaining responders (RespondIO, Twilio, Microsoft,
Slack, SalesAssist) still drop the envelope — add the argument when one of them needs it, the
parameter is optional and changes nothing for a plain-text agent.

Connectors set two protected props on their class:
- `$messageTypeVerb` — e.g. `'whatsapp'`, `'mailgun-email'`, `'respondio-text'`, `'twilio-sms'` (used by `createMessage()` for the outbound's `MessageType`)
- `$communicationChannel` — e.g. `'whatsapp'`, `'email'`, `'sms'`, `'respondio'` (written to the outbound message's custom field)

## Adding a new backend (e.g. Anthropic-direct)

1. Add a new file `Actions/Chat/RunAnthropicChatAction.php` taking `(Agent $agent, ?Session $session, string $message, Users $user, ...)` and returning `string`.
2. Add an `instanceof YourHandler` branch in [`AgentChatKernel::runHandler()`](Actions/Chat/AgentChatKernel.php) ahead of the default Neuron fallthrough.
3. If the backend needs `sourceChannel` / `sourceMessage` (e.g. for remote session identity), add them to your action's constructor — they're already on the kernel and available via `$this->sourceChannel` / `$this->sourceMessage`.
4. Add a test stub mirroring `tests/Stubs/Intelligence/FakeNeuronProvider.php` so the new backend can be exercised end-to-end without network.

## Adding a new channel connector

1. Build the inbound stack the way Mailgun/WaSender/RespondIO/Twilio already do: `ProcessXxxWebhookJob` → persist `Message` + associate Lead/People + fire `AFTER_ADDING_MESSAGE_TO_CHANNEL`.
2. Build an `AgentChannelResponderActivity` that resolves the agent, creates a `Session` via `CreateSessionAction` keyed on `SessionChannelService::buildChannelSessionUuid($channel, $app, $company)`, and calls `AgentChannelResponderAction::execute()`.
3. Write `AgentChannelResponderAction extends BaseAgentChannelReplyAction`:
   - Set `$messageTypeVerb` + `$communicationChannel`
   - Implement `execute()` following the shape above
4. Mirror the end-to-end test from `tests/Connectors/Integration/WaSender/AgentChannelResponderEndToEndTest.php` — same setup, swap the connector-specific outbound mocking strategy.

## Testing pattern

```php
// Neuron stub that returns a fixed response — see tests/Stubs/Intelligence/SalesNeuronAgentStub.php
$agentType = AgentType::factory()->withAppId($app->getId())->create([
    'provider' => 'neuron',
    'handler'  => SalesNeuronAgentStub::class,
]);
$agent = Agent::factory()->withAppId(...)->withCompanyId(...)->create(['agent_type_id' => $agentType->getId()]);

// Drive the action directly (bypasses executeIntegration wrapper which needs IntegrationCompany)
$action = new AgentChannelResponderAction(
    $channel,
    $inbound,
    $agent,
    $session,
);

try {
    $action->execute([]);
} catch (Throwable) {
    // Outbound API call fails in test env without real credentials — persistence ran first.
}

// Assert the kernel ran end-to-end and persisted the reply
$outbound = Message::query()
    ->whereJsonContains('message->from_ia', true)
    ->whereHas('channels', fn ($q) => $q->where('channels.id', $channel->getId()))
    ->latest('id')
    ->first();
$this->assertStringContainsString('Hola Mundo', (string) $outbound->message['content']);
```

## The provider's input ceiling has two halves — guard both

Gemini rejects a request over 1,048,576 input tokens with a 400, and 400 is deliberately **not** in
`LlmHttpRetryService::RETRYABLE_STATUS_CODES` (replaying a deterministic overflow just burns the
retry). The turn falls into `humanizedFallback()`, so the person gets "I ran into a hiccup" and the
work silently never ran. Two independent things can push a turn over, and they need separate guards:

| Half | Grows via | Guard |
|---|---|---|
| **Stored history** replayed at the start of a turn | every past turn of the thread | `KanvasHistoryTrimmer`, wired by `HasKanvasAgentBehavior::resources()` |
| **Tool output** added *inside* the turn in progress | a tool loop pulling diffs / whole files | `BoundToolResultsMiddleware` |

**A message store only loads rows; it never trims.** Neuron 4's `ChatHistory` runs its trimmer only on
`addMessage()`, and the first add of a turn happens *after* the provider call (the inbound message is
committed once the call succeeds), so the stock history sends the loaded thread whole.
`KanvasChatHistory::getMessages()` trims the load for that reason; without it a rollup or channel thread
past the model's limit failed on every turn (KANVAS-ECOSYSTEM-6F1, regressed by the v4 upgrade). The
stock `HistoryTrimmer` only cuts; Kanvas rows also need the same-role fold
(dual persistence writes some turns twice, and providers reject two consecutive assistant turns or a
history that opens with one), so every Kanvas agent opens its history with
[`KanvasHistoryTrimmer`](ChatHistory/KanvasHistoryTrimmer.php): `fold()` (merge consecutive same-role
turns, re-attach media blocks, drop leading assistant turns) and **then** the stock cut.
`HasKanvasAgentBehavior::resources()` is the one place that builds the `ChatHistory`; `getChatHistory()`
is final in v4, so override `messageStore()` to pick the store and leave the trimmer alone.

| Store | Rows | Thread |
|---|---|---|
| `ConversationMessageStore` | `agent_conversation_messages`; writes every turn | session uuid (the conversation row's `title`) |
| `EntityRollupMessageStore` | Social `messages` keyed by the entity (Lead / People); writes tool telemetry only | entity uuid; filters to one session only when the thread *is* a session uuid |
| `ChannelMessageStore` | Social channel messages, read-only | the channel; persistence is the caller's |
| Laravel agents (`KanvasLaravelAgent::messages()`, `RemembersConversationsWithinBudget`) | `LaravelHistoryBudgetTrimmer::trim()` to the same `ModelContextWindowService` budget | n/a — the package replays from the store each turn |

Anything that replays rows outside an agent run (`LeadConversationTranscriptService`, the @mention
responder) calls `KanvasHistoryTrimmer::fold()` on them itself.

`MAX_LOADED_ROWS` on `ConversationMessageStore` caps hydration on top of that — a memory guard, not a
context guard, because `content` is a longtext, and it sits far above what any window holds so the
token trim is always what decides. Coverage: `tests/Intelligence/Agents/LoadedHistoryTrimTest.php`.

The window itself is **sized to the model that will answer**, by
[`ModelContextWindowService`](../Services/ModelContextWindowService.php), passed in by
`HasKanvasAgentBehavior::resources()` via `resolvedContextWindow()`. It reads `model_pricing.max_input_tokens` — filled
by the nightly `nervous-system:sync-model-pricing`, so a freshly migrated environment reads NULL and
every agent silently falls back to the 50K floor until that runs.

**The model ceiling is an upper bound, not the budget.** `kanvas.agents.max_history_tokens` (env
`AGENT_MAX_HISTORY_TOKENS`, default 50K) caps the result, and it wins over both the ceiling and the
floor. An app can raise its own cap with the `agent_max_history_tokens` setting
(`AgentRunConfigurationEnum::MAX_HISTORY_TOKENS`); unset or 0 keeps the platform value, and the model
ceiling still bounds it. The history is re-sent on every tool-loop step, so sizing Gemini to its ~655K ceiling took daily
Gemini spend from ~$600 to ~$1,500 the day it shipped (2026-09-18). Raise the cap only with the cost in
view.

**Two traps in that number:**

- **The catalogue reports the model's largest possible window, not the one our requests may spend.**
  LiteLLM lists `claude-sonnet-4-5` at 1,000,000 because that window exists — behind
  `anthropic-beta: context-1m-2025-08-07`, which nothing here sends. Budgeting it would put the request
  straight back over the limit. `PROVIDER_CEILING_CAP` caps Anthropic for that reason; check for an
  opt-in header before trusting any suspiciously large ceiling. Gemini's 1,048,576 needs none — the
  provider named that exact number back to us in the 400.
- **Raising the flat default is not the fix.** The budget is derived —
  `(ceiling − tool-output reserve − prompt reserve) / 1.35` — and the 1.35 is load-bearing: NeuronAI's
  TokenCounter assumes 4 chars/token while code and JSON run nearer 3, so an undiscounted budget
  under-counts a diff-heavy history by about a third and can still cross the ceiling.

Trimming forgets; summarizing keeps. Agents on `ConversationMessageStore` register
`KanvasSummarization` on the chat node: at 80% of the window the oldest turns are compacted into a
summary the model reads, their rows are stamped `archived_at` (never deleted, so the transcript and the
spend rollup keep them), and the summary itself is written to Social as a private `agent_summary`
message so a human can see what the agent kept. Rollup and channel stores never summarize: their rows
belong to other writers. Detail in [`Neuron/CLAUDE.md`](Neuron/CLAUDE.md).

### When the tool-output budget runs out: refuse, then continue

Once a turn has spent `MAX_CHARS_PER_TURN`, `BoundToolResultsMiddleware::before()` **refuses** every further
call (`NOT_EXECUTED`) instead of running it and hiding the output. A hidden result leaves the model unable
to tell whether a write happened, so it reports the item as pending and the next turn creates it twice.
A call already in the batch that spent the budget did run, so it reads "ran, output withheld" — the two
must never be confused, because the next turn works only from the model's report.

That report is the whole hand-off: history reload replays text, never `tool_results`, so a new turn
starts small with a fresh budget. `ContinueAgentTurnJob::dispatchIfCutShort()` queues that turn itself — capped at `MAX_CONTINUATIONS`, stopped early when a turn repeats only calls
already made, and stopped when a person writes in the channel after the reply. Internal agents only
(`conversesWithUser()`): on a customer surface a stranger could turn one message into several paid turns.

Wired on Slack and the async in-app chat. Another surface opts in by calling `ContinueAgentTurnJob::dispatchIfCutShort()` —
**after** its reply is delivered, never before, or the follow-up lands above it.

## Writing agent tools (Neuron `#[AgentTool]`)

Every capability an agent can invoke is a tool class under `Neuron/Tools/{Area}/`, one per file. Follow
these conventions so new tools stay consistent with the ~90 that already exist — they're what the LLM
sees and what keeps a hallucinating model from crashing a chat or leaking data.

### Skeleton

```php
#[AgentTool(name: 'Send Email')]          // human label — drives the nervous_system_tools catalog sync
class SendEmailTool extends Tool
{
    use ResolvesLeadForTool;              // pull in the resolve-or-error trait(s) you need

    protected string $name = 'send_email';   // snake_case — the id the LLM calls
    protected ?string $description = '…';    // nullable to match the vendor base; what it does, WHEN to use it, and hard limits ("you cannot choose the recipient")

    #[Override]
    protected function properties(): array
    {
        return [ new ToolProperty(name: 'lead_id', type: PropertyType::INTEGER, description: '…', required: true), /* … */ ];
    }

    public function __invoke(int $lead_id, string $subject, ?string $cc = null): array { /* … */ }
}
```

The attribute `name:` is the human label; the `$name` property is the snake_case LLM id. Keep `$description` a
literal property default: `AgentToolDiscoveryService` reads it through reflection for the catalog sync, and a
description built in a constructor is invisible to it.
`toolType` on the attribute defaults to `'system'`; `requiresPermission` maps to Bouncer abilities for the
catalog. Register the tool on an agent's tool list — either via `withContext(...)` or constructor injection
(both styles exist).

### Return shape — structured, never throw at the LLM

Tools **return** a result array; they do **not** let exceptions reach the chat. The dominant dialect is
`['status' => 'success'|'error', 'message' => …]` plus tool-specific keys on success, and a `note` key
carrying a short natural-language instruction for what the model should say/do next
([`SendEmailTool.php`](Neuron/Tools/CRM/SendEmailTool.php), [`SendSmsTool.php`](Neuron/Tools/CRM/SendSmsTool.php)).
Mutation/find tools use sibling dialects (`created`/`updated` + `message`, or `found`/`error`) — pick one
and stay consistent **within a tool family**. Write error `message`s to *instruct the model* ("Do not retry",
"ask the person for the address, save it with update_lead, then retry") — they are prompts, not logs.

Wrap action calls: `try { … } catch (Throwable $e) { report($e); return ['status'=>'error','message'=>'…']; }`.
Catch expected misses specifically (`ModelNotFoundException`, `ValidationException`) and turn them into an
actionable message; `report($e)` still fires so the incident is tracked while the LLM sees calm copy.

### Resolve-or-return-error — never trust an LLM-supplied id

The single most-repeated idiom. An id the model passes (`lead_id`, `message_id`, `plan_id`, an email) is a
**hallucination risk** — resolving it with a bare `getById()`/`firstOrFail()` throws `ModelNotFoundException`
straight into the chat. Use the shared `Resolves*ForTool` traits, which return the typed model **or** an
`is_array()`-detectable error you return verbatim:

```php
$result = $this->resolveLeadOrError($lead_id);
if (is_array($result)) { return $result; }   // hallucinated id → structured error, chat survives
$lead = $result;
```

Available (all in `Neuron/Tools/Traits/`): `ResolvesLeadForTool`, `ResolvesDealForTool`,
`ResolvesMessageForTool`, `ResolvesOrganizationForTool`, `ResolvesEmployeeForTool`, `ResolvesPlanForTool`,
`ResolvesTaskForTool`, `ResolvesPositionAndDepartmentForTool`, plus `FindsTenantRecordForTool` (generic
model+column lookup).
**When you add a tool that operates on a new entity type, add a `Resolves{Entity}ForTool` trait rather than
resolving inline** — that's how the idiom stays uniform.

**Every resolve trait MUST scope by the tool's tenant, and MUST fail closed when it has none.** A bare
`Model::getById($id)` matches any row on the platform, so an LLM-supplied (prompt-injectable) id becomes
a cross-tenant read — another company's prospect PII returned into a customer chat — or, on a write/send/
delete tool, an action against their record. Resolve via `getByIdFromCompanyApp()` and, when tenant
context is missing, `report()` + return the same structured error rather than falling back to an unscoped
lookup. `ResolvesLeadForTool` pulls in `HasKanvasContext` itself so every host tool is context-bearing;
`ResolvesDealForTool` reads context the host declares (some deal tools promote their own `$app`/`$company`,
and a trait re-declaring them fatals on property composition). Regression coverage:
[`tests/Intelligence/Tools/LeadToolTenantScopingTest.php`](../../../../tests/Intelligence/Tools/LeadToolTenantScopingTest.php).

Context reaches those tools through [`MergesRegisteredTools`](../Traits/MergesRegisteredTools.php), which
runs `fillKanvasContext()` over BOTH the registry-resolved tools and the subclass's hardcoded baseline —
so `new LeadRefTool()` in `SalesAgent::tools()` is tenant-bound without per-line `withContext()` wiring.
A tool constructed outside that path (a test, a one-off script) must call `withContext()` itself.

### Tenant scoping inside tools

- Context tools use `HasKanvasContext` — typed `protected Apps $app; Companies $company; Users $user;` set via
  `->withContext($app, $company, $user)` when the agent builds its tool list. Typed (non-nullable) props
  **fail loud** if a tool runs without context instead of silently falling back to `auth()`/globals.
- Scope every query with `->fromApp($this->app)->fromCompany($this->company)` — including id/email lookups,
  so a foreign id resolves to nothing rather than another tenant's row (`FindsTenantRecordForTool`,
  `ReadUserActivityTool`).
- Mutating tools gate on the **requesting human** via `GuardsAdminForTool::requireAdminOrError()` (the
  tool-layer mirror of `@guardByAdmin`) — not on the agent's own user.
- Honor the audience/memory-scope rule from the top of this file: a customer-facing tool must be
  entity-scoped, never company-wide `read_my_ledger`.

### A lead's open state is its named status, never `Lead::isOpen()`

`Lead::isOpen()` reads the integer `status` column, which nothing in the app writes, so it is `true`
for every lead; marking a lead Lost writes `leads_status_id` only. A tool that reported `is_open` from
it told an agent a lost deal was an active negotiation. In a tool, report `status` as
`$lead->statusName()` and `is_open` as `$lead->hasOpenLeadStatus()`, and filter with the
`hasOpenLeadStatus()` / `hasClosedLeadStatus()` scopes, which honour the company's
`guild_open_leads_status_ids`. `search_leads`, `find_leads_bulk`, `LeadBaseFilter`, `list_stale_leads`
and `get_person` are the references; the follow-up and outreach gates still use `isOpen()` and are a
known, separate decision. Deals carry the same pair (`status_id` named, integer `status` mirrored from
the API): use `Deal::statusName()`, `hasOpenStatus()` and the `havingDealState()` scope the same way.

### Destination safety — the recipient is never a free LLM param

**Any tool that sends something outward (email, SMS, WhatsApp, notification, hand-off) resolves the
destination from the entity or from verified tenant membership — never from a free-typed LLM string.** This
is a hard anti-exfiltration rule, followed by every outbound tool today, not a `SendEmailTool` quirk: a model
that picks the destination can be prompt-injected into mailing a quote to `attacker@evil.com`.

- **Customer-facing sends** (`send_email`, `send_sms`): the model composes the content; the tool resolves the
  address/number from the lead's own `deliverable()` contacts (not opted-out, not hard-bounced). No recipient
  param at all. Say so in the description ("you cannot choose the recipient").
- **A destination MAY be an LLM param only if the tool validates it against a closed set before sending.**
  Two allowed shapes:
  - Verified membership — `send_email_to_user` / `send_slack_direct_message` take `recipient_email` but
    resolve it through `UsersRepository::getUserOfAppByEmail()` + `belongsToCompany()`; a non-member errors out.
  - Allowlist against on-file contacts — `send_email`'s optional `cc` is filtered by `resolveCcRecipients()`
    to addresses that case-insensitively match an existing deliverable contact on the lead's **person, its
    participants, or its organization**; unknown addresses are silently dropped and returned in
    `cc_rejected` so the model can tell the user. Every candidate person is re-checked with
    `fromApp` + `fromCompany` before its addresses count: the lead is tenant-scoped, but participants and
    org members hang off it through raw FKs that connector imports, lead merges and the
    `addLeadParticipant` mutation write, so the allowlist asserts the tenant itself rather than inheriting
    it. No tenant context → no CC at all. This is the reference pattern for "let the agent widen delivery
    without opening an exfiltration hole" — copy it, don't invent a looser one.

If you need a genuinely new "send to X" capability, the recipient must be entity-derived or closed-set-verified.
There is no approved path for a free-text external recipient.

### Param typing

- Optional params use **nullable typed defaults** (`?string $cc = null`) — the Neuron base normalizes a
  missing optional to `null` before `__invoke`, so a non-nullable default would `TypeError`. This matches the
  root `no-non-nullable-defaults` rule; normalize inside the body (`$cc ?? ''`, `trim()`).
- LLM-facing params are **scalar** (STRING/INTEGER/BOOLEAN/NUMBER) by default — a comma-separated STRING you
  split is usually enough (see `send_email`'s `cc`). Domain enums stay **internal** (filtering/dispatch);
  expose their allowed values as free STRING with the options named in the description.
- **Never declare a bare `ToolProperty(type: PropertyType::ARRAY)` or `::OBJECT`.** Gemini rejects the
  *entire* request — every tool in the turn, not just the offender — for both shapes, because
  `ToolProperty::getJsonSchema()` emits neither `items` nor `properties`:
  - `properties[x].items: missing field` for a bare ARRAY (Sentry KANVAS-ECOSYSTEM-606)
  - `properties[x].properties: should be non-empty for OBJECT type` for a bare OBJECT

  A list of records → `ArrayProperty` (always emits `items`) with an `ObjectProperty` item, see
  [`CreateArCreditMemoTool`](Neuron/Tools/Acumatica/CreateArCreditMemoTool.php). A list of scalars →
  `ArrayProperty` with a `ToolProperty` item, see [`CreatePersonTool`](Neuron/Tools/CRM/CreatePersonTool.php)'s
  `tags`. A **free-form key→value map** can't be expressed at all (Gemini has no `additionalProperties`) —
  declare it as STRING carrying a JSON object and decode with
  [`DecodesJsonObjectParam`](Neuron/Tools/Traits/DecodesJsonObjectParam.php), which still accepts a real
  array so nothing breaks if a provider hands back structured input.

  Guarded by [`AgentToolProviderPayloadTest`](../../../../tests/Intelligence/NervousSystem/AgentToolProviderPayloadTest.php),
  which maps **every** Neuron tool through the real Gemini/Anthropic/OpenAI `ToolMapper`s and validates the
  emitted schema. It also carries an opt-in live check (`GEMINI_API_KEY`) that sends the full tool payload to
  Gemini — the only test that proves the real API accepts it.
- Normalize manually in `__invoke`: `trim()` everything, treat empty string as absent, clamp numerics
  (`max(1, min($limit ?? 50, 200))`), re-validate required scalars for blank-after-trim.

### A description that names another tool is a dependency — grant it or don't name it

Tool descriptions are prompt text, and the model obeys them. `AddLeadNoteTool` says "the ID of the lead
(from search_leads or **get_lead_ref**)", so an agent holding that tool but not `LeadRefTool` calls a
name it was never given. Neuron's `findTool()` throws while *parsing the response*, before any tool
runs, so the whole turn dies and the person gets the generic "I ran into a hiccup" apology
(KANVAS-ECOSYSTEM-675 — Polly in Slack).

**When you add a tool to an agent's `tools()`, add the tools its description points at too** — or, if
the pointer is only correct for *some* agents, keep the prose but accept it will be recovered at
runtime rather than satisfied. The names a toolset dangles are easy to check:

```php
// descriptions of the agent's own tools + their properties, matched against its own tool names
preg_match_all('/\b(?:get|search|find|create|update|set|add|send|list|upload|cancel|reschedule|reassign|stop|read)_[a-z0-9_]+\b/', $allDescriptions, $m);
array_diff(array_unique($m[0]), $toolNames);   // → names the model is told about but cannot call
```

The crash itself is now contained: every provider `AgentProviderService` builds uses
[`RecoversUnknownToolCalls`](Neuron/Providers/RecoversUnknownToolCalls.php), which answers an unknown
name with a stand-in tool whose result names the tools the agent *does* have, so the model
self-corrects on the next round instead of the turn dying. **That is a safety net, not a licence** — a
dangling reference still burns a round-trip and pushes the model toward a capability it doesn't have.
`ReceptionistAgent` currently dangles `set_lead_status`, `set_lead_custom_fields`, `find_crm_records`,
`list_people`, `create_organization`; `SalesManagerAgent` dangles `find_leads_bulk`,
`update_lead_description`, `create_message`. Each is a grant-or-reword decision nobody has made yet.

### Run budget — key per-item tools by inputs (`TrackByInputs`)

NeuronAI caps every tool at `getMaxRuns()` (default 10) runs **per turn**, counted per *key*. The default
key is the tool **name**, so *all* calls to one tool share a single budget — 11 distinct calls in a turn
(an 11-row CSV import, an org chart, a batch of messages) throw `ToolRunsExceededException` and abort the
whole turn. This is the recurring Sentry KANVAS-ECOSYSTEM-621 / KANVAS-ECOSYSTEM-64Q — it is **not** an HR
problem, 64Q was `find_customer` resolving names row-by-row out of a user's Excel.

**Any tool the agent can call once-per-item over a list — every entity-scoped `find`/`get`/`create`/`update`/`send`
that acts on a single record identified by its inputs — MUST key its budget by inputs:**

```php
use NeuronAI\Tools\TrackByInputs;

class FindEmployeeTool extends Tool
{
    use TrackByInputs;   // getRunKey() = name . ':' . sha1(json(inputs))
    // ...
}
```

Distinct arguments → distinct key → own budget (bulk over N distinct items works). Identical arguments →
same key → a stuck loop is still capped at 10. No state, no config; it just swaps "count per tool name"
for "count per (tool name + arguments)". Override `getRunKey()` to hash only the fields that matter
(the trait's docblock shows `file_path:offset`) when hashing all inputs is too strict.

**Skip it only for a tool that must keep a hard AGGREGATE ceiling per turn regardless of inputs** — an
expensive/rate-limited external call, a destructive bulk op. There the per-name cap is a deliberate
throttle: keep the default, or set an explicit low `getMaxRuns()` with a one-line reason. `List*`/`Search*`
tools that return many rows in **one** call don't loop, so they don't need it.

A `find_*` tool that returns an empty result set should also say the retry is pointless (`message` on
`count: 0`, see `find_customer` / `find_vendor`). A bare `count: 0` reads as "try again" and the model
re-calls with the same arguments until the budget trips — same crash, different cause.

### A refused or failed write MUST read as a failure, not just carry an `error` key

A tool that returns `['error' => '...']` and nothing else gets narrated as the happy path. Real
incident: `update_template` refused to edit a template the agent did not own, returned a bare `error`,
and the agent told the user it had updated it. Nothing in the payload said "this did not happen", and
`error` on its own is a word the model is free to summarise away.

Every non-success return goes through [`ReportsToolOutcome`](../Tools/Traits/ReportsToolOutcome.php)
(shared namespace `Kanvas\Intelligence\Tools\Traits`, so Neuron and Laravel tools both use it):

```php
return $this->denied(
    sprintf('Template #%d belongs to someone else, so nothing was changed.', $templateId),
    guidance: 'Say explicitly that this template was NOT updated.'
);
```

That yields `success: false` + `outcome: denied` + a `note` carrying
[`ToolOutcomeEnum::guidance()`](../Enums/ToolOutcomeEnum.php) — three independent signals instead of
one. Use `ok()` on the success path so `success: true` and `outcome: ok` are the only shape a model
ever sees for "it happened". `denied()` for a policy/permission refusal, `invalidArgs()` for a fixable
argument, `notFound()` for a miss, `noop()` for "ran fine, changed nothing".

The backstop is the platform-context line in `HasKanvasAgentBehavior::platformContext()` — it rides
every agent turn and says a payload with `success: false` / an `error` / a denied outcome means the
action did NOT happen. Prompt alone is not enough; the payload has to agree with it.

A loud refusal still lands *after* the agent has promised the edit in front of the user, so the read
that surfaces the permission flag has to say what the flag costs: `get_template` / `list_templates`
attach "anything with `owned: false` … update_template WILL be refused on it, never promise the user an
edit to one" whenever an unowned row is in the result. A flag the model can read past is not a warning.

Coverage: `TemplateToolsTest::testUpdateNotOwnedTemplateIsFlaggedAsFailedNotSilent`,
`testGetNotOwnedTemplateWarnsItCannotBeEdited`.

### A read whose answer can't change in a turn should answer the repeat, not re-run it

`TrackByInputs` bounds the waste; it never tells the model *why* it stopped, so a model with nothing else
to try keeps calling until the cap kills the turn. For a **read** whose answer cannot change within one
turn, add [`GuardsRepeatCalls`](Neuron/Tools/Traits/GuardsRepeatCalls.php) as well and wrap the body in
`oncePerTurn($inputs, fn () => …)`: the second identical call gets the first call's own result back,
labelled `outcome: noop` with an instruction to stop. `ReadChannelWindowTool` is the reference
(KANVAS-ECOSYSTEM-6A1 — an agent woken on a task it had no tool for re-read one channel ten times).

**Call `initRepeatGuard()` in the tool's constructor.** NeuronAI hands each call a shallow `clone` of
the registered tool, so the ledger is only shared if it exists before the first clone; build it lazily
and every call gets its own empty one and the guard silently never fires — which is how the trait sat
unused and broken until 6A1. `GuardsRepeatCallsTest` fails if a guarded tool forgets it.

Opt in per tool. A status poll or job check is *supposed* to return something different on the second
identical call — guarding those would hide real progress.

Reference/coverage: [`HumanResourcesAgentToolsTest::testBulkCreateToolsBudgetRunsPerInputsNotPerToolName`](../../../../tests/GraphQL/HumanResources/HumanResourcesAgentToolsTest.php),
plus the per-domain equivalents in [`AccountsReceivableAgentToolsTest`](../../../../tests/Scribe/Intelligence/AccountsReceivableAgentToolsTest.php),
[`AccountsPayableAgentToolsTest`](../../../../tests/Scribe/Intelligence/AccountsPayableAgentToolsTest.php),
[`EventToolsTest`](../../../../tests/Intelligence/Agents/Tools/EventToolsTest.php) and
[`FindProductToolTest`](../../../../tests/Souk/Orders/FindProductToolTest.php).

## Stopping a turn

`aiAgentCancelChat(agent_id, session_id)` sets a Redis flag keyed by the thread
(`AgentTurnCancellationService`, prefix `kanvas:agent-turn:cancel:`). `CancelsOnRequestMiddleware`, on
`ChatNode` and `ToolNode`, reads it before every inference and every tool call and throws
`AgentTurnCancelledException`; a request already at the provider and a tool already running finish
first, so a stop never leaves a write half done. `RunNeuronChatAction` treats the exception as a stop,
not a fault: no fallback prose, no `logTurn`, no `report()`; it calls `discardTurn()` on the handler
(the message row leaves the model's window, the durable run is abandoned) and rethrows.
`ProcessAgentChatTurnJob` broadcasts `agent.chat.cancelled`; the sync `userChat` fails with a
validation error. The flag is cleared in `finally` on every exit, so a stop that lands after the
reply cannot cancel the resend. The key is the thread, and userChat threads by session uuid, which
is why the mutation takes a session: a channel turn (threaded by the entity) is out of its reach.
Frontend contract: `docs/intelligence/agent-chat-cancel-frontend.md`.

## Don't break

- **`AgentChatKernel` is load-bearing for 4 call sites** — `userChat` (GraphQL), channel responders (×6), `WakeAgentForPlanJob`, `AgentReceiverJob`. Any change to its constructor or `execute()` contract ripples through all of them. Test both `userChat` and at least one channel responder end-to-end after touching it.
- **The connector path threads by entity, not by session.** `AgentChatKernel::threadId()` returns the session entity's uuid whenever `sourceChannel` is set, and that is what keeps cross-channel rollup working — a prospect emails Monday, WhatsApps Tuesday, and both land on one thread. Neuron 4 needs a thread on every run, so a new caller cannot skip it: pass `sourceChannel` when there is one and let the kernel pick the id. Never hand a session uuid to a channel agent.
- **Don't pass `app` / `company` to the kernel.** They were removed deliberately. The agent IS the tenant.
- **`persistConversation: false` requires the caller to persist.** If you omit `createMessage()` after the kernel call, the outbound reply never lands in `messages` and the next inbound turn won't see it in history. `BaseAgentChannelReplyAction::createMessage()` is the one true persistence path on the connector side.
- **Don't instantiate agent handlers manually.** Pre-refactor, every connector did `new $this->agent->type->handler()` + `setConfiguration()` by hand. This bypassed the kernel and the four backends got out of sync. Always go through `new AgentChatKernel(...)->execute()`.
- **`EntityRollupMessageStore` rolls up cross-channel by design.** If you find yourself wanting to scope it to a specific channel, re-read its class docblock first — the rollup is the design intent for sales agents.
- **The email outreach anchors the thread subject on the lead.** `AgentReachOutOnChannelAction` persists the agent's email subject to the lead's `title_email_follow_up` custom field (first touch wins). The inbound Mailgun responder and the cron follow-up engine both **read** that field as the outbound subject so every email stays in one thread. Don't repurpose, overwrite, or stop writing `title_email_follow_up` from the outreach without updating both readers — see [FollowUp/CLAUDE.md → "Email follow-ups thread under the original outreach"](../FollowUp/CLAUDE.md).
- **A message store loads rows; it never folds or trims them.** `KanvasHistoryTrimmer` runs inside the
  `ChatHistory` that `resources()` builds. A store that pre-cuts hides rows from the fold and the two
  diverge. New store = extend `KanvasMessageStore`, implement `loadActive()` + `persist()`, pick it in
  `messageStore()`; see the section above.
- **A `privateUserTurn` is not broadcast by the kernel.** Nobody typed it, so whatever drove the turn
  delivers the reply (see `ContinueAgentTurnJob`, `RunScheduledAgentActionJob`). A new private-turn caller
  that expects the kernel to push the reply to the live chat will show nothing.
- **Never let a tool send to an LLM-chosen destination.** A new outbound tool must resolve its recipient from the entity or verify it against a closed set (company membership / on-file contacts) before sending — see "Destination safety" above. A free-text external recipient is an exfiltration hole, not a feature.

## Pointers to deeper context

- [`Actions/Chat/AgentChatKernel.php`](Actions/Chat/AgentChatKernel.php) — the kernel's own class docblock explains the routing logic in 7 lines
- [`Actions/BaseAgentChannelReplyAction.php`](Actions/BaseAgentChannelReplyAction.php) — base class docblock explains the connector-side contract
- [`Neuron/Tools/CRM/SendEmailTool.php`](Neuron/Tools/CRM/SendEmailTool.php) — reference for tool authoring: resolve-or-error, entity-derived recipient, and the allowlist-filtered `cc` (destination-safety) pattern. Traits it leans on live in [`Neuron/Tools/Traits/`](Neuron/Tools/Traits/).
- Product recommendation tool (`Laravel/Tools/Inventory/ProductRecommendationLookupTool.php`) — a thin pass-through to `RecommendProductsAction`; the search backend is resolved per tenant behind it. Pass the shopper's sentence verbatim. Pipeline and configuration: [`src/Domains/Inventory/CLAUDE.md`](../../Inventory/CLAUDE.md).
- Existing end-to-end tests in [`tests/Connectors/Integration/{WaSender,Mailgun,RespondIO,Twilio}/AgentChannelResponderEndToEndTest.php`](../../../../tests/Connectors/Integration/) — copy-paste shape when adding a new connector

## Laravel agents: a tool result must never be empty, and a sub-agent needs steps to answer in

Gemini's Interactions API rejects the whole follow-up request when a `function_result` carries an empty
text block — `400 Request contains an invalid argument`, with no field named (Sentry KANVAS-ECOSYSTEM-6J2).
The old `generateContent` path accepted an empty result, so this only surfaced with laravel/ai 1.x.

The way it happens: laravel-ai budgets **1.5 steps per tool** (`TextGenerationLoop::resolveMaxSteps`), so
a one-tool sub-agent gets two steps; when the model spends both on tool calls the loop stops and the
sub-agent's `->text` is `''`. `CheckLeadDuplicateSubAgent` is told to run two searches, so it ran out
every time and had been handing the parent an empty answer for months before Gemini started refusing it.

- **Every `KanvasAgentAsTool` is passed to the model through `KanvasSubAgentTool`** (`KanvasLaravelAgent::tools()`
  and `KanvasAgentAsTool::tools()` both wrap), which turns an empty answer into a sentence the parent can
  act on. Never hand laravel-ai a bare sub-agent.
- **`KanvasAgentAsTool::maxSteps()` is 8** so a sub-agent can run its calls and still answer. Override per
  sub-agent only with the reason in a docblock.
- **A parent agent's budget is `config['max_steps']` on the Agent record** (`KanvasLaravelAgent::maxSteps()`);
  null keeps the 1.5×tools default.
- **A tool's model-facing name is the snake slug of its `#[AgentTool]` label** (`HasKanvasContext::name()`,
  so `Create Lead` → `create_lead`), which is what every prompt already says. Before that default,
  laravel-ai declared the class basename (`CreateLeadTool`) for 20 tools and the model was told to call
  functions that did not exist. Override `name()` only when the prompts use another word
  (`search_leads`, `get_current_time`). `DynamicSubAgent` slugs the tenant's agent name and falls back to
  `sub_agent_{id}` when the slug cannot open a function name: Gemini accepts only
  `[a-zA-Z_][a-zA-Z0-9_.-]{0,63}` and rejects the whole request otherwise.
  `LaravelToolNamesTest` fails on a class-named or invalid tool.

## `agent_conversations` / `agent_conversation_messages` — the Laravel AI 1.x shape

Both tables are Laravel AI's conversation store (`KanvasConversationStore extends DatabaseConversationStore`,
connection `intelligence`) with Kanvas columns on top. Rules every writer follows:

- **An assistant turn is `steps`, never `tool_calls`/`tool_results` columns** — those are gone. Build the
  value with `ConversationStepsHelper::forRow($role, $content, $toolCalls, $toolResults)`; it folds each
  result onto the call that produced it and normalises the Laravel (`id`/`arguments`), Neuron
  (`callId`/`inputs`) and OpenAI (`function.name`, JSON-string arguments) spellings. Neuron's
  `ToolResultMessage` is a `UserMessage`, so a tool result can sit on a `role = user` row — `forRow()`
  handles that; "user row ⇒ empty steps" is wrong.
- **GraphQL `tool_calls` / `tool_results` are deprecated accessors over `steps`**
  (`AgentConversationMessage::toolCalls()` / `toolResults()`), kept so clients can migrate to `steps`.
- **`status`** is `completed` / `paused` (a tool awaits approval) / `failed` (`meta.error`). Readers that feed
  a model (daily learning) filter on completed; budget and spend readers keep failed turns.
- **`archived_at` and `sequence`** belong to the Neuron working window, not to the transcript: a row stamped
  `archived_at` was compacted into a summary and the model no longer sees it, but every reader of the
  table (GraphQL, daily learning, spend rollup) keeps reading it. Order the active window by `sequence`,
  never by `created_at` alone.
- **Participant = who the conversation belongs to**, a morph: `Users` (staff chat), `People` (public /
  agentic-commerce chat), `Agent` (runtime import, scheduled wake, an agent acting as its own user).
  Resolve it with `KanvasConversationStore::participantFor($session, $user, $agent)`: a human at the keyboard
  owns the conversation whatever record the session points at (a staff session can be keyed to a People
  record; a uuid can carry a stale People session beside the live one); only when the acting user is an AI
  identity (`actsAsAi()` — the agent's dedicated user or the company's AI agent user) does the session's
  Person own it, else the Agent. `user_id` is always the acting user (the
  agent's dedicated user for agent-owned rows); `agent_id` is which agent the conversation is with. An
  anonymous public session opens with no participant and `conversationForSession()` claims it on the
  first turn after the session is keyed to a Person.
- **Usage JSON differs by writer** — laravel ≤0.11 `prompt_tokens`, laravel 1.x `input_tokens` INCLUDING
  cache tokens, Neuron/runtimes `input_tokens` excluding them. Sum it only through
  `ConversationUsageSqlHelper`, which subtracts the cache counts where the 1.x keys are present.
- **Never assume the backfill state of a shared database.** Tests that skip `DatabaseTransactions` on the
  `intelligence` connection leave rows behind; the drop migration runs `agents:backfill-conversation-steps` itself when rows lack `steps` and stops with the count
  if any remain, so the whole upgrade ships in one deploy.
