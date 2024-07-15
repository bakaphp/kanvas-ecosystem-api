<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Builders\Engagements;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class TaskEngagementBuilder
{
    public function getLeadTaskItems(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $company = auth()->user()->getCurrentCompany();
        $user = auth()->user();
        $app = app(Apps::class);

        $lead = Lead::getByIdFromCompanyApp($args['lead_id'], $company, $app);
        $leadId = $lead->getId();

        return TaskListItem::leftJoin('company_task_engagement_items', function ($join) use ($lead) {
            $join->on('company_task_list_items.id', '=', 'company_task_engagement_items.task_list_item_id')
                 ->where('company_task_engagement_items.lead_id', '=', $lead->getId());
        })
        ->leftJoin('company_task_list', 'company_task_list.id', '=', 'company_task_list_items.task_list_id')
        ->where('company_task_list.companies_id', '=', $lead->companies_id)
        ->select(
            'company_task_list_items.*',
            'company_task_engagement_items.lead_id',
            'company_task_engagement_items.status',
            'company_task_engagement_items.engagement_start_id',
            'company_task_engagement_items.engagement_end_id',
            'company_task_engagement_items.created_at',
            'company_task_engagement_items.updated_at'
        );
    }
}
