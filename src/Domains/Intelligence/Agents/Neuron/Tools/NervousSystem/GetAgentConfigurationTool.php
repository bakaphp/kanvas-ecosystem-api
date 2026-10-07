<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RunsInExplicitCompany;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Get Agent Configuration', category: 'nervous_system')]
class GetAgentConfigurationTool extends Tool
{
    use HasKanvasContext;
    use GuardsAdminForTool;
    use RunsInExplicitCompany;
    use ReportsToolOutcome;
    use TrackByInputs;

    protected string $name = 'get_agent_configuration';

    protected ?string $description = 'Read an agent\'s name, persona, instructions, type and selected tool references for '
        . 'configuration copying. Admin only. Does not export credentials, provider config or runtime state. '
        . 'IDs identify the source; resolve destination IDs before copying. Private sub-agent tools '
        . 'must be recreated in the destination. This is not a complete export.';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'agent_id',
                type: PropertyType::INTEGER,
                description: 'Agent ID in the selected company.',
                required: true,
            ),
            $this->companyUuidProperty(),
        ];
    }

    public function __invoke(int $agent_id, ?string $company_uuid = null): array
    {
        if ($company_uuid !== null) {
            return $this->inExplicitCompany($company_uuid, $this->contextAgent(), fn (self $tool): array => $tool($agent_id));
        }
        if (! $this->hasTenantContext()) {
            return $this->denied('Company context is required.');
        }
        if ($denied = $this->requireRequestingAdminOrError()) {
            return $this->denied($denied['message']);
        }

        try {
            $agent = Agent::query()->fromApp($this->app)->fromCompany($this->company)->notDeleted()
                ->with(['type', 'selectedTools'])->whereKey($agent_id)->first();
            if ($agent === null) {
                return $this->notFound(['success' => false, 'message' => 'No agent with that ID exists in the selected company.']);
            }

            return $this->ok([
                'agent' => $agent->only(['id', 'uuid', 'name', 'role', 'description', 'soul', 'instructions', 'output_format', 'is_sub_agent', 'is_active', 'parent_id', 'user_id']),
                'agent_type' => $agent->type?->name,
                'tools' => $agent->selectedTools->map(fn ($tool): array => $tool->only(['id', 'name', 'agents_id', 'tool_type']))->all(),
                'excluded' => ['config', 'identity', 'user_context', 'tools_config', 'voice_config', 'credentials', 'runtime_state'],
            ]);
        } catch (Throwable $e) {
            report($e);

            return $this->failed('Could not read agent configuration.');
        }
    }
}
