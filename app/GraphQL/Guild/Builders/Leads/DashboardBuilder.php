<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Builders\Leads;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Agents\Enums\AgentFilterEnum;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Leads\Enums\LeadFilterEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Throwable;

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
        $app = app(Apps::class);
        $memberId = 'member_number_' . $company->getId();

        $agentInfo = Agent::where('companies_id', $company->getId())
            ->where('users_id', $user->getId())
            ->first();

        $memberId = (int) ($user->get($memberId) ? $user->get($memberId) : $user->getId());

        $closedStatuses = $company->get('LEADS_CLOSED_STATUS_NAMES') ?? ['complete', 'closed'];
        $openStatuses = $company->get('LEADS_OPEN_STATUS_NAMES') ?? ['new', 'active', 'open', 'in_progress'];
        $closedStatusPlaceholders = implode(',', array_fill(0, count($closedStatuses), '?'));
        $openStatusPlaceholders = implode(',', array_fill(0, count($openStatuses), '?'));

        if ($company->get(AgentFilterEnum::FITTER_BY_OWNER->value) && $agentInfo) {
            return Lead::selectRaw("
                    COUNT(CASE WHEN leads_status.name IN ({$openStatusPlaceholders}) THEN 1 END) as total_active_leads,
                    COUNT(CASE WHEN leads_status.name IN ({$closedStatusPlaceholders}) THEN 1 END) as total_closed_leads,
                    (SELECT count(*) FROM agents where owner_linked_source_id = ? AND companies_id = ? and status_id = 1) as total_agents
                ", [...$openStatuses, ...$closedStatuses, $agentInfo->users_linked_source_id, $company->getId()])
                ->join('leads_status', 'leads.leads_status_id', '=', 'leads_status.id')
                ->fromCompany($company);
        }

        if ($app->get(LeadFilterEnum::FILTER_BY_OWNER_AGENTS->value) && $user->get('ZOHO_USER_OWNER_ID')) {
            try {
                $query = Lead::selectRaw("
                    COUNT(CASE WHEN leads_status.name IN ({$openStatusPlaceholders}) THEN 1 END) as total_active_leads,
                    COUNT(CASE WHEN leads_status.name IN ({$closedStatusPlaceholders}) THEN 1 END) as total_closed_leads,
                    (SELECT count(*) FROM agents where owner_linked_source_id = ? AND companies_id = ? and status_id = 1) as total_agents
                ", [...$openStatuses, ...$closedStatuses, $agentInfo->users_linked_source_id, $company->getId()])
                    ->join('leads_status', 'leads.leads_status_id', '=', 'leads_status.id')
                    ->where('leads_owner_id', $user->getId())
                    ->where('leads_receivers_id', '=', LeadReceiver::getByName('Agents Platform', $app)->getId())
                    ->fromCompany($company)
                    ->where('leads.is_deleted', 0);

                return $query;
            } catch (Throwable $e) {
                //ignore
            }
        }

        /**
         * @var Builder
         */
        return Lead::selectRaw("
                    COUNT(CASE WHEN leads_status.name IN ({$openStatusPlaceholders}) THEN 1 END) as total_active_leads,
                    COUNT(CASE WHEN leads_status.name IN ({$closedStatusPlaceholders}) THEN 1 END) as total_closed_leads,
                    (SELECT count(*) FROM agents where owner_id = ? AND companies_id = ? and status_id = 1) as total_agents
                ", [...$openStatuses, ...$closedStatuses, $memberId, $company->getId()])
                 ->join('leads_status', 'leads.leads_status_id', '=', 'leads_status.id')
                 ->fromCompany($company);
    }
}
