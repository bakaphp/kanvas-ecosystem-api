<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Config;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Support\AgentReadAccessCompanies;
use Kanvas\Intelligence\Enums\ConfigurationEnum;

class AgentConfigManagement
{
    public function setAgentSetting(mixed $root, array $request): bool
    {
        $agent = $this->agentFromInput($request['input']['entity_uuid']);
        $key = $request['input']['key'];
        $value = $request['input']['value'];

        if ($key === ConfigurationEnum::AGENT_READ_ACCESS_COMPANIES->value) {
            $value = AgentReadAccessCompanies::assertGrantable($agent, auth()->user(), $value);
        }

        $agent->set($key, $value);

        return true;
    }

    public function deleteAgentSetting(mixed $root, array $request): bool
    {
        $this->agentFromInput($request['input']['entity_uuid'])
            ->del($request['input']['key']);

        return true;
    }

    private function agentFromInput(string $uuid): Agent
    {
        $user = auth()->user();

        /** @var Agent $agent */
        $agent = Agent::getByUuidFromCompanyApp(
            $uuid,
            $user->getCurrentCompany(),
            app(Apps::class)
        );

        return $agent;
    }
}
