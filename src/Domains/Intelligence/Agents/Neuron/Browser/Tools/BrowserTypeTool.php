<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Browser\Tools;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Browser Type', category: 'Browser')]
class BrowserTypeTool extends AbstractBrowserTool
{
    protected string $name = 'browser_type';

    protected ?string $description = 'Replace the content of an editable element using an ID from the latest '
        . 'browser_snapshot.';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty('element_id', PropertyType::INTEGER, 'Element ID from the latest snapshot.', true),
            new ToolProperty('text', PropertyType::STRING, 'Text to place in the element.', true),
        ];
    }

    public function __invoke(int $element_id, string $text): array
    {
        return $this->safely(fn (): array => $this->session->type($element_id, $text));
    }
}
