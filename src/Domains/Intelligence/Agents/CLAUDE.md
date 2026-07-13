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

    // 4. Persist outbound exactly once via base class
    $messageResponse = $this->createMessage($responseText, $to, $this->message, $this->channel);

    // 5. Send via connector client only if not locked (support-mode + human-takeover)
    if (! $messageResponse->is_locked) {
        /* connector-specific outbound call */
    }

    return ['response' => $responseText, /* ... */];
}
```

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

## Don't break

- **`AgentChatKernel` is load-bearing for 4 call sites** — `userChat` (GraphQL), channel responders (×6), `WakeAgentForPlanJob`, `AgentReceiverJob`. Any change to its constructor or `execute()` contract ripples through all of them. Test both `userChat` and at least one channel responder end-to-end after touching it.
- **Don't call `setThreadId` from the connector path.** Activating the per-thread filter on Neuron's history breaks cross-channel rollup — a prospect emails Monday, WhatsApps Tuesday, and the agent loses the prior conversation. The kernel's conditional (`if ($this->sourceChannel === null)`) is what protects this. If you wire a new code path to the kernel, pass `sourceChannel` when there's one.
- **Don't pass `app` / `company` to the kernel.** They were removed deliberately. The agent IS the tenant.
- **`persistConversation: false` requires the caller to persist.** If you omit `createMessage()` after the kernel call, the outbound reply never lands in `messages` and the next inbound turn won't see it in history. `BaseAgentChannelReplyAction::createMessage()` is the one true persistence path on the connector side.
- **Don't instantiate agent handlers manually.** Pre-refactor, every connector did `new $this->agent->type->handler()` + `setConfiguration()` by hand. This bypassed the kernel and the four backends got out of sync. Always go through `new AgentChatKernel(...)->execute()`.
- **`SalesAssistKanvasMessageHistory` rolls up cross-channel by design.** If you find yourself wanting to scope it to a specific channel, re-read its class docblock first — the rollup is the design intent for sales agents.
- **The email outreach anchors the thread subject on the lead.** `AgentReachOutOnChannelAction` persists the agent's email subject to the lead's `title_email_follow_up` custom field (first touch wins). The inbound Mailgun responder and the cron follow-up engine both **read** that field as the outbound subject so every email stays in one thread. Don't repurpose, overwrite, or stop writing `title_email_follow_up` from the outreach without updating both readers — see [FollowUp/CLAUDE.md → "Email follow-ups thread under the original outreach"](../FollowUp/CLAUDE.md).

## Pointers to deeper context

- [`Actions/Chat/AgentChatKernel.php`](Actions/Chat/AgentChatKernel.php) — the kernel's own class docblock explains the routing logic in 7 lines
- [`Actions/BaseAgentChannelReplyAction.php`](Actions/BaseAgentChannelReplyAction.php) — base class docblock explains the connector-side contract
- Product recommendation tools (`Laravel/Tools/Inventory/{ProductRecommendationLookupTool,TypesenseProductRecommendationTool}.php`) — SQL/Algolia hybrid vs Typesense NL search, identical `{product, variants[]}` output shape. How the search engine is resolved, configured per app, and indexed (incl. Typesense Natural Language Search) is documented in [`src/Domains/Inventory/CLAUDE.md`](../../Inventory/CLAUDE.md).
- Existing end-to-end tests in [`tests/Connectors/Integration/{WaSender,Mailgun,RespondIO,Twilio}/AgentChannelResponderEndToEndTest.php`](../../../../tests/Connectors/Integration/) — copy-paste shape when adding a new connector
