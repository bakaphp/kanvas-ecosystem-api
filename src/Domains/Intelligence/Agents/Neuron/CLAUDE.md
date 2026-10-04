# Neuron 4 as Kanvas runs it

Loads when work touches `src/Domains/Intelligence/Agents/Neuron/`. The parent
[`Agents/CLAUDE.md`](../CLAUDE.md) covers what an agent is and who it talks to; this file covers the
framework seams underneath, the ones that are easy to get wrong because the v3 shape is still in
muscle memory. Verify framework behaviour against `vendor/neuron-core/neuron-ai/src`, not the docs
site: the two have disagreed before.

## Tools: identity is a property default, not a constructor

```php
class SendEmailTool extends Tool
{
    use TrackByInputs;

    protected string $name = 'send_email';
    protected string $description = '…';

    public function __invoke(int $lead_id, string $subject): array { … }
}
```

- `Tool` is abstract with no constructor in v4, so there is nothing to `parent::__construct`. A tool
  that needs context takes it through `HasKanvasContext` / `withContext()`, never through `__construct`
  arguments that shadow name or description.
- **`$description` stays a literal.** `AgentToolDiscoveryService` reads it with reflection
  (`getDefaultProperties()`), so a description assembled at runtime is simply missing from the
  `nervous_system_tools` catalog, with no error.
- `HasRunKey` no longer exists; `use TrackByInputs;` alone keys the per-turn run budget by arguments.
  The rule for when a tool needs it is in the parent file.
- `ToolNode::resolveTool()` still hands every call a shallow `clone` of the registered tool. That is
  what `GuardsRepeatCalls` relies on (ledger built in the constructor, shared by the clones). If a
  future release stops cloning, the guard starts firing across turns.
- A tool answers with a string, an array, or a `ToolOutput`. MCP tools answer `ToolOutput`; cast with
  `(string)` where a caller wants text, never `getResult()` straight into `json_decode`.

## Thread id: mandatory, and the kernel chooses it

Every run needs `setThreadId()` before `chat()`; v4 throws without one. The id is never chosen in an
agent or a connector: `AgentChatKernel::threadId()` picks the session **entity's uuid** when the turn
comes from a channel (so a prospect's email, WhatsApp and follow-up runs share one thread) and the
session uuid otherwise. `HasKanvasAgentBehavior::sessionThreadId()` returns the thread only when it is
a session uuid, which is how `EntityRollupMessageStore` knows whether to filter to one session. The
`KanvasAgentCommand` CLI and `NeuronAgentFactory` take the thread explicitly for the same reason, and
`RunNeuronChatAction` binds the session uuid itself when a caller that skips the kernel
(`RespondToMentionJob`, `DraftCustomerUpdateAction`) hands it an unbound handler.

## History: `resources()` is the only seam

`Agent::getChatHistory()` is final in v4. The override point is `resources()`, and
`HasKanvasAgentBehavior::resources()` is the one place a `ChatHistory` is built:

```
messageStore()  → which rows            (override per agent)
KanvasHistoryTrimmer → fold, then cut   (never swapped)
contextWindow() → model ceiling, capped by AGENT_MAX_HISTORY_TOKENS
```

Stores extend `Stores/KanvasMessageStore`: `loadActive()` reads rows, `persist()` writes one,
`append()` dedupes by message id, and `archive()` / `clear()` are deliberate no-ops because Kanvas
owns retention (Social messages and `agent_conversation_messages` are business records, not a cache).
A row id is the message's own UUIDv7 with Neuron's `msg_` prefix stripped, and the store calls
`setId()` back so a reply carries its row id without a second query.

| Store | Agents |
|---|---|
| `ConversationMessageStore` | `KanvasGenericNeuronAgent`, userChat, scheduled actions, project manager |
| `EntityRollupMessageStore` | customer-facing agents on a Lead / People, `FollowUpAgent` |
| `ChannelMessageStore` | `SystemUserAgent` answering in a Social channel |

## Tool-result budget: swap the tool, don't skip the call

v4's `ToolNode` skips a call only when an approval policy rejected it, so there is no hook that says
"don't run this one". `BoundToolResultsMiddleware::beforeAgentNode` therefore replaces the tool in
`$resources->tools` with a `RefusedToolStub` that answers `NOT_EXECUTED`; the node runs the stub and
the model is told, truthfully, that the call did not happen. `afterAgentNode` bounds the trailing
`ToolResultMessage` in the history by character budget. The middleware is stateless on purpose: it
derives "spent this turn" from the history, so a fresh instance per node sees the same budget.
Registered once on `ToolNode::class`; `ParallelToolNode` extends it and matches by `instanceof`.

## Unknown tool calls have two halves

A model that calls a tool it was never given hits two different places, and both must answer or the
turn dies with a `ProviderException` / `ToolException`:

1. **Provider parse** — `RecoversUnknownToolCalls::findTool()` on `KanvasGemini` returns an
   `UnknownToolStub` so the response loads and the stub stays declared on later rounds.
2. **Registry miss** — `ToolNode` resolves against the agent's `ToolRegistry`, where no stub lives, and
   throws. `HasKanvasAgentBehavior::resolveToolErrorHandler()` answers that `ToolException` with the same
   `UnknownToolStub::feedback()` text so the model can self-correct.

Coverage: `tests/Intelligence/Agents/Providers/UnknownToolCallRecoveryTest.php` has one test per half.

## Providers

- `chat()` returns `ProviderResponse`; the message is `->message()`. `KanvasGemini::processChatResult()`
  still runs the blocked-response and malformed-call checks on it.
- `Agent::chat()` returns `AgentState`, not a message. `getMessage()` can be `null` on an empty reply;
  `RunNeuronChatAction` substitutes an empty `AssistantMessage` rather than failing the turn.
- **Anthropic `inputTokens` now include cached tokens.** `ModelPricingCalculator::costFor()` subtracts
  `cacheReadTokens` before pricing the uncached input; charging both was double-billing every cached
  turn.

## MCP

`GuardedHttpMcpTransport` implements `setProtocolVersion()` and sends `MCP-Protocol-Version`; the
value resets on disconnect and on unserialize because the server negotiates it per connection.
`CachedMcpConnector::invokeTool()` returns `ToolOutput`, including the budget-exhausted and async
hand-off answers.

## Legacy lineage

The `Types/{BaseAgent,CRMAgent,InventoryAgent,SocialCreatorAgent,SocialEngagementAgent}` lineage is
gone; `2026_10_04_000000_repoint_legacy_neuron_agent_types` points their `agent_types.handler` rows at
`KanvasGenericNeuronAgent`. `OpenClawAgentHandler`, `ADKAgent` and `ClaudeManagedAgentHandler` in the
same folder are runtime handlers, not Neuron agents, and stay.

## Test doubles

`tests/Stubs/Intelligence/`: `FakeNeuronProvider` (fixed reply), `ScriptedToolCallNeuronProvider`
(one tool call, then a reply; records the second round's messages), `RepeatingToolCallNeuronProvider`,
`CapturingNeuronAgentStub`, and `Tools/CallbackTool` for a closure-backed tool with real properties.
Run `setThreadId()` on any stub agent before `chat()`.
