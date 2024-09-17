<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Plans\Repositories;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Subscription\Plans\Models\Plan;
use Baka\Traits\SearchableTrait;

class PlanRepository
{
    use SearchableTrait;
    
    /**
     * Get the model instance for Plan.
     *
     * @return Model
     */
    public static function getModel(): Model
    {
        return new Plan();
    }
}
