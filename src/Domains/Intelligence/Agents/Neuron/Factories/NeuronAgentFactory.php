<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Factories;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Contracts\BehavesAsKanvasAgent;
use Kanvas\Users\Models\Users;
use RuntimeException;

class NeuronAgentFactory
{
    /**
     * @param string $threadId The conversation's address; Neuron refuses to run an unbound agent.
     */
    public static function fromAgent(
        Agent $agent,
        string $threadId,
        ?Model $entity = null,
        ?Users $user = null,
    ): BehavesAsKanvasAgent {
        $handlerClass = $agent->type->handler;

        if (! is_subclass_of($handlerClass, BehavesAsKanvasAgent::class)) {
            throw new RuntimeException(
                "Agent handler [{$handlerClass}] must implement " . BehavesAsKanvasAgent::class
            );
        }

        /** @var BehavesAsKanvasAgent $neuronAgent */
        $neuronAgent = new $handlerClass();
        $neuronAgent->setConfiguration(agent: $agent, entity: $entity, user: $user);

        $neuronAgent->setThreadId($threadId);

        return $neuronAgent;
    }
}
