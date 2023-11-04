<?php

declare(strict_types=1);

namespace Kanvas\ContentEngine\Reviews\Models;

use Kanvas\ContentEngine\Models\BaseModel;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $users_id
 * @property string $name
 */
class ReviewType extends BaseModel
{
    protected $table = 'review_types';
    protected $guarded = [];
}
