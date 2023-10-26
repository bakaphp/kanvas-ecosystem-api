<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Builders\Leads;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
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

        $memberId = (int) ($user->get($memberId) ? $user->get($memberId) : $user->getId());
        /**
         * @var Builder
         */
        return Lead::selectRaw('
                    COUNT(CASE WHEN leads_status.name = ? THEN 1 END) + COUNT(CASE WHEN leads_status.name = ? THEN 1 END)  as total_active_leads,
                    COUNT(CASE WHEN leads_status.name = ? THEN 1 END) as total_closed_leads,
                    (SELECT count(*) FROM agents where owner_id = ? AND companies_id = ?) as total_agents
                ', ['active', 'created', 'closed', $memberId, $company->getId()])
                 ->join('leads_status', 'leads.leads_status_id', '=', 'leads_status.id')
                 ->fromCompany($company);
    }
}
