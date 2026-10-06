# Neuron 4 as Kanvas runs it

Loads when work touches `src/Domains/Intelligence/Agents/Neuron/`. The parent
[`Agents/CLAUDE.md`](../CLAUDE.md) covers what an agent is and who it talks to; this file covers the
framework seams underneath, the ones that are easy to get wrong because the v3 shape is still in
muscle memory. Verify framework behaviour against `vendor/neuron-core/neuron-ai/src`, not the docs
site: the two have disagreed before.

## Tenant settings at a glance

Every switch a tenant can flip, so none is forgotten when a tenant asks. App settings are `$app->set()`,
the one company setting is `$company->set()`; unset means the default.

| Setting | Scope | Enum | Default | What it does |
|---|---|---|---|---|
| `agent_chat_async` | app | `AppSettingsEnums::AGENT_CHAT_ASYNC` | off | every `userChat` turn runs on the `agent-chat` queue and answers `pending` |
| `agent_memory_enabled` | app | `KnowledgeConfigurationEnum::AGENT_MEMORY_ENABLED` | **on** (needs Typesense creds) | `0` stops writing and recalling company memory; prune keeps running |
| `agent_memory_ingest_min_chars` | app | `…::AGENT_MEMORY_INGEST_MIN_CHARS` | 80 | a turn shorter than this is not remembered |
| `agent_memory_result_limit` | app | `…::AGENT_MEMORY_RESULT_LIMIT` | 4 | memory hits injected per turn, on top of the knowledge limit |
| `agent_memory_retention_days` | app | `…::AGENT_MEMORY_RETENTION_DAYS` | 365 | `agents:prune-memory` drops conversation + ledger memory older than this; saved memories never |
| `neuron_lead_rag_enabled` + `_collection` / `_result_limit` / `_min_score` / embedding keys | app | `KnowledgeConfigurationEnum::ENABLED`… | off | uploaded-knowledge retrieval (documents), opt-in |
| `agent_durable_runs_enabled` | app | `AgentRunConfigurationEnum::DURABLE_RUNS` | **on** | `0` turns off Redis-persisted runs and resume-on-redelivery |
| `agent_max_history_tokens` | app | `AgentRunConfigurationEnum::MAX_HISTORY_TOKENS` | platform `AGENT_MAX_HISTORY_TOKENS` (50K) | a wider history window for one app, still under the model ceiling |
| `agent_summary_llm_model` (+ `agent_summary_llm_provider`) | app | `AgentRunConfigurationEnum::SUMMARY_MODEL` / `SUMMARY_PROVIDER` | none (agent's own provider) | a cheaper model writes history summaries, with the app's key |
| `agent_summary_llm_config_id` | **company** | `AgentRunConfigurationEnum::SUMMARY_LLM_CONFIG` | none | one of the company's LLM configs writes summaries; wins over the two app settings |
| `agent_tool_search_enabled` | app | `AgentRunConfigurationEnum::TOOL_SEARCH` | **on** | `0` sends every granted tool and MCP toolkit on every round again; see "Tool search" below |

Add a row here whenever a new `*ConfigurationEnum` case lands.

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
- `ToolkitInterface` grew `add(ToolInterface ...$tools)` in 4.1.0. `RemoteMcpToolkit` implements the
  interface by hand (composition, see its docblock), so every interface addition lands there too or the
  class fatals at load; PHPStan level 0 is what catches it.

## Tool search: granted tools are found, not carried

Carrying every tool schema on every round costs ~35K tokens on an admin agent, 29K of them the Kernel
MCP toolkit it never calls. `resources()` splits the resolved tools in two
(`HasKanvasAgentBehavior::splitForToolSearch()`):

- **Always on**: the identity set (`ALWAYS_ON_TOOLS`: time, memory, ledger, `who_is_user`, links,
  artifacts) and whatever the handler class hardcodes in `tools()`. These are the ones the prompt
  refers to by name, so they must be callable without a search.
- **Pool**: everything `MergesRegisteredTools` resolved from the capability registry (catalog grants
  and MCP toolkits, expanded to their tools; `registryToolNames` records them). Neuron's
  `ToolSearchMiddleware`, returned from `globalMiddleware()`, adds `tool_search` to every node and
  re-adds whatever this turn's `tool_search` results matched, so a found tool is callable on the next
  round and gone on the next user message.

The split only happens when the pool has at least `TOOL_SEARCH_MIN_POOL` (6) tools; below that the
search tool and its prompt block cost as much as the schemas they would hide. Per app:
`agent_tool_search_enabled = 0` disables it. Agent 12323 measured: 52 tools became 13 always-on
(2.8K tokens) plus 39 pooled (32K); the same turn went 7.4 s to 4.0 s.

The middleware is `KanvasToolSearchMiddleware`, Neuron's with the search tool swapped for
`KanvasToolSearchTool`. The stock tool answers a miss with "No tools found matching 'x'", which the
model reads as "try another word": a request for a WhatsApp message on an agent with no such tool ran
nine searches and then re-did the previous turn's action. The Kanvas tool answers a miss with the full,
sorted list of pooled names (an MCP toolkit as one line with its count, because 29 `kernel__*` names
read as a menu and the model probed them as a workaround), says the list is complete and tells the model
to answer the user instead of probing, and after `MAX_SEARCHES_PER_TURN` (4) it
stops searching and answers the same way. The count is read off the turn's `ToolResultMessage`s by
distinct call id, because the registry keeps the first `tool_search` it is given (the middleware
removes and re-adds it on every node) and a durable-run resume rebuilds the middleware.

A pooled tool called by name without a search is a correct call, not a hallucination: the miss reply
names every pooled tool, so the model will do exactly that. The middleware loads it on the `ToolNode`
from the `ToolCallEvent`. The provider has already registered an `UnknownToolStub` for the name while
parsing the response, so `RecoversUnknownToolCalls::setTools()` drops a stub whose name a real tool now
carries; Gemini rejects "Duplicate function declaration" otherwise (seen live 2026-10-05). The error
handler's "not loaded on this round" answer is the fallback for a name in `poolToolNames` that still
misses. And the search is lexical
(`ToolSearchTool::search()`: name and description words, levenshtein), so a catalog tool with a vague
description is unfindable; the class `$description` is what the model searches, not the catalog row.
`ToolSearchOnAgentTest` covers the split, the next-round call, the blind call and the switch;
`ToolSearchMissTest` the miss reply, the cap and the per-turn reset.

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
contextWindow() → model ceiling, capped by AGENT_MAX_HISTORY_TOKENS (or the app's agent_max_history_tokens)
```

`KanvasChatHistory` trims on load as well as on add: since 4.1 the chat node sends `getMessages()`
plus the inbound turn and adds to the history afterwards, so an add-only trim runs after the first
request of every turn (KANVAS-ECOSYSTEM-6HP, a 1,962-email rollup sent whole).

The window is a **history** budget and the trimmer measures the history by its content
(`KanvasHistoryTrimmer::getCheckpoints()` returns none). The stock trimmer reads the provider's prompt
count off the last assistant turn, which includes instructions and every tool schema: an agent with 35K
tokens of tool schemas measured over a 50K window after one turn, lost its previous turn on every turn
and summarized on every tool round (2026-10-04). `calculateTotalUsage()` therefore also reports history
tokens, not request tokens.

The fold is capped at the window: a user turn the provider refused stays as an unanswered row, the
next turn folds onto it, and the cut never touches the live turn, so a project heartbeat whose prompt is
a whole context bundle grew by one bundle per failed wake until Gemini refused it
(KANVAS-ECOSYSTEM-6JD). Past the cap the newer turn replaces the older one and the orphan row is archived.

Stores extend `Stores/KanvasMessageStore`: `loadActive()` reads rows, `persist()` writes one,
`append()` dedupes by message id, `clear()` archives the thread, and the count-based `archive()` is a
final no-op (see "Archive and summarize" below). Nothing is ever deleted: Social messages and
`agent_conversation_messages` are business records, not a cache.
A row id is the message's own UUIDv7 with Neuron's `msg_` prefix stripped, and the store calls
`setId()` back so a reply carries its row id without a second query.

| Store | Agents |
|---|---|
| `ConversationMessageStore` | `KanvasGenericNeuronAgent`, userChat, scheduled actions, project manager |
| `EntityRollupMessageStore` | customer-facing agents on a Lead / People, `FollowUpAgent` |
| `ChannelMessageStore` | `SystemUserAgent` answering in a Social channel |

## Archive and summarize: by identity, never by count

`ChatHistory::addMessage()` archives `count(before) − count(after)` oldest rows after a trim. Our
trimmer also folds consecutive same-role turns, which shortens the list without dropping a turn, so
that count would stamp the wrong rows. `KanvasChatHistory` (what `resources()` builds) archives by
identity instead: the fold records the ids it absorbed under `KanvasHistoryTrimmer::FOLDED_IDS`, and
the rows archived are exactly the loaded ids that neither survived nor live on inside a survivor.
`KanvasMessageStore::archive(count)` therefore stays a no-op everywhere; `archiveMessages(ids)` is the
real hook, and only `ConversationMessageStore` implements it (`archived_at`).

`KanvasSummarization` runs on `ChatNode` for agents whose store is the conversation store, at
`contextWindow() * 0.8` keeping 8 messages. Three Kanvas rules on top of Neuron's middleware:

- `flushAll()` archives the thread, never deletes it: the GraphQL transcript reads every row, the model
  reads `loadActive()`. A kept-tail message re-appended after the flush has a row already, so
  `persist()` un-archives it instead of inserting a duplicate, and gives it a fresh `sequence`: the
  active window is ordered by that column, because a kept turn must follow the summary and neither
  `created_at` (seconds) nor the uuid7 id (construction time) can say so. Legacy rows carry null and
  sort first, where they belong.
- The summary row carries `meta.__meta.summary = true` and `social_message_id`; the same text is written
  to Social as a private `agent_summary` message (`AgentMessageTypeEnum`, distinct from the lead
  `summary` verb) on the session channel and entity, with the covered id range, `archived_count` and the
  token counts before and after. That write never fails the turn.
- `EntityRollupMessageStore` lists `agent_summary` among its internal verbs and `ChannelMessageStore`
  skips it, so a customer-facing agent never replays the agent's own compaction note.

The summary is written by the agent's own provider unless a cheaper one is configured: a COMPANY setting
`agent_summary_llm_config_id` naming one of the admin's LLM configs (its key and base URI travel with it) wins,
then the app settings `agent_summary_llm_model` + optional `agent_summary_llm_provider`
(`AgentProviderService::summaryProvider()`); the Social note records the model that wrote it.
The hard cut never drops the summary row: `KanvasHistoryTrimmer::trim()` puts it back ahead of the kept
tail (folded into the first kept turn) when a cut took it, which needs the `summary` flag on reloaded rows,
so `ConversationMessageStore::loadActive()` restores `meta.__meta` onto each message. Thresholds are the `summarizationMaxTokens()` / `summarizationMessagesToKeep()` hooks; a test lowers
them (`SummarizingNeuronAgentStub`) instead of generating 40K tokens of history.

## Company memory: the tenant pair is the key, the thread is a filter

v4's own semantic memory is keyed by thread id, the individual-assistant design. Here memory belongs
to the company and the record: `ConversationMemoryNode` replaces `AgentEndNode` on agents whose
`remembersForCompany()` is true (`SystemUserAgent` and subclasses, and every customer-facing agent on
`HasProspectIsolatedHistory`; never a `privateUserTurn`) and writes the last human message plus
the final reply as one `conversation` document into the shared knowledge collection, through
`KnowledgeVectorStore` (the Neuron `VectorStoreInterface` over `TypesenseKnowledgeStore`). The
document is keyed by the reply id so a replay upserts; `apps_id`, `companies_id`, `agent_id`,
`users_id` and the subject entity ride as metadata. A failed embedding never fails the turn.

Reading it back is `CompanyMemoryRetrieval`, composed with `KnowledgeRetrieval` in
`HasKnowledgeRag::retrieval()`. It pins `apps_id`/`companies_id` itself AND `retrievalScope()` pins
them again on every retrieval of the agent, so a per-run filter can narrow but never widen
(`CompanyMemoryRetrievalTest`). **Who recalls what is decided by audience, not by the switch**
(`recallMemoryScope()`): a `ConversesWithCustomer` agent gets `recordMemoryScope()`, the record it is on
(and a Lead's person, which every channel session of that prospect is keyed to), so it remembers and
improves with its customer and never sees another prospect (`CustomerFacingAgentMemoryTest`); no record
in scope on a customer surface means no recall at all. An internal agent recalls the raw conversations
**its own human** had with any agent, plus everything the company kept on purpose: saved memories
(`remember`) and ledger outcomes. What Jenn told agent B is hers; a fact she asked an agent to remember,
or a plan it approved, is the company's (`InternalAgentMemoryPrivacyTest`, Max's decision 2026-10-04).
An internal turn with no human (a cron) recalls only the shared kinds. Recall also
leaves out the turns the model is already reading: `CompanyMemoryRetrieval` takes the current thread and
`KanvasMessageStore::activeWindowStartedAt()`, and skips that thread's documents written since then,
while older turns of the same thread (archived by a trim or a summary) stay recallable. Neuron's own
`SemanticMemoryRetrieval` is keyed by a list of thread ids and is not used here: it cannot express the
audience scope above, and its `ConversationIngestionNode` writes no tenant metadata. Hits reach the model labelled `[Earlier conversation, 2026-09-28]`,
`[Saved memory, …]`, `[Ledger, …]` so it knows provenance and age. Uploaded knowledge stays the
knowledge retrieval's job: memory only reads `source_type in (conversation, memory, ledger)`. The
reverse holds too: `TypesenseKnowledgeStore::search()` reads through `KnowledgeScope::knowledgeFilter()`,
which leaves those kinds out, because an organization-wide knowledge read would otherwise return every
user's turns in the company with no audience scope at all. A turn is written through
`ConversationMemoryNode::transcript()`, live and from the reindex sweep alike, which drops a `NO_UPDATE`
reply and strips `AgentTurnResponse::noOpGuidance()` from the human side: recalled with the guidance in
it, a stored agent-to-agent turn told a later turn to answer a human with `NO_UPDATE`.

Per-app switches (`KnowledgeConfigurationEnum`): `agent_memory_enabled` (**on by default**, a tenant
sets `0` to opt out; it costs one embedding per qualifying turn on the app's key, and an app with no
Typesense credentials is off without a setting because it has nowhere to write — `KnowledgeComponents::memoryEnabled()`
is the one predicate every reader uses), `agent_memory_ingest_min_chars` (80), `agent_memory_result_limit`
(4, on top of the knowledge limit), `agent_memory_retention_days` (365). Because memory is on everywhere,
a Typesense or embedding outage must never fail a turn: `CompanyMemoryRetrieval` answers without recall
and `ConversationMemoryNode` ends the turn without the write, both logged. Uploaded knowledge
(`neuron_lead_rag_enabled`, `KnowledgeConfigurationEnum::ENABLED`) stays opt-in. Boolean app settings
read through `HashTableTrait::getBool($key, default:)`.

Two more kinds reach the same store through the ledger (`LedgerKnowledgeSource`, registered in
`KnowledgeSourceRegistry`, dispatched from `AppendEventAction::maybeIndexMemory()` through the queued
`IndexKnowledgeJob`): a `remember` tool call (`agent.knowledge.saved`) as `source_type = memory`, kept
as the agent wrote it and never pruned, and an allowlisted outcome (the list in
`LedgerKnowledgeSource::wants()`, which names only event types the ledger really emits) as
`source_type = ledger`, one line of what happened, pruned like a conversation. Each `KnowledgeSource`
owns its `find()` and `isEnabledFor()`: the ledger table has no `is_deleted` and memory has its own
switch, so the registry never queries models itself. On-demand recall is the `search_memory` tool
(`RemembersForCompany::memoryTools()`, universal on every agent whose memory is active and whose
audience scope is non-null): same store, same `CompanyMemoryRetrieval::scopeFor()` filter, plus a
`since`/`until` window on `created_at` and a limit up to 25, for the questions the automatic 4-hit
recall cannot answer ("what did we agree in September"). `agents:prune-memory` (03:30 daily) applies the retention; `agents:reindex-memory
--since --app` writes a window of past turns and outcomes for a first rollout or after an embedding
outage, keyed like the live path so a re-run upserts. Adding a field to the collection (`TypesenseKnowledgeStore::LATER_FIELDS`)
is done by the first write after the deploy, and Typesense accepts one schema alter at a time: every
other worker gets a 422 that `TypesenseKnowledgeStore` turns into `CollectionUpdateInProgressException`,
which `IndexKnowledgeJob` releases for 90 s and `ConversationMemoryNode` logs without reporting
(KANVAS-ECOSYSTEM-6J9, 314 failed index jobs in two minutes). A worker checks the schema once per
process, so a schema change needs the worker restart the deploy already does. A test swaps the store and embeddings through `companyMemoryStore()` /
`companyMemoryEmbeddings()` (`RememberingSystemUserAgentStub`) instead of needing Typesense; the live
round trip is `KnowledgeVectorStoreTest`, skipped when no cluster is reachable.

## Durable runs: resume, never restart

`SystemUserAgent` and subclasses opt in (`durableRuns()`); the per-app `agent_durable_runs_enabled`
(`AgentRunConfigurationEnum`) is **on by default** and only ever turns it off (`0`). Active, `persistence()` keeps the run in Redis (`RedisPersistence`,
prefix `kanvas:agent-run:`, phpredis client required; any other client falls back to in-memory with a
warning). Every committed step, each tool result included, survives the worker.

The engine's rules decide what happens next, so know them: a plain `chat()` on a thread with a dead run
(status failed, or running with its 600s lease expired) **sweeps it and starts over**, tools included;
`chat()` on a thread whose lease is still fresh throws `RunInFlightException`, because another worker is
probably alive on it. Recovery is explicit: `recoverInterruptedRun($inbound)` inspects the thread and,
only when the dead run was started by that same message, continues it by run id
(`ExecutionRequest::start(..., runId, recoverFailed: true)`), replaying memoized tool results instead of
running them. `RunNeuronChatAction` tries that before `chat()`, so a redelivered job never repeats a
write (`DurableAgentRunTest`). A different message is a new turn.

A second inbound on a thread whose run still holds its lease, two emails minutes apart on one AP
mailbox, is refused with `RunInFlightException`. `RunNeuronChatAction::chatOnceTheThreadSettles()`
waits for it, polling every 5 s for up to 150 s, and only then lets the exception through
(KANVAS-ECOSYSTEM-6JE: 17 unanswered emails in an hour, each later redelivered by Mailgun's retry).

Neuron tool approvals leave a run Suspended; nothing here uses them, and a suspended durable run would
refuse new chats on its thread until `abandon()`. Renaming a tool strands any run suspended on it.

## Tool-result budget: swap the tool, don't skip the call

v4's `ToolNode` skips a call only when an approval policy rejected it, so there is no hook that says
"don't run this one". `BoundToolResultsMiddleware::beforeAgentNode` therefore replaces the tool in
`$resources->tools` with a `RefusedToolStub` that answers `NOT_EXECUTED`; the node runs the stub and
the model is told, truthfully, that the call did not happen. `afterAgentNode` bounds the trailing
`ToolResultMessage` in the history by character budget. The middleware is stateless on purpose: it
derives "spent this turn" from the history, so a fresh instance per node sees the same budget.
Registered once on `ToolNode::class`; `ParallelToolNode` extends it and matches by `instanceof`.

## Two provider overrides v4 still needs

- `KanvasGemini::createToolCallMessage()` re-indexes the function-call parts: upstream collects them
  with a key-preserving `array_filter` and reads the thought signature off key 0, so a thinking model's
  thought part ahead of the call loses the signature and Gemini rejects the next request.
- `KanvasRouterProvider` (what `NeuronResponderProviderFallback` builds) keeps the agent's
  `SystemMessage` as given; `neuron-core/router` 2.1.0 stores it in a `?string` and throws a TypeError
  on the first turn of any tenant with a fallback LLM config. Drop it when the package fixes the type.

## Unknown tool calls have two halves

A model that calls a tool it was never given hits two different places, and both must answer or the
turn dies with a `ProviderException` / `ToolException`:

1. **Provider parse** — `RecoversUnknownToolCalls::findTool()` on every Kanvas provider returns an
   `UnknownToolStub` so the response loads and the stub stays declared on later rounds (and is never
   offered back to the model as an available tool).
2. **Registry miss** — `ToolNode` resolves against the agent's `ToolRegistry`, where no stub lives, and
   throws. `HasKanvasAgentBehavior::resolveToolErrorHandler()` answers that `ToolException` with the same
   `UnknownToolStub::response()` payload so the model can self-correct.

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
