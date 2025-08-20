<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Souk\Discounts\Models\Discount;

class DiscountRepository
{
    /**
     * Get discounts query
     * @return Builder<Discount>
     */
    public static function getQuery(AppInterface $app, ?CompanyInterface $company = null): Builder
    {
        $query = Discount::query()
            ->where('apps_id', $app->getId())
            ->where('is_deleted', false);

        if ($company !== null) {
            $query->where('companies_id', $company->getId());
        }

        return $query;
    }

    /**
     * Get active discounts
     */
    public static function getActiveDiscounts(AppInterface $app, CompanyInterface $company): Collection
    {
        return self::getQuery($app, $company)
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->get();
    }

    /**
     * Find discount by code
     */
    public static function findByCode(string $code, AppInterface $app, CompanyInterface $company): ?Discount
    {
        return self::getQuery($app, $company)
            ->where('code', $code)
            ->first();
    }

    /**
     * Get discounts by type
     */
    public static function getByType(int $typeId, AppInterface $app, CompanyInterface $company): Collection
    {
        return self::getQuery($app, $company)
            ->where('discount_type_id', $typeId)
            ->get();
    }

    /**
     * Get expired discounts
     */
    public static function getExpiredDiscounts(AppInterface $app, ?CompanyInterface $company = null): Collection
    {
        return self::getQuery($app, $company)
            ->where('end_date', '<', now())
            ->get();
    }

    /**
     * Get upcoming discounts
     */
    public static function getUpcomingDiscounts(AppInterface $app, ?CompanyInterface $company = null): Collection
    {
        return self::getQuery($app, $company)
            ->where('start_date', '>', now())
            ->get();
    }

    /**
     * Search discounts
     */
    public static function search(string $search, AppInterface $app, ?CompanyInterface $company = null): Collection
    {
        return self::getQuery($app, $company)
            ->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            })
            ->get();
    }
}
