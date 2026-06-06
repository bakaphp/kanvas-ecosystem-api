<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Laravel\SubAgents\DynamicSubAgent;
use Kanvas\Intelligence\Agents\Models\Agent as AgentRecord;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Override;
use Stringable;

#[AgentTypeDefinition(
    name: 'Generic Laravel Agent',
    description: 'Generic agent using the Laravel-AI runtime — picks the LLM provider from the agent\'s model config. Use for fast tests of the Laravel-AI path.',
    provider: 'laravel',
    soul: 'You are a helpful, concise assistant running inside Kanvas via the Laravel-AI runtime.',
    outputFormat: 'Plain text. Use short paragraphs; use lists only when enumerating distinct items.',
)]
class KanvasGenericLaravelAgent extends KanvasLaravelAgent
{
    use MergesRegisteredTools;

    #[Override]
    public function instructions(): Stringable|string
    {
        return $this->instructionsFromRecord();
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
