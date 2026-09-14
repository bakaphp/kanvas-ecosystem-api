<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\NervousSystem\Project\Models\Project;

/**
 * Look up a Nervous System Project by id, scoped to the tool's app + company (from HasKanvasContext),
 * returning either the Project OR a structured error array the LLM can act on — so a hallucinated
 * project_id never crashes the chat, and a foreign one resolves to nothing rather than another
 * tenant's board.
 *
 * Pattern:
 *
 *   $result = $this->resolveProjectOrError($project_id);
 *   if (is_array($result)) {
 *       return $result;     // tool returns the structured error to the agent
 *   }
 *   $project = $result;     // typed Project from here on
 */
trait ResolvesProjectForTool
{
    /**
     * @return Project|array{error: string}
     */
    protected function resolveProjectOrError(int $id, ?string $notFoundMessage = null): Project|array
    {
        $project = Project::query()
            ->where('id', $id)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        if ($project instanceof Project) {
            return $project;
        }

        return ['error' => $notFoundMessage ?? "Project {$id} was not found."];
    }
}
