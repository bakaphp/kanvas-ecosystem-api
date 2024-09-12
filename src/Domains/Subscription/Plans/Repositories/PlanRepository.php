<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Plans\Repositories;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Subscription\Plans\Models\Plan;

class PlanRepository
{
    /**
     * Get the model instance for Plan.
     *
     * @return Model
     */
    public static function getModel(): Model
    {
        return new Plan();
    }

    /**
     * Get a plan by its ID.
     *
     * @param int $id
     * @return Plan
     */
    public static function getById(int $id): Plan
    {
        return Plan::findOrFail($id);
    }

    /**
     * Get a plan by its name.
     *
     * @param string $name
     * @return Plan
     */
    public static function getByName(string $name): Plan
    {
        return Plan::where('name', $name)->firstOrFail();
    }

    /**
     * Get a plan by its Stripe ID.
     *
     * @param string $stripeId
     * @return Plan
     */
    public static function getByStripeId(string $stripeId): Plan
    {
        return Plan::where('stripe_id', $stripeId)->firstOrFail();
    }
}
