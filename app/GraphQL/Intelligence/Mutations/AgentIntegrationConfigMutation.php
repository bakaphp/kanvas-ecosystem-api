<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\SetAgentIntegrationConfigAction;
use Kanvas\Intelligence\Agents\Enums\AgentIntegrationConfigKeyEnum;
use Kanvas\Intelligence\Agents\Models\Agent;

class AgentIntegrationConfigMutation
{
    public function set(mixed $root, array $req): Agent
    {
        $input = $req['input'];
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = Agent::getByIdFromCompanyApp((int) $input['agent_id'], $company, $app);

        $entries = array_map(
            static fn (array $entry): array => [
                'key' => AgentIntegrationConfigKeyEnum::fromName((string) $entry['key']),
                'value' => $entry['value'] ?? null,
            ],
            $input['config'],
        );

        return new SetAgentIntegrationConfigAction($agent, $entries)->execute();
    }
}
