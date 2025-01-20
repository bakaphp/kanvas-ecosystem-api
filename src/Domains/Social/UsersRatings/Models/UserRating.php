<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersRatings\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Social\Models\BaseModel;
use Kanvas\Social\UsersRatings\Observers\UserRatingObserver;
use Kanvas\SystemModules\Models\SystemModules;

/**
 * @property int $id
 * @property int $users_id
 * @property int $companies_id
 * @property int $apps_id
 * @property int $system_modules_id
 * @property int $entity_id
 * @property float $ratings
 * @property string $comment
 */
#[ObservedBy([UserRatingObserver::class])]
class UserRating extends BaseModel
{
    protected $table = 'users_ratings';

    protected $fillable = [
        'users_id',
        'companies_id',
        'apps_id',
        'system_modules_id',
        'entity_id',
        'rating',
        'comment',
    ];

    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'system_modules_id');
    }

    public function entity()
    {
        return $this->belongsTo($this->systemModule->model_name, 'entity_id');
    }
}
