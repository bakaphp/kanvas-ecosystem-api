<?php

declare(strict_types=1);

namespace Kanvas\Event\Facilitators\Models;

use Kanvas\Event\Models\BaseModel;

class Facilitator extends BaseModel
{
    protected $table = 'facilitators';
    protected $guarded = [];
}
