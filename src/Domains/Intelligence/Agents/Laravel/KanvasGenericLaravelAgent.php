<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel;

use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Capability\Services\CapabilityProvider;
use Override;
use Stringable;

class KanvasGenericLaravelAgent extends KanvasLaravelAgent
{
    #[Override]
    public function instructions(): Stringable|string
    {
        $parts = array_filter([
            $this->agentRecord?->soul,
            $this->agentRecord?->instructions,
            $this->agentRecord?->output_format,
        ]);

        return implode("\n\n", $parts);
    }

    #[Override]
    public function agentTools(): iterable
    {
        if ($this->agentRecord === null) {
            return [];
        }

        $tools = [];

        foreach (new CapabilityProvider()->getActiveTools($this->agentRecord) as $tool) {
            /** @var Tool $tool */
            if ($tool->handler === null || ! class_exists($tool->handler)) {
                continue;
            }

            $tools[] = new $tool->handler();
        }

        return $tools;
    }
}
