<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Kanvas\Event\Models\BaseModel;

class EventClass extends BaseModel
{
    protected $table = 'event_classes';
    protected $guarded = [];
}
