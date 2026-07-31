<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Project\Actions\UpdateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Models\Project;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Lets the PM manage the PROJECT itself — most importantly, SET THE OBJECTIVE once the humans have
 * given one (the answer to "what's the goal?"), and move the project's own status (done when the
 * objective is reached, blocked/on_hold when it can't move). Without this the PM can organize tasks
 * but never record the objective or close the project.
 */
#[AgentTool(name: 'Update Project', category: 'nervous_system')]
class UpdateNervousSystemProjectTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'update_nervous_system_project',
            description: 'Update the project itself. Use this to SET or refine the objective once you know '
                . 'it, retitle/rescope the project, or move its status (active, on_hold, blocked, done, '
                . 'cancelled). Set status=done only when the objective has actually been reached. Only pass '
                . 'the fields you want to change.',
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
                name: 'project_id',
                type: PropertyType::INTEGER,
                description: 'The project to update (from the Context).',
                required: true,
            ),
            new ToolProperty(
                name: 'objective',
                type: PropertyType::STRING,
                description: 'The project objective / definition of done. Set this once the humans give you one.',
                required: false,
            ),
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'New project title (optional).',
                required: false,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'New long-form description (optional).',
                required: false,
            ),
            new ToolProperty(
                name: 'status',
                type: PropertyType::STRING,
                description: 'New status: active | on_hold | blocked | done | cancelled (optional).',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $project_id,
        ?string $objective = null,
        ?string $title = null,
        ?string $description = null,
        ?string $status = null,
    ): array {
        $project = Project::query()
            ->where('id', $project_id)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        if ($project === null) {
            return ['error' => "Project {$project_id} was not found."];
        }

        $data = [];
        if ($objective !== null) {
            $data['objective'] = $objective;
        }
        if ($title !== null) {
            $data['title'] = $title;
        }
        if ($description !== null) {
            $data['description'] = $description;
        }
        if ($status !== null) {
            $data['status'] = $status;
        }

        try {
            $updated = new UpdateProjectAction(
                $project,
                ProjectData::forUpdate($project, $this->app, $this->company, $this->user, $data),
            )->execute();
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'project_id' => $updated->getId(),
            'title' => $updated->title,
            'objective' => $updated->objective,
            'status' => $updated->status,
        ];
    }
}
