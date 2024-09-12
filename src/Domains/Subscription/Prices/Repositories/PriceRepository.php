<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Prices\Repositories;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Subscription\Prices\Models\Price;

class PriceRepository
{
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

    /**
     * Get a price by its Stripe ID.
     *
     * @param string $stripeId
     * @return Price
     */
    public static function getByStripeId(string $stripeId): Price
    {
        return Price::where('stripe_id', $stripeId)->firstOrFail();
    }
}
