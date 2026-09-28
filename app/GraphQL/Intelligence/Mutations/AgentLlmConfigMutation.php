<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Intelligence\Agents\Actions\CreateAgentLlmConfigAction;
use Kanvas\Intelligence\Agents\Actions\UpdateAgentLlmConfigAction;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentLlmConfig as AgentLlmConfigData;
use Kanvas\Intelligence\Agents\Models\AgentLlmConfig;

class AgentLlmConfigMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): AgentLlmConfig
    {
        $ctx = $this->actingContext();

        return new CreateAgentLlmConfigAction(
            AgentLlmConfigData::from($ctx->app, $ctx->user, $ctx->company, $request['input']),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): AgentLlmConfig
    {
        $ctx = $this->actingContext();

        /** @var AgentLlmConfig $config */
        $config = AgentLlmConfig::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        return new UpdateAgentLlmConfigAction(
            $config,
            AgentLlmConfigData::forUpdate($config, $ctx->app, $ctx->company, $ctx->user, $request['input']),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $ctx = $this->actingContext();

        /** @var AgentLlmConfig $config */
        $config = AgentLlmConfig::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        return $config->softDelete();
    }
}
