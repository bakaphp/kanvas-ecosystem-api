<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Browser;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use Override;

/** Minimal agent used by integration tests to exercise Neuron's real tool loop. */
class BrowserTestAgent extends Agent
{
    public function __construct(
        private readonly AIProviderInterface $aiProvider,
        private readonly BrowserToolkit $browserToolkit,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return $this->aiProvider;
    }

    #[Override]
    public function instructions(): string
    {
        return 'Use the browser tools to navigate and inspect pages. Report only information visible in snapshots.';
    }

    #[Override]
    protected function tools(): array
    {
        return [$this->browserToolkit];
    }

    public function close(): void
    {
        $this->browserToolkit->close();
    }
}
