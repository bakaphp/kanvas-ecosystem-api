<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\SubAgents;

use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\KanvasAgentAsTool;
use Kanvas\Intelligence\Agents\Models\Agent as AgentRecord;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Capability\Services\CapabilityProvider;

#[AgentTool(name: 'Dynamic Sub Agent')]
class DynamicSubAgent extends KanvasAgentAsTool
{
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
        $tools = [];

        foreach (new CapabilityProvider()->getActiveTools($this->agentRecord) as $tool) {
            /** @var Tool $tool */
            if ($tool->agents_id !== null) {
                $subRecord = AgentRecord::find($tool->agents_id);
                if ($subRecord) {
                    $tools[] = new self($subRecord);
                }
                continue;
            }

            if ($tool->handler === null || ! class_exists($tool->handler)) {
                continue;
            }

            $tools[] = new $tool->handler();
        }

        return $tools;
    }
}
