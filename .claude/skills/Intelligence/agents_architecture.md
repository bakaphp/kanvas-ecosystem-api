# Kanvas AI Agent Architecture

## Executive Summary

Four agent frameworks, each used for what it's designed for:

- **Neuron AI** — One PHP class per agent. Conversational, streaming, RAG, tools, middleware, interrupt/resume workflows. The primary framework for most agents.
- **Laravel AI SDK** — One PHP class per agent. Request-scoped, structured output, lightweight. Best for one-shot tasks (scoring, enrichment, classification).
- **Google ADK** — One PHP class per agent (client-side). Python AI logic hosted externally, PHP class wraps the REST API. Same `setConfiguration()` contract as Neuron/Laravel. Each ADK agent can have different behavior, endpoints, and session management.
- **OpenClaw** — The only truly dynamic framework. All OpenClaw agents use the same PHP handler class (`OpenClawAgentHandler`). Configured entirely via DB records (`role`, `config`, `soul`, `tools_config`). Deployed as Docker containers on EC2. Different "types" of OpenClaw agents are created by varying the DB config.

All four frameworks share the same dispatch pattern: `AgentType` identifies the framework (via a `provider` field), and each `Agent` record has a `handler` field pointing to its PHP class. Every agent — including OpenClaw — has a handler. The difference is OpenClaw agents all share the same class while Neuron/Laravel/ADK agents each have their own.

---

## 1. Framework Dispatch

### 1.1 AgentType: The Framework Registry

`AgentType` gains a `provider` field that identifies which framework the type belongs to:

```
agent_types table:
  id=1, name="Neuron AI CRM",       provider="neuron"
  id=2, name="Neuron AI Support",   provider="neuron"
  id=3, name="Laravel AI Scoring",  provider="laravel"
  id=4, name="Laravel AI Enrichment", provider="laravel"
  id=5, name="Google ADK Orchestrator", provider="adk"
  id=6, name="OpenClaw Persistent", provider="openclaw"
```

**Migration:**

```php
Schema::table('agent_types', function (Blueprint $table) {
    $table->string('provider')->nullable()->after('name');
    // provider: "neuron" | "laravel" | "adk" | "openclaw"
});
```

The `provider` field tells the system which framework this agent uses. It doesn't determine the handler — that's on the `Agent` record.

### 1.2 Agent: The Handler Reference

Each `Agent` record has a `handler` field pointing to its PHP class. This is the class that gets instantiated at runtime:

```
agents table:
  name: "Lead Qualification Agent"
  agent_type_id: 1 (Neuron AI CRM)
  handler: "Kanvas\Intelligence\Agents\Neuron\Revenue\LeadQualificationAgent"

  name: "Lead Scoring Agent"
  agent_type_id: 3 (Laravel AI Scoring)
  handler: "Kanvas\Intelligence\Agents\Laravel\Revenue\LeadScoringAgent"

  name: "Campaign Optimizer"
  agent_type_id: 5 (Google ADK Orchestrator)
  handler: "Kanvas\Intelligence\Agents\ADK\Growth\CampaignOptimizationAgent"

  name: "Social Monitor Bot"
  agent_type_id: 6 (OpenClaw Persistent)
  handler: "Kanvas\Intelligence\Agents\OpenClaw\OpenClawAgentHandler"  ← same class for all OpenClaw agents
```

**Migration:**

```php
Schema::table('agents', function (Blueprint $table) {
    $table->string('handler')->nullable()->after('agent_type_id');
});
```

### 1.3 Updated ProcessAgentChatAction Dispatch

```php
// Every agent has a handler — no fallback needed
$currentAgent = new $this->agent->handler();
$currentAgent->setConfiguration($this->agent, $sessionEntity);
```

The dispatch is identical for all four frameworks. Each handler class follows the same contract:

```
1. new $handlerClass()
2. ->setConfiguration($agent, $entity, $externalReferenceId)
3. ->chat($message)  or  ->prompt($message)  or framework-specific method
```

### 1.4 Framework Summary

| Provider   | Handler on                                      | PHP class per agent?   | Behavior defined in                                        |
| ---------- | ----------------------------------------------- | ---------------------- | ---------------------------------------------------------- |
| `neuron`   | `agent.handler`                                 | Yes                    | PHP class (`instructions()`, `tools()`, `middleware()`)    |
| `laravel`  | `agent.handler`                                 | Yes                    | PHP class (`instructions()`, `tools()`, `schema()`)        |
| `adk`      | `agent.handler`                                 | Yes                    | PHP class wraps Python REST API (`chat()`, `chatSimple()`) |
| `openclaw` | `agent.handler` (always `OpenClawAgentHandler`) | No — one class for all | DB record (`role`, `config`, `soul`, `tools_config`)       |

---

## 2. Agent Class Structure

### 2.1 Directory Structure

Organized **by framework first**, then by business domain. This keeps each framework self-contained — its agents, tools, and workflows all live together. No confusion about which base class or tool format to use.

```
src/Domains/Intelligence/Agents/
├── AgentProviderFactory.php               # Shared — resolves LLM provider from AgentModel.config
│
├── Neuron/                                # ── Everything Neuron AI ──
│   ├── KanvasNeuronAgent.php              # Base class (extends NeuronAI\RAG\RAG)
│   ├── Revenue/
│   │   ├── LeadQualificationAgent.php
│   │   ├── DealDeskAgent.php
│   │   └── CollectionsAgent.php
│   ├── Growth/
│   │   ├── OutreachAgent.php
│   │   └── FollowUpAgent.php
│   ├── CustomerSuccess/
│   │   ├── OnboardingAgent.php
│   │   ├── SupportTriageAgent.php
│   │   └── ChurnPreventionAgent.php
│   ├── Tools/                             # Neuron-format tools (Tool::make, ToolProperty, __invoke)
│   │   ├── CRM/
│   │   │   ├── GetLeadTool.php
│   │   │   ├── GetPeopleProfileTool.php
│   │   │   ├── UpdateLeadStageTool.php
│   │   │   ├── SearchLeadsTool.php
│   │   │   ├── AssignLeadOwnerTool.php
│   │   │   ├── CreateEngagementTool.php
│   │   │   └── CRMToolkit.php            # Neuron AbstractToolkit bundle
│   │   ├── Inventory/
│   │   │   ├── GetProductTool.php
│   │   │   ├── GetInventoryTool.php       # Extracted from current CRMAgent
│   │   │   ├── CheckStockTool.php
│   │   │   └── InventoryToolkit.php
│   │   ├── Commerce/
│   │   │   └── GetOrderStatusTool.php
│   │   ├── Social/
│   │   │   ├── SendMessageTool.php
│   │   │   └── GetConversationTool.php
│   │   ├── ActionEngine/
│   │   │   ├── SendActionTool.php
│   │   │   └── GetTaskStatusTool.php
│   │   └── System/
│   │       ├── GetCurrentTimeTool.php
│   │       ├── HandOffToHumanTool.php
│   │       ├── ScheduleFollowUpTool.php
│   │       └── SearchKnowledgeBaseTool.php
│   └── Workflows/                         # Neuron AI interrupt/resume workflows
│       ├── LeadQualificationWorkflow.php
│       ├── DealDeskWorkflow.php
│       ├── SupportEscalationWorkflow.php
│       └── Nodes/
│           ├── ScoreLeadNode.php
│           ├── QualifyLeadNode.php
│           ├── HumanReviewNode.php
│           └── AssignPipelineNode.php
│
├── Laravel/                               # ── Everything Laravel AI ──
│   ├── KanvasLaravelAgent.php             # Base class (implements Agent, Conversational, HasTools)
│   ├── Revenue/
│   │   ├── LeadScoringAgent.php           # HasStructuredOutput
│   │   └── InvoiceGenerationAgent.php     # HasStructuredOutput
│   ├── Product/
│   │   ├── InventoryMonitorAgent.php
│   │   ├── CatalogEnrichmentAgent.php     # HasStructuredOutput
│   │   └── AnomalyDetectionAgent.php
│   ├── Operations/
│   │   └── DataSyncAgent.php
│   ├── CustomerSuccess/
│   │   └── NpsFeedbackAgent.php           # HasStructuredOutput
│   └── Tools/                             # Laravel-format tools (make:tool pattern)
│       ├── CRM/
│       │   ├── GetLeadTool.php
│       │   └── GetPeopleProfileTool.php
│       ├── Inventory/
│       │   └── GetProductTool.php
│       └── Commerce/
│           ├── CreateOrderTool.php
│           └── GetOrderStatusTool.php
│
├── ADK/                                   # ── Everything Google ADK ──
│   ├── KanvasADKAgent.php                 # Base class (wraps GoogleADKService REST API)
│   ├── Growth/
│   │   └── CampaignOptimizationAgent.php  # Python app: campaign-optimizer
│   ├── Product/
│   │   └── PricingOptimizationAgent.php   # Python app: pricing-optimizer
│   └── Operations/
│       ├── ReportingAgent.php             # Python app: reporting
│       └── CrossSystemCoordinatorAgent.php # Python app: coordinator
│
└── OpenClaw/                              # ── OpenClaw (single handler) ──
    └── OpenClawAgentHandler.php           # Same class for ALL OpenClaw agents
                                           # Behavior from DB: agent.role, config, soul, tools_config
                                           # Knows which server/container via agent.activeDeployment
```

**Why framework-first instead of domain-first:**

- Open `Neuron/` → everything is Neuron. Tools use `Tool::make()` + `ToolProperty`. Agents extend `KanvasNeuronAgent`.
- Open `Laravel/` → everything is Laravel AI. Tools use `make:tool` pattern. Agents implement `Agent` contract.
- No ambiguity. No mixing. As the project grows, each folder scales independently.
- When adding a new agent, you immediately know which folder to put it in based on the framework choice.

**Tool duplication is intentional.** Neuron `GetLeadTool` and Laravel `GetLeadTool` both query the same `Lead::getByIdFromCompanyApp()`, but wrap it in their framework's native tool format. The 10-15 lines of framework glue per tool is worth the clarity — no adapters, no abstractions, just each framework's native pattern.

### 2.2 KanvasNeuronAgent Base Class

Replaces current `BaseAgent`. Provides the shared infrastructure every Neuron agent needs — dynamic provider resolution, entity-scoped chat history, and the `setConfiguration()` contract.

```php
namespace Kanvas\Intelligence\Agents\Neuron;

use Kanvas\Intelligence\Agents\AgentProviderFactory;
use Kanvas\Intelligence\Agents\Models\Agent;
use NeuronAI\RAG\RAG;

abstract class KanvasNeuronAgent extends RAG
{
    protected ?Agent $agent = null;
    protected ?Apps $app = null;
    protected ?Companies $company = null;
    protected ?Model $entity = null;
    protected ?string $externalReferenceId = null;

    public function setConfiguration(
        Agent $agent,
        ?Model $entity = null,
        ?string $externalReferenceId = null,
    ): void {
        $this->agent = $agent;
        $this->entity = $entity;
        $this->app = $agent->app;
        $this->company = $agent->company;
        $this->externalReferenceId = $externalReferenceId;
    }

    // Dynamic provider from AgentModel.config
    protected function provider(): AIProviderInterface
    {
        return AgentProviderFactory::make($this->agent, $this->app);
    }

    // Default instructions from agent DB record (can be overridden)
    public function instructions(): string
    {
        $role = $this->agent->role;

        $prompt = new SystemPrompt(
            background: $role['background'] ?? [],
            steps: $role['steps'] ?? [],
            output: $role['output'] ?? [],
        )->__toString();

        if ($this->agent->instructions) {
            $prompt .= "\n\n" . $this->agent->instructions;
        }

        return $prompt;
    }

    // Entity-scoped chat history
    protected function chatHistory(): AbstractChatHistory
    {
        if ($this->entity === null) {
            return new InMemoryChatHistory(
                contextWindow: $this->agent->config['context_window'] ?? 50000
            );
        }

        return new RedisAgentChatHistory(
            agent: $this->agent,
            entity: $this->entity,
            externalReferenceId: $this->externalReferenceId,
            contextWindow: $this->agent->config['context_window'] ?? 50000,
        );
    }

    // RAG infrastructure
    protected function embeddings(): EmbeddingsProviderInterface
    {
        return new OpenAIEmbeddingsProvider(
            key: $this->app->get(ConfigurationEnum::OPEN_AI_EMBEDDINGS_KEY->value),
            model: $this->app->get(ConfigurationEnum::OPEN_AI_EMBEDDINGS_MODEL->value) ?? 'text-embedding-3-small',
        );
    }

    protected function vectorStore(): VectorStoreInterface
    {
        return new MemoryVectorStore();
    }

    // Subclasses define their own tools
    abstract protected function tools(): array;
}
```

### 2.3 Concrete Neuron Agent Example: LeadQualificationAgent

```php
namespace Kanvas\Intelligence\Agents\Neuron\Revenue;

use Kanvas\Intelligence\Agents\Neuron\KanvasNeuronAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetPeopleProfileTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateLeadStageTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\GetCurrentTimeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\HandOffToHumanTool;

class LeadQualificationAgent extends KanvasNeuronAgent
{
    public function instructions(): string
    {
        // Can use parent's DB-driven instructions or define custom ones
        $base = parent::instructions();

        return $base . "\n\n" .
            "When qualifying a lead:\n" .
            "1. Retrieve the lead and people profile\n" .
            "2. Assess fit based on company size, industry, and engagement signals\n" .
            "3. Score 0-100 and update the pipeline stage accordingly\n" .
            "4. If score is borderline (40-60), hand off to human for review";
    }

    protected function tools(): array
    {
        return [
            GetLeadTool::make($this->app, $this->company, $this->entity),
            GetPeopleProfileTool::make($this->app, $this->company, $this->entity),
            UpdateLeadStageTool::make($this->app, $this->company),
            GetCurrentTimeTool::make(),
            HandOffToHumanTool::make($this->agent),
        ];
    }

    // Optional: middleware for tool approval
    protected function middleware(): array
    {
        return [
            ToolNode::class => [
                new ToolApproval(tools: [
                    UpdateLeadStageTool::class => fn (ToolInterface $tool): bool =>
                        // Only require approval for stage changes to "won" or "lost"
                        in_array($tool->getInput('stage'), ['won', 'lost']),
                ]),
            ],
        ];
    }
}
```

### 2.4 Concrete Neuron Agent Example: DealDeskAgent

Conversational agent for WhatsApp/SMS — guides customers through credit apps, documents, deposits:

```php
namespace Kanvas\Intelligence\Agents\Neuron\Revenue;

use Kanvas\Intelligence\Agents\Neuron\KanvasNeuronAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetPeopleProfileTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\ActionEngine\SendActionTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\ActionEngine\GetTaskStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\GetInventoryTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\HandOffToHumanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\ScheduleFollowUpTool;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\StreamingNode;

class DealDeskAgent extends KanvasNeuronAgent
{
    protected function tools(): array
    {
        return [
            GetLeadTool::make($this->app, $this->company, $this->entity),
            GetPeopleProfileTool::make($this->app, $this->company, $this->entity),
            SendActionTool::make($this->app, $this->company),
            GetTaskStatusTool::make($this->app, $this->company),
            GetInventoryTool::make($this->app, $this->company, $this->entity),
            HandOffToHumanTool::make($this->agent),
            ScheduleFollowUpTool::make($this->agent),
        ];
    }

    // Long conversations need summarization
    protected function middleware(): array
    {
        $summarization = new Summarization(
            provider: $this->resolveProvider(),
            maxTokens: 10000,
            messagesToKeep: 5,
        );

        return [
            ChatNode::class => [$summarization],
            StreamingNode::class => [$summarization],
        ];
    }
}
```

### 2.5 Concrete Neuron Agent Example: SupportTriageAgent

```php
namespace Kanvas\Intelligence\Agents\Neuron\CustomerSuccess;

use Kanvas\Intelligence\Agents\Neuron\KanvasNeuronAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetPeopleProfileTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Social\GetConversationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Social\SendMessageTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\HandOffToHumanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\SearchKnowledgeBaseTool;

class SupportTriageAgent extends KanvasNeuronAgent
{
    protected function tools(): array
    {
        return [
            GetLeadTool::make($this->app, $this->company, $this->entity),
            GetPeopleProfileTool::make($this->app, $this->company, $this->entity),
            GetConversationTool::make($this->app, $this->company),
            SendMessageTool::make($this->app, $this->company),
            SearchKnowledgeBaseTool::make($this->app),
            HandOffToHumanTool::make($this->agent),
        ];
    }
}
```

---

## 3. Laravel AI Agent Pattern

### 3.1 KanvasLaravelAgent Base Class

```php
namespace Kanvas\Intelligence\Agents\Laravel;

use Kanvas\Intelligence\Agents\Models\Agent as AgentModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

abstract class KanvasLaravelAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    protected AgentModel $agentRecord;
    protected Apps $app;
    protected Companies $company;
    protected ?Model $entity = null;

    public function __construct() {}

    public function setConfiguration(
        AgentModel $agent,
        ?Model $entity = null,
        ?string $externalReferenceId = null,
    ): void {
        $this->agentRecord = $agent;
        $this->entity = $entity;
        $this->app = $agent->app;
        $this->company = $agent->company;
    }

    // Resolve provider from AgentModel.config
    public function getProvider(): ?Lab
    {
        $config = $this->agentRecord->model?->config ?? [];
        $provider = $config['provider'] ?? null;

        return match ($provider) {
            'anthropic' => Lab::Anthropic,
            'openai' => Lab::OpenAI,
            'gemini' => Lab::Gemini,
            'groq' => Lab::Groq,
            'mistral' => Lab::Mistral,
            'ollama' => Lab::Ollama,
            'deepseek' => Lab::DeepSeek,
            default => null,
        };
    }

    public function getModel(): ?string
    {
        return $this->agentRecord->model?->config['model'] ?? null;
    }

    // Default: load from AgentHistory
    public function messages(): iterable
    {
        if (!$this->entity) {
            return [];
        }

        return AgentHistory::where('agent_id', $this->agentRecord->getId())
            ->where('entity_namespace', get_class($this->entity))
            ->where('entity_id', $this->entity->getId())
            ->latest()
            ->limit(50)
            ->get()
            ->reverse()
            ->flatMap(function ($history) {
                $messages = [];
                if ($history->input) {
                    $messages[] = new Message($history->input['role'] ?? 'user', $history->input['content'] ?? '');
                }
                if ($history->output) {
                    $messages[] = new Message($history->output['role'] ?? 'assistant', $history->output['content'] ?? '');
                }
                return $messages;
            })
            ->all();
    }

    // Prompt with dynamic provider/model
    public function promptWithConfig(string $message): mixed
    {
        return $this->prompt(
            $message,
            provider: $this->getProvider(),
            model: $this->getModel(),
            timeout: $this->agentRecord->config['timeout'] ?? 120,
        );
    }

    abstract public function instructions(): string;
    abstract public function tools(): iterable;
}
```

### 3.2 Concrete Laravel AI Agent: LeadScoringAgent

One-shot scoring — no conversation, just structured output:

```php
namespace Kanvas\Intelligence\Agents\Neuron\Revenue;

use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Laravel\Tools\CRM\GetLeadTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\CRM\GetPeopleProfileTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

class LeadScoringAgent extends KanvasLaravelAgent implements HasStructuredOutput
{
    public function instructions(): string
    {
        return 'You are a lead scoring specialist. Analyze the lead data and return a score from 0-100 '
             . 'with scoring factors and a priority level.';
    }

    public function tools(): iterable
    {
        return [
            new GetLeadTool($this->app, $this->company, $this->entity),
            new GetPeopleProfileTool($this->app, $this->company, $this->entity),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->integer()->min(0)->max(100)->required(),
            'priority' => $schema->string()->required(),   // low, normal, high, urgent
            'factors' => $schema->string()->required(),     // explanation
            'qualified' => $schema->boolean()->required(),
        ];
    }
}
```

### 3.3 Concrete Laravel AI Agent: CatalogEnrichmentAgent

```php
namespace Kanvas\Intelligence\Agents\Laravel\Product;

use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\GetProductTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

class CatalogEnrichmentAgent extends KanvasLaravelAgent implements HasStructuredOutput
{
    public function instructions(): string
    {
        return 'You enrich product catalog entries. Generate SEO-optimized descriptions, '
             . 'suggest categories, and flag missing data.';
    }

    public function tools(): iterable
    {
        return [
            new GetProductTool($this->app, $this->company, $this->entity),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'seo_description' => $schema->string()->required(),
            'suggested_categories' => $schema->string()->required(),
            'missing_fields' => $schema->string()->required(),
            'quality_score' => $schema->integer()->min(0)->max(100)->required(),
        ];
    }
}
```

---

## 4. Google ADK Agent Pattern

### 4.1 KanvasADKAgent Base Class

ADK agents follow the same `setConfiguration()` contract but their AI logic runs in Python hosted elsewhere. Each PHP class is a typed client that knows how to talk to a specific Python ADK app.

```php
namespace Kanvas\Intelligence\Agents\ADK;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\GoogleADKService;

abstract class KanvasADKAgent
{
    protected ?Agent $agent = null;
    protected ?Apps $app = null;
    protected ?Companies $company = null;
    protected ?Model $entity = null;
    protected string $content = '';

    public function setConfiguration(
        Agent $agent,
        ?Model $entity = null,
        ?string $externalReferenceId = null,
    ): void {
        $this->agent = $agent;
        $this->entity = $entity;
        $this->app = $agent->app;
        $this->company = $agent->company;
    }

    // Each ADK agent can override these to point to different Python apps
    protected function getAppName(): string
    {
        return $this->agent->config['adk_app_name']
            ?? $this->app->get(ConfigurationEnum::ADK_APP_NAME->value)
            ?? 'orchestrator';
    }

    protected function useStreaming(): bool
    {
        return $this->agent->config['use_streaming'] ?? true;
    }

    protected function getADKService(): GoogleADKService
    {
        return new GoogleADKService($this->app, $this->company);
    }

    public function chat(string $userId, string $sessionId, string $message, ?callable $onChunk = null): self
    {
        $service = $this->getADKService();
        $service->startSession($userId, $sessionId);

        if ($this->useStreaming() && $onChunk) {
            $this->content = $service->chat($userId, $sessionId, $message, $onChunk);
        } else {
            $response = $service->chatSimple($userId, $sessionId, $message);
            $this->content = $this->extractResponseText($response);
        }

        return $this;
    }

    public function chatSimple(string $userId, string $sessionId, string $message): self
    {
        $service = $this->getADKService();
        $service->startSession($userId, $sessionId);

        $response = $service->chatSimple($userId, $sessionId, $message);
        $this->content = $this->extractResponseText($response);

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    // Extracted from current ADKAgent — shared response parsing
    protected function extractResponseText(array $response): string
    {
        if (isset($response['content']['parts'])) {
            return $this->extractPartsText($response['content']['parts']);
        }

        if (array_is_list($response)) {
            foreach (array_reverse($response) as $event) {
                if (isset($event['content']['parts'])) {
                    $extracted = $this->extractPartsText($event['content']['parts']);
                    if ($extracted !== '') {
                        return $extracted;
                    }
                }
            }
        }

        return json_encode($response);
    }

    protected function extractPartsText(array $parts): string
    {
        return implode('', array_filter(array_column($parts, 'text')));
    }
}
```

### 4.2 Concrete ADK Agent: CampaignOptimizationADKAgent

Each ADK agent points to a specific Python app with its own behavior:

```php
namespace Kanvas\Intelligence\Agents\ADK\Growth;

use Kanvas\Intelligence\Agents\ADK\KanvasADKAgent;

class CampaignOptimizationAgent extends KanvasADKAgent
{
    // Points to the campaign-optimizer Python ADK app
    protected function getAppName(): string
    {
        return $this->agent->config['adk_app_name'] ?? 'campaign-optimizer';
    }

    // This agent works better without streaming (returns full analysis)
    protected function useStreaming(): bool
    {
        return false;
    }

    // Convenience method for scheduled runs (no session needed)
    public function analyze(string $companyId): self
    {
        return $this->chatSimple(
            userId: $companyId,
            sessionId: 'analysis-' . date('Y-m-d'),
            message: 'Run campaign performance analysis for today.',
        );
    }
}
```

### 4.3 Concrete ADK Agent: ReportingADKAgent

```php
namespace Kanvas\Intelligence\Agents\Operations;

use Kanvas\Intelligence\Agents\ADK\KanvasADKAgent;

class ReportingADKAgent extends KanvasADKAgent
{
    protected function getAppName(): string
    {
        return $this->agent->config['adk_app_name'] ?? 'reporting';
    }

    // Reporting agent supports both streaming (for chat) and simple (for scheduled)
    protected function useStreaming(): bool
    {
        return $this->agent->config['use_streaming'] ?? true;
    }

    // Inject business context into the ADK session before chatting
    public function chatWithContext(string $userId, string $sessionId, string $message, array $context): self
    {
        $service = $this->getADKService();
        $service->startSession($userId, $sessionId);

        // Inject context as session events so the Python agent has data to work with
        if (!empty($context)) {
            $service->injectSessionEvents($userId, $sessionId, [
                ['role' => 'user', 'content' => 'Context: ' . json_encode($context)],
            ]);
        }

        return $this->chat($userId, $sessionId, $message);
    }
}
```

### 4.4 Why ADK Agents Are Class-Specific

Even though the Python logic is hosted externally, each ADK agent PHP class can:

- Point to a **different Python app** (`getAppName()`)
- Use **different streaming behavior** (`useStreaming()`)
- Have **custom convenience methods** (`analyze()`, `chatWithContext()`)
- Handle **different session management** (some need context injection, some don't)
- Override **response parsing** for agents that return custom formats

This is the same principle as Neuron/Laravel — each agent is its own class with its own behavior. The difference is the behavior runs in Python, and the PHP class is the typed client.

---

## 5. Tool System

### 5.1 Neuron AI Tools

Follow Neuron AI's native `Tool::make()` pattern. Each tool is a reusable class:

```php
namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

class GetLeadTool extends Tool
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected ?Model $entity = null,
    ) {
        parent::__construct(
            'get_lead',
            'Retrieve lead information including status, pipeline stage, owner, contacts, and custom fields.',
        );
    }

    public static function make(Apps $app, Companies $company, ?Model $entity = null): static
    {
        return new static($app, $company, $entity);
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The lead ID to retrieve. If not provided, uses the current entity.',
                required: false,
            ),
        ];
    }

    public function __invoke(?int $lead_id = null): array
    {
        $lead = $lead_id
            ? Lead::getByIdFromCompanyApp($lead_id, $this->company, $this->app)
            : ($this->entity instanceof Lead ? $this->entity : null);

        if (!$lead) {
            return ['error' => 'No lead found'];
        }

        return [
            'id' => $lead->getId(),
            'title' => $lead->title,
            'description' => $lead->description,
            'status' => $lead->status?->name,
            'pipeline' => $lead->pipeline?->name,
            'stage' => $lead->stage?->name,
            'owner' => $lead->owner
                ? $lead->owner->firstname . ' ' . $lead->owner->lastname
                : 'Unassigned',
            'source' => $lead->source?->name,
            'type' => $lead->type?->name,
            'people' => $lead->people ? [
                'name' => $lead->people->getName(),
                'email' => $lead->people->getEmails()->first()?->value,
                'phone' => $lead->people->getPhones()->first()?->value,
            ] : null,
            'custom_fields' => $lead->getAll(),
            'is_active' => $lead->isActive(),
            'created_at' => $lead->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
```

### 5.2 UpdateLeadStageTool (Write Operation)

```php
namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

class UpdateLeadStageTool extends Tool
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
    ) {
        parent::__construct(
            'update_lead_stage',
            'Move a lead to a different pipeline stage. Use this after qualification or status changes.',
        );
    }

    public static function make(Apps $app, Companies $company): static
    {
        return new static($app, $company);
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The lead ID to update',
                required: true,
            ),
            new ToolProperty(
                name: 'stage',
                type: PropertyType::STRING,
                description: 'The pipeline stage name to move the lead to (e.g., "qualified", "contacted", "won", "lost")',
                required: true,
            ),
        ];
    }

    public function __invoke(int $lead_id, string $stage): array
    {
        $lead = Lead::getByIdFromCompanyApp($lead_id, $this->company, $this->app);
        $pipeline = $lead->pipeline;

        $newStage = PipelineStage::where('pipelines_id', $pipeline->getId())
            ->where('name', $stage)
            ->where('is_deleted', 0)
            ->firstOrFail();

        $lead->pipeline_stage_id = $newStage->getId();
        $lead->saveOrFail();

        return [
            'success' => true,
            'lead_id' => $lead->getId(),
            'new_stage' => $newStage->name,
            'pipeline' => $pipeline->name,
        ];
    }
}
```

### 5.3 HandOffToHumanTool

```php
namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

class HandOffToHumanTool extends Tool
{
    public function __construct(
        protected Agent $agent,
    ) {
        parent::__construct(
            'hand_off_to_human',
            'Transfer the conversation to a human agent. Use when the customer requests it, '
            . 'when dealing with billing/legal issues, or when confidence is low.',
        );
    }

    public static function make(Agent $agent): static
    {
        return new static($agent);
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Why the handoff is needed',
                required: true,
            ),
        ];
    }

    public function __invoke(string $reason): array
    {
        // Uses existing HandOffActivity logic
        $entity = $this->agent->entity ?? null;

        if ($entity instanceof Lead) {
            $entity->set(ConfigurationEnum::MUTE_AI_AGENT->value, 'muted');

            // Notify lead owner
            $entity->fireWorkflow(
                WorkflowEnum::HANDOFF->value,
                true,
                ['reason' => $reason, 'agent_id' => $this->agent->getId()],
            );
        }

        return [
            'success' => true,
            'message' => 'Conversation transferred to human agent.',
            'reason' => $reason,
        ];
    }
}
```

### 5.4 Neuron AI Toolkit (Grouping Related Tools)

For agents that need a bundle of CRM tools, create a toolkit:

```php
namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use NeuronAI\Tools\Toolkits\AbstractToolkit;

class CRMToolkit extends AbstractToolkit
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected ?Model $entity = null,
    ) {}

    public static function make(Apps $app, Companies $company, ?Model $entity = null): static
    {
        return new static($app, $company, $entity);
    }

    public function guidelines(): ?string
    {
        return 'Use CRM tools to look up lead and customer data before making decisions. '
             . 'Always verify lead status before updating stages.';
    }

    public function provide(): array
    {
        return [
            GetLeadTool::make($this->app, $this->company, $this->entity),
            GetPeopleProfileTool::make($this->app, $this->company, $this->entity),
            UpdateLeadStageTool::make($this->app, $this->company),
            SearchLeadsTool::make($this->app, $this->company),
            AssignLeadOwnerTool::make($this->app, $this->company),
        ];
    }
}
```

Usage in an agent:

```php
protected function tools(): array
{
    return [
        CRMToolkit::make($this->app, $this->company, $this->entity),
        HandOffToHumanTool::make($this->agent),
    ];
}
```

### 5.5 Laravel AI Tools

Laravel AI uses its own tool format (generated via `php artisan make:tool`). These live under `Laravel/Tools/` and follow the SDK's native pattern. The business logic (querying leads, products, etc.) is the same — just wrapped differently.

```php
namespace Kanvas\Intelligence\Agents\Laravel\Tools\CRM;

// Generated via: php artisan make:tool GetLeadTool
// Follows Laravel AI SDK's tool interface
class GetLeadTool
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected ?Model $entity = null,
    ) {}

    // Laravel AI tool methods — same Lead::getByIdFromCompanyApp() logic
    // as the Neuron version, wrapped in Laravel AI's format
}
```

### 5.6 Tool Organization

Tools are **separated by framework** — each lives in its own `Tools/` folder under the framework namespace. Both versions call the same domain code (models, actions, services). See the directory structure in section 2.1 for the full layout.

**Why duplicate?** Neuron tools use `Tool::make()` + `ToolProperty` + `__invoke()`. Laravel tools use `make:tool` scaffolding. Different base classes, different property APIs, different invocation patterns. Trying to abstract over both would create more complexity than just writing thin wrappers.

---

## 6. Provider Factory

### 6.1 AgentProviderFactory

Shared by all Neuron AI agents via `KanvasNeuronAgent::provider()`. Reads from `AgentModel.config`:

```php
namespace Kanvas\Intelligence\Agents;

// Shared across Neuron and Laravel — lives at the Agents root, not inside a framework folder
class AgentProviderFactory
{
    public static function make(Agent $agent, Apps $app): AIProviderInterface
    {
        $agentModel = $agent->model;

        // Fallback to existing Gemini config if no AgentModel
        if (!$agentModel || !$agentModel->config) {
            return new Gemini(
                key: $app->get(ConfigurationEnum::GEMINI_KEY->value),
                model: $app->get(ConfigurationEnum::GEMINI_MODEL->value) ?? 'gemini-2.0-flash-lite',
                httpClient: new GuzzleHttpClient(timeout: 220, connectTimeout: 220),
            );
        }

        $config = $agentModel->config;
        $provider = $config['provider'];
        $model = $config['model'] ?? $agentModel->name;
        $apiKey = $app->get($config['key_setting'] ?? '');
        $timeout = $agent->config['timeout'] ?? 220;
        $httpClient = new GuzzleHttpClient(timeout: $timeout, connectTimeout: $timeout);

        // Agent-level parameter overrides
        $parameters = array_filter([
            'temperature' => $agent->config['temperature'] ?? $config['default_parameters']['temperature'] ?? null,
            'max_tokens' => $agent->config['max_tokens'] ?? $config['default_parameters']['max_tokens'] ?? null,
        ]);

        return match ($provider) {
            'anthropic' => new Anthropic(key: $apiKey, model: $model, parameters: $parameters, httpClient: $httpClient),
            'openai' => new OpenAIResponses(key: $apiKey, model: $model, parameters: $parameters),
            'gemini' => new Gemini(key: $apiKey, model: $model, parameters: $parameters, httpClient: $httpClient),
            'ollama' => new Ollama(url: $app->get($config['base_url_setting'] ?? ''), model: $model, httpClient: $httpClient),
            'mistral' => new Mistral(key: $apiKey, model: $model, parameters: $parameters),
            'deepseek' => new Deepseek(key: $apiKey, model: $model, parameters: $parameters),
            'grok' => new Grok(key: $apiKey, model: $model, parameters: $parameters),
            'openai_compatible' => new OpenAILike(
                baseUri: $app->get($config['base_url_setting']),
                key: $apiKey,
                model: $model,
                parameters: $parameters,
            ),
            default => throw new ValidationException("Unsupported provider: {$provider}"),
        };
    }
}
```

### 6.2 AgentModel.config Schema

```json
{
    "provider": "anthropic",
    "model": "claude-sonnet-4-20250514",
    "key_setting": "anthropic_api_key",
    "default_parameters": {
        "temperature": 0.7,
        "max_tokens": 4096
    }
}
```

`key_setting` is the app custom field name that holds the API key. Secrets stay in the existing custom fields system.

---

## 7. Neuron AI Workflows (Interrupt/Resume)

### 7.1 When to Use Workflows vs Plain Agents

| Use Case                                | Pattern                                           |
| --------------------------------------- | ------------------------------------------------- |
| Single-turn task (scoring, enrichment)  | Laravel AI agent — `prompt()`, done               |
| Multi-turn conversation (chat, support) | Neuron AI agent — `chat()` with history           |
| Multi-step process with human approval  | Neuron AI **Workflow** — nodes + interrupt/resume |
| Cross-domain orchestration              | Google ADK — multi-agent via REST                 |

### 7.2 Workflow Structure

```
src/Domains/Intelligence/Agents/Workflows/
├── LeadQualificationWorkflow.php
├── DealDeskWorkflow.php
├── SupportEscalationWorkflow.php
└── Nodes/
    ├── ScoreLeadNode.php
    ├── QualifyLeadNode.php
    ├── HumanReviewNode.php
    ├── AssignPipelineNode.php
    ├── SendOutreachNode.php
    └── EscalateNode.php
```

### 7.3 LeadQualificationWorkflow

```php
namespace Kanvas\Intelligence\Agents\Workflows;

use NeuronAI\Workflow\Workflow;

class LeadQualificationWorkflow extends Workflow
{
    protected function nodes(): array
    {
        return [
            new ScoreLeadNode(),
            new QualifyLeadNode(),
            new HumanReviewNode(),     // Interrupts for borderline scores
            new AssignPipelineNode(),
        ];
    }
}
```

### 7.4 ScoreLeadNode

```php
class ScoreLeadNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): ScoreEvent
    {
        $agent = $state->get('agent');       // Agent record from DB
        $lead = $state->get('lead');
        $app = $state->get('app');
        $company = $state->get('company');

        // Use the scoring agent
        $scorer = new LeadScoringAgent();
        $scorer->setConfiguration($agent, $lead);

        $result = $scorer->promptWithConfig(
            "Score this lead based on the available data."
        );

        $state->set('score', $result['score']);
        $state->set('factors', $result['factors']);
        $state->set('qualified', $result['qualified']);

        return new ScoreEvent();
    }
}
```

### 7.5 HumanReviewNode (Interrupt/Resume)

```php
class HumanReviewNode extends Node
{
    public function __invoke(ScoreEvent $event, WorkflowState $state): AssignEvent|StopEvent
    {
        $score = $state->get('score');

        // Clear cases — no human needed
        if ($score > 70) {
            $state->set('qualified', true);
            return new AssignEvent();
        }
        if ($score < 30) {
            $state->set('qualified', false);
            return new StopEvent();
        }

        // Borderline (30-70): pause for human review
        $response = $this->interrupt(
            new ApprovalRequest(
                message: "Lead scored {$score}/100. Factors: " . $state->get('factors'),
                actions: [
                    new Action('qualify', 'Qualify Lead', "Approve qualification"),
                    new Action('disqualify', 'Disqualify Lead', "Reject lead"),
                ],
            )
        );

        $action = $response->getAction('qualify');
        $state->set('qualified', $action->isApproved());
        $state->set('reviewer_feedback', $action->feedback);

        return $action->isApproved() ? new AssignEvent() : new StopEvent();
    }
}
```

### 7.6 Running the Workflow

```php
// From a KanvasActivity (Kanvas workflow engine integration)
class LeadQualificationActivity extends KanvasActivity
{
    public function execute($lead, Apps $app, array $params): array
    {
        $agent = Agent::where('slug', 'lead-qualification')
            ->fromApp($app)
            ->fromCompany($lead->company)
            ->where('is_active', true)
            ->notDeleted()
            ->first();

        if (!$agent) {
            return ['skipped' => true];
        }

        $workflow = new LeadQualificationWorkflow(
            new EloquentPersistence(WorkflowInterrupt::class)
        );

        try {
            $state = $workflow->init(new WorkflowState([
                'agent' => $agent,
                'lead' => $lead,
                'app' => $app,
                'company' => $lead->company,
            ]))->run();

            return [
                'score' => $state->get('score'),
                'qualified' => $state->get('qualified'),
            ];
        } catch (WorkflowInterrupt $interrupt) {
            // Store for human review UI
            $lead->set('workflow_resume_token', $interrupt->getResumeToken());
            $lead->set('workflow_request', json_encode($interrupt->getRequest()));

            return [
                'status' => 'pending_review',
                'score' => $state->get('score') ?? null,
            ];
        }
    }
}
```

---

## 8. Agent Registration

### 8.1 Agent → AgentType → Handler Mapping

Each `Agent` record has a `handler` column pointing to its PHP class:

```
agents:
  name: "Lead Qualification Agent"
  agent_type_id: 1 (Neuron AI CRM)
  handler: "Kanvas\Intelligence\Agents\Neuron\Revenue\LeadQualificationAgent"
  agent_model_id: → AgentModel("Claude Sonnet 4")
  role: { background: [...], steps: [...], output: [...] }
  instructions: "Additional context..."
  tools_config: null  (tools come from the PHP class)
  config: { temperature: 0.3, context_window: 50000 }

  name: "Lead Scoring Agent"
  agent_type_id: 3 (Laravel AI Scoring)
  handler: "Kanvas\Intelligence\Agents\Laravel\Revenue\LeadScoringAgent"

  name: "Campaign Optimizer"
  agent_type_id: 5 (Google ADK Orchestrator)
  handler: "Kanvas\Intelligence\Agents\ADK\Growth\CampaignOptimizationAgent"

  name: "Social Monitor Bot"
  agent_type_id: 6 (OpenClaw Persistent)
  handler: "Kanvas\Intelligence\Agents\OpenClaw\OpenClawAgentHandler"
```

**Every agent has a handler** — even OpenClaw. The difference is OpenClaw agents all share the same handler class, while Neuron/Laravel/ADK agents each have their own class.

**Dispatch in ProcessAgentChatAction:**

```php
$currentAgent = new $this->agent->handler();
$currentAgent->setConfiguration($this->agent, $sessionEntity);
```

### 8.2 Why OpenClaw Agents Need the Handler

Even though OpenClaw is dynamic (behavior from DB), the handler class is still needed because:

- Workflow activities may need to talk to a specific OpenClaw agent: `new OpenClawAgentHandler()` + `setConfiguration($agent)` → the agent record tells it which server/container
- Channel responders instantiate via `new $agent->handler()` — same pattern for all frameworks
- No special-casing in dispatch logic — every agent has a handler, period

---

## 9. Agent Catalog by Business Domain

### Revenue

| Agent                  | Framework  | Class                                    | Primary Tools                                       |
| ---------------------- | ---------- | ---------------------------------------- | --------------------------------------------------- |
| LeadQualificationAgent | Neuron AI  | `Neuron\Revenue\LeadQualificationAgent`  | CRMToolkit, HandOff                                 |
| LeadScoringAgent       | Laravel AI | `Laravel\Revenue\LeadScoringAgent`       | GetLead, GetPeopleProfile + structured output       |
| DealDeskAgent          | Neuron AI  | `Neuron\Revenue\DealDeskAgent`           | CRMToolkit, ActionEngineToolkit, Inventory, HandOff |
| InvoiceGenerationAgent | Laravel AI | `Laravel\Revenue\InvoiceGenerationAgent` | GetLead, CreateOrder + structured output            |
| CollectionsAgent       | Neuron AI  | `Neuron\Revenue\CollectionsAgent`        | GetOrderStatus, SendMessage, ScheduleFollowUp       |

### Growth

| Agent                     | Framework  | Class                                  | Primary Tools                          |
| ------------------------- | ---------- | -------------------------------------- | -------------------------------------- |
| OutreachAgent             | Neuron AI  | `Neuron\Growth\OutreachAgent`          | GetLead, GetPeopleProfile, SendMessage |
| FollowUpAgent             | Neuron AI  | `Neuron\Growth\FollowUpAgent`          | GetLead, SendMessage, ScheduleFollowUp |
| CampaignOptimizationAgent | Google ADK | `ADK\Growth\CampaignOptimizationAgent` | Python app: `campaign-optimizer`       |
| ProspectingAgent          | OpenClaw   | `OpenClaw\OpenClawAgentHandler`        | configured in openclaw.json            |
| ContentCreationAgent      | OpenClaw   | `OpenClaw\OpenClawAgentHandler`        | configured in openclaw.json            |
| SocialEngagementAgent     | OpenClaw   | `OpenClaw\OpenClawAgentHandler`        | configured in openclaw.json            |

### Product

| Agent                    | Framework  | Class                                    | Primary Tools                   |
| ------------------------ | ---------- | ---------------------------------------- | ------------------------------- |
| InventoryMonitorAgent    | Laravel AI | `Laravel\Product\InventoryMonitorAgent`  | CheckStock, SendMessage         |
| CatalogEnrichmentAgent   | Laravel AI | `Laravel\Product\CatalogEnrichmentAgent` | GetProduct + structured output  |
| AnomalyDetectionAgent    | Laravel AI | `Laravel\Product\AnomalyDetectionAgent`  | GetOrderStatus, SendMessage     |
| PricingOptimizationAgent | Google ADK | `ADK\Product\PricingOptimizationAgent`   | Python app: `pricing-optimizer` |

### Operations

| Agent                       | Framework  | Class                                        | Primary Tools                       |
| --------------------------- | ---------- | -------------------------------------------- | ----------------------------------- |
| DataSyncAgent               | Laravel AI | `Laravel\Operations\DataSyncAgent`           | GetLead, GetProduct, GetOrderStatus |
| ReportingAgent              | Google ADK | `ADK\Operations\ReportingAgent`              | Python app: `reporting`             |
| CrossSystemCoordinatorAgent | Google ADK | `ADK\Operations\CrossSystemCoordinatorAgent` | Python app: `coordinator`           |

### Customer Success

| Agent                | Framework  | Class                                         | Primary Tools                                        |
| -------------------- | ---------- | --------------------------------------------- | ---------------------------------------------------- |
| OnboardingAgent      | Neuron AI  | `Neuron\CustomerSuccess\OnboardingAgent`      | SendAction, GetTaskStatus, GetPeopleProfile          |
| SupportTriageAgent   | Neuron AI  | `Neuron\CustomerSuccess\SupportTriageAgent`   | CRMToolkit, GetConversation, SearchKB, HandOff       |
| ChurnPreventionAgent | Neuron AI  | `Neuron\CustomerSuccess\ChurnPreventionAgent` | GetPeopleProfile, ApplyDiscount, SendMessage         |
| NpsFeedbackAgent     | Laravel AI | `Laravel\CustomerSuccess\NpsFeedbackAgent`    | GetPeopleProfile, GetOrderStatus + structured output |

---

## 10. New Models

### AgentTask

Tracks business outcomes from agent work:

```
Table: agent_tasks (intelligence DB)
  id, uuid, apps_id, companies_id
  agent_id → Agent
  agent_history_id → AgentHistory (nullable)
  entity_namespace, entity_id (polymorphic)
  task_type: string (qualification, scoring, enrichment, outreach, triage)
  status: string (pending, in_progress, completed, failed, cancelled)
  priority: string (low, normal, high, urgent)
  input: JSON, output: JSON
  confidence_score: float (0-1)
  requires_review: boolean
  reviewed_by, reviewed_at, review_outcome (nullable)
  started_at, completed_at, error_message
  is_deleted, created_at, updated_at
```

### AgentToolExecution

Audit log of tool calls:

```
Table: agent_tool_executions (intelligence DB)
  id, uuid, apps_id, companies_id
  agent_id, agent_history_id, agent_task_id (nullable)
  tool_name: string (e.g., "crm.update_lead_stage")
  input_params: JSON, output_result: JSON
  status: string (success, failed, denied)
  duration_ms: int
  entity_namespace, entity_id
  is_deleted, created_at, updated_at
```

### AgentCostLedger

Per-interaction cost tracking:

```
Table: agent_cost_ledger (intelligence DB)
  id, uuid, apps_id, companies_id
  agent_id, agent_history_id
  provider, model: string
  input_tokens, output_tokens, cache_read_tokens, cache_write_tokens: int
  cost_usd: decimal(10,6)
  billing_period: date
  is_deleted, created_at, updated_at
```

### AgentSchedule

Cron-triggered agent runs:

```
Table: agent_schedules (intelligence DB)
  id, uuid, apps_id, companies_id
  agent_id → Agent
  schedule_expression: string (cron syntax)
  input_template: JSON
  is_active: boolean
  last_run_at, next_run_at: timestamp (nullable)
  is_deleted, created_at, updated_at
```

---

## 11. Migration Path

### Phase 1: Foundation

1. Add `provider` column to `agent_types` table (migration)
2. Add `handler` column to `agents` table (migration)
3. `AgentProviderFactory` — dynamic provider from AgentModel.config
4. `KanvasNeuronAgent` base class — replaces `BaseAgent`
5. `KanvasLaravelAgent` base class — new
6. `KanvasADKAgent` base class — replaces current `ADKAgent`
7. Extract `CRMAgent` tools into standalone tool classes (GetLeadTool, GetPeopleProfileTool, GetInventoryTool)
8. Extract `get_current_time` → GetCurrentTimeTool
9. Create HandOffToHumanTool from existing HandOffActivity logic
10. Create CRMToolkit, InventoryToolkit

### Phase 2: Revenue Agents

11. LeadQualificationAgent (Neuron AI) + LeadQualificationActivity
12. LeadScoringAgent (Laravel AI) + LeadScoringActivity
13. DealDeskAgent (Neuron AI) — replace/extend existing CRMAgent
14. InvoiceGenerationAgent (Laravel AI)
15. CollectionsAgent (Neuron AI)

### Phase 3: ADK + Remaining Agents

16. CampaignOptimizationADKAgent, ReportingADKAgent, CrossSystemCoordinatorADKAgent, PricingOptimizationADKAgent
17. Build remaining Neuron/Laravel agent classes per catalog (section 8)
18. LeadQualificationWorkflow with interrupt/resume
19. DealDeskWorkflow for multi-step deal progression
20. SupportEscalationWorkflow

### Phase 4: New Models + Observability

21. AgentTask, AgentToolExecution, AgentCostLedger, AgentSchedule models + migrations
22. Update TrackAgentUsageAction to record token costs
23. GraphQL reporting queries
24. Register all new activities in KanvasWorkflowSynActionCommand

### Phase 5: Deprecation

25. Deprecate `BaseAgent`, `CRMAgent`, `InventoryAgent`, `SocialCreatorAgent`, `SocialEngagementAgent`, current `ADKAgent`
26. Migrate existing agent records to new handler classes + populate `handler` column
27. Populate `agent_types.provider` for all existing rows
28. Remove deprecated classes after verification
