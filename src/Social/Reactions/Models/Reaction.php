<?php

declare(strict_types=1);

namespace Kanvas\Social\Reactions\Models;

use Kanvas\Social\Models\BaseModel;

/**
 * class Reaction
 * @property int $id
 * @property string $name
 * @property string $icon
 * @property int $apps_id
 * @property int $companies_id
*/
class Reaction extends BaseModel
{
    protected $table = 'reactions';

    protected $guarded = [];
}
