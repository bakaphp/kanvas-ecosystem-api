<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Contracts\ProvidesToolDependencies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\CurrentTimeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\DynamicSubAgentTool;
use Kanvas\Intelligence\Agents\Services\AgentProviderService;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Users\Models\Users;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\RAG;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Node;
use Override;

class BaseKanvasAgent extends RAG implements ProvidesToolDependencies
{
    protected ?Agent $agent = null;
    protected ?Apps $app = null;
    protected ?Companies $company = null;
    protected ?Model $entity = null;
    protected ?string $externalReferenceId = null;
    protected ?Users $user = null;
    protected ?string $threadId = null;
    protected ?Session $session = null;
    protected ?Lead $currentLead = null;

    /** @var list<string> Attachment URLs/paths (image/audio/PDF) on the current turn's user prompt. */
    protected array $turnMedia = [];

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

    /**
     * The provider the agent is currently configured to call. Exposed so the image-caption
     * path can describe attachments with the SAME model the agent uses (provider() is
     * protected on the NeuronAI base).
     */
    public function captionProvider(): AIProviderInterface
    {
        return $this->provider();
    }

    // Per-turn lead resolution: the kernel-plumbed currentLead wins; entity-as-Lead
    // is the legacy fallback for sessions that still point directly at a Lead row.
    protected function resolveLeadForTurn(): ?Lead
    {
        return $this->currentLead
            ?? ($this->entity instanceof Lead ? $this->entity : null);
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
        return [
            new CurrentTimeTool(),
        ];
    }

    /**
     * RAG is opt-in. A concrete agent enables it by using a trait that provides
     * leadRagNodes(), such as HasLeadRag.
     *
     * @return list<Node>
     */
    #[Override]
    protected function ragNodes(): array
    {
        return method_exists($this, 'leadRagNodes')
            ? $this->leadRagNodes()
            : [];
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
            background: explode("\n", $role['background'] ?? ''),
            steps: explode("\n", $role['steps'] ?? ''),
            output: explode("\n", $role['output'] ?? ''),
        )->__toString();
    }
}
