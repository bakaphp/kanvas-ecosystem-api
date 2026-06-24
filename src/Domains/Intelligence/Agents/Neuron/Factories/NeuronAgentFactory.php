<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Factories;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use Kanvas\Users\Models\Users;
use RuntimeException;

class NeuronAgentFactory
{
    public static function fromName(
        string $name,
        Apps $app,
        ?Model $entity = null,
        ?string $externalReferenceId = null,
        ?Users $user = null,
    ): BaseKanvasAgent {
        $agent = Agent::where('name', $name)
            ->where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->firstOrFail();

        return self::fromAgent($agent, $entity, $externalReferenceId, $user);
    }

    public static function fromSlug(
        string $slug,
        Apps $app,
        ?Model $entity = null,
        ?string $externalReferenceId = null,
        ?Users $user = null,
    ): BaseKanvasAgent {
        $agent = Agent::where('slug', $slug)
            ->where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->firstOrFail();

        return self::fromAgent($agent, $entity, $externalReferenceId, $user);
    }

    public static function fromAgent(
        Agent $agent,
        ?Model $entity = null,
        ?string $externalReferenceId = null,
        ?Users $user = null,
    ): BaseKanvasAgent {
        $handlerClass = $agent->type->handler;

        if (! is_subclass_of($handlerClass, BaseKanvasAgent::class)) {
            throw new RuntimeException(
                "Agent handler [{$handlerClass}] must extend " . BaseKanvasAgent::class
            );
        }

        /** @var BaseKanvasAgent $neuronAgent */
        $neuronAgent = new $handlerClass();
        $neuronAgent->setConfiguration(
            agent: $agent,
            entity: $entity,
            externalReferenceId: $externalReferenceId,
            user: $user,
        );

        return $neuronAgent;
    }
}
