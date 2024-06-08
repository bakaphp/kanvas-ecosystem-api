<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Builders\Engagements;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\ActionEngine\Tasks\Models\TaskEngagementItem;
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

        /**
         * @var Builder
         */
        return TaskEngagementItem::query()->where('lead_id', $lead->getId());
    }
}
