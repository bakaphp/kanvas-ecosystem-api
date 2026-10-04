<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\SubAgents;

use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\KanvasAgentAsTool;
use Kanvas\Intelligence\Agents\Models\Agent as AgentRecord;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;

#[AgentTool(
    name: 'Dynamic Sub Agent',
    description: 'Delegate a question to one of the company\'s own configured agents, described by that agent\'s record at runtime.',
    category: 'crm',
)]
class DynamicSubAgent extends KanvasAgentAsTool
{
    use MergesRegisteredTools;

    public function __construct(private readonly AgentRecord $agentRecord)
    {
    }

    /**
     * The record name is free text typed by a tenant. Slugging drops accents and punctuation; a name that
     * still is not a function name (it opens with a digit, or is empty) falls back to the record id.
     */
    public function name(): string
    {
        $slug = Str::substr(Str::slug($this->agentRecord->name, '_'), 0, 64);

        return preg_match(KanvasToolInterface::FUNCTION_NAME_PATTERN, $slug) === 1
            ? $slug
            : 'sub_agent_' . $this->agentRecord->getId();
    }

    public function description(): string
    {
        return $this->agentRecord->soul ?? $this->agentRecord->description ?? $this->agentRecord->name;
    }

    public function instructions(): string
    {
        return $this->agentRecord->personaPrompt();
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
