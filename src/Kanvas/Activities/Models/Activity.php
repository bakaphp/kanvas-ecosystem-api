<?php

declare(strict_types=1);

namespace Kanvas\Activities\Models;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Users\Models\Users;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

class Activity extends SpatieActivity
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'activity_log';

    public function user()
    {
        return $this->belongsTo(Users::class, 'causer_id');
    }

    public function scopeForAppAndCompany($query, AppInterface $app, CompanyInterface $company)
    {
        $suffix = "-{$company->getId()}-{$app->getId()}";

        return $query->where('log_name', 'LIKE', '%' . $suffix);
    }

    public function scopeForApp($query, AppInterface $app)
    {
        $suffix = "-{$app->getId()}";
        return $query->where('log_name', 'LIKE', '%' . $suffix);
    }
}
