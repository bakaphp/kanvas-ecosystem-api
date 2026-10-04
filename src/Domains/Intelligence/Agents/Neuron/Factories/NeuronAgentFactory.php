<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Factories;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Contracts\BehavesAsKanvasAgent;
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
        ?string $threadId = null,
    ): BehavesAsKanvasAgent {
        return self::fromAgent(
            self::findAgent('name', $name, $app),
            $entity,
            $externalReferenceId,
            $user,
            $threadId,
        );
    }

    public static function fromSlug(
        string $slug,
        Apps $app,
        ?Model $entity = null,
        ?string $externalReferenceId = null,
        ?Users $user = null,
        ?string $threadId = null,
    ): BehavesAsKanvasAgent {
        return self::fromAgent(
            self::findAgent('slug', $slug, $app),
            $entity,
            $externalReferenceId,
            $user,
            $threadId,
        );
    }

    private static function findAgent(string $column, string $value, Apps $app): Agent
    {
        return Agent::where($column, $value)
            ->where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->firstOrFail();
    }

    public static function fromAgent(
        Agent $agent,
        ?Model $entity = null,
        ?string $externalReferenceId = null,
        ?Users $user = null,
        ?string $threadId = null,
    ): BehavesAsKanvasAgent {
        $handlerClass = $agent->type->handler;

        if (! is_subclass_of($handlerClass, BehavesAsKanvasAgent::class)) {
            throw new RuntimeException(
                "Agent handler [{$handlerClass}] must implement " . BehavesAsKanvasAgent::class
            );
        }

        /** @var BehavesAsKanvasAgent $neuronAgent */
        $neuronAgent = new $handlerClass();
        $neuronAgent->setConfiguration(
            agent: $agent,
            entity: $entity,
            externalReferenceId: $externalReferenceId,
            user: $user,
        );

        if ($threadId !== null) {
            $neuronAgent->setThreadId($threadId);
        }

        return $neuronAgent;
    }
}
