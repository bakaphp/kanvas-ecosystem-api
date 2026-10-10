<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Browser\Tools;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Browser Navigate', category: 'Browser')]
class BrowserNavigateTool extends AbstractBrowserTool
{
    protected string $name = 'browser_navigate';

    protected ?string $description = 'Open an absolute public HTTP or HTTPS URL in the current browser page. '
        . 'Returns the final URL and page title. Inspect the page with browser_snapshot afterward.';

    #[Override]
    protected function properties(): array
    {
        return [new ToolProperty('url', PropertyType::STRING, 'Absolute public HTTP or HTTPS URL.', true)];
    }

    public function __invoke(string $url): array
    {
        return $this->safely(fn (): array => $this->session->navigate($url));
    }
}
