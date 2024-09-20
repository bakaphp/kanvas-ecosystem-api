<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Prices\Repositories;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Subscription\Prices\Models\Price;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Baka\Contracts\AppInterface;
use Baka\Traits\SearchableTrait;

class PriceRepository
{
    use SearchableTrait;

    public static function getModel(): Model
    {
        return new Price();
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
}
