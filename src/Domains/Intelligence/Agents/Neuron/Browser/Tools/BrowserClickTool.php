<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Browser\Tools;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Browser Click', category: 'Browser')]
class BrowserClickTool extends AbstractBrowserTool
{
    protected string $name = 'browser_click';

    protected ?string $description = 'Click an interactive element using an ID from the latest browser_snapshot. '
        . 'Take a new snapshot after clicking.';

    #[Override]
    protected function properties(): array
    {
        return [new ToolProperty('element_id', PropertyType::INTEGER, 'Element ID from the latest snapshot.', true)];
    }

    public function __invoke(int $element_id): array
    {
        return $this->safely(fn (): array => $this->session->click($element_id));
    }
}
