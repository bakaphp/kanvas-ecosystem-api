<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Types\ActivityLog;

use Kanvas\Users\Models\Users;
use Spatie\Activitylog\Models\Activity;

class ActivityLogType
{
    public function user(Activity $root): ?Users
    {
        if (! $root->causer_id) {
            return null;
        }

        return Users::find($root->causer_id);
    }
}
