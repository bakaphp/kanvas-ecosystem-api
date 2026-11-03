<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Browser;

use Kanvas\Intelligence\Agents\Neuron\Browser\Tools\BrowserClickTool;
use Kanvas\Intelligence\Agents\Neuron\Browser\Tools\BrowserNavigateTool;
use Kanvas\Intelligence\Agents\Neuron\Browser\Tools\BrowserPressTool;
use Kanvas\Intelligence\Agents\Neuron\Browser\Tools\BrowserSnapshotTool;
use Kanvas\Intelligence\Agents\Neuron\Browser\Tools\BrowserTypeTool;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use Override;

class BrowserToolkit extends AbstractToolkit
{
    public function __construct(private readonly BrowserSession $session)
    {
    }

    #[Override]
    public function guidelines(): ?string
    {
        return <<<'GUIDELINES'
            You control a real web browser.
            Use browser_navigate to open a website.
            Use browser_snapshot to inspect the page before interacting with it.
            browser_snapshot returns numeric element IDs; only use IDs from the latest snapshot.
            Use browser_click to click, browser_type to replace text, and browser_press for keyboard interaction.
            Never invent element IDs. If an element is stale, take a new snapshot.
            Do not invent information that is not visible in the browser.
            GUIDELINES;
    }

    #[Override]
    public function provide(): array
    {
        return [
            new BrowserNavigateTool($this->session),
            new BrowserSnapshotTool($this->session),
            new BrowserClickTool($this->session),
            new BrowserTypeTool($this->session),
            new BrowserPressTool($this->session),
        ];
    }

    public function close(): void
    {
        $this->session->close();
    }
}
