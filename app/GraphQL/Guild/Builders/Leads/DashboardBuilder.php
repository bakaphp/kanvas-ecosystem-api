<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Builders\Leads;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Guild\Agents\Enums\AgentFilterEnum;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Leads\Models\Lead;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class DashboardBuilder
{
    public function getCompanyInfo(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $memberId = 'member_number_' . $company->getId();

        $agentInfo = Agent::where('companies_id', $company->getId())
            ->where('users_id', $user->getId())
            ->first();

        $memberId = (int) ($user->get($memberId) ? $user->get($memberId) : $user->getId());

        if ($company->get(AgentFilterEnum::FITTER_BY_OWNER->value) && $agentInfo) {
            return Lead::selectRaw('
                    COUNT(CASE WHEN leads_status.name = ? THEN 1 END) + COUNT(CASE WHEN leads_status.name = ? THEN 1 END)  as total_active_leads,
                    COUNT(CASE WHEN leads_status.name = ? THEN 1 END) as total_closed_leads,
                    (SELECT count(*) FROM agents where owner_linked_source_id = ? AND companies_id = ? and status_id = 1) as total_agents
                ', ['active', 'created', 'complete', $agentInfo->users_linked_source_id, $company->getId()])
                ->join('leads_status', 'leads.leads_status_id', '=', 'leads_status.id')
                ->fromCompany($company);
        }

        /**
         * @var Builder
         */
        return Lead::selectRaw('
                    COUNT(CASE WHEN leads_status.name = ? THEN 1 END) + COUNT(CASE WHEN leads_status.name = ? THEN 1 END)  as total_active_leads,
                    COUNT(CASE WHEN leads_status.name = ? THEN 1 END) as total_closed_leads,
                    (SELECT count(*) FROM agents where owner_id = ? AND companies_id = ? and status_id = 1) as total_agents
                ', ['active', 'created', 'complete', $memberId, $company->getId()])
                 ->join('leads_status', 'leads.leads_status_id', '=', 'leads_status.id')
                 ->fromCompany($company);
    }
}
