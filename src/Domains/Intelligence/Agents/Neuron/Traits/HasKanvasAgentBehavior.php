<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Traits;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\CurrentTimeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\DynamicSubAgentTool;
use Kanvas\Intelligence\Agents\Services\AgentProviderService;
use Kanvas\Intelligence\Agents\Traits\HasTemporalContext;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Users\Models\Users;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;
use Override;

trait HasKanvasAgentBehavior
{
    use HasTemporalContext;

    protected ?Agent $agent = null;
    protected ?Apps $app = null;
    protected ?Companies $company = null;
    protected ?Model $entity = null;
    protected ?string $externalReferenceId = null;
    protected ?Users $user = null;
    protected ?string $threadId = null;
    protected ?Session $session = null;
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

    public function setConfiguration(
        Agent $agent,
        ?Model $entity = null,
        ?string $externalReferenceId = null,
        ?Users $user = null,
    ): void {
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
        // Global agents (apps_id=0/companies_id=0) have no FK relation; fall back
        // to the user's current company.
        $this->app = $agent->app;
        $this->company = $agent->company ?? $user->getCurrentCompany();
        $this->externalReferenceId = $externalReferenceId;
        $this->user = $user;
    }

    public function setThreadId(string $threadId): void
    {
        $this->threadId = $threadId;
    }

    public function setSession(?Session $session): void
    {
        $this->session = $session;
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
     * Whether this agent's chatHistory already writes each turn to the agent_conversation_messages
     * store. When true, RunNeuronChatAction skips its own logTurn to avoid a duplicate conversation.
     * Default false — SalesAssist-style histories write to Social messages, so logTurn is their only
     * conversation-store record.
     */
    public function persistsTurnsToConversationStore(): bool
    {
        return false;
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

    /**
     * The provider the agent is currently configured to call. Exposed so the image-caption
     * path can describe attachments with the SAME model the agent uses (provider() is
     * protected on the NeuronAI base).
     */
    public function captionProvider(): AIProviderInterface
    {
        return $this->provider();
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
        $tools = parent::getTools();

        foreach ($this->universalTools() as $universal) {
            if (! $this->hasToolNamed($tools, $universal->getName())) {
                $tools[] = $universal;
            }
        }

        // A registry-granted tool (e.g. toggled on in the admin UI) can share its name with one
        // a subclass hardcodes in tools() — the registry merge in MergesRegisteredTools can't see
        // that hardcoded addition since it happens after parent::tools() returns. Keeping the LAST
        // occurrence favors the hardcoded instance, which is always appended after the registry
        // merge in this codebase's array_merge(parent::tools(), [...]) convention.
        return $this->dedupeByName($tools);
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
        ];
    }

    /**
     * The agent's local timezone for time-relative reasoning: company timezone, then user timezone, then
     * UTC. Each is validated as a real IANA zone so a blank/garbage tenant value falls through.
     */
    private function resolveTenantTimezone(): ?string
    {
        foreach ([$this->company?->timezone, $this->user?->timezone] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && in_array($candidate, timezone_identifiers_list(), true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<int, object> $tools
     */
    private function hasToolNamed(array $tools, string $name): bool
    {
        foreach ($tools as $tool) {
            if ($tool instanceof ToolInterface && $tool->getName() === $name) {
                return true;
            }
        }

        return false;
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
            threadId: $this->threadId,
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
        $role = $this->agent->role ?? [];

        return new SystemPrompt(
            background: [
                ...explode("\n", $role['background'] ?? ''),
                ...$this->temporalContextLines($this->resolveTenantTimezone()),
                ...self::platformContext(),
            ],
            steps: explode("\n", $role['steps'] ?? ''),
            output: explode("\n", $role['output'] ?? ''),
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
            'A DELIVERABLE IS NEVER THE BODY OF A MESSAGE. When you produce a document — an HTML '
            . 'template, a rendered page, a report, a PDF — put it in Kanvas as a record '
            . '(create_template, then update_template to revise it and generate_template_pdf to render '
            . 'it) or attach it as a file. Then write what you made and NAME it, the way a person sends '
            . 'a link or an attachment rather than pasting two hundred lines into the thread.',
            'Never paste markup, code or a document body into a chat message or a plan comment as the '
            . 'deliverable. Nobody can use it there: it cannot be rendered, revised or reused, and it '
            . 'buries the conversation. A short snippet to illustrate a point is fine — the artifact '
            . 'itself is not. If you have no tool to store it, say which one you are missing rather '
            . 'than pasting it anyway.',
        ];
    }
}
