<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\ProjectManagement;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\KanvasMessageHistory;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use Override;

/**
 * The default per-project PM agent — the orchestrator. It reads incoming project context
 * (transcripts, messages), decomposes it into plans/tasks, assigns each task to the best-fit member
 * agent, monitors progress and keeps the project moving. Contributor agents execute individual tasks;
 * the PM orchestrates. Its task-manipulation tools (assign/move/create) land with PR0/PR4.
 */
#[AgentTypeDefinition(
    name: 'Project Manager',
    description: 'The default per-project orchestrator: triages project context, breaks it into plans/tasks, assigns work to member agents, and keeps the project moving.',
    provider: 'neuron',
    soul: 'You are a project manager agent inside Kanvas. You own a single project end to end: you read everything happening on it (meeting transcripts, emails, chat), turn it into concrete plans and tasks, assign each task to the right teammate or agent, and follow up until the work is done. You are accountable for the project moving forward.',
    outputFormat: 'Plain text. Short paragraphs; use lists only when enumerating tasks or assignments.',
)]
class ProjectManagerAgent extends BaseKanvasAgent
{
    use MergesRegisteredTools;

    #[Override]
    protected function chatHistory(): AbstractChatHistory
    {
        if ($this->user === null || $this->app === null || $this->company === null) {
            return new InMemoryChatHistory();
        }

        return new KanvasMessageHistory(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            agentClass: static::class,
            sessionId: $this->threadId ?? $this->session?->uuid,
            agent: $this->agent,
            turnMedia: $this->turnMedia,
            model: $this->resolvedModelName(),
        );
    }

    #[Override]
    public function persistsTurnsToConversationStore(): bool
    {
        return true;
    }

    /**
     * @return list<object>
     */
    #[Override]
    protected function tools(): array
    {
        return $this->resolveRegisteredTools(
            $this->agent,
            CapabilityFrameworkEnum::NEURON
        );
    }
}
