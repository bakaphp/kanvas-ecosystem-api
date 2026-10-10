<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Browser\Tools;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;

#[AgentTool(name: 'Browser Snapshot', category: 'Browser')]
class BrowserSnapshotTool extends AbstractBrowserTool
{
    protected string $name = 'browser_snapshot';

    protected ?string $description = 'Inspect the current page and return compact visible text plus interactive '
        . 'elements with numeric IDs. Use this before clicking or typing. IDs are valid only for the latest snapshot.';

    public function __invoke(): array
    {
        return $this->safely(fn (): array => $this->session->snapshot());
    }
}
