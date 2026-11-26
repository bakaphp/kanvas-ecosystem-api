<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Agents\Concerns;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Services\AgentProviderService;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolRegistry;

/**
 * For a `BaseKanvasAgent` that does one job per call (transcribe this audio, write these notes): one
 * message in, one reply out. No tools, no memory, no stored history, so none of the base agent's
 * conversation machinery is wired, and no Agent row is needed: the caller hands it the provider.
 */
trait RunsSingleTurn
{
    private ?AIProviderInterface $singleTurnProvider = null;
    private string $singleTurnProviderName = 'gemini';

    public function runWith(AIProviderInterface $provider, string $providerName): static
    {
        $this->singleTurnProvider = $provider;
        $this->singleTurnProviderName = $providerName;

        return $this;
    }

    public function providerName(): string
    {
        return $this->singleTurnProviderName;
    }

    public function resolvedModelName(): string
    {
        return $this->provider()->getModel();
    }

    protected function provider(): AIProviderInterface
    {
        if ($this->singleTurnProvider !== null) {
            return $this->singleTurnProvider;
        }

        // Reached through the chat kernel (someone messaging a hired agent of this type): it answers
        // on its own provider, still with no tools or memory.
        return $this->agent !== null
            ? AgentProviderService::resolve($this->agent)
            : throw new ValidationException('Call runWith() before running ' . static::class . '.');
    }

    /**
     * The window is far above any model's: the turn is the whole input (base64 audio, a full
     * transcript) and the history must never trim it out of its own request.
     */
    protected function resources(): AgentResources
    {
        return new AgentResources(
            $this->getProvider(),
            new ChatHistory(new InMemoryMessageStore(), $this->requireWorkflowId(), 1_000_000_000),
            new SystemMessage($this->instructions()),
            new ToolRegistry(),
        );
    }

    public function getTools(): array
    {
        return [];
    }

    protected function globalMiddleware(): array
    {
        return [];
    }

    protected function middleware(): array
    {
        return [];
    }

    protected function remembersForCompany(): bool
    {
        return false;
    }
}
