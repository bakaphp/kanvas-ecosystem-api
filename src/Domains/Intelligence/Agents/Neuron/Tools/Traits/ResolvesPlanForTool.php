<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\NervousSystem\Plan\Models\Plan;

/**
 * Look up a Nervous System Plan by id, scoped to the tool's app + company (from HasKanvasContext),
 * returning either the Plan OR a structured error array the LLM can act on — so a hallucinated
 * plan_id never crashes the chat with an unhandled null-dereference.
 *
 * Pattern:
 *
 *   $result = $this->resolvePlanOrError($plan_id);
 *   if (is_array($result)) {
 *       return $result;     // tool returns the structured error to the agent
 *   }
 *   $plan = $result;        // typed Plan from here on
 */
trait ResolvesPlanForTool
{
    /**
     * @return Plan|array{error: string}
     */
    protected function resolvePlanOrError(int $id, ?string $notFoundMessage = null): Plan|array
    {
        $plan = Plan::query()
            ->where('id', $id)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        if ($plan instanceof Plan) {
            return $plan;
        }

        return ['error' => $notFoundMessage ?? "Plan {$id} was not found."];
    }
}
