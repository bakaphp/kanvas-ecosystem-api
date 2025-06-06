<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\ActivityLog;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;
use Spatie\Activitylog\Models\Activity;

class ActivityLogQuery
{
    public function getAllActivityLogs(mixed $root, array $query): Builder
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $systemModules = SystemModules::getByUuid($query['system_module_uuid'], $app);
        $module = $systemModules->model_name;

        $entity = $module::getById($query['entity_id'], $app);

        return Activity::query()
            ->where('subject_type', $module)
            ->where('subject_id', $entity->getKey());
    }
}
