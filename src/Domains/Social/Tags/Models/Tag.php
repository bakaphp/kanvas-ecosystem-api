<?php

declare(strict_types=1);

namespace Kanvas\Social\Tags\Models;

use Kanvas\Social\Models\BaseModel;

/**
 * @property int id
 * @property int apps_id
 * @property int companies_id
 * @property int users_id
 * @property string name
 * @property string slug
 * @property string color
 * @property float weight
 */
class Tag extends BaseModel
{
    protected $guarded = [];
}
