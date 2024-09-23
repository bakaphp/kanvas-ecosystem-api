<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Prices\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Subscription\Prices\Models\Price;

class PriceRepository
{
    use SearchableTrait;

    public static function getModel(): Model
    {
        return new Price();
    }

    public static function getByIdWithApp(int $id, AppInterface $app): Price
    {
        try {
            return self::getModel()::notDeleted()
                ->select('apps_plans_prices.*')
                ->join('apps_plans', 'apps_plans.id', '=', 'apps_plans_prices.apps_plans_id')
                ->where('apps_plans_prices.id', $id)
                ->where('apps_plans.apps_id', $app->getId())
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException('No stripe price configure for this app');
        }
    }
}
