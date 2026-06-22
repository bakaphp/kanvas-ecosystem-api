<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use NeuronAI\Agent\Agent as NeuronAIAgent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Gemini\Gemini;
use Override;

class BaseKanvasAgent extends NeuronAIAgent
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
     * Per-turn "which deal is the conversation about right now" — independent
     * of the session entity (People-keyed). Sourced from the request's lead_id
     * by AgentChatKernel every turn.
     */
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
     * The concrete model this agent will call (agent config → app default →
     * hard default). Exposed so the chat path can record it on the turn for
     * usage/cost rollups.
     */
    public function resolvedModelName(): string
    {
        $config = $this->agent->config ?? [];

        return $config['model'] ?? $this->app->get(ConfigurationEnum::GEMINI_MODEL->value) ?? 'gemini-2.5-pro';
    }

    #[Override]
    protected function provider(): AIProviderInterface
    {
        $config = $this->agent->config ?? [];
        $key = $config['key'] ?? $this->app->get(ConfigurationEnum::GEMINI_KEY->value);
        $model = $this->resolvedModelName();

        if (! is_string($key) || $key === '') {
            throw new ValidationException(
                'Gemini API key is not configured for this agent or app.'
                . ' Set agent.config.key or the app ' . ConfigurationEnum::GEMINI_KEY->value . ' setting.'
            );
        }

        return new Gemini(
            key: $key,
            model: $model,
        );
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
