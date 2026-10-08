<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Concerns;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\ChatHistory\KanvasChatHistory;
use Kanvas\Intelligence\Agents\ChatHistory\KanvasHistoryTrimmer;
use Kanvas\Intelligence\Agents\Enums\AgentRunConfigurationEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Middleware\BoundToolResultsMiddleware;
use Kanvas\Intelligence\Agents\Neuron\Middleware\CancelsOnRequestMiddleware;
use Kanvas\Intelligence\Agents\Neuron\Middleware\KanvasToolSearchMiddleware;
use Kanvas\Intelligence\Agents\Neuron\Stores\ConversationMessageStore;
use Kanvas\Intelligence\Agents\Neuron\Stores\EntityRollupMessageStore;
use Kanvas\Intelligence\Agents\Neuron\Stores\KanvasMessageStore;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\CurrentTimeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\RenderArtifactTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\DynamicSubAgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Fallback\UnknownToolStub;
use Kanvas\Intelligence\Agents\Services\AgentProviderService;
use Kanvas\Intelligence\Agents\Services\ModelContextWindowService;
use Kanvas\Intelligence\Agents\Traits\HasTemporalContext;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Scheduling\Services\ScheduledActionTimezoneResolver;
use Kanvas\Users\Models\Users;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolRegistry;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use Override;
use Throwable;

trait HasKanvasAgentBehavior
{
    use RemembersForCompany;
    use RunsDurably;
    use SummarizesHistory;

    use HasTemporalContext;

    protected bool $humanDirectedConversation = false;

    /**
     * Below this many searchable tools there is nothing to save: the search tool and its prompt block
     * cost about as much as the schemas they would hide.
     */
    private const int TOOL_SEARCH_MIN_POOL = 6;

    /**
     * Catalog grants stay in the prompt up to this many: each is a few hundred tokens and an agent
     * reaches for them on ordinary turns, so pooling them adds a search round to most turns for
     * little saving. MCP toolkits are the token hog and are always pooled.
     */
    private const int CATALOG_ALWAYS_ON_LIMIT = 25;

    /**
     * Tools that stay on every round whatever the switch says: the identity set the prompt already
     * refers to by name, and the search tool itself.
     */
    private const array ALWAYS_ON_TOOLS = [
        'get_current_time',
        'search_knowledge',
        'remember',
        'who_is_user',
        'read_my_ledger',
        'get_file_link',
        'render_artifact',
        'build_admin_link',
        'capability_lookup',
        'tool_search',
    ];

    protected ?Agent $agent = null;
    protected ?Apps $app = null;
    protected ?Companies $company = null;
    protected ?Model $entity = null;
    protected ?Users $user = null;
    protected ?Session $session = null;

    /** @var list<string> */
    private array $registeredToolNames = [];

    /**
     * Tools the model reaches through `tool_search` instead of carrying on every round.
     *
     * @var list<ToolInterface>
     */
    private array $toolSearchPool = [];

    protected ?Lead $currentLead = null;

    /**
     * The human this turn is answering, when it can't be read off the session entity — an @mention
     * conversation runs with `$user` set to the agent's OWN user and a session whose entity is the
     * record (a Lead), so "remind me" would otherwise resolve to the agent itself.
     */
    protected ?Users $conversationHuman = null;

    /** @var list<string> Attachment URLs/paths (image/audio/PDF) on the current turn's user prompt. */
    protected array $turnMedia = [];
    protected bool $privateUserTurn = false;
    protected bool $rendersArtifacts = false;

    public function setConfiguration(Agent $agent, ?Model $entity = null, ?Users $user = null): void
    {
        if ($user === null) {
            throw new ValidationException(
                'A Users instance is required to configure a Neuron agent. '
                . 'Pass the authenticated user, or fall back to the AI agent user via '
                . '$company->getAiAgentUserOrFail() when running in a non-request context '
                . '(webhook, queued job, CLI).'
            );
        }

        $this->agent = $agent;
        $this->entity = $entity;
        $this->app = $agent->app;
        $this->company = $agent->companyFor($user);
        $this->user = $user;
    }

    /**
     * The thread as the userChat surface binds it: the session uuid. A channel turn is bound to the
     * entity uuid instead, and the stores that roll a record's history up across channels must not
     * narrow their load to that, or every row written before the thread was bound disappears.
     */
    protected function sessionThreadId(): ?string
    {
        $threadId = $this->getThreadId();

        return $threadId !== null && $threadId === $this->session?->uuid ? $threadId : null;
    }

    public function setSession(?Session $session): void
    {
        $this->session = $session;
    }

    public function setHumanDirectedConversation(bool $humanDirected): void
    {
        $this->humanDirectedConversation = $humanDirected;
    }

    public function isHumanDirectedConversation(): bool
    {
        return $this->humanDirectedConversation;
    }

    public function setConversationHuman(?Users $user): void
    {
        $this->conversationHuman = $user;
    }

    /**
     * The person an admin-guarded tool must authorize against — never the agent itself.
     *
     * `$this->user` is the turn's actor, and what that means depends on the surface: in a user chat
     * it IS the human, but on the @mention and channel surfaces it is the AGENT'S OWN user. Handing
     * that to an admin guard gets it wrong in both directions — an agent user that happens to be an
     * admin authorizes whoever is talking to it, and one that isn't denies the real admin. Only
     * `conversationHuman` is set to the actual person (see RespondToMentionJob), so it wins wherever
     * it is set, and `$this->user` remains the answer on the surfaces where it is the human.
     */
    public function requestingHuman(): ?Users
    {
        return $this->conversationHuman ?? $this->user;
    }

    /**
     * A cancelled turn leaves the person's message in the transcript but out of the model's window, so
     * the resend is the only copy the agent answers; the durable run is abandoned so the thread takes it.
     */
    public function discardTurn(Message $message): void
    {
        $threadId = $this->getThreadId();
        $store = $this->resolveMessageStore();

        if ($threadId !== null && $store instanceof KanvasMessageStore) {
            $store->archiveMessages($threadId, [KanvasMessageStore::bareId($message->getId())]);
        }

        if (! $this->durableRunsActive()) {
            return;
        }

        try {
            $this->abandon();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Whether this agent's message store already writes each turn to agent_conversation_messages. When
     * true, RunNeuronChatAction skips its own logTurn to avoid a duplicate conversation; the rollup and
     * in-memory stores leave logTurn as the only conversation-store record.
     */
    public function persistsTurnsToConversationStore(): bool
    {
        return $this->ownsTranscript();
    }

    /**
     * Whether the agent's own store is the transcript: it persists every turn and it is the one store
     * that archives, so summarization applies there and nowhere else.
     */
    protected function ownsTranscript(): bool
    {
        return $this->resolveMessageStore() instanceof ConversationMessageStore;
    }

    public function setCurrentLead(?Lead $lead): void
    {
        $this->currentLead = $lead;
    }

    /**
     * @param list<string> $media The current turn's attachment URLs (image/audio/PDF), plumbed from
     *                            the kernel so the chat history can persist a reference (the handler
     *                            itself only ever sees the base64 content blocks built downstream).
     */
    public function setTurnMedia(array $media): void
    {
        $this->turnMedia = array_values($media);
    }

    public function setPrivateUserTurn(bool $private): void
    {
        $this->privateUserTurn = $private;
    }

    public function setRendersArtifacts(bool $renders): void
    {
        $this->rendersArtifacts = $renders;
    }

    // The record this turn is about, entity-agnostic: the kernel-plumbed currentLead
    // wins, else the entity in scope (any Model). This is the generic seam RAG and
    // any future non-Lead agent resolve against.
    protected function resolveEntityForTurn(): ?Model
    {
        return $this->currentLead ?? $this->entity;
    }

    // CRM lens over the record in scope. The entity-as-Lead branch is the legacy
    // fallback for sessions that still point directly at a Lead row.
    protected function resolveLeadForTurn(): ?Lead
    {
        $entity = $this->resolveEntityForTurn();

        return $entity instanceof Lead ? $entity : null;
    }

    /**
     * Souls tell the model to call get_current_time, and Neuron kills the turn with a
     * ProviderException when the model names a tool the provider was never given
     * (Sentry KANVAS-ECOSYSTEM-600). Time must therefore reach every agent whatever its
     * tools() returns — a hardcoded baseline, a registry selection, or nothing at all.
     * Deduped by tool name, so an operator who also grants it in the registry gets one copy.
     */
    #[Override]
    public function getTools(): array
    {
        // Last occurrence wins, so the universal baseline goes first: any copy the agent supplies, by
        // hardcoding or by registry grant, replaces it. Between a hardcoded and a registry tool of the same
        // name, whichever the subclass's tools() lists last is kept.
        $tools = $this->dedupeByName([...$this->universalTools(), ...parent::getTools()]);

        if ($this->rendersArtifacts) {
            return $tools;
        }

        // Off a rendering surface the block reaches the reader as raw JSON, whoever granted the tool.
        return array_values(
            array_filter(
                $tools,
                fn (object $tool): bool => ! $tool instanceof RenderArtifactTool
            )
        );
    }

    /**
     * @param array<int, object> $tools
     * @return list<object>
     */
    private function dedupeByName(array $tools): array
    {
        $byName = [];
        foreach ($tools as $tool) {
            $key = $tool instanceof ToolInterface ? $tool->getName() : spl_object_id($tool);
            $byName[$key] = $tool;
        }

        return array_values($byName);
    }

    /**
     * @return list<ToolInterface>
     */
    protected function universalTools(): array
    {
        // read_file is deliberately NOT here. It reaches any file the company owns, so it is granted
        // per agent (or held intrinsically by the PM) rather than handed to every agent that exists —
        // a customer-facing agent talked into a filesystem_id would read another prospect's quote.
        return [
            new CurrentTimeTool($this->resolveTenantTimezone()),
            ...$this->memoryTools(),
        ];
    }

    /**
     * The agent's local timezone for time-relative reasoning: company timezone, then user timezone, then
     * UTC. Each is validated as a real IANA zone so a blank/garbage tenant value falls through.
     */
    private function resolveTenantTimezone(): string
    {
        return new ScheduledActionTimezoneResolver()->resolve($this->company, $this->user);
    }

    /**
     * Dependencies MergesRegisteredTools injects into a registry tool's constructor
     * by type — so a tool assigned in the UI that needs Apps/Companies/Users/Session/
     * Agent/entity (e.g. CreateLeadTool) is instantiated instead of silently skipped.
     *
     * @return list<object>
     */
    #[Override]
    public function toolDependencyCandidates(): array
    {
        return array_values(array_filter([
            $this->app,
            $this->company,
            $this->actingUser(),
            $this->session,
            $this->agent,
            $this->resolveLeadForTurn(),
            $this->entity,
        ]));
    }

    /**
     * Resolve nervous-system rows backed by another Agent instead of a PHP handler.
     */
    protected function resolveRegisteredSubAgentTool(Tool $tool): ?object
    {
        $subAgent = $tool->agent;

        if (
            $subAgent === null
            || ! $subAgent->is_active
            || $subAgent->is_deleted
            || $subAgent->getId() === $this->agent?->getId()
            || $this->user === null
        ) {
            return null;
        }

        return new DynamicSubAgentTool(
            agentRecord: $subAgent,
            entity: $this->entity,
            user: $this->user,
            session: $this->session,
            currentLead: $this->resolveLeadForTurn(),
            threadId: $this->getThreadId(),
        );
    }

    protected function actingUser(): ?Users
    {
        return $this->user;
    }

    /**
     * Apply this agent's context — app, company, and its own acting user — to a list of
     * HasKanvasContext tools, so a subclass can declare its tool suite without repeating the wiring
     * on every line.
     *
     * @param list<object> $tools
     *
     * @return list<ToolInterface>
     */
    protected function addToolContext(array $tools): array
    {
        /** @var list<ToolInterface> $configured */
        $configured = array_map(
            fn (object $tool): object => $tool->withContext($this->app, $this->company, $this->actingUser()),
            $tools,
        );

        return $configured;
    }

    public function resolvedModelName(): string
    {
        return AgentProviderService::resolveModel($this->requireAgent());
    }

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return AgentProviderService::resolve($this->requireAgent());
    }

    /**
     * Sized to the model that will answer, so an agent on a 1M-token model is not held to the budget of
     * the smallest context we run.
     */
    #[Override]
    protected function contextWindow(): int
    {
        return ModelContextWindowService::forAgent($this->agent);
    }

    /**
     * Mirrors Agent::resources() with one difference: the working history is opened with our trimmer.
     * getChatHistory() is final and builds the stock HistoryTrimmer, which would drop both the
     * KanvasTokenCounter image guard (KANVAS-ECOSYSTEM-6HC) and the same-role fold that keeps a turn
     * written by two writers from reaching the model twice. Re-check the parent body on a Neuron bump.
     */
    #[Override]
    protected function resources(): AgentResources
    {
        [$instructions, $tools] = $this->resolveTools();
        [$tools, $this->toolSearchPool] = $this->splitForToolSearch($tools);
        $this->registeredToolNames = self::toolNames($tools);

        $history = new KanvasChatHistory(
            $this->resolveMessageStore(),
            $this->requireWorkflowId(),
            $this->contextWindow ?? $this->contextWindow(),
            KanvasHistoryTrimmer::make(),
        );

        return new AgentResources(
            $this->getProvider(),
            $history,
            $instructions,
            new ToolRegistry($tools),
        );
    }

    /**
     * MCP toolkits move out of the prompt into a pool the model searches (Neuron's
     * ToolSearchMiddleware); the identity set, a handler's hardcoded tools and a reasonable number of
     * catalog grants stay. One MCP toolkit alone was 29K tokens on every round, three quarters of the
     * prompt, for an agent that never called it.
     *
     * @param list<ToolInterface|ProviderToolInterface> $tools
     * @return array{0: list<ToolInterface|ProviderToolInterface>, 1: list<ToolInterface>}
     */
    protected function splitForToolSearch(array $tools): array
    {
        if (! $this->toolSearchActive()) {
            return [$tools, []];
        }

        $searchable = array_diff($this->searchableToolNames(), self::ALWAYS_ON_TOOLS);
        $pooled = static fn (mixed $tool): bool => $tool instanceof ToolInterface && in_array($tool->getName(), $searchable, true);
        $pool = array_values(array_filter($tools, $pooled));

        if (count($pool) < self::TOOL_SEARCH_MIN_POOL) {
            return [$tools, []];
        }

        return [
            array_values(array_filter($tools, static fn (mixed $tool): bool => ! $pooled($tool))),
            $pool,
        ];
    }

    /**
     * @param list<ToolInterface|ProviderToolInterface> $tools
     * @return list<string>
     */
    private static function toolNames(array $tools): array
    {
        $names = [];
        foreach ($tools as $tool) {
            if ($tool instanceof ToolInterface) {
                $names[] = $tool->getName();
            }
        }

        return $names;
    }

    protected function toolSearchActive(): bool
    {
        return $this->app?->getBool(AgentRunConfigurationEnum::TOOL_SEARCH->value, default: true) ?? false;
    }

    /**
     * @return list<string>
     */
    protected function searchableToolNames(): array
    {
        $mcp = property_exists($this, 'mcpToolNames') ? $this->mcpToolNames : [];
        $catalog = property_exists($this, 'catalogToolNames') ? $this->catalogToolNames : [];

        return count($catalog) > self::CATALOG_ALWAYS_ON_LIMIT ? [...$mcp, ...$catalog] : $mcp;
    }

    /**
     * Depends on resources() having run first: the Graph constructor resolves resources before it reads
     * the global middleware, which is what fills the pool. Re-check that order on a Neuron bump.
     *
     * @return WorkflowMiddleware[]
     */
    #[Override]
    protected function globalMiddleware(): array
    {
        return $this->toolSearchPool === [] ? [] : [new KanvasToolSearchMiddleware($this->toolSearchPool)];
    }

    /**
     * @return array<class-string<NodeInterface>, WorkflowMiddleware|WorkflowMiddleware[]>
     */
    #[Override]
    protected function middleware(): array
    {
        $cancel = new CancelsOnRequestMiddleware();
        $middleware = [
            ToolNode::class => [new BoundToolResultsMiddleware(), $cancel],
            ChatNode::class => [$cancel],
        ];

        if ($this->summarizesHistory()) {
            $middleware[ChatNode::class][] = $this->summarization();
        }

        return $middleware;
    }

    /**
     * A model that names a tool it was never given is a recoverable mistake, not a platform fault. The
     * provider answers the parse with an UnknownToolStub (RecoversUnknownToolCalls) so the response
     * loads, but ToolNode resolves the call against the agent's registry, where the stub is not, and
     * throws. Answering that throw with the same feedback is what lets the next round self-correct
     * (KANVAS-ECOSYSTEM-675). Anything else returns null, so it propagates.
     */
    #[Override]
    protected function resolveToolErrorHandler(): ?callable
    {
        return function (Throwable $e, ToolCall $call): ?string {
            if (! $e instanceof ToolException) {
                return null;
            }

            if (in_array($call->getName(), $this->registeredToolNames, true)) {
                return null;
            }

            if (in_array($call->getName(), self::toolNames($this->toolSearchPool), true)) {
                return json_encode([
                    'status' => 'error',
                    'message' => sprintf(
                        'The tool "%s" exists but is not loaded on this round. Call tool_search with the query "%s" first, then call it.',
                        $call->getName(),
                        $call->getName()
                    ),
                ]);
            }

            return json_encode(UnknownToolStub::response($call->getName(), $this->registeredToolNames));
        };
    }

    protected function conversationStore(): MessageStoreInterface
    {
        if ($this->user === null || $this->app === null || $this->company === null) {
            return new InMemoryMessageStore();
        }

        return new ConversationMessageStore(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            agentClass: static::class,
            sessionId: $this->session?->uuid,
            agent: $this->agent,
            turnMedia: $this->turnMedia,
            model: $this->resolvedModelName(),
            privateUserTurn: $this->privateUserTurn,
            participant: KanvasConversationStore::participantFor($this->session, $this->user, $this->agent),
        );
    }

    /**
     * @param string|null $sessionThreadId Narrows the rollup to one session; null loads the record's whole
     *                                     cross-channel history.
     */
    protected function entityRollupStore(?string $sessionThreadId, bool $includeInternal = false): MessageStoreInterface
    {
        if ($this->entity === null || $this->user === null || $this->app === null || $this->company === null) {
            return new InMemoryMessageStore();
        }

        return new EntityRollupMessageStore(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            entity: $this->entity,
            sessionThreadId: $sessionThreadId,
            includeInternal: $includeInternal,
            currentLead: $this->currentLead,
        );
    }

    private function requireAgent(): Agent
    {
        if ($this->agent === null) {
            throw new ValidationException('Agent not set. Call setConfiguration() before invoking the agent.');
        }

        return $this->agent;
    }

    #[Override]
    public function instructions(): string
    {
        return new SystemPrompt(
            background: [
                ...explode("\n", $this->agent?->roleSection('background', "\n") ?? ''),
                ...$this->temporalContextLines($this->resolveTenantTimezone()),
                ...$this->memoryRecallLines(),
                ...self::platformContext(),
            ],
            steps: explode("\n", $this->agent?->roleSection('steps', "\n") ?? ''),
            output: explode("\n", $this->agent?->roleSection('output', "\n") ?? ''),
        )->__toString();
    }

    /**
     * The platform context as a prompt block, for an agent that writes its own `instructions()` and so
     * never reaches the SystemPrompt above.
     *
     * Two of them do (ProjectManagerAgent, ProgrammingAgent), and each was silently exempt from every
     * rule here — including "a deliverable is never the body of a message", which is the one thing
     * that has to hold for every agent or it holds for none.
     */
    protected function platformContextBlock(): string
    {
        return "\n\nHOW WORK IS DONE HERE — this applies to you like every other agent:\n"
            . implode("\n", array_map(fn (string $line): string => '- ' . $line, self::platformContext()));
    }

    /**
     * Where the agent is running. Without it, one that meets a gap fills it from training data — a
     * real agent refused to build a publishing workflow and sent a human off to find n8n/Zapier.
     *
     * Kept to a few lines: it rides on every turn of every agent.
     *
     * @return list<string>
     */
    public static function platformContext(): array
    {
        return [
            'You run inside Kanvas, and Kanvas is the orchestrator. It has its own workflow engine: '
            . 'rules fire on a record and a trigger, run catalog activities, and receivers bring '
            . 'outside traffic in. Integrations (WordPress, WhatsApp, email, CRMs) are configured in '
            . 'Kanvas too.',
            'Never propose Zapier, n8n, Make, cron jobs, or "a developer with API access" for '
            . 'something Kanvas already does, and never call automation impossible because YOU cannot '
            . 'do it.',
            'When you lack a capability, say plainly which Kanvas tool or permission you are missing '
            . 'and ask an administrator to grant it or run it for you. That is a request someone can '
            . 'act on; "reassign to an engineer" is not.',
            'CONTEXT COMES LABELLED. [Company document] is the company\'s own material and the source for '
            . 'facts about it (addresses, hours, policies, prices); [Record history] is this record\'s past; '
            . '[Earlier conversation], [Saved memory] and [Ledger] are what was said or done before. Answer '
            . 'facts from the documents. What the context does not carry, search_knowledge looks up.',
            'WHEN SEVERAL READS ARE INDEPENDENT, REQUEST THEM IN ONE STEP: five plan reads is one tool-call '
            . 'batch, not five rounds. Call the tool whose result answers the question; do not look up the '
            . 'time, the person, your capabilities or the project list first unless the answer depends on it.',
            'ONLY WRITE WHAT YOU WERE ASKED TO. A question is answered, not turned into a plan, a task '
            . 'or a note; a greeting gets a greeting. Never create, assign, comment on or annotate a '
            . 'record the person did not ask for, and when you cannot do what was asked, say so instead '
            . 'of recording the request somewhere else.',
            'NEVER REPORT AN ACTION AS DONE UNLESS THE TOOL SAID IT WAS. A tool result carrying '
            . '"success": false, an "error", or an outcome of denied/not_found/invalid_args means it did '
            . 'NOT happen. Say what was blocked and why, in the same words the tool gave you. Reporting a '
            . 'refused write as done is worse than the refusal: the person stops checking.',
            'A DELIVERABLE IS NEVER THE BODY OF A MESSAGE. When you produce a document — an HTML '
            . 'template, a rendered page, a report, a PDF — put it in Kanvas as a record '
            . '(create_template, then update_template to revise it and generate_template_pdf to render '
            . 'it) or attach it as a file. Then write what you made and NAME it, the way a person sends '
            . 'a link or an attachment rather than pasting two hundred lines into the thread. Name it '
            . 'with a LINK, not an id: call get_file_link on the filesystem_id and hand back what it '
            . 'returns — "Filesystem ID: 10981582" is a lookup you are asking the reader to do.',
            'Never paste markup, code or a document body into a chat message or a plan comment as the '
            . 'deliverable. Nobody can use it there: it cannot be rendered, revised or reused, and it '
            . 'buries the conversation. A short snippet to illustrate a point is fine — the artifact '
            . 'itself is not. If you have no tool to store it, say which one you are missing rather '
            . 'than pasting it anyway.',
        ];
    }
}
