<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Kanvas\Event\Models\BaseModel;

class EventStatus extends BaseModel
{
    protected $table = 'event_statuses';
    protected $guarded = [];
}
