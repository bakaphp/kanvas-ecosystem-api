<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\NervousSystem\Plan\Models\Task;

/**
 * Look up a Nervous System Task by id, scoped to the tool's app + company (from HasKanvasContext),
 * returning either the Task OR a structured error array the LLM can act on — so a hallucinated
 * task_id never crashes the chat with an unhandled null-dereference.
 *
 * Pattern:
 *
 *   $result = $this->resolveTaskOrError($task_id);
 *   if (is_array($result)) {
 *       return $result;     // tool returns the structured error to the agent
 *   }
 *   $task = $result;        // typed Task from here on
 */
trait ResolvesTaskForTool
{
    /**
     * @return Task|array{error: string}
     */
    protected function resolveTaskOrError(int $id, ?string $notFoundMessage = null): Task|array
    {
        $task = Task::query()
            ->where('id', $id)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        if ($task instanceof Task) {
            return $task;
        }

        return ['error' => $notFoundMessage ?? "Task {$id} was not found."];
    }
}
