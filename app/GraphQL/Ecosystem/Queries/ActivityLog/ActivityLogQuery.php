<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\ActivityLog;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Activities\Models\Activity as ModelsActivity;
use Kanvas\Apps\Models\Apps;

class ActivityLogQuery
{
    public function getAllActivityLogs(mixed $root, array $query): Builder
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        return ModelsActivity::forAppAndCompany($app, $company);
    }
}
