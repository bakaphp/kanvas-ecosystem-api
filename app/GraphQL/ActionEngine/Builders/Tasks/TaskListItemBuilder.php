<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Builders\Tasks;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Apps\Models\Apps;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class TaskListItemBuilder
{
    public function getItems(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        return TaskListItem::query()
            ->join('company_task_list', 'company_task_list_items.task_list_id', '=', 'company_task_list.id')
            ->where('company_task_list.companies_id', $company->getId())
            ->where('company_task_list.apps_id', $app->getId())
            ->where('company_task_list.is_deleted', 0)
            ->select('company_task_list_items.*');
    }
}
