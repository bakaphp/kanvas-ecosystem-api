<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources;

use Kanvas\HumanResources\Departments\Models\Department;
use Kanvas\HumanResources\Positions\Actions\CreatePositionAction;
use Kanvas\HumanResources\Positions\DataTransferObject\Position as PositionData;
use Kanvas\HumanResources\Positions\Models\Position;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPositionAndDepartmentForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Creates a job position/title so onboarding isn't blocked when the role doesn't exist yet — the agent
 * calls this, then retries create_employee with the same title. Admin only. Idempotent: returns the
 * existing position when a title already exists rather than duplicating it.
 */
#[AgentTool(name: 'Create Position', category: 'human_resources')]
class CreatePositionTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ResolvesPositionAndDepartmentForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'create_position',
            description: 'Creates a job position/title (optionally in a department, with a level). Admin only. Use '
                . 'this when create_employee reports the position does not exist, then retry create_employee with the '
                . 'same title. Returns the existing position if the title already exists (no duplicate).',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'The position/job title, e.g. "Junior Backend Developer".',
                required: true,
            ),
            new ToolProperty(
                name: 'department_name',
                type: PropertyType::STRING,
                description: 'Optional department name to attach the position to.',
                required: false,
            ),
            new ToolProperty(
                name: 'level',
                type: PropertyType::STRING,
                description: 'Optional seniority level, e.g. "junior", "senior", "lead".',
                required: false,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Optional description of the role.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $title,
        ?string $department_name = null,
        ?string $level = null,
        ?string $description = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        $existing = $this->findPositionByTitle($title);
        if ($existing instanceof Position) {
            return [
                'created' => false,
                'already_exists' => true,
                'position_id' => $existing->getId(),
                'title' => $existing->title,
                'message' => 'A position with this title already exists — use it as-is.',
            ];
        }

        $department = $this->findDepartmentByName($department_name);

        $position = new CreatePositionAction(
            new PositionData(
                app: $this->app,
                company: $this->company,
                user: $this->user,
                title: $title,
                department: $department instanceof Department ? $department : null,
                level: $level,
                description: $description,
            ),
        )->execute();

        return [
            'created' => true,
            'position_id' => $position->getId(),
            'title' => $position->title,
            'department' => $department?->name,
            'level' => $position->level,
        ];
    }
}
