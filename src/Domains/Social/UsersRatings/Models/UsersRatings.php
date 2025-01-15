<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersRatings\Models;

use Kanvas\Social\Models\BaseModel;

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
class UsersRatings extends BaseModel
{
    protected $table = 'users_ratings';

    protected $fillable = [
        'users_id',
        'companies_id',
        'apps_id',
        'system_modules_id',
        'entity_id',
        'rating',
        'comment'
    ];
}
