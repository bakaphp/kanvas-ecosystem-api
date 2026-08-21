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
| Example | `SystemUserAgent` (implements `ConversesWithUser`) | `SalesAgent` |
| Talks to | company **staff** | a **prospect / customer** |
| Reached via | @mention, channel, DM, task assignment, ownership, follow | inbound connector channel (WhatsApp/email/SMS) on a lead |
| Acts as | itself (its own user) | itself, as a consistent **persona** |
| Conversation memory | per channel/entity | per prospect (`SalesAssistKanvasMessageHistory` rollup — continuity *within* a lead) |
| Cross-entity memory | ✅ full — `read_my_ledger` is company-wide | ❌ none on the customer surface — prospect-isolated |

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

**`sourceChannel` / `sourceMessage` (connector path AND any other rollup caller):**
- ADK uses them to compute its remote `userId` exactly the way `ADKAgent::chat()` does today (preserves remote session identity — without this, ADK conversations silently fork to a different memory key).
- Neuron uses `sourceChannel !== null` as the signal to **skip `setThreadId`** — channel agents thread by entity (Lead/People), not by per-channel session, so cross-channel rollup works (the design intent of `SalesAssistKanvasMessageHistory`).

**Pass `sourceChannel: $session->channel` from any cron/queue-driven caller that needs cross-session history.** Today: connector channel responders (×6) and `FollowUpLeadAction`. Omitting it triggers `setThreadId` which filters the agent's history to a single session uuid — typically zero useful messages for a cron-spawned agent that didn't originate the prior conversation. The bug surfaces as the agent producing literal-template-copy output because the LLM gets no meaningful prior turns. `WakeAgentForPlanJob` and `AgentReceiverJob` haven't been audited for this yet — check whether they need the same fix.

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

    public function __construct()
    {
        parent::__construct(
            name: 'send_email',           // snake_case — the id the LLM calls
            description: '…',             // what it does, WHEN to use it, and hard limits ("you cannot choose the recipient")
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [ new ToolProperty(name: 'lead_id', type: PropertyType::INTEGER, description: '…', required: true), /* … */ ];
    }

    public function __invoke(int $lead_id, string $subject, ?string $cc = null): array { /* … */ }
}
```

The attribute `name:` is the human label; the `parent::__construct(name:)` is the snake_case LLM id.
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
    to addresses that case-insensitively match an existing deliverable contact on the lead's **person or
    organization**; unknown addresses are silently dropped and returned in `cc_rejected` so the model can
    tell the user. This is the reference pattern for "let the agent widen delivery without opening an
    exfiltration hole" — copy it, don't invent a looser one.

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

### Run budget — key per-item tools by inputs (`TrackByInputs`)

NeuronAI caps every tool at `getMaxRuns()` (default 10) runs **per turn**, counted per *key*. The default
key is the tool **name**, so *all* calls to one tool share a single budget — 11 distinct calls in a turn
(an 11-row CSV import, an org chart, a batch of messages) throw `ToolRunsExceededException` and abort the
whole turn. This is the recurring Sentry KANVAS-ECOSYSTEM-621 / KANVAS-ECOSYSTEM-64Q — it is **not** an HR
problem, 64Q was `find_customer` resolving names row-by-row out of a user's Excel.

**Any tool the agent can call once-per-item over a list — every entity-scoped `find`/`get`/`create`/`update`/`send`
that acts on a single record identified by its inputs — MUST key its budget by inputs:**

```php
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\TrackByInputs;

class FindEmployeeTool extends Tool implements HasRunKey
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

Reference/coverage: [`HumanResourcesAgentToolsTest::testBulkCreateToolsBudgetRunsPerInputsNotPerToolName`](../../../../tests/GraphQL/HumanResources/HumanResourcesAgentToolsTest.php),
plus the per-domain equivalents in [`AccountsReceivableAgentToolsTest`](../../../../tests/Scribe/Intelligence/AccountsReceivableAgentToolsTest.php),
[`AccountsPayableAgentToolsTest`](../../../../tests/Scribe/Intelligence/AccountsPayableAgentToolsTest.php),
[`EventToolsTest`](../../../../tests/Intelligence/Agents/Tools/EventToolsTest.php) and
[`FindProductToolTest`](../../../../tests/Souk/Orders/FindProductToolTest.php).

## Don't break

- **`AgentChatKernel` is load-bearing for 4 call sites** — `userChat` (GraphQL), channel responders (×6), `WakeAgentForPlanJob`, `AgentReceiverJob`. Any change to its constructor or `execute()` contract ripples through all of them. Test both `userChat` and at least one channel responder end-to-end after touching it.
- **Don't call `setThreadId` from the connector path.** Activating the per-thread filter on Neuron's history breaks cross-channel rollup — a prospect emails Monday, WhatsApps Tuesday, and the agent loses the prior conversation. The kernel's conditional (`if ($this->sourceChannel === null)`) is what protects this. If you wire a new code path to the kernel, pass `sourceChannel` when there's one.
- **Don't pass `app` / `company` to the kernel.** They were removed deliberately. The agent IS the tenant.
- **`persistConversation: false` requires the caller to persist.** If you omit `createMessage()` after the kernel call, the outbound reply never lands in `messages` and the next inbound turn won't see it in history. `BaseAgentChannelReplyAction::createMessage()` is the one true persistence path on the connector side.
- **Don't instantiate agent handlers manually.** Pre-refactor, every connector did `new $this->agent->type->handler()` + `setConfiguration()` by hand. This bypassed the kernel and the four backends got out of sync. Always go through `new AgentChatKernel(...)->execute()`.
- **`SalesAssistKanvasMessageHistory` rolls up cross-channel by design.** If you find yourself wanting to scope it to a specific channel, re-read its class docblock first — the rollup is the design intent for sales agents.
- **The email outreach anchors the thread subject on the lead.** `AgentReachOutOnChannelAction` persists the agent's email subject to the lead's `title_email_follow_up` custom field (first touch wins). The inbound Mailgun responder and the cron follow-up engine both **read** that field as the outbound subject so every email stays in one thread. Don't repurpose, overwrite, or stop writing `title_email_follow_up` from the outreach without updating both readers — see [FollowUp/CLAUDE.md → "Email follow-ups thread under the original outreach"](../FollowUp/CLAUDE.md).
- **Never let a tool send to an LLM-chosen destination.** A new outbound tool must resolve its recipient from the entity or verify it against a closed set (company membership / on-file contacts) before sending — see "Destination safety" above. A free-text external recipient is an exfiltration hole, not a feature.

## Pointers to deeper context

- [`Actions/Chat/AgentChatKernel.php`](Actions/Chat/AgentChatKernel.php) — the kernel's own class docblock explains the routing logic in 7 lines
- [`Actions/BaseAgentChannelReplyAction.php`](Actions/BaseAgentChannelReplyAction.php) — base class docblock explains the connector-side contract
- [`Neuron/Tools/CRM/SendEmailTool.php`](Neuron/Tools/CRM/SendEmailTool.php) — reference for tool authoring: resolve-or-error, entity-derived recipient, and the allowlist-filtered `cc` (destination-safety) pattern. Traits it leans on live in [`Neuron/Tools/Traits/`](Neuron/Tools/Traits/).
- Product recommendation tool (`Laravel/Tools/Inventory/ProductRecommendationLookupTool.php`) — a thin pass-through to `RecommendProductsAction`; the search backend is resolved per tenant behind it. Pass the shopper's sentence verbatim. Pipeline and configuration: [`src/Domains/Inventory/CLAUDE.md`](../../Inventory/CLAUDE.md).
- Existing end-to-end tests in [`tests/Connectors/Integration/{WaSender,Mailgun,RespondIO,Twilio}/AgentChannelResponderEndToEndTest.php`](../../../../tests/Connectors/Integration/) — copy-paste shape when adding a new connector
