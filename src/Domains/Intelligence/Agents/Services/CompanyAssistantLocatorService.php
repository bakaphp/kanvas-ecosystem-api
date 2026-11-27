<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Enums\CompanyAssistantSettingEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Company\CompanyBrainAgent;

/**
 * Which agent is a company's assistant. Nothing stops a company from holding several Company Brain
 * agents, so one of them has to be the answer.
 */
class CompanyAssistantLocatorService
{
    public static function for(Apps $app, Companies $company): ?Agent
    {
        $candidates = self::candidates($app, $company);
        $designated = (int) $company->get(CompanyAssistantSettingEnum::AGENT_ID->value, 0);

        // A setting that names a foreign or retired agent is ignored: the company still has an assistant.
        return $candidates->firstWhere('id', $designated) ?? $candidates->first();
    }

    /**
     * @return Collection<int, Agent> oldest first
     */
    public static function candidates(Apps $app, Companies $company): Collection
    {
        // The handler is a class name, so a subclass of the Brain is matched in PHP, not in SQL.
        $brainTypeIds = AgentType::query()
            ->fromAppOrGlobal($app)
            ->notDeleted()
            ->get(['id', 'handler'])
            ->filter(fn (AgentType $type): bool => is_a((string) $type->handler, CompanyBrainAgent::class, true))
            ->pluck('id');

        return Agent::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('is_active', 1)
            ->whereIn('agent_type_id', $brainTypeIds)
            ->orderBy('id')
            ->get();
    }
}
