<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Browser\Tools;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserSession;

#[AgentTool(name: 'Browser Snapshot', category: 'Browser')]
class BrowserSnapshotTool extends AbstractBrowserTool
{
    public function __construct(BrowserSession $session)
    {
        parent::__construct(
            $session,
            'browser_snapshot',
            'Inspect the current page and return compact visible text plus interactive elements with numeric IDs. '
                . 'Use this before clicking or typing. IDs are valid only for the latest snapshot.',
        );
    }

    public function __invoke(): array
    {
        return $this->safely(fn (): array => $this->session->snapshot());
    }
}
