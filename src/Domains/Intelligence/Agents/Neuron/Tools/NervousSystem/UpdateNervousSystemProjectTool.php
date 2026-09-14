<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesProjectForTool;
use Kanvas\NervousSystem\Project\Actions\UpdateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Lets the PM manage the PROJECT itself — most importantly, SET THE OBJECTIVE once the humans have
 * given one (the answer to "what's the goal?"), and move the project's own status (done when the
 * objective is reached, blocked/on_hold when it can't move). Without this the PM can organize tasks
 * but never record the objective or close the project.
 */
#[AgentTool(name: 'Update Project', category: 'nervous_system')]
class UpdateNervousSystemProjectTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ResolvesProjectForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'update_nervous_system_project',
            description: 'Update the project itself. Use this to SET or refine the objective once you know '
                . 'it, retitle/rescope the project, move its status (active, on_hold, blocked, done, '
                . 'cancelled, archived), or change its deadline and priority. Set status=done only when the '
                . 'objective has actually been reached. Only pass the fields you want to change.',
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
                description: 'New status: active | draft | on_hold | blocked | done | cancelled | archived '
                    . '(optional).',
                required: false,
            ),
            new ToolProperty(
                name: 'deadline_at',
                type: PropertyType::STRING,
                description: 'New due date, ISO-8601 (e.g. 2026-09-30). Pass "none" to clear it.',
                required: false,
            ),
            new ToolProperty(
                name: 'priority',
                type: PropertyType::INTEGER,
                description: 'New priority; higher runs first.',
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
        ?string $deadline_at = null,
        ?int $priority = null,
    ): array {
        $project = $this->resolveProjectOrError($project_id);

        if (is_array($project)) {
            return $project;
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
        if ($deadline_at !== null) {
            // forUpdate keeps the current deadline unless the key is present, so clearing one needs a
            // value the model can actually type.
            $data['deadline_at'] = in_array(strtolower(trim($deadline_at)), ['none', 'null', ''], true)
                ? null
                : $deadline_at;
        }
        if ($priority !== null) {
            $data['priority'] = $priority;
        }

        try {
            $updated = new UpdateProjectAction(
                $project,
                ProjectData::forUpdate(
                    $project,
                    $this->app,
                    $this->company,
                    $this->user,
                    $data,
                ),
            )->execute();
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'project_id' => $updated->getId(),
            'title' => $updated->title,
            'objective' => $updated->objective,
            'status' => $updated->status,
            'deadline_at' => $updated->deadline_at?->toDateString(),
            'priority' => $updated->priority,
        ];
    }
}
