# Agents — Kanvas Ecosystem API

Loads when work touches anything under `src/Domains/Intelligence/Agents/`. For unrelated work this stays unloaded.

Documents the **current state** of the agent chat flow and the rules for safely changing it.

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

**`sourceChannel` / `sourceMessage` (connector path only):**
- ADK uses them to compute its remote `userId` exactly the way `ADKAgent::chat()` does today (preserves remote session identity — without this, ADK conversations silently fork to a different memory key).
- Neuron uses `sourceChannel !== null` as the signal to **skip `setThreadId`** — channel agents thread by entity (Lead/People), not by per-channel session, so cross-channel rollup works (the design intent of `SalesAssistKanvasMessageHistory`).

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

## Pointers to deeper context

- [`Actions/Chat/AgentChatKernel.php`](Actions/Chat/AgentChatKernel.php) — the kernel's own class docblock explains the routing logic in 7 lines
- [`Actions/BaseAgentChannelReplyAction.php`](Actions/BaseAgentChannelReplyAction.php) — base class docblock explains the connector-side contract
- Existing end-to-end tests in [`tests/Connectors/Integration/{WaSender,Mailgun,RespondIO,Twilio}/AgentChannelResponderEndToEndTest.php`](../../../../tests/Connectors/Integration/) — copy-paste shape when adding a new connector
