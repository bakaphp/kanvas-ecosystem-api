# Agents — Neuron AI

## Location

`src/Domains/Intelligence/Agents/Neuron/`

## Stack

- Framework: **Neuron AI** (`neuron-core/neuron-ai`)
- Base class: `KanvasNeuronAgent` (extends `NeuronAI\RAG\RAG`)
- Entry point: `ProcessAgentChatAction`
- Provider resolution: `AgentProviderFactory` (dynamic from DB, never hardcoded)

---

## DB Structure

```
agent_types
  provider = "neuron"

agents
  handler        → Full PHP class namespace of the agent
  agent_model_id → agent_models.model_name       = AI model string
                 → agent_models.agent_provider_id
                      → agent_providers.handler  = PHP provider class
                      → agent_providers.config   = { api_key, ... }
  role (JSON)    → { background, steps, output } = SystemPrompt
  config (JSON)  → { temperature, max_tokens, context_window, timeout }
```

### `agents.role` JSON shape

```json
{
    "background": ["Who the agent is"],
    "steps": ["What steps to follow"],
    "output": ["How to respond"]
}
```

---

## Directory Structure

```
src/Domains/Intelligence/Agents/
├── AgentProviderFactory.php         ← shared across all frameworks
└── Neuron/
    ├── KanvasNeuronAgent.php        ← base class
    ├── Revenue/
    │   ├── LeadQualificationAgent.php
    │   ├── DealDeskAgent.php
    │   └── CollectionsAgent.php
    ├── Growth/
    │   ├── OutreachAgent.php
    │   └── FollowUpAgent.php
    ├── CustomerSuccess/
    │   ├── OnboardingAgent.php
    │   ├── SupportTriageAgent.php
    │   └── ChurnPreventionAgent.php
    ├── Tools/
    │   ├── CRM/
    │   │   ├── GetLeadTool.php
    │   │   ├── GetPeopleProfileTool.php
    │   │   ├── UpdateLeadStageTool.php
    │   │   ├── SearchLeadsTool.php
    │   │   ├── AssignLeadOwnerTool.php
    │   │   ├── CreateEngagementTool.php
    │   │   └── CRMToolkit.php
    │   ├── Inventory/
    │   │   ├── GetProductTool.php
    │   │   ├── GetInventoryTool.php
    │   │   ├── CheckStockTool.php
    │   │   └── InventoryToolkit.php
    │   ├── Commerce/
    │   │   └── GetOrderStatusTool.php
    │   ├── Social/
    │   │   ├── SendMessageTool.php
    │   │   └── GetConversationTool.php
    │   ├── ActionEngine/
    │   │   ├── SendActionTool.php
    │   │   └── GetTaskStatusTool.php
    │   └── System/
    │       ├── GetCurrentTimeTool.php
    │       ├── HandOffToHumanTool.php
    │       ├── ScheduleFollowUpTool.php
    │       └── SearchKnowledgeBaseTool.php
    └── Workflows/
        ├── LeadQualificationWorkflow.php
        ├── DealDeskWorkflow.php
        ├── SupportEscalationWorkflow.php
        └── Nodes/
            ├── ScoreLeadNode.php
            ├── QualifyLeadNode.php
            ├── HumanReviewNode.php
            └── AssignPipelineNode.php
```

---

## KanvasNeuronAgent Base Class

`src/Domains/Intelligence/Agents/Neuron/KanvasNeuronAgent.php`

Replaces the old `BaseAgent`. Key responsibilities:

- `setConfiguration(Agent $agent, ?Model $entity, ?string $externalReferenceId)` — injects context
- `provider()` — resolves dynamically via `AgentProviderFactory`, never hardcoded
- `instructions()` — loads from `agents.role` JSON (background/steps/output) + optional `agents.instructions`
- `chatHistory()` — returns `RedisAgentChatHistory` if entity is set, `InMemoryChatHistory` otherwise
- `tools()` — abstract, each subclass defines its own

### Critical: provider is always dynamic

Never instantiate `Gemini`, `Anthropic`, etc. directly in agent classes. Always go through:

```php
protected function provider(): AIProviderInterface
{
    return AgentProviderFactory::make($this->agent, $this->app);
}
```

### Chat history resolution

| Condition                | Class used                                                       |
| ------------------------ | ---------------------------------------------------------------- |
| `$this->entity !== null` | `RedisAgentChatHistory` — scoped to entity + externalReferenceId |
| `$this->entity === null` | `InMemoryChatHistory` — session only                             |

`context_window` comes from `$this->agent->config['context_window']`, default `50000`.

---

## Dispatch Flow

`ProcessAgentChatAction` instantiates every agent the same way:

```php
$currentAgent = new $this->agent->handler();
$currentAgent->setConfiguration($this->agent, $sessionEntity, $externalReferenceId);
$response = $currentAgent->chat(new UserMessage($message));
```

`handler` on the `agents` table must point to a class that extends `KanvasNeuronAgent`.

---

## Tool Pattern

Tools live at `src/Domains/Intelligence/Agents/Neuron/Tools/`.

### Base structure

```php
class SomeTool extends Tool
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected ?Model $entity = null,
    ) {
        parent::__construct('tool_name', 'Tool description for the LLM.');
    }

    public static function make(Apps $app, Companies $company, ?Model $entity = null): static
    {
        return new static($app, $company, $entity);
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'param',
                type: PropertyType::STRING,
                description: 'Description for the LLM',
                required: true,
            ),
        ];
    }

    public function __invoke(string $param): array
    {
        // implementation
    }
}
```

### Rules

- `properties()` = params the **LLM controls**
- Constructor params (`app`, `company`, `entity`) = params **PHP controls**, never exposed to LLM
- Always use `static::make()` factory when instantiating tools inside agent `tools()` arrays
- `HandOffToHumanTool` sets `ConfigurationEnum::MUTE_AI_AGENT` on the lead and fires the handoff workflow

### CRMToolkit

Group related tools with `AbstractToolkit` when an agent needs many CRM tools at once:

```php
// In agent tools():
CRMToolkit::make($this->app, $this->company, $this->entity)
// Provides: GetLead, GetPeopleProfile, UpdateLeadStage, SearchLeads, AssignLeadOwner
```

### Property types

```php
// Scalar
new ToolProperty(name: 'p', type: PropertyType::STRING, description: '...', required: true)

// Array
new ArrayProperty(name: 'items', description: '...', required: true, items: new ToolProperty(...))

// Object
new ObjectProperty(name: 'obj', description: '...', required: true, properties: [...])
```

---

## Chat History (Multi-channel)

A lead can have multiple channels simultaneously (sms, whatsapp, email, notes, assistant).
`KanvasChatHistory` reads from `AppModuleMessage` and builds the history Neuron sees.

### Data path

```
Lead (entityId)
    ↓
AppModuleMessage
    WHERE system_modules = Lead::class AND entity_id = $leadId
    ↓
Message
    → message_types_id → MessageType.verb  (sms, email, whatsapp, note, ai, etc.)
    → users_id         → User name
    → message (JSON)   → extract text content
```

### Message format Neuron receives

```
[sms | John Doe]: me interesa el financiamiento
[assistant]: Claro, déjame explicarte las opciones...
[whatsapp | John Doe]: cuánto es el enganche?
[notes | Carlos Mendez]: este lead ya visitó el dealer la semana pasada
```

Format: `[channel | SenderName]: content`

AI messages (`verb` = `ai`, `assistant`, `bot`) → `AssistantMessage`
All others → `UserMessage` with the prefix above

### typeFilter strategies per agent type

| Agent            | typeFilter                           | Reason                        |
| ---------------- | ------------------------------------ | ----------------------------- |
| `FollowUpAgent`  | `['sms', 'whatsapp', 'email', 'ai']` | Only external conversation    |
| `AssistantAgent` | `[]` (all)                           | Lead owner needs full context |
| `VoiceAgent`     | `['call', 'ai']`                     | Only call transcript          |
| `NoteAgent`      | `['notes', 'internal', 'ai']`        | Only internal CRM context     |

### Message JSON structure

`Message.message` is a JSON field. Use `$message->getMessage()` which returns array.
Common keys: `text`, `content`, `body`, `message`. Always fallback to `json_encode($data)`.

---

## Concrete Agent Structure

```php
class LeadQualificationAgent extends KanvasNeuronAgent
{
    public function instructions(): string
    {
        return parent::instructions() . "\n\nAdditional context...";
    }

    protected function tools(): array
    {
        return [
            CRMToolkit::make($this->app, $this->company, $this->entity),
            HandOffToHumanTool::make($this->agent),
        ];
    }

    // Optional: middleware for tool approval, summarization, etc.
    protected function middleware(): array
    {
        return [];
    }
}
```

---

## Workflows (Interrupt/Resume)

Use workflows when a multi-step process requires human approval at some point.

| Pattern               | When to use                                 |
| --------------------- | ------------------------------------------- |
| Plain agent `chat()`  | Multi-turn conversation                     |
| Laravel AI `prompt()` | One-shot structured output                  |
| Neuron Workflow       | Multi-step process with human approval gate |

### Interrupt pattern

A node calls `$this->interrupt(new ApprovalRequest(...))` to pause execution.
The resume token is stored on the entity custom fields for the UI to pick up.
On resume, the workflow continues from that node with the human's decision.

---

## Multi-Agent (Swarms)

- `agents.is_multi_agent = true` → agent can orchestrate others
- `agents.multi_agent_list` → JSON list of sub-agent IDs
- Sub-agents are wrapped as Tools and called from the orchestrator agent
- `agent_swarms` + `agent_swarm_members` tables track swarm membership

---

## Adding a New Neuron Agent

1. Create class in the appropriate domain folder under `Neuron/`
2. Extend `KanvasNeuronAgent`
3. Implement `tools()` with relevant tools
4. Set `handler` in the `agents` DB record to the full class namespace
5. Make sure `agent_types.provider = "neuron"` for the associated type

## Adding a New Tool

1. Create class under `Neuron/Tools/{Domain}/`
2. Extend `Tool`
3. Constructor: `Apps $app`, `Companies $company`, optional `?Model $entity`
4. Add `static make()` factory
5. Implement `properties()` and `__invoke()`
6. Do NOT register in `agent_tools` table — tools are now defined in the agent PHP class
