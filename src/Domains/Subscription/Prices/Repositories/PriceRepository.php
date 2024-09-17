<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Prices\Repositories;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Subscription\Prices\Models\Price;
use Baka\Traits\SearchableTrait;

class PriceRepository
{
    use SearchableTrait;
    
    /**
     * Get a price by its ID.
     *
     * @param int $id
     * @return Price
     */
    public static function getModel(): Model
    {
        return new Price();
    }
}
