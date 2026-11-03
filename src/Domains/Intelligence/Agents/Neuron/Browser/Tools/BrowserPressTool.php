<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Browser\Tools;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Browser Press', category: 'Browser')]
class BrowserPressTool extends AbstractBrowserTool
{
    private const KEYS = ['Enter', 'Escape', 'Tab', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'];

    protected string $name = 'browser_press';

    protected ?string $description = 'Send one allowed keyboard key to an element from the latest browser_snapshot. '
        . 'Use Enter to submit forms or confirm autocomplete choices.';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty('element_id', PropertyType::INTEGER, 'Element ID from the latest snapshot.', true),
            new ToolProperty('key', PropertyType::STRING, 'Keyboard key to send.', true, self::KEYS),
        ];
    }

    public function __invoke(int $element_id, string $key): array
    {
        return $this->safely(fn (): array => $this->session->press($element_id, $key));
    }
}
