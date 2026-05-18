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
        // Per-field fallback to the AgentType template: an agent that
        // leaves any of soul/instructions/output_format blank inherits
        // that field from its type, so the type acts as a base persona
        // the agent can override piece by piece.
        $type = $this->agentRecord?->type;
        $coalesce = static fn (?string $a, ?string $b): ?string => ($a !== null && $a !== '') ? $a : $b;

        $parts = array_filter([
            $coalesce($this->agentRecord?->soul, $type?->soul),
            $coalesce($this->agentRecord?->instructions, $type?->instructions),
            $coalesce($this->agentRecord?->output_format, $type?->output_format),
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
