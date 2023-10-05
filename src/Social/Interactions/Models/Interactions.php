<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Models;

use Kanvas\Social\Models\BaseModel;

/**
 * Class Interactions.
 *
 * @property int $id
 * @property int $apps_id
 * @property string $name
 * @property string $title
 * @property string $icon
 * @property string $description
 */
class Interactions extends BaseModel
{
    protected $table = 'interactions';
    protected $guarded = [];
}
