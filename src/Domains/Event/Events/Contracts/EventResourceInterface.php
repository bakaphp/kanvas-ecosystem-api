<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

interface EventResourceInterface
{
    public function eventResources(): MorphMany;
}
