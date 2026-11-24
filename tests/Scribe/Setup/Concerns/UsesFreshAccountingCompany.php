<?php

declare(strict_types=1);

namespace Tests\Scribe\Setup\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Users\Models\Users;

/**
 * Company settings live in Redis, which no transaction rolls back, so the setup is exercised on a fresh
 * company per test and never on the shared one other tests read.
 */
trait UsesFreshAccountingCompany
{
    protected function freshCompany(?string $countryCode = 'US', ?string $name = null): Companies
    {
        $company = Companies::factory()->create([
            'users_id' => Auth::id(),
            'is_active' => 1,
            'country_code' => $countryCode,
            'name' => $name ?? 'Acme Setup ' . Str::random(8),
        ]);
        $company->associateApp($this->kanvasApp);

        return $company;
    }

    protected function accountingAgent(Companies $company, string $handler, ?Users $user = null): Agent
    {
        $type = AgentType::factory()
            ->withAppId($this->kanvasApp->getId())
            ->create(['provider' => 'neuron', 'handler' => $handler]);

        return Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Accounting ' . Str::random(6),
                'agent_type_id' => $type->getId(),
                'user_id' => ($user ?? static::$cachedUser)->getId(),
                'is_active' => true,
            ]);
    }
}
