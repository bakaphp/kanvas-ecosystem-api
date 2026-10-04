<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Concerns;

use Kanvas\Intelligence\Agents\Neuron\Middleware\KanvasSummarization;

/**
 * The rolling summary an agent on the conversation store keeps instead of forgetting. Requires the
 * HasKanvasAgentBehavior properties.
 */
trait SummarizesHistory
{
    /**
     * The rollup and channel stores hold Social rows other writers own and rebuild their window from
     * the database each load, so they never summarize.
     */
    protected function summarizesHistory(): bool
    {
        return $this->ownsTranscript();
    }

    protected function summarization(): KanvasSummarization
    {
        return new KanvasSummarization(
            agent: $this->requireAgent(),
            session: $this->session,
            fallbackAuthor: $this->user,
            model: $this->resolvedModelName(),
            maxTokens: $this->summarizationMaxTokens(),
            messagesToKeep: $this->summarizationMessagesToKeep(),
        );
    }

    /**
     * Below the window, not at it: the trimmer cuts at the window and forgets, the summary has to run
     * first and keep the thread.
     */
    protected function summarizationMaxTokens(): int
    {
        return (int) ($this->contextWindow() * 0.8);
    }

    protected function summarizationMessagesToKeep(): int
    {
        return 8;
    }
}
