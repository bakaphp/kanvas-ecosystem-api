<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventResource;

trait EventResourceTrait
{
    public function eventResources(): MorphMany
    {
        return $this->morphMany(EventResource::class, 'resource');
    }

    public function events(): MorphToMany
    {
        return $this->morphToMany(
            Event::class,
            'resources',
            'event_resources',
            'resources_id',
            'event_id',
            'id',
            'id'
        );
    }
}
