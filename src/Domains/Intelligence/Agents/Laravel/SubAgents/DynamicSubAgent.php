<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\SubAgents;

use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\KanvasAgentAsTool;
use Kanvas\Intelligence\Agents\Models\Agent as AgentRecord;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;

#[AgentTool(name: 'Dynamic Sub Agent')]
class DynamicSubAgent extends KanvasAgentAsTool
{
    use MergesRegisteredTools;

    public function __construct(private readonly AgentRecord $agentRecord)
    {
    }

    public function name(): string
    {
        return Str::snake($this->agentRecord->name);
    }

    public function description(): string
    {
        return $this->agentRecord->soul ?? $this->agentRecord->description ?? $this->agentRecord->name;
    }

    public function instructions(): string
    {
        $type = $this->agentRecord->type;
        $coalesce = static fn (?string $a, ?string $b): ?string => ($a !== null && $a !== '') ? $a : $b;

        $parts = array_filter([
            $coalesce($this->agentRecord->soul, $type?->soul),
            $coalesce($this->agentRecord->instructions, $type?->instructions),
            $coalesce($this->agentRecord->output_format, $type?->output_format),
        ]);

        return implode("\n\n", $parts);
    }

    public function agentTools(): iterable
    {
        return $this->resolveRegisteredTools(
            $this->agentRecord,
            CapabilityFrameworkEnum::LARAVEL
        );
    }

    /**
     * Sub-agents can themselves point at further sub-agents — wrap recursively
     * as another DynamicSubAgent so the chain works. Standard handlers fall
     * through to the default resolver.
     */
    protected function resolveRegisteredTool(Tool $tool): ?object
    {
        if ($tool->agents_id !== null) {
            $subRecord = AgentRecord::find($tool->agents_id);

            return $subRecord !== null ? new self($subRecord) : null;
        }

        return $this->defaultRegisteredToolResolver($tool);
    }
}
