<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesProjectForTool;
use Kanvas\NervousSystem\Project\Actions\DeleteProjectAction;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Removes a project that should not exist — a duplicate, or one opened in error. Soft-deletes through
 * DeleteProjectAction, which cascades to the project's plans, sub-projects and members, so this is the
 * widest-blast-radius board tool the PM holds: the returned counts say what went with it.
 *
 * Deliberately NOT the way to finish or drop real work — a project that happened has to stay readable,
 * so done/cancelled/archived via update_nervous_system_project is the answer there. The description
 * says so because the model, not a caller, is what picks between them.
 */
#[AgentTool(name: 'Delete Project', category: 'nervous_system')]
class DeleteNervousSystemProjectTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ResolvesProjectForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'delete_nervous_system_project',
            description: 'Delete a project along with its plans, tasks, sub-projects and members. Use ONLY '
                . 'for a project that should not exist — a duplicate, or one opened by mistake. For work '
                . 'that is finished, abandoned or paused, use update_nervous_system_project with '
                . 'status=done, cancelled, archived or on_hold instead — never delete it.',
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
                description: 'The project to delete.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $project_id): array
    {
        if (! $this->hasTenantContext()) {
            return ['error' => 'This agent has no company context, so it cannot delete a project.'];
        }

        $project = $this->resolveProjectOrError($project_id);

        if (is_array($project)) {
            return $project;
        }

        // Counted before the cascade runs — afterwards they're soft-deleted and no longer countable.
        $planCount = $project->plans()->notDeleted()->count();
        $title = $project->title;

        try {
            $deleted = new DeleteProjectAction($project)->execute();
        } catch (Throwable $e) {
            report($e);

            return ['error' => $e->getMessage()];
        }

        return [
            'project_id' => $project_id,
            'title' => $title,
            'deleted' => $deleted,
            'plans_deleted' => $planCount,
            'message' => 'Deleted with its plans, tasks and members. Do not recreate it unless the work '
                . 'genuinely starts again.',
        ];
    }
}
