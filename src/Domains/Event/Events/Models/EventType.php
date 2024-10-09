<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Kanvas\Event\Models\BaseModel;

class EventType extends BaseModel
{
    protected $table = 'event_types';
    protected $guarded = [];
}
