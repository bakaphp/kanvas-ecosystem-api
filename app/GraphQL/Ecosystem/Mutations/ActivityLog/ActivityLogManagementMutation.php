<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\ActivityLog;

use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;
use Spatie\Activitylog\Models\Activity;

class ActivityLogManagementMutation
{
    public function createActivityLog(mixed $root, array $req): Activity
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $systemModules = SystemModules::getByUuid($req['input']['system_module_uuid'], $app);
        $module = $systemModules->model_name;

        $entity = $module::getById($req['input']['entity_id'], $app);
        $logName = $req['input']['log_name'] ?? 'default';
        $activity = activity($logName)
            ->causedBy($user)
            ->performedOn($entity)
            ->withProperties((array) $req['input']['properties'])
            ->log($req['input']['description'] ?? null);

        return $activity;
    }
}
