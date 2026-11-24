<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Support\CompanyScope;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Support\AgentReadAccessCompanies;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Users\Models\Users;

/**
 * The host test sets `$kanvasApp` and `$human` in setUp().
 */
trait BuildsCrossCompanyScopes
{
    private Apps $kanvasApp;
    private Users $human;

    private function scoped(object $tool, Companies $home, CompanyScope $scope): object
    {
        return $tool->withContext(
            $this->kanvasApp,
            $home,
            $this->human,
            scope: $scope,
        );
    }

    private function company(bool $member = true): Companies
    {
        $company = Companies::factory()->create();

        if ($member) {
            $company->associateUserApp($this->human, $this->kanvasApp, 1);
        }

        return $company;
    }

    /**
     * @param list<int> $readAccess
     */
    private function agent(Companies $home, array $readAccess = [], ?string $handler = null): Agent
    {
        $type = AgentType::factory()
            ->withAppId($this->kanvasApp->getId())
            ->create(array_filter(['provider' => 'neuron', 'handler' => $handler]));

        $agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($home->getId())
            ->create(['agent_type_id' => $type->getId()]);

        if ($readAccess !== []) {
            $agent->set(ConfigurationEnum::AGENT_READ_ACCESS_COMPANIES->value, $readAccess);
        }

        return $agent;
    }

    private function scope(Companies $home, Companies ...$others): CompanyScope
    {
        $agent = $this->agent($home, array_map(fn (Companies $company): int => (int) $company->getId(), $others));

        return AgentReadAccessCompanies::scopeFor($agent, $home, $this->human);
    }

    private function person(Companies $company, string $firstname): People
    {
        return People::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->withUserId($this->human->getId())
            ->create(['firstname' => $firstname, 'lastname' => 'Scope', 'name' => $firstname . ' Scope']);
    }
}
