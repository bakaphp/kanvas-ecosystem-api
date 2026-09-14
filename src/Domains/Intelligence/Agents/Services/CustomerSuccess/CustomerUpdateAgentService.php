<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services\CustomerSuccess;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\CustomerSuccess\CustomerUpdateAgent;

/**
 * Which agent writes this account's update.
 *
 * The account's own company, not the operator's: the monthly batch walks accounts across every company
 * on an app, so "the current company" is meaningless there and picking the wrong one would have an
 * agent from company A writing to company B's customer.
 */
class CustomerUpdateAgentService
{
    /**
     * @param int|null $agentId an explicit choice from the operator, which wins over the lookup
     */
    public function resolve(Apps $app, Organization $organization, ?int $agentId = null): ?Agent
    {
        if ($agentId !== null && $agentId > 0) {
            /** @var Agent $agent */
            $agent = Agent::getById($agentId, $app);

            return $agent;
        }

        /** @var Agent|null $agent */
        $agent = Agent::query()
            ->fromApp($app)
            ->where('companies_id', $organization->companies_id)
            ->whereHas('type', fn ($query) => $query->where('handler', CustomerUpdateAgent::class))
            ->notDeleted()
            ->first();

        return $agent;
    }
}
