<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel;

use Kanvas\Intelligence\Agents\Laravel\SubAgents\DynamicSubAgent;
use Kanvas\Intelligence\Agents\Models\Agent as AgentRecord;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Override;
use Stringable;

class KanvasGenericLaravelAgent extends KanvasLaravelAgent
{
    use MergesRegisteredTools;

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
        return $this->resolveRegisteredTools(
            $this->agentRecord,
            CapabilityFrameworkEnum::LARAVEL
        );
    }

    /**
     * Laravel handlers support "agent-as-tool" — a registry row whose
     * agents_id points at another Agent. Wrap it as a DynamicSubAgent.
     * Everything else falls through to the standard handler instantiation.
     */
    protected function resolveRegisteredTool(Tool $tool): ?object
    {
        if ($tool->agents_id !== null) {
            $subAgentRecord = AgentRecord::find($tool->agents_id);

            return $subAgentRecord !== null ? new DynamicSubAgent($subAgentRecord) : null;
        }

        return $this->defaultRegisteredToolResolver($tool);
    }
}
