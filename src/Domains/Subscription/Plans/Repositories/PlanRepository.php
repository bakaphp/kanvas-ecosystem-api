<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Plans\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Subscription\Plans\Models\Plan;

class PlanRepository
{
    use SearchableTrait;

    /**
     * Get the model instance for Plan.
     */
    public static function getModel(): Model
    {
        return new Plan();
    }

    public static function getByIdWithApp(int $id, ?AppInterface $app = null): Model
    {
        try {
            $query = self::getModel()::notDeleted()->where('id', $id);

            if ($app) {
                $query = $query->fromApp($app);
            }

            return $query->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }

    public static function getByStripeId(string $stripeId, AppInterface $app): Model
    {
        try {
            $query = self::getModel()::notDeleted()->fromApp($app)->where('stripe_id', $stripeId);

            return $query->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }
}
